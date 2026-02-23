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

define('include_conditions', true);
define('SCHEDULE_FUNCTIONS_PATH', __DIR__ . '/../../api/charge_schedule_functions.php');
define('CONDITIONAL_SCHEDULE_RESOLVER_PATH', __DIR__ . '/../resolve_schedule_conditions.php');

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
        $byTime[$time] = $item['value'];
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
            $slot['value'] = $byTime[$slotTime];
            $slot['source'] = 'condition';
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
    $wantResolved = $type === 'schedule' && (isset($_GET['resolved']) || (isset($_GET['format']) && $_GET['format'] === 'resolved'));
    if ($wantResolved) {
        ensureScheduleFunctions();
        $schedule = normalizeScheduleKeys($data);
        $date = isset($_GET['date']) ? $_GET['date'] : date('Ymd');
        if (!preg_match('/^\d{8}$/', $date)) {
            $date = date('Ymd');
        }
        $resolved = resolveScheduleForDate($schedule, $date);
        if (include_conditions) {
            $resolved = mergeResolvedWithConditional($resolved, $date);
        }
        $uiEntries = [];
        foreach ($schedule as $k => $v) {
            $uiEntries[] = ['key' => (string) $k, 'value' => $v];
        }
        usort($uiEntries, function ($a, $b) {
            return strcmp($a['key'], $b['key']);
        });
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
        'data' => $data,
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
    if (strlen($keyStr) !== 12) {
        throw new Exception("Key must be 12 characters (YYYYMMDDHHmm format)" . ($context ? " [$context]" : ""));
    }
    if ($value !== 'netzero' && $value !== 'netzero+' && !is_numeric($value)) {
        throw new Exception("Invalid value. Must be 'netzero', 'netzero+', or a number");
    }
}

function applyScheduleEntryAndWrite($filePath, $key, $val, $orig, $input) {
    $schedule = readDataFile($filePath);
    $schedule = $schedule === null ? [] : normalizeScheduleKeys($schedule);
    if ($orig !== null && $orig !== $key) {
        unset($schedule[$orig]);
    }
    $limit1HourRestoreEntry = null;
    if (!empty($input['limit1hour']) && strpos($key, '*') === false && file_exists(SCHEDULE_FUNCTIONS_PATH)) {
        require_once SCHEDULE_FUNCTIONS_PATH;
        $limit1HourRestoreEntry = getLimit1HourRestoreEntry($schedule, $key);
    }
    $schedule[$key] = $val;
    if ($limit1HourRestoreEntry !== null) {
        $schedule[$limit1HourRestoreEntry['key']] = $limit1HourRestoreEntry['value'];
    }
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
    $filePath = getDataFilePath('schedule', []);
    if ($filePath === null) {
        throw new Exception("Invalid type: schedule");
    }
    if (isset($input['action']) && ($input['action'] === 'simulate' || $input['action'] === 'delete')) {
        ensureScheduleFunctions();
        $schedule = readDataFile($filePath);
        $schedule = $schedule === null ? [] : normalizeScheduleKeys($schedule);
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
    if (count($input) === 2 && isset($input['key']) && isset($input['value'])) {
        $key = (string) $input['key'];
        $val = $input['value'];
        $orig = isset($input['originalKey']) ? (string) $input['originalKey'] : null;
        validateScheduleKeyValue($key, $val, 'POST single-entry');
        if (is_numeric($val)) {
            $val = (int) $val;
        }
        $resp = applyScheduleEntryAndWrite($filePath, $key, $val, $orig, $input);
        if ($resp === null) {
            throw new Exception(buildWriteFailureMessage('POST schedule single-entry', $filePath));
        }
        return $resp;
    }
    foreach ($input as $key => $value) {
        $keyStr = (string) $key;
        if (strlen($keyStr) !== 12 && !preg_match('/^[\d*]{12}$/', $keyStr)) {
            throw new Exception("Invalid schedule key format: '$keyStr'. Keys must be 12 characters (YYYYMMDDHHmm format) or contain wildcards.");
        }
        if ($value !== 'netzero' && $value !== 'netzero+' && !is_numeric($value)) {
            throw new Exception("Invalid schedule value for key '$keyStr'. Value must be 'netzero', 'netzero+', or a number.");
        }
    }
    if (!writeDataFileAtomic($filePath, $input)) {
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
    if (!isset($input['key']) || !isset($input['value'])) {
        throw new Exception("Missing parameters. Required: key, value");
    }
    $orig = isset($input['originalKey']) ? (string) $input['originalKey'] : null;
    $key = (string) $input['key'];
    $val = $input['value'];
    validateScheduleKeyValue($key, $val, 'PUT');
    if (is_numeric($val)) {
        $val = (int) $val;
    }
    $filePath = getDataFilePath('schedule', []);
    if ($filePath === null) {
        throw new Exception("Invalid type: schedule");
    }
    $resp = applyScheduleEntryAndWrite($filePath, $key, $val, $orig, $input);
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
    $schedule = normalizeScheduleKeys($schedule);
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
