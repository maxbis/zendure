<?php
// schedule/api/charge_schedule_api.php

require_once dirname(__DIR__, 2) . '/common/php/system_config.php';
require_once __DIR__ . '/charge_schedule_functions.php';

function buildScheduleWriteFailureMessage($context, $filePath) {
    $details = function_exists('getLastWriteScheduleAtomicError')
        ? getLastWriteScheduleAtomicError()
        : null;
    $base = "Write failed [$context]: " . basename($filePath);
    return $details ? ($base . " (" . $details . ")") : $base;
}

// CORS headers to allow cross-origin requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle OPTIONS preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $chargeScheduleSystemConfig = loadSystemConfig();
    date_default_timezone_set($chargeScheduleSystemConfig['installation']['timezone']);
} catch (SystemConfigException $error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Shared system configuration: ' . $error->getMessage(),
    ]);
    exit();
}

// Configuration
$dataFile = __DIR__ . '/../data/charge_schedule.json';

// --- Request Handling ---

$method = $_SERVER['REQUEST_METHOD'];
$schedule = loadSchedule($dataFile);
$response = ['success' => false];

try {
    if ($method === 'GET') {
        $schedule = normalizeScheduleMap($schedule, 'charge_schedule_api GET');
        $date = isset($_GET['date']) ? $_GET['date'] : date('Ymd');
        if (!preg_match('/^\d{8}$/', $date)) {
            $date = date('Ymd');
        }

        $uiEntries = makeUiScheduleEntries($schedule);

        $resolved = resolveScheduleForDateWithConditions($schedule, $date);

        $response = [
            'success' => true,
            'entries' => $uiEntries,
            'resolved' => $resolved,
            'date' => $date,
            'currentHour' => date('H') . '00',
            'currentTime' => date('Hi') // Current time in HHmm format (e.g., "0930")
        ];
    } elseif ($method === 'PUT' || $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $schedule = normalizeScheduleMap($schedule, 'charge_schedule_api write');

            if (
                $method === 'POST' &&
                is_array($input) &&
                isset($input['action']) &&
                $input['action'] === 'clear_non_wildcard'
            ) {
                $beforeCount = count($schedule);
                $filteredSchedule = [];

                foreach ($schedule as $entryKey => $entryValue) {
                    if (strpos((string) $entryKey, '*') !== false) {
                        $filteredSchedule[$entryKey] = $entryValue;
                    }
                }

                $keptCount = count($filteredSchedule);
                $removedCount = $beforeCount - $keptCount;

                if (writeScheduleAtomic($dataFile, $filteredSchedule)) {
                    $response = [
                        'success' => true,
                        'removed' => $removedCount,
                        'kept' => $keptCount
                    ];
                } else {
                    throw new Exception(buildScheduleWriteFailureMessage('charge_schedule_api clear_non_wildcard', $dataFile));
                }
            } else {
            // PUT/POST handles both add and edit operations
            // Validate required fields
            if (is_array($input) && isset($input['key']) && isset($input['value']) && !isset($input['entry'])) {
                throw new Exception("Legacy schedule write format is no longer supported. Use { key, entry: { value } }.");
            }
            if (!is_array($input) || !isset($input['key']) || !isset($input['entry'])) {
                throw new Exception("Missing key or entry");
            }
            
            $key = (string) $input['key'];
            $entry = $input['entry'];
            
            // originalKey is optional - only needed when editing and changing the key
            $orig = isset($input['originalKey']) ? (string) $input['originalKey'] : null;

            [$key, $normalizedEntry] = normalizeScheduleWritePayload($key, $entry, 'charge_schedule_api write');

            // If originalKey is provided and different from new key, remove the old entry
            if ($orig !== null && $orig !== $key) {
                unset($schedule[$orig]);
            }
            
            // Set the new entry (or update existing one)
            $schedule[$key] = $normalizedEntry;

            $boundaryResult = addNextHourAutoBoundary($schedule, $key, $normalizedEntry);
            $schedule = $boundaryResult['schedule'];

            // Automatically drop outdated concrete-date entries on save
            $schedule = cleanOutdatedScheduleEntries($schedule);

            if (writeScheduleAtomic($dataFile, $schedule)) {
                $response = [
                    'success' => true,
                    'auto_boundary_added' => $boundaryResult['boundary_key']
                ];
            } else {
                throw new Exception(buildScheduleWriteFailureMessage('charge_schedule_api PUT/POST', $dataFile));
            }
            }
    } elseif ($method === 'DELETE') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['key']))
            throw new Exception("Missing key");

        $key = (string) $input['key'];
        unset($schedule[$key]);

        // Automatically drop outdated concrete-date entries on save
        $schedule = cleanOutdatedScheduleEntries($schedule);

        if (writeScheduleAtomic($dataFile, $schedule)) {
            $response = ['success' => true];
        } else {
            throw new Exception(buildScheduleWriteFailureMessage('charge_schedule_api DELETE', $dataFile));
        }
    }
} catch (Exception $e) {
    $response = ['success' => false, 'error' => $e->getMessage()];
}

echo json_encode($response);
