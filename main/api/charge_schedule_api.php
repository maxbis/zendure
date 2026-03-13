<?php
// schedule/api/charge_schedule_api.php

// Ensure server timezone matches local expectation
date_default_timezone_set('Europe/Amsterdam');

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

// Configuration
$dataFile = __DIR__ . '/../data/charge_schedule.json';

// --- Request Handling ---

$method = $_SERVER['REQUEST_METHOD'];
$schedule = loadSchedule($dataFile);
$response = ['success' => false];

try {
    if ($method === 'GET') {
        $date = isset($_GET['date']) ? $_GET['date'] : date('Ymd');
        if (!preg_match('/^\d{8}$/', $date)) {
            $date = date('Ymd');
        }

        // UI Entries (Sorted Key ASC)
        $uiEntries = [];
        foreach ($schedule as $k => $v) {
            $uiEntries[] = ['key' => (string) $k, 'value' => $v];
        }
        usort($uiEntries, function ($a, $b) {
            return strcmp($a['key'], $b['key']);
        });

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
            if (!is_array($input) || !isset($input['key']) || !isset($input['value'])) {
                throw new Exception("Missing key or value");
            }
            
            $key = (string) $input['key'];
            $val = $input['value'];
            
            // originalKey is optional - only needed when editing and changing the key
            $orig = isset($input['originalKey']) ? (string) $input['originalKey'] : null;

            // Validate key format
            if (strlen($key) !== 12) {
                throw new Exception("Key must be 12 characters");
            }
            
            // Validate value
            if ($val !== 'auto' && $val !== 'netzero' && $val !== 'netzero+' && !is_numeric($val)) {
                throw new Exception("Invalid value. Must be 'auto', 'netzero', 'netzero+', or a number");
            }
            
            // Convert numeric value to int
            if (is_numeric($val)) {
                $val = (int) $val;
            }

            // If originalKey is provided and different from new key, remove the old entry
            if ($orig !== null && $orig !== $key) {
                unset($schedule[$orig]);
            }
            
            // Set the new entry (or update existing one)
            $schedule[$key] = $val;

            // Automatically drop outdated concrete-date entries on save
            $schedule = cleanOutdatedScheduleEntries($schedule);

            if (writeScheduleAtomic($dataFile, $schedule)) {
                $response = ['success' => true];
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
