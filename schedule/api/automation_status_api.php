<?php
// schedule/api/automation_status_api.php

header('Content-Type: application/json');

// Configuration
$dataFile = __DIR__ . '/../../data/automation_status.json';
$retentionDays = 3;
$retentionSeconds = $retentionDays * 24 * 60 * 60;

// --- Helper Functions ---

function loadStatusData($dataFile) {
    if (!file_exists($dataFile)) {
        return ['entries' => [], 'lastUpdate' => null];
    }
    $json = file_get_contents($dataFile);
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['entries'])) {
        return ['entries' => [], 'lastUpdate' => null];
    }
    return $data;
}

function writeStatusData($dataFile, $data) {
    // Ensure the directory exists
    $dir = dirname($dataFile);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            error_log("Failed to create directory: $dir");
            return false;
        }
    }
    
    // Check if directory is writable
    if (!is_writable($dir)) {
        error_log("Directory is not writable: $dir");
        return false;
    }
    
    $tempFile = $dataFile . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT);
    
    if ($json === false) {
        error_log("Failed to encode JSON data");
        return false;
    }
    
    if (file_put_contents($tempFile, $json) === false) {
        error_log("Failed to write temp file: $tempFile");
        return false;
    }
    
    if (!rename($tempFile, $dataFile)) {
        error_log("Failed to rename temp file to: $dataFile");
        // Clean up temp file if rename failed
        @unlink($tempFile);
        return false;
    }
    
    return true;
}

function cleanupOldEntries($entries, $retentionSeconds) {
    $now = time();
    return array_filter($entries, function($entry) use ($now, $retentionSeconds) {
        $entryTime = isset($entry['timestamp']) ? (int)$entry['timestamp'] : 0;
        return ($now - $entryTime) <= $retentionSeconds;
    });
}

function calculateRunningTime($entries) {
    if (empty($entries)) {
        return 0;
    }
    
    // Find most recent 'start' event
    $startTime = null;
    $startIndex = -1;
    
    for ($i = count($entries) - 1; $i >= 0; $i--) {
        if (isset($entries[$i]['type']) && $entries[$i]['type'] === 'start') {
            $startTime = isset($entries[$i]['timestamp']) ? (int)$entries[$i]['timestamp'] : null;
            $startIndex = $i;
            break;
        }
    }
    
    if ($startTime === null) {
        return 0;
    }
    
    // Check if there's a 'stop' event after the start
    $stopTime = null;
    for ($i = $startIndex + 1; $i < count($entries); $i++) {
        if (isset($entries[$i]['type']) && $entries[$i]['type'] === 'stop') {
            $stopTime = isset($entries[$i]['timestamp']) ? (int)$entries[$i]['timestamp'] : null;
            break;
        }
    }
    
    if ($stopTime !== null) {
        // Running time from start to stop
        return $stopTime - $startTime;
    } else {
        // Still running: current time - start time
        return time() - $startTime;
    }
}

// --- Request Handling ---

$method = $_SERVER['REQUEST_METHOD'];
$response = ['success' => false];

try {
    if ($method === 'GET') {
        // Get limit parameter (default 1, max 1000)
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 1;
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 1000) {
            $limit = 1000;
        }
        
        $data = loadStatusData($dataFile);
        $entries = $data['entries'];
        
        // Filter to only 'change' events and get last N
        $changeEvents = array_filter($entries, function($entry) {
            return isset($entry['type']) && $entry['type'] === 'change';
        });
        
        // Sort by timestamp descending (most recent first)
        usort($changeEvents, function($a, $b) {
            $timeA = isset($a['timestamp']) ? (int)$a['timestamp'] : 0;
            $timeB = isset($b['timestamp']) ? (int)$b['timestamp'] : 0;
            return $timeB - $timeA;
        });
        
        // Get last N changes
        $lastChanges = array_slice($changeEvents, 0, $limit);
        
        // Get last alive timestamp (most recent entry of any type)
        $lastAlive = null;
        if (!empty($entries)) {
            $mostRecent = end($entries);
            $lastAlive = isset($mostRecent['timestamp']) ? (int)$mostRecent['timestamp'] : null;
        }
        
        // Calculate running time
        $runningTime = calculateRunningTime($entries);
        
        $response = [
            'success' => true,
            'lastChanges' => array_values($lastChanges),
            'lastAlive' => $lastAlive,
            'runningTime' => $runningTime,
            'entryCount' => count($entries),
            'lastUpdate' => $data['lastUpdate']
        ];
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['type'])) {
            throw new Exception("Missing 'type' field");
        }
        
        $validTypes = ['start', 'stop', 'change', 'heartbeat'];
        if (!in_array($input['type'], $validTypes)) {
            throw new Exception("Invalid type. Must be one of: " . implode(', ', $validTypes));
        }
        
        $entry = [
            'timestamp' => isset($input['timestamp']) ? (int)$input['timestamp'] : time(),
            'type' => $input['type'],
            'oldValue' => isset($input['oldValue']) ? $input['oldValue'] : null,
            'newValue' => isset($input['newValue']) ? $input['newValue'] : null
        ];
        
        $data = loadStatusData($dataFile);
        $entriesBefore = count($data['entries']);
        $data['entries'][] = $entry;
        $entriesAfterAdd = count($data['entries']);
        
        // Cleanup old entries (keep last 3 days)
        $data['entries'] = array_values(cleanupOldEntries($data['entries'], $retentionSeconds));
        $entriesAfterCleanup = count($data['entries']);
        
        // Update last update timestamp
        $data['lastUpdate'] = time();
        
        if (writeStatusData($dataFile, $data)) {
            $response = [
                'success' => true,
                'entryCount' => count($data['entries']),
                'filePath' => $dataFile,
                'lastEntryType' => $entry['type'],
                'debug' => [
                    'entriesBefore' => $entriesBefore,
                    'entriesAfterAdd' => $entriesAfterAdd,
                    'entriesAfterCleanup' => $entriesAfterCleanup,
                    'entryTimestamp' => $entry['timestamp'],
                    'currentTime' => time()
                ]
            ];
        } else {
            $errorDetails = "Failed to write status file: $dataFile";
            if (!is_dir(dirname($dataFile))) {
                $errorDetails .= " (Directory does not exist)";
            } elseif (!is_writable(dirname($dataFile))) {
                $errorDetails .= " (Directory is not writable)";
            } elseif (file_exists($dataFile) && !is_writable($dataFile)) {
                $errorDetails .= " (File exists but is not writable)";
            }
            error_log($errorDetails);
            throw new Exception($errorDetails);
        }
    } else {
        throw new Exception("Method not allowed. Use GET or POST");
    }
} catch (Exception $e) {
    $response = ['success' => false, 'error' => $e->getMessage()];
}

echo json_encode($response);

