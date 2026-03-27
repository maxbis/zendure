<?php
// data/api/data_api.php

date_default_timezone_set('Europe/Amsterdam');

require_once __DIR__ . '/data_functions.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Configuration
define('DATA_DIR', __DIR__ . '/..');
define('ALLOWED_TYPES', ['price', 'zendure', 'zendure_p1', 'schedule', 'automation_status', 'file', 'list']);
define('PRICE_RETENTION_DAYS', 4);
define('PRICE_ARCHIVE_DIR', DATA_DIR . '/price_archive');
define('MAIN_CONFIG_PATH', __DIR__ . '/../../config/config.json');
define('SCHEDULE_FUNCTIONS_PATH', __DIR__ . '/../../api/charge_schedule_functions.php');
define('CONDITIONAL_SCHEDULE_RESOLVER_PATH', __DIR__ . '/../resolve_schedule_conditions.php');

function loadMainConfig() {
    if (!file_exists(MAIN_CONFIG_PATH) || !is_readable(MAIN_CONFIG_PATH)) {
        return [];
    }
    $raw = file_get_contents(MAIN_CONFIG_PATH);
    if ($raw === false) {
        return [];
    }
    $cfg = json_decode($raw, true);
    return is_array($cfg) ? $cfg : [];
}

function getIncludeConditionsFlag() {
    $cfg = loadMainConfig();
    if (!array_key_exists('include_conditions', $cfg)) {
        return false;
    }
    $parsed = filter_var($cfg['include_conditions'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed === null ? false : $parsed;
}

define('include_conditions', getIncludeConditionsFlag());

function buildWriteFailureMessage($context, $filePath) {
    $details = function_exists('getLastWriteDataFileAtomicError')
        ? getLastWriteDataFileAtomicError()
        : null;
    $base = "Write failed [$context]: " . basename($filePath);
    return $details ? ($base . " (" . $details . ")") : $base;
}

function ensureScheduleFunctions() {
    if (!file_exists(SCHEDULE_FUNCTIONS_PATH)) {
        throw new Exception("Schedule functions file not found: " . SCHEDULE_FUNCTIONS_PATH);
    }
    require_once SCHEDULE_FUNCTIONS_PATH;
}

function normalizeScheduleKeys($schedule) {
    $out = [];
    if (is_array($schedule)) {
        foreach ($schedule as $k => $v) {
            $out[(string) $k] = $v;
        }
    }
    return $out;
}

function readJsonBody() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON in request body: " . json_last_error_msg());
    }
    return $data;
}

function getDataParamsForType($type) {
    $params = [];
    if ($type === 'file') {
        if (!isset($_GET['name'])) {
            throw new Exception("Missing 'name' parameter for file type");
        }
        $params['name'] = $_GET['name'];
    }
    return $params;
}

function getConditionalResolvedForDate($date) {
    if (!file_exists(CONDITIONAL_SCHEDULE_RESOLVER_PATH)) {
        return null;
    }

    $cmd = 'php ' . escapeshellarg(CONDITIONAL_SCHEDULE_RESOLVER_PATH) . ' 2>/dev/null';
    $raw = @shell_exec($cmd);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || empty($decoded['success']) || !isset($decoded['resolved']) || !is_array($decoded['resolved'])) {
        return null;
    }

    foreach ($decoded['resolved'] as $group) {
        if (!is_array($group) || !isset($group['date']) || !isset($group['items']) || !is_array($group['items'])) {
            continue;
        }
        if ((string) $group['date'] !== (string) $date) {
            continue;
        }
        return $group['items'];
    }

    return null;
}

function normalizeDataApiResolvedConditionalMetadata($item) {
    $meta = [
        'value' => $item['value'],
        'runtime_conditions' => (isset($item['runtime_conditions']) && is_array($item['runtime_conditions']))
            ? array_values($item['runtime_conditions'])
            : null,
        'fallback_value' => array_key_exists('fallback_value', $item) ? $item['fallback_value'] : null,
        'rule_name' => (isset($item['rule_name']) && is_string($item['rule_name']) && trim($item['rule_name']) !== '')
            ? trim((string) $item['rule_name'])
            : null,
        'rule_index' => (array_key_exists('rule_index', $item) && is_numeric($item['rule_index']))
            ? ((int) $item['rule_index'])
            : null,
    ];

    if (array_key_exists('min_power', $item)) {
        $meta['min_power'] = normalizeOptionalScheduleBound($item['min_power'], 'min_power');
    }
    if (array_key_exists('max_power', $item)) {
        $meta['max_power'] = normalizeOptionalScheduleBound($item['max_power'], 'max_power');
    }
    if (
        array_key_exists('min_power', $meta) &&
        array_key_exists('max_power', $meta) &&
        $meta['min_power'] !== null &&
        $meta['max_power'] !== null &&
        $meta['min_power'] > $meta['max_power']
    ) {
        throw new Exception("Invalid resolved conditional metadata. 'min_power' cannot be greater than 'max_power'");
    }

    return $meta;
}

