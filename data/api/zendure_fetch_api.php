<?php
// data/api/zendure_fetch_api.php

date_default_timezone_set('Europe/Amsterdam');

header('Content-Type: application/json');

// Configuration paths
define('CONFIG_FILE', __DIR__ . '/../../status/config/config.json');
define('DATA_DIR', __DIR__ . '/..');

// --- Helper Functions ---

/**
 * Load configuration from JSON file
 * 
 * @return array Configuration array
 * @throws Exception If config file cannot be loaded or parsed
 */
function loadConfig() {
    if (!file_exists(CONFIG_FILE)) {
        throw new Exception("Configuration file not found: " . CONFIG_FILE);
    }
    
    $jsonContent = file_get_contents(CONFIG_FILE);
    if ($jsonContent === false) {
        throw new Exception("Failed to read configuration file: " . CONFIG_FILE);
    }
    
    $config = json_decode($jsonContent, true);
    if ($config === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON in configuration file: " . json_last_error_msg());
    }
    
    return $config;
}

/**
 * Resolve data directory path from config
 * Handles relative paths like "../data/" by resolving from Energy root
 * 
 * @param string $dataDirPath Path from config
 * @return string Resolved path
 */
function resolveDataDir($dataDirPath) {
    if (strpos($dataDirPath, '../') === 0) {
        // Relative path - resolve from Energy root
        // __DIR__ is data/api/, dirname(dirname(__DIR__)) is Energy/
        $baseDir = dirname(dirname(__DIR__));
        $dataDir = $baseDir . '/' . trim(str_replace('../', '', $dataDirPath), '/');
        return $dataDir;
    } else {
        // Absolute or relative to current directory
        return $dataDirPath;
    }
}

/**
 * Write JSON data atomically to avoid concurrency issues
 * 
 * @param string $filePath Full path to the target file
 * @param array $data Data to write as JSON
 * @throws Exception If writing fails
 */
function writeJsonAtomic($filePath, $data) {
    $dataDir = dirname($filePath);
    
    // Ensure data directory exists
    if (!is_dir($dataDir)) {
        if (!mkdir($dataDir, 0755, true)) {
            throw new Exception("Failed to create data directory: {$dataDir}");
        }
    }
    
    // Check if directory is writable
    if (!is_writable($dataDir)) {
        throw new Exception("Data directory is not writable: {$dataDir}");
    }
    
    // Write to temporary file first
    $tempFile = $filePath . '.tmp';
    $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    if ($jsonContent === false) {
        throw new Exception("Failed to encode JSON data");
    }
    
    try {
        if (file_put_contents($tempFile, $jsonContent) === false) {
            throw new Exception("Failed to write temporary file: {$tempFile}");
        }
        
        // Atomically replace the target file with the temp file
        if (!rename($tempFile, $filePath)) {
            throw new Exception("Failed to rename temporary file to target file");
        }
    } catch (Exception $e) {
        // Clean up temp file if something goes wrong
        if (file_exists($tempFile)) {
            @unlink($tempFile);
        }
        throw $e;
    }
}

/**
 * Fetch status from the Zendure device
 * 
 * @param string $deviceIp IP address of the Zendure device
 * @return array Fetched data array
 * @throws Exception If fetch fails
 */
function fetchZendureData($deviceIp) {
    $url = "http://{$deviceIp}/properties/report";
    
    // Use cURL for HTTP request
    $ch = curl_init($url);
    if ($ch === false) {
        throw new Exception("Failed to initialize cURL");
    }
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($response === false || !empty($curlError)) {
        throw new Exception("cURL error: {$curlError}");
    }
    
    if ($httpCode !== 200) {
        throw new Exception("HTTP error: {$httpCode}");
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("JSON decode error: " . json_last_error_msg());
    }
    
    // Extract properties and pack data
    $props = $data['properties'] ?? [];
    $packs = $data['packData'] ?? [];
    
    // Prepare reading data with timestamp
    $readingData = [
        'timestamp' => date('c'), // ISO 8601 format
        'properties' => $props,
        'packData' => $packs
    ];
    
    return $readingData;
}

// --- Request Handling ---

$method = $_SERVER['REQUEST_METHOD'];
$response = ['success' => false];

try {
    // Load configuration
    $config = loadConfig();
    
    // Resolve data directory path
    $dataDirPath = $config['dataDir'] ?? '../data/';
    $dataDir = resolveDataDir($dataDirPath);
    $dataFile = rtrim($dataDir, '/') . '/zendure_data.json';
    
    if ($method === 'GET' || $method === 'POST') {
        // Get IP address from parameter or use config default
        $deviceIp = null;
        
        if ($method === 'GET') {
            $deviceIp = isset($_GET['ip']) ? $_GET['ip'] : null;
        } elseif ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $deviceIp = isset($input['ip']) ? $input['ip'] : (isset($_GET['ip']) ? $_GET['ip'] : null);
        }
        
        // Use config default if no IP provided
        if (empty($deviceIp)) {
            $deviceIp = $config['deviceIp'] ?? null;
        }
        
        if (empty($deviceIp)) {
            throw new Exception("No device IP address specified. Please provide 'ip' parameter or set 'deviceIp' in configuration.");
        }
        
        // Validate IP address format (basic validation)
        if (!filter_var($deviceIp, FILTER_VALIDATE_IP)) {
            throw new Exception("Invalid IP address format: {$deviceIp}");
        }
        
        // Fetch data from Zendure device
        $readingData = fetchZendureData($deviceIp);
        
        // Save to JSON file atomically
        writeJsonAtomic($dataFile, $readingData);
        
        // Get file timestamp
        $fileTimestamp = file_exists($dataFile) ? filemtime($dataFile) : time();
        
        $response = [
            'success' => true,
            'message' => 'Zendure data fetched and saved successfully',
            'deviceIp' => $deviceIp,
            'data' => $readingData,
            'file' => basename($dataFile),
            'filePath' => $dataFile,
            'timestamp' => $fileTimestamp,
            'timestampIso' => date('c', $fileTimestamp)
        ];
        
    } else {
        throw new Exception("Method not allowed. Use GET or POST");
    }
    
} catch (Exception $e) {
    error_log("Zendure Fetch API Error: " . $e->getMessage());
    http_response_code(400);
    $response = [
        'success' => false,
        'error' => $e->getMessage()
    ];
}

echo json_encode($response);
?>

