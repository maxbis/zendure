<?php
// data/api/data_api.php

date_default_timezone_set('Europe/Amsterdam');

require_once __DIR__ . '/data_functions.php';

header('Content-Type: application/json');

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
                $response = [
                    'success' => true,
                    'data' => $data,
                    'file' => basename($filePath),
                    'timestamp' => file_exists($filePath) ? filemtime($filePath) : null
                ];
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
        } else {
            // Handle other types (zendure, zendure_p1, schedule, automation_status)
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
                throw new Exception("Failed to write file: " . basename($filePath));
            }
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
        } else {
            throw new Exception("DELETE method only supported for price type");
        }
    }
    else {
        throw new Exception("Method not allowed. Use GET, POST, or DELETE");
    }
} catch (Exception $e) {
    error_log("Data API Error: " . $e->getMessage());
    $response = ['success' => false, 'error' => $e->getMessage()];
}

echo json_encode($response);