function mergeResolvedWithConditional($resolved, $date) {
    if (!is_array($resolved)) {
        return $resolved;
    }

    $conditionalItems = getConditionalResolvedForDate($date);
    if (!is_array($conditionalItems) || empty($conditionalItems)) {
        return $resolved;
    }

    $byTime = [];
    foreach ($conditionalItems as $item) {
        if (!is_array($item) || !isset($item['time']) || !array_key_exists('value', $item)) {
            continue;
        }
        $time = str_pad((string) $item['time'], 4, '0', STR_PAD_LEFT);
        $byTime[$time] = normalizeDataApiResolvedConditionalMetadata($item);
    }

    if (empty($byTime)) {
        return $resolved;
    }

    foreach ($resolved as &$slot) {
        if (!is_array($slot) || !isset($slot['time'])) {
            continue;
        }
        $slotTime = str_pad((string) $slot['time'], 4, '0', STR_PAD_LEFT);
        if (array_key_exists($slotTime, $byTime)) {
            $slotKey = isset($slot['key']) ? (string) $slot['key'] : '';
            $isManualNonWildcard = $slotKey !== '' && strpos($slotKey, '*') === false;
            // Priority model:
            // exact manual keys always win; wildcard/empty base slots may be overridden by conditions
            if ($isManualNonWildcard) {
                continue;
            }
            $slotMeta = $byTime[$slotTime];
            $slot['value'] = $slotMeta['value'];
            $slot['source'] = 'condition';
            if (is_array($slotMeta['runtime_conditions']) && !empty($slotMeta['runtime_conditions'])) {
                $slot['runtime_conditions'] = $slotMeta['runtime_conditions'];
            } else {
                unset($slot['runtime_conditions']);
            }
            if ($slotMeta['fallback_value'] !== null) {
                $slot['fallback_value'] = $slotMeta['fallback_value'];
            } else {
                unset($slot['fallback_value']);
            }
            if (array_key_exists('min_power', $slotMeta)) {
                if ($slotMeta['min_power'] !== null) {
                    $slot['min_power'] = $slotMeta['min_power'];
                } else {
                    unset($slot['min_power']);
                }
            }
            if (array_key_exists('max_power', $slotMeta)) {
                if ($slotMeta['max_power'] !== null) {
                    $slot['max_power'] = $slotMeta['max_power'];
                } else {
                    unset($slot['max_power']);
                }
            }
            if ($slotMeta['rule_name'] !== null) {
                $slot['rule_name'] = $slotMeta['rule_name'];
            } else {
                unset($slot['rule_name']);
            }
            if ($slotMeta['rule_index'] !== null) {
                $slot['rule_index'] = $slotMeta['rule_index'];
            } else {
                unset($slot['rule_index']);
            }
        }
    }
    unset($slot);

    return $resolved;
}

// --- GET handlers ---

function handleGetList() {
    $pattern = isset($_GET['pattern']) ? $_GET['pattern'] : null;
    $files = listDataFiles($pattern);
    return [
        'success' => true,
        'files' => $files,
        'count' => count($files)
    ];
}

function handleGetPrice() {
    if (isset($_GET['list']) && $_GET['list'] === 'true') {
        $files = listDataFiles('price*.json');
        return ['success' => true, 'files' => $files, 'count' => count($files)];
    }
    if (!isset($_GET['date'])) {
        throw new Exception("Missing 'date' parameter for price type");
    }
    $params = ['date' => $_GET['date']];
    $filePath = getDataFilePath('price', $params);
    if ($filePath === null) {
        throw new Exception("Invalid date parameter. Expected format: YYYYMMDD");
    }
    $data = readDataFile($filePath);
    if ($data === null) {
        return [
            'success' => false,
            'error' => 'File not found',
            'file' => basename($filePath)
        ];
    }
    return [
        'success' => true,
        'data' => $data,
        'file' => basename($filePath),
        'timestamp' => file_exists($filePath) ? filemtime($filePath) : null
    ];
}

