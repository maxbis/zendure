<?php
// data/api/data_api.php

date_default_timezone_set('Europe/Amsterdam');

require_once __DIR__ . '/data_functions.php';


header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Configuration
define('DATA_DIR', __DIR__ . '/..');
define('ALLOWED_TYPES', ['price', 'zendure', 'zendure_p1', 'schedule', 'automation_status', 'file', 'list']);
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('PRICE_RETENTION_DAYS', 4); // Days to keep price files before archiving
define('PRICE_ARCHIVE_DIR', DATA_DIR . '/price_archive');

// --- Request Handling ---

$method = $_SERVER['REQUEST_METHOD'];
$type = isset($_GET['type']) ? $_GET['type'] : null;
$response = ['success' => false];

try {
    // Validate type
    if ($type === null) {
        throw new Exception("Missing 'type' parameter");
    }
    
    if (!in_array($type, ALLOWED_TYPES)) {
        throw new Exception("Invalid type: $type. Allowed types: " . implode(', ', ALLOWED_TYPES));
    }
    
    // Handle GET requests
    if ($method === 'GET') {
        if ($type === 'list') {
            // List all JSON files
            $pattern = isset($_GET['pattern']) ? $_GET['pattern'] : null;
            $files = listDataFiles($pattern);
            $response = [
                'success' => true,
                'files' => $files,
                'count' => count($files)
            ];
        } elseif ($type === 'price') {
            // Handle price file operations
            if (isset($_GET['list']) && $_GET['list'] === 'true') {
                // List all price files
                $files = listDataFiles('price*.json');
                $response = [
                    'success' => true,
                    'files' => $files,
                    'count' => count($files)
                ];
            } elseif (isset($_GET['date'])) {
                // Read specific price file
                $params = ['date' => $_GET['date']];
                $filePath = getDataFilePath('price', $params);
                
                if ($filePath === null) {
                    throw new Exception("Invalid date parameter. Expected format: YYYYMMDD");
                }
                
                $data = readDataFile($filePath);
                if ($data === null) {
                    $response = [
                        'success' => false,
                        'error' => 'File not found',
                        'file' => basename($filePath)
                    ];
                } else {
                    $response = [
                        'success' => true,
                        'data' => $data,
                        'file' => basename($filePath),
                        'timestamp' => file_exists($filePath) ? filemtime($filePath) : null
                    ];
                }
            } else {
                throw new Exception("Missing 'date' parameter for price type");
            }
        } else {
            // Handle other types (zendure, zendure_p1, schedule, automation_status, file)
            $params = [];
            
            if ($type === 'file') {
                if (!isset($_GET['name'])) {
                    throw new Exception("Missing 'name' parameter for file type");
                }
                $params['name'] = $_GET['name'];
            }
            
            $filePath = getDataFilePath($type, $params);
            
            if ($filePath === null) {
                throw new Exception("Invalid parameters for type: $type");
            }
            
            $data = readDataFile($filePath);
            if ($data === null) {
                $response = [
                    'success' => false,
                    'error' => 'File not found',
                    'file' => basename($filePath)
                ];
            } else {
                // Check if resolved format is requested for schedule type
                if ($type === 'schedule' && (isset($_GET['resolved']) || isset($_GET['format']) && $_GET['format'] === 'resolved')) {
                    // Require schedule resolution functions
                    $scheduleFunctionsPath = __DIR__ . '/../../schedule/api/charge_schedule_functions.php';
                    if (!file_exists($scheduleFunctionsPath)) {
                        throw new Exception("Schedule functions file not found: $scheduleFunctionsPath");
                    }
                    require_once $scheduleFunctionsPath;
                    
                    // Normalize schedule data (like loadSchedule does)
                    $schedule = [];
                    if (is_array($data)) {
                        foreach ($data as $k => $v) {
                            $schedule[(string) $k] = $v;
                        }
                    }
                    
                    // Get date parameter (default: today)
                    $date = isset($_GET['date']) ? $_GET['date'] : date('Ymd');
                    if (!preg_match('/^\d{8}$/', $date)) {
                        $date = date('Ymd');
                    }
                    
                    // Resolve schedule for the date
                    $resolved = resolveScheduleForDate($schedule, $date);
                
                    // PHP's json_encode() converts numeric-looking strings like "1000" to integers
                    // but preserves "0900" (with leading zero) as a string
                    // For consistency, we don't modify here - the frontend should handle both types
                    // Or alternatively, we could prefix/suffix the time value
                    
                    // Format UI entries (sorted key-value pairs)
                    $uiEntries = [];
                    foreach ($schedule as $k => $v) {
                        $uiEntries[] = ['key' => (string) $k, 'value' => $v];
                    }
                    usort($uiEntries, function ($a, $b) {
                        return strcmp($a['key'], $b['key']);
                    });
                    
                    // Return response in old endpoint format
                    $response = [
                        'success' => true,
                        'resolved' => $resolved,
                        'currentHour' => date('H') . '00',
                        'currentTime' => date('Hi'),
                        'entries' => $uiEntries,
                        'date' => $date
                    ];
                } else {
                    // Standard response format
                    $response = [
                        'success' => true,
                        'data' => $data,
                        'file' => basename($filePath),
                        'timestamp' => file_exists($filePath) ? filemtime($filePath) : null
                    ];
                }
            }
        }
    }
    // Handle POST requests
    elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON in request body: " . json_last_error_msg());
        }
        
        if ($type === 'price') {
            if (!isset($_GET['date'])) {
                throw new Exception("Missing 'date' parameter for price type");
            }
            
            // Validate price data
            $validation = validatePriceData($input);
            if (!$validation['valid']) {
                throw new Exception("Invalid price data: " . $validation['error']);
            }
            
            $params = ['date' => $_GET['date']];
            $filePath = getDataFilePath('price', $params);
            
            if ($filePath === null) {
                throw new Exception("Invalid date parameter. Expected format: YYYYMMDD");
            }
            
            if (writeDataFileAtomic($filePath, $input)) {
                // Run cleanup after successful price file write
                $cleanupStats = cleanupOldPriceFiles(PRICE_RETENTION_DAYS, DATA_DIR, PRICE_ARCHIVE_DIR);
                
                $response = [
                    'success' => true,
                    'message' => 'File saved successfully',
                    'file' => basename($filePath),
                    'cleanup' => [
                        'moved' => $cleanupStats['moved'],
                        'skipped' => $cleanupStats['skipped'],
                        'errors' => count($cleanupStats['errors'])
                    ]
                ];
                
                // Log cleanup errors if any
                if (!empty($cleanupStats['errors'])) {
                    error_log("Price cleanup errors: " . implode('; ', $cleanupStats['errors']));
                }
            } else {
                throw new Exception("Failed to write file: " . basename($filePath));
            }
        } elseif ($type === 'file') {
            if (!isset($_GET['name'])) {
                throw new Exception("Missing 'name' parameter for file type");
            }
            
            $params = ['name' => $_GET['name']];
            $filePath = getDataFilePath('file', $params);
            
            if ($filePath === null) {
                throw new Exception("Invalid filename parameter");
            }
            
            if (writeDataFileAtomic($filePath, $input)) {
                $response = [
                    'success' => true,
                    'message' => 'File saved successfully',
                    'file' => basename($filePath)
                ];
            } else {
                throw new Exception("Failed to write file: " . basename($filePath));
            }
        } elseif ($type === 'schedule') {
            // Special handling for schedule type
            // Support multiple formats:
            // 1. Clear action: {"action": "simulate"} or {"action": "delete"}
            // 2. Single entry: {"key": "202512220000", "value": 22} - add to existing schedule
            // 3. Full schedule: {"202512220000": 22, "202512220100": 33} - replace entire schedule
            if (!is_array($input)) {
                throw new Exception("Schedule data must be an array");
            }
            
            $params = [];
            $filePath = getDataFilePath($type, $params);
            
            if ($filePath === null) {
                throw new Exception("Invalid type: schedule");
            }
            
            // Check if this is a clear action
            if (isset($input['action']) && ($input['action'] === 'simulate' || $input['action'] === 'delete')) {
                // Require schedule functions for clearOldEntries
                $scheduleFunctionsPath = __DIR__ . '/../../schedule/api/charge_schedule_functions.php';
                if (!file_exists($scheduleFunctionsPath)) {
                    throw new Exception("Schedule functions file not found: $scheduleFunctionsPath");
                }
                require_once $scheduleFunctionsPath;
                
                // Read current schedule
                $schedule = readDataFile($filePath);
                if ($schedule === null) {
                    $schedule = [];
                }
                
                // Normalize schedule keys to strings
                $normalizedSchedule = [];
                foreach ($schedule as $k => $v) {
                    $normalizedSchedule[(string) $k] = $v;
                }
                $schedule = $normalizedSchedule;
                
                $action = $input['action'];
                $simulate = ($action === 'simulate');
                
                // Get the keys to delete
                $result = clearOldEntries($schedule, $simulate);
                
                if ($simulate) {
                    // Return count and list of keys that would be deleted
                    $response = [
                        'success' => true,
                        'count' => $result['count'],
                        'entries' => $result['entries']
                    ];
                } else {
                    // Actually delete the entries
                    foreach ($result['entries'] as $key) {
                        if (isset($schedule[$key])) {
                            unset($schedule[$key]);
                        }
                    }
                    
                    if (writeDataFileAtomic($filePath, $schedule)) {
                        $response = [
                            'success' => true,
                            'count' => $result['count']
                        ];
                    } else {
                        throw new Exception("Failed to write file");
                    }
                }
            }
            // Check if this is a single entry object (format: {"key": "...", "value": ...})
            // POST with single entry redirects to PUT logic for backward compatibility
            elseif (count($input) === 2 && isset($input['key']) && isset($input['value'])) {
                // Single entry format - use PUT logic (originalKey is optional)
                $key = (string) $input['key'];
                $val = $input['value'];
                $orig = isset($input['originalKey']) ? (string) $input['originalKey'] : null;
                
                // Validate key length
                if (strlen($key) !== 12) {
                    throw new Exception("Key must be 12 characters (YYYYMMDDHHmm format)");
                }
                
                // Validate value
                if ($val !== 'netzero' && $val !== 'netzero+' && !is_numeric($val)) {
                    throw new Exception("Invalid value. Must be 'netzero', 'netzero+', or a number");
                }
                
                // Convert numeric value to int
                if (is_numeric($val)) {
                    $val = (int) $val;
                }
                
                // Read current schedule
                $schedule = readDataFile($filePath);
                if ($schedule === null) {
                    // File doesn't exist, create empty schedule
                    $schedule = [];
                }
                
                // Normalize schedule keys to strings
                $normalizedSchedule = [];
                foreach ($schedule as $k => $v) {
                    $normalizedSchedule[(string) $k] = $v;
                }
                $schedule = $normalizedSchedule;
                
                // If originalKey is provided and different from new key, remove the old entry
                if ($orig !== null && $orig !== $key) {
                    unset($schedule[$orig]);
                }
                
                // Add or update the entry
                $schedule[$key] = $val;
                
                // Write updated schedule
                if (writeDataFileAtomic($filePath, $schedule)) {
                    $response = [
                        'success' => true,
                        'message' => 'Schedule entry saved successfully',
                        'file' => basename($filePath)
                    ];
                } else {
                    throw new Exception("Failed to write file: " . basename($filePath));
                }
            } else {
                // Full schedule format - validate and replace entire schedule
                // Validate that keys look like schedule keys (12 characters: YYYYMMDDHHmm)
                // Allow wildcards (*) in keys
                foreach ($input as $key => $value) {
                    $keyStr = (string) $key;
                    // Schedule keys should be 12 characters (YYYYMMDDHHmm) or contain wildcards
                    if (strlen($keyStr) !== 12 && !preg_match('/^[\d*]{12}$/', $keyStr)) {
                        throw new Exception("Invalid schedule key format: '$keyStr'. Keys must be 12 characters (YYYYMMDDHHmm format) or contain wildcards.");
                    }
                    
                    // Validate value
                    if ($value !== 'netzero' && $value !== 'netzero+' && !is_numeric($value)) {
                        throw new Exception("Invalid schedule value for key '$keyStr'. Value must be 'netzero', 'netzero+', or a number.");
                    }
                }
                
                // Write the full schedule
                if (writeDataFileAtomic($filePath, $input)) {
                    $response = [
                        'success' => true,
                        'message' => 'Schedule saved successfully',
                        'file' => basename($filePath)
                    ];
                } else {
                    throw new Exception("Failed to write file: " . basename($filePath));
                }
            }
        } else {
            // Handle other types (zendure, zendure_p1, automation_status)
            $params = [];
            $filePath = getDataFilePath($type, $params);
            
            if ($filePath === null) {
                throw new Exception("Invalid type: $type");
            }
            
            if (writeDataFileAtomic($filePath, $input)) {
                $response = [
                    'success' => true,
                    'message' => 'File saved successfully',
                    'file' => basename($filePath)
                ];
            } else {
                $error = "Failed to write file: " . basename($filePath);
                throw new Exception($error);
            }
        }
    }
    // Handle PUT requests
    elseif ($method === 'PUT') {
        if ($type === 'schedule') {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON in request body: " . json_last_error_msg());
            }
            
            // Validate required fields
            if (!isset($input['key']) || !isset($input['value'])) {
                throw new Exception("Missing parameters. Required: key, value");
            }
            
            // originalKey is optional - only needed when editing and changing the key
            $orig = isset($input['originalKey']) ? (string) $input['originalKey'] : null;
            $key = (string) $input['key'];
            $val = $input['value'];
            
            // Validate key length
            if (strlen($key) !== 12) {
                throw new Exception("Key must be 12 characters");
            }
            
            // Validate value
            if ($val !== 'netzero' && $val !== 'netzero+' && !is_numeric($val)) {
                throw new Exception("Invalid value. Must be 'netzero', 'netzero+', or a number");
            }
            
            // Convert numeric value to int
            if (is_numeric($val)) {
                $val = (int) $val;
            }
            
            // Get file path
            $params = [];
            $filePath = getDataFilePath('schedule', $params);
            
            if ($filePath === null) {
                throw new Exception("Invalid type: schedule");
            }
            
            // Read current schedule
            $schedule = readDataFile($filePath);
            if ($schedule === null) {
                // File doesn't exist, create empty schedule
                $schedule = [];
            }
            
            // Normalize schedule keys to strings (like loadSchedule does)
            $normalizedSchedule = [];
            foreach ($schedule as $k => $v) {
                $normalizedSchedule[(string) $k] = $v;
            }
            $schedule = $normalizedSchedule;
            
            // If originalKey is provided and different from new key, remove the old entry
            if ($orig !== null && $orig !== $key) {
                unset($schedule[$orig]);
            }
            
            // Set new value (add or update)
            $schedule[$key] = $val;
            
            // Write updated schedule
            if (writeDataFileAtomic($filePath, $schedule)) {
                $response = [
                    'success' => true,
                    'message' => 'Schedule entry saved successfully',
                    'file' => basename($filePath)
                ];
            } else {
                throw new Exception("Failed to write file: " . basename($filePath));
            }
        } else {
            throw new Exception("PUT method only supported for schedule type");
        }
    }
    // Handle DELETE requests
    elseif ($method === 'DELETE') {
        if ($type === 'price') {
            if (!isset($_GET['date'])) {
                throw new Exception("Missing 'date' parameter for price type");
            }
            
            $params = ['date' => $_GET['date']];
            $filePath = getDataFilePath('price', $params);
            
            if ($filePath === null) {
                throw new Exception("Invalid date parameter. Expected format: YYYYMMDD");
            }
            
            if (!file_exists($filePath)) {
                $response = [
                    'success' => false,
                    'error' => 'File not found',
                    'file' => basename($filePath)
                ];
            } elseif (unlink($filePath)) {
                $response = [
                    'success' => true,
                    'message' => 'File deleted successfully',
                    'file' => basename($filePath)
                ];
            } else {
                throw new Exception("Failed to delete file: " . basename($filePath));
            }
        } elseif ($type === 'schedule') {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON in request body: " . json_last_error_msg());
            }
            
            if (!isset($input['key'])) {
                throw new Exception("Missing parameters. Required: key");
            }
            
            $key = (string) $input['key'];
            
            // Get file path
            $params = [];
            $filePath = getDataFilePath('schedule', $params);
            
            if ($filePath === null) {
                throw new Exception("Invalid type: schedule");
            }
            
            // Read current schedule
            $schedule = readDataFile($filePath);
            if ($schedule === null) {
                // File doesn't exist, return error
                $response = [
                    'success' => false,
                    'error' => 'Schedule file not found'
                ];
            } else {
                // Normalize schedule keys to strings (like PUT handler does)
                $normalizedSchedule = [];
                foreach ($schedule as $k => $v) {
                    $normalizedSchedule[(string) $k] = $v;
                }
                $schedule = $normalizedSchedule;
                
                // Check if key exists
                if (!isset($schedule[$key])) {
                    $response = [
                        'success' => false,
                        'error' => 'Schedule entry not found'
                    ];
                } else {
                    // Remove the entry
                    unset($schedule[$key]);
                    
                    // Write updated schedule
                    if (writeDataFileAtomic($filePath, $schedule)) {
                        $response = [
                            'success' => true,
                            'message' => 'Schedule entry deleted successfully',
                            'file' => basename($filePath)
                        ];
                    } else {
                        throw new Exception("Failed to write file: " . basename($filePath));
                    }
                }
            }
        } else {
            throw new Exception("DELETE method only supported for price and schedule types");
        }
    }
    else {
        throw new Exception("Method not allowed. Use GET, POST, PUT, or DELETE");
    }
} catch (Exception $e) {
    error_log("Data API Error: " . $e->getMessage());
    $response = ['success' => false, 'error' => $e->getMessage()];
}

echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

