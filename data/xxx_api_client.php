<?php
/**
 * API Client Library for Zendure Data APIs
 * 
 * Provides functions to read data from various APIs:
 * - Charge Schedule API
 * - Automation Status API
 * 
 * Usage:
 *   require_once __DIR__ . '/api_client.php';
 *   $scheduleData = fetchChargeSchedule($apiBaseUrl, '20251225');
 *   $statusData = fetchAutomationStatus($apiBaseUrl, 'all', 10);
 */

/**
 * Make an HTTP GET request and return the JSON-decoded response
 * 
 * @param string $url The API endpoint URL
 * @param int $timeout Timeout in seconds (default: 10)
 * @return array|null Decoded JSON response or null on error
 */
function httpGetJson($url, $timeout = 10) {
    $ch = curl_init($url);
    
    if ($ch === false) {
        error_log("Failed to initialize cURL for URL: $url");
        return null;
    }
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    
    curl_close($ch);
    
    if ($response === false || !empty($curlError)) {
        error_log("cURL error fetching $url: $curlError");
        return null;
    }
    
    if ($httpCode !== 200) {
        error_log("HTTP error $httpCode when fetching $url");
        return null;
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decode error for $url: " . json_last_error_msg());
        return null;
    }
    
    return $data;
}

/**
 * Make an HTTP POST request with JSON data and return the JSON-decoded response
 * 
 * @param string $url The API endpoint URL
 * @param array $data Data to send as JSON in the request body
 * @param int $timeout Timeout in seconds (default: 10)
 * @return array|null Decoded JSON response or null on error
 */
function httpPostJson($url, $data, $timeout = 10) {
    $ch = curl_init($url);
    
    if ($ch === false) {
        error_log("Failed to initialize cURL for URL: $url");
        return null;
    }
    
    $jsonData = json_encode($data);
    if ($jsonData === false) {
        error_log("Failed to encode JSON data for POST to $url");
        return null;
    }
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonData,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonData)
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    
    curl_close($ch);
    
    if ($response === false || !empty($curlError)) {
        error_log("cURL error posting to $url: $curlError");
        return null;
    }
    
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("HTTP error $httpCode when posting to $url");
        return null;
    }
    
    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decode error for POST response from $url: " . json_last_error_msg());
        return null;
    }
    
    return $decoded;
}

/**
 * Build the charge schedule API URL
 * 
 * @param string $apiBaseUrl Base URL for the API (e.g., 'https://www.wijs.ovh/zendure/schedule/api')
 * @param string|null $date Date in YYYYMMDD format (optional, defaults to today)
 * @return string Full API URL
 */
function buildChargeScheduleUrl($apiBaseUrl, $date = null) {
    $url = rtrim($apiBaseUrl, '/') . '/charge_schedule_api.php';
    if ($date !== null && preg_match('/^\d{8}$/', $date)) {
        $url .= '?date=' . urlencode($date);
    }
    return $url;
}

/**
 * Fetch charge schedule data from the API
 * 
 * @param string $apiBaseUrl Base URL for the API (e.g., 'https://www.wijs.ovh/zendure/schedule/api')
 * @param string|null $date Date in YYYYMMDD format (optional, defaults to today)
 * @return array|null API response data or null on error. Returns array with keys:
 *   - 'success': bool
 *   - 'entries': array of schedule entries
 *   - 'resolved': array of resolved schedule entries for the date
 *   - 'date': string date in YYYYMMDD format
 *   - 'currentHour': string current hour in HH00 format
 *   - 'currentTime': string current time in HHmm format
 */
function fetchChargeSchedule($apiBaseUrl, $date = null) {
    $url = buildChargeScheduleUrl($apiBaseUrl, $date);
    $data = httpGetJson($url);
    
    if ($data === null) {
        return null;
    }
    
    if (!isset($data['success']) || $data['success'] !== true) {
        error_log("Charge schedule API returned success=false: " . ($data['error'] ?? 'Unknown error'));
        return null;
    }
    
    return $data;
}

/**
 * Build the automation status API URL
 * 
 * @param string $apiBaseUrl Base URL for the API (e.g., 'https://www.wijs.ovh/zendure/schedule/api')
 * @param string $type Entry type filter: 'all', 'start', 'stop', 'change', 'heartbeat' (default: 'all')
 * @param int|null $limit Maximum number of entries to return (optional)
 * @return string Full API URL
 */