function handleGetData($type) {
    $params = getDataParamsForType($type);
    $filePath = getDataFilePath($type, $params);
    if ($filePath === null) {
        throw new Exception("Invalid parameters for type: $type");
    }
    $data = readDataFile($filePath);
    if ($data === null) {
        $errorDetails = '';
        if (!file_exists($filePath)) {
            $errorDetails = "Data file ($filePath) does not exist";
        } elseif (!is_readable($filePath)) {
            $errorDetails = "Data file exists but is not readable by the web process";
        } else {
            $raw = @file_get_contents($filePath);
            if ($raw === false) {
                $errorDetails = "Data file exists but reading failed";
            } else {
                json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $errorDetails = "Data file contains invalid JSON: " . json_last_error_msg();
                } else {
                    $errorDetails = "Data file could not be loaded for an unknown reason";
                }
            }
        }
        $prefix = ($type === 'schedule') ? 'Schedule data unavailable' : 'Data unavailable';
        return [
            'success' => false,
            'error' => $prefix . ': ' . $errorDetails,
            'file' => basename($filePath)
        ];
    }
    if ($type === 'schedule') {
        ensureScheduleFunctions();
    }
    $wantResolved = $type === 'schedule' && (isset($_GET['resolved']) || (isset($_GET['format']) && $_GET['format'] === 'resolved'));
    if ($wantResolved) {
        $schedule = normalizeScheduleMap(normalizeScheduleKeys($data), 'GET schedule resolved');
        $date = isset($_GET['date']) ? $_GET['date'] : date('Ymd');
        if (!preg_match('/^\d{8}$/', $date)) {
            $date = date('Ymd');
        }
        $resolved = resolveScheduleForDate($schedule, $date);
        if (include_conditions) {
            $resolved = mergeResolvedWithConditional($resolved, $date);
        }
        $uiEntries = makeUiScheduleEntries($schedule);
        return [
            'success' => true,
            'date' => $date,
            'currentHour' => date('H') . '00',
            'currentTime' => date('Hi'),
            'resolved' => $resolved,
            'entries' => $uiEntries,
        ];
    }
    return [
        'success' => true,
        'data' => $type === 'schedule'
            ? normalizeScheduleMap(normalizeScheduleKeys($data), 'GET schedule raw')
            : $data,
        'file' => basename($filePath),
        'timestamp' => file_exists($filePath) ? filemtime($filePath) : null
    ];
}

// --- POST handlers ---

function handlePostPrice($input) {
    if (!isset($_GET['date'])) {
        throw new Exception("Missing 'date' parameter for price type");
    }
    $validation = validatePriceData($input);
    if (!$validation['valid']) {
        throw new Exception("Invalid price data: " . $validation['error']);
    }
    $params = ['date' => $_GET['date']];
    $filePath = getDataFilePath('price', $params);
    if ($filePath === null) {
        throw new Exception("Invalid date parameter. Expected format: YYYYMMDD");
    }
    if (!writeDataFileAtomic($filePath, $input)) {
        throw new Exception(buildWriteFailureMessage('POST price', $filePath));
    }
    $cleanupStats = cleanupOldPriceFiles(PRICE_RETENTION_DAYS, DATA_DIR, PRICE_ARCHIVE_DIR);
    if (!empty($cleanupStats['errors'])) {
        error_log("Price cleanup errors: " . implode('; ', $cleanupStats['errors']));
    }
    return [
        'success' => true,
        'message' => 'File saved successfully',
        'file' => basename($filePath),
        'cleanup' => [
            'moved' => $cleanupStats['moved'],
            'skipped' => $cleanupStats['skipped'],
            'errors' => count($cleanupStats['errors'])
        ]
    ];
}

function handlePostFile($input) {
    if (!isset($_GET['name'])) {
        throw new Exception("Missing 'name' parameter for file type");
    }
    $params = ['name' => $_GET['name']];
    $filePath = getDataFilePath('file', $params);
    if ($filePath === null) {
        throw new Exception("Invalid filename parameter");
    }
    if (!writeDataFileAtomic($filePath, $input)) {
        throw new Exception(buildWriteFailureMessage('POST file', $filePath));
    }
    return [
        'success' => true,
        'message' => 'File saved successfully',
        'file' => basename($filePath)
    ];
}

function validateScheduleKeyValue($keyStr, $value, $context = '') {
    normalizeScheduleWritePayload($keyStr, $value, $context);
}

