# Integration Example: Using Flask API from PHP

This document shows how to update `statusa/index.php` to use the Flask API instead of direct device calls.

## Step 1: Update statusa/index.php

Replace the direct device fetching code with API calls:

### Before (Direct Device Access):

```php
// Auto-update: Fetch fresh data from devices on every page load
require_once __DIR__ . '/classes/read_zendure.php';
$solarflow = new SolarFlow2400($config['deviceIp']);
$solarflow->getStatus(false); // Non-verbose, just saves data

require_once __DIR__ . '/classes/read_zendure_p1.php';
$p1Meter = new ZendureP1Meter($config['p1MeterIp']);
$p1Meter->update(false); // Non-verbose, just saves data
```

### After (Using Flask API):

```php
// API configuration - add to config.json or config.php
$apiBaseUrl = 'http://raspberry-pi-ip:5000';  // Change to your Raspberry Pi IP

// Fetch Zendure device status from API
function fetchFromApi($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        return $data['success'] ? $data['data'] : null;
    }
    return null;
}

// Fetch fresh data from API
$zendureApiData = fetchFromApi($apiBaseUrl . '/api/zendure/status');
$p1ApiData = fetchFromApi($apiBaseUrl . '/api/zendure/p1');

// Save to JSON files (for backward compatibility with existing code)
if ($zendureApiData) {
    $dataDir = $config['dataDir'];
    // Resolve path...
    $dataFile = rtrim($dataDir, '/') . '/zendure_data.json';
    file_put_contents($dataFile, json_encode($zendureApiData, JSON_PRETTY_PRINT));
}

if ($p1ApiData) {
    $dataDir = $config['dataDir'];
    // Resolve path...
    $p1DataFile = rtrim($dataDir, '/') . '/zendure_p1_data.json';
    file_put_contents($p1DataFile, json_encode($p1ApiData, JSON_PRETTY_PRINT));
}

// Use the API data directly or load from saved files
$zendureData = $zendureApiData ?: loadZendureData($dataFile)['data'];
$p1Data = $p1ApiData ?: loadZendureData($p1DataFile)['data'];
```

## Step 2: Add API URL to Config

Add the Flask API URL to your `statusa/config/config.json`:

```json
{
  "dataDir": "../data/",
  "dataFile": null,
  "deviceIp": "192.168.2.93",
  "deviceSn": "HOA1NAN9N385989",
  "p1MeterIp": "192.168.2.94",
  "apiBaseUrl": "http://192.168.2.100:5000"
}
```

## Step 3: Error Handling

Add proper error handling for API failures:

```php
$zendureApiData = fetchFromApi($apiBaseUrl . '/api/zendure/status');
if (!$zendureApiData) {
    // Fallback to cached data if API is unavailable
    $dataResult = loadZendureData($dataFile);
    $zendureData = $dataResult['data'];
    $errorMessage = 'API unavailable, using cached data';
} else {
    $zendureData = $zendureApiData;
}
```

## Benefits

1. **Separation of Concerns**: Device communication logic is isolated in the Flask app
2. **Raspberry Pi Deployment**: Can run the Flask app on a Raspberry Pi while the web server runs elsewhere
3. **Scalability**: Multiple web interfaces can use the same API
4. **Maintenance**: Easier to update device communication logic in one place

## Testing

Test the API connection from PHP:

```php
$testUrl = $apiBaseUrl . '/api/health';
$response = @file_get_contents($testUrl);
if ($response) {
    $health = json_decode($response, true);
    echo "API Status: " . $health['status'];
} else {
    echo "API is not reachable";
}
```