function buildAutomationStatusUrl($apiBaseUrl, $type = 'all', $limit = null) {
    $url = rtrim($apiBaseUrl, '/') . '/automation_status_api.php';
    $params = [];
    
    if ($type !== null) {
        $params[] = 'type=' . urlencode($type);
    }
    
    if ($limit !== null && is_numeric($limit) && $limit > 0) {
        $params[] = 'limit=' . (int)$limit;
    }
    
    if (!empty($params)) {
        $url .= '?' . implode('&', $params);
    }
    
    return $url;
}

/**
 * Fetch automation status data from the API
 * 
 * @param string $apiBaseUrl Base URL for the API (e.g., 'https://www.wijs.ovh/zendure/schedule/api')
 * @param string $type Entry type filter: 'all', 'start', 'stop', 'change', 'heartbeat' (default: 'all')
 * @param int|null $limit Maximum number of entries to return (optional)
 * @return array|null API response data or null on error. Returns array with keys:
 *   - 'success': bool
 *   - 'entries': array of status entries
 *   - 'total': int total number of entries (if applicable)
 */
function fetchAutomationStatus($apiBaseUrl, $type = 'all', $limit = null) {
    $url = buildAutomationStatusUrl($apiBaseUrl, $type, $limit);
    $data = httpGetJson($url);
    
    if ($data === null) {
        return null;
    }
    
    if (!isset($data['success']) || $data['success'] !== true) {
        error_log("Automation status API returned success=false: " . ($data['error'] ?? 'Unknown error'));
        return null;
    }
    
    return $data;
}

/**
 * Post automation status update to the API
 * 
 * @param string $apiBaseUrl Base URL for the API (e.g., 'https://www.wijs.ovh/zendure/schedule/api')
 * @param string $eventType Event type: 'start', 'stop', 'change', 'heartbeat'
 * @param mixed $oldValue Previous value (for 'change' events)
 * @param mixed $newValue New value (for 'change' events)
 * @param int|null $timestamp Unix timestamp (optional, defaults to current time)
 * @return array|null API response data or null on error
 */
function postAutomationStatus($apiBaseUrl, $eventType, $oldValue = null, $newValue = null, $timestamp = null) {
    if ($timestamp === null) {
        $timestamp = time();
    }
    
    $url = rtrim($apiBaseUrl, '/') . '/automation_status_api.php';
    $payload = [
        'type' => $eventType,
        'timestamp' => $timestamp,
        'oldValue' => $oldValue,
        'newValue' => $newValue
    ];
    
    $data = httpPostJson($url, $payload);
    
    if ($data === null) {
        return null;
    }
    
    if (!isset($data['success']) || $data['success'] !== true) {
        error_log("Automation status API POST returned success=false: " . ($data['error'] ?? 'Unknown error'));
        return null;
    }
    
    return $data;
}

/**
 * Get API base URL from config file
 * 
 * @param string|null $configPath Path to config.json file (optional)
 * @return array|null Array with 'apiUrl' and 'statusApiUrl' keys, or null on error
 */
function getApiUrlsFromConfig($configPath = null) {
    if ($configPath === null) {
        // Try to find config.json in common locations
        $possiblePaths = [
            __DIR__ . '/../config/config.json',
            __DIR__ . '/../../config/config.json',
            __DIR__ . '/../run_schedule/config/config.json'
        ];
        
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $configPath = $path;
                break;
            }
        }
        
        if ($configPath === null) {
            error_log("Config file not found in any expected location");
            return null;
        }
    }
    
    if (!file_exists($configPath)) {
        error_log("Config file not found: $configPath");
        return null;
    }
    
    $json = file_get_contents($configPath);
    if ($json === false) {
        error_log("Failed to read config file: $configPath");
        return null;
    }
    
    $config = json_decode($json, true);
    if ($config === null) {
        error_log("Failed to parse config file JSON: " . json_last_error_msg());
        return null;
    }
    
    // Extract base URLs (remove the filename to get base path)
    $apiUrl = $config['apiUrl'] ?? null;
    $statusApiUrl = $config['statusApiUrl'] ?? null;
    
    if ($apiUrl) {
        // Remove filename to get base path
        $apiUrl = dirname($apiUrl);
    }
    
    if ($statusApiUrl) {
        // Remove filename to get base path
        $statusApiUrl = dirname($statusApiUrl);
    }
    
    return [
        'apiUrl' => $apiUrl,
        'statusApiUrl' => $statusApiUrl
    ];
}