function applyScheduleEntryAndWrite($filePath, $key, $entry, $orig, $input) {
    $schedule = readDataFile($filePath);
    $schedule = $schedule === null ? [] : normalizeScheduleMap(normalizeScheduleKeys($schedule), 'existing schedule');
    [$key, $normalizedEntry] = normalizeScheduleWritePayload($key, $entry, 'write schedule');
    if ($orig !== null && $orig !== $key) {
        unset($schedule[$orig]);
    }
    $schedule[$key] = $normalizedEntry;
    if (!function_exists('cleanOutdatedScheduleEntries') && file_exists(SCHEDULE_FUNCTIONS_PATH)) {
        require_once SCHEDULE_FUNCTIONS_PATH;
    }
    if (function_exists('cleanOutdatedScheduleEntries')) {
        $schedule = cleanOutdatedScheduleEntries($schedule);
    }
    if (!writeDataFileAtomic($filePath, $schedule)) {
        return null;
    }
    return [
        'success' => true,
        'message' => 'Schedule entry saved successfully',
        'file' => basename($filePath)
    ];
}

function handlePostSchedule($input) {
    if (!is_array($input)) {
        throw new Exception("Schedule data must be an array");
    }
    ensureScheduleFunctions();
    $filePath = getDataFilePath('schedule', []);
    if ($filePath === null) {
        throw new Exception("Invalid type: schedule");
    }
    if (isset($input['action']) && ($input['action'] === 'simulate' || $input['action'] === 'delete')) {
        ensureScheduleFunctions();
        $schedule = readDataFile($filePath);
        $schedule = $schedule === null ? [] : normalizeScheduleMap(normalizeScheduleKeys($schedule), 'POST schedule action');
        $simulate = ($input['action'] === 'simulate');
        $result = clearOldEntries($schedule, $simulate);
        if ($simulate) {
            return ['success' => true, 'count' => $result['count'], 'entries' => $result['entries']];
        }
        foreach ($result['entries'] as $key) {
            if (isset($schedule[$key])) {
                unset($schedule[$key]);
            }
        }
        if (!writeDataFileAtomic($filePath, $schedule)) {
            throw new Exception(buildWriteFailureMessage('POST schedule action=delete', $filePath));
        }
        return ['success' => true, 'count' => $result['count']];
    }
    if (isset($input['action']) && $input['action'] === 'clear_non_wildcard') {
        $schedule = readDataFile($filePath);
        $schedule = $schedule === null ? [] : normalizeScheduleMap(normalizeScheduleKeys($schedule), 'POST schedule clear_non_wildcard');

        $beforeCount = count($schedule);
        $filteredSchedule = [];
        foreach ($schedule as $entryKey => $entryValue) {
            if (strpos((string) $entryKey, '*') !== false) {
                $filteredSchedule[$entryKey] = $entryValue;
            }
        }

        $keptCount = count($filteredSchedule);
        $removedCount = $beforeCount - $keptCount;

        if (!writeDataFileAtomic($filePath, $filteredSchedule)) {
            throw new Exception(buildWriteFailureMessage('POST schedule action=clear_non_wildcard', $filePath));
        }

        return ['success' => true, 'removed' => $removedCount, 'kept' => $keptCount];
    }
    if (isset($input['key']) && isset($input['value']) && !isset($input['entry'])) {
        throw new Exception("Legacy schedule write format is no longer supported. Use { key, entry: { value } }.");
    }
    if (isset($input['key']) && isset($input['entry'])) {
        $key = (string) $input['key'];
        $entry = $input['entry'];
        $orig = isset($input['originalKey']) ? (string) $input['originalKey'] : null;
        validateScheduleKeyValue($key, $entry, 'POST single-entry');
        $resp = applyScheduleEntryAndWrite($filePath, $key, $entry, $orig, $input);
        if ($resp === null) {
            throw new Exception(buildWriteFailureMessage('POST schedule single-entry', $filePath));
        }
        return $resp;
    }
    $normalizedInput = normalizeScheduleMap($input, 'POST schedule full-replace');
    if (!writeDataFileAtomic($filePath, $normalizedInput)) {
        throw new Exception(buildWriteFailureMessage('POST schedule full-replace', $filePath));
    }
    return [
        'success' => true,
        'message' => 'Schedule saved successfully',
        'file' => basename($filePath)
    ];
}

function handlePostGeneric($type, $input) {
    $filePath = getDataFilePath($type, []);
    if ($filePath === null) {
        throw new Exception("Invalid type: $type");
    }
    if (!writeDataFileAtomic($filePath, $input)) {
        throw new Exception(buildWriteFailureMessage('POST generic type=' . $type, $filePath));
    }
    return [
        'success' => true,
        'message' => 'File saved successfully',
        'file' => basename($filePath)
    ];
}

// --- PUT handlers ---

