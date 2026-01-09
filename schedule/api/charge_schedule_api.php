<?php
// schedule/api/charge_schedule_api.php

// Ensure server timezone matches local expectation
date_default_timezone_set('Europe/Amsterdam');

require_once __DIR__ . '/charge_schedule_functions.php';

header('Content-Type: application/json');

// Configuration
$dataFile = __DIR__ . '/../../data/charge_schedule.json';

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

        $resolved = resolveScheduleForDate($schedule, $date);

        $response = [
            'success' => true,
            'entries' => $uiEntries,
            'resolved' => $resolved,
            'date' => $date,
            'currentHour' => date('H') . '00',
            'currentTime' => date('Hi') // Current time in HHmm format (e.g., "0930")
        ];
    } elseif ($method === 'PUT' || $method === 'POST') {
        // PUT handles both add and edit operations
        // POST is a wrapper that redirects to PUT logic for backward compatibility
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        if (!isset($input['key']) || !isset($input['value'])) {
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
        if ($val !== 'netzero' && $val !== 'netzero+' && !is_numeric($val)) {
            throw new Exception("Invalid value");
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

        if (writeScheduleAtomic($dataFile, $schedule)) {
            $response = ['success' => true];
        } else {
            throw new Exception("Failed to write file");
        }
    } elseif ($method === 'DELETE') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['key']))
            throw new Exception("Missing key");

        $key = (string) $input['key'];
        unset($schedule[$key]);

        if (writeScheduleAtomic($dataFile, $schedule)) {
            $response = ['success' => true];
        } else {
            throw new Exception("Failed to write file");
        }
    }
} catch (Exception $e) {
    $response = ['success' => false, 'error' => $e->getMessage()];
}

echo json_encode($response);