function handlePutSchedule($input) {
    ensureScheduleFunctions();
    if (isset($input['key']) && isset($input['value']) && !isset($input['entry'])) {
        throw new Exception("Legacy schedule write format is no longer supported. Use { key, entry: { value } }.");
    }
    if (!isset($input['key']) || !isset($input['entry'])) {
        throw new Exception("Missing parameters. Required: key, entry");
    }
    $orig = isset($input['originalKey']) ? (string) $input['originalKey'] : null;
    $key = (string) $input['key'];
    $entry = $input['entry'];
    validateScheduleKeyValue($key, $entry, 'PUT');
    $filePath = getDataFilePath('schedule', []);
    if ($filePath === null) {
        throw new Exception("Invalid type: schedule");
    }
    $resp = applyScheduleEntryAndWrite($filePath, $key, $entry, $orig, $input);
    if ($resp === null) {
        throw new Exception(buildWriteFailureMessage('PUT schedule', $filePath));
    }
    return $resp;
}

// --- DELETE handlers ---

function handleDeletePrice() {
    if (!isset($_GET['date'])) {
        throw new Exception("Missing 'date' parameter for price type");
    }
    $params = ['date' => $_GET['date']];
    $filePath = getDataFilePath('price', $params);
    if ($filePath === null) {
        throw new Exception("Invalid date parameter. Expected format: YYYYMMDD");
    }
    if (!file_exists($filePath)) {
        return [
            'success' => false,
            'error' => 'File not found',
            'file' => basename($filePath)
        ];
    }
    if (!unlink($filePath)) {
        throw new Exception("Failed to delete file: " . basename($filePath));
    }
    return [
        'success' => true,
        'message' => 'File deleted successfully',
        'file' => basename($filePath)
    ];
}

function handleDeleteSchedule($input) {
    ensureScheduleFunctions();
    if (!isset($input['key'])) {
        throw new Exception("Missing parameters. Required: key");
    }
    $key = (string) $input['key'];
    $filePath = getDataFilePath('schedule', []);
    if ($filePath === null) {
        throw new Exception("Invalid type: schedule");
    }
    $schedule = readDataFile($filePath);
    if ($schedule === null) {
        return ['success' => false, 'error' => 'Schedule file not found'];
    }
    $schedule = normalizeScheduleMap(normalizeScheduleKeys($schedule), 'DELETE schedule');
    if (!isset($schedule[$key])) {
        return ['success' => false, 'error' => 'Schedule entry not found'];
    }
    unset($schedule[$key]);
    if (!writeDataFileAtomic($filePath, $schedule)) {
        throw new Exception(buildWriteFailureMessage('DELETE schedule', $filePath));
    }
    return [
        'success' => true,
        'message' => 'Schedule entry deleted successfully',
        'file' => basename($filePath)
    ];
}

// --- Main dispatcher ---

$method = $_SERVER['REQUEST_METHOD'];
$type = isset($_GET['type']) ? $_GET['type'] : null;
$response = ['success' => false];

try {
    if ($type === null) {
        throw new Exception("Missing 'type' parameter");
    }
    if (!in_array($type, ALLOWED_TYPES)) {
        throw new Exception("Invalid type: $type. Allowed types: " . implode(', ', ALLOWED_TYPES));
    }

    if ($method === 'GET') {
        if ($type === 'list') {
            $response = handleGetList();
        } elseif ($type === 'price') {
            $response = handleGetPrice();
        } else {
            $response = handleGetData($type);
        }
    } elseif ($method === 'POST') {
        $input = readJsonBody();
        if ($type === 'price') {
            $response = handlePostPrice($input);
        } elseif ($type === 'file') {
            $response = handlePostFile($input);
        } elseif ($type === 'schedule') {
            $response = handlePostSchedule($input);
        } else {
            $response = handlePostGeneric($type, $input);
        }
    } elseif ($method === 'PUT') {
        if ($type !== 'schedule') {
            throw new Exception("PUT method only supported for schedule type");
        }
        $response = handlePutSchedule(readJsonBody());
    } elseif ($method === 'DELETE') {
        if ($type === 'price') {
            $response = handleDeletePrice();
        } elseif ($type === 'schedule') {
            $response = handleDeleteSchedule(readJsonBody());
        } else {
            throw new Exception("DELETE method only supported for price and schedule types");
        }
    } else {
        throw new Exception("Method not allowed. Use GET, POST, PUT, or DELETE");
    }
} catch (Exception $e) {
    error_log("Data API Error: " . $e->getMessage());
    $response = ['success' => false, 'error' => $e->getMessage()];
}

echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
