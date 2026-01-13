<?php
/**
 * Shared data bootstrap for Charge/Discharge Status partials.
 *
 * Provides:
 * - $dataApiUrl, $p1ApiUrl
 * - $MIN_CHARGE_LEVEL, $MAX_CHARGE_LEVEL, $TOTAL_CAPACITY_KWH
 * - $zendureData, $p1Data, $chargeStatusError, $lastUpdate
 *
 * This file is safe to include multiple times; it will only execute once.
 */

// Prevent duplicate initialization if included from multiple partials
if (isset($charge_status_data_initialized) && $charge_status_data_initialized === true) {
    return;
}
$charge_status_data_initialized = true;

// Include required functions for temperature conversion and color calculation
require_once __DIR__ . '/../includes/formatters.php';
require_once __DIR__ . '/../includes/colors.php';

// Fetch Zendure data from API
$zendureData = null;
$p1Data = null;
$chargeStatusError = null;
$lastUpdate = null;

// Load API URLs from config file
$dataApiUrl = null;
$p1ApiUrl = null;
$configPath = __DIR__ . '/../../config/config.json';
if (file_exists($configPath)) {
    $configJson = file_get_contents($configPath);
    if ($configJson !== false) {
        $config = json_decode($configJson, true);
        if ($config !== null) {
            $location = $config['location'] ?? 'remote';
            if ($location === 'local') {
                $baseUrl = $config['dataApiUrl-local'] ?? null;
            } else {
                $baseUrl = $config['dataApiUrl'] ?? null;
            }

            if ($baseUrl) {
                $dataApiUrl = $baseUrl . (strpos($baseUrl, '?') !== false ? '&' : '?') . 'type=zendure';
                $p1ApiUrl = $baseUrl . (strpos($baseUrl, '?') !== false ? '&' : '?') . 'type=zendure_p1';
            }
        }
    }
}

// Fallback to dynamic construction if config not available
if ($dataApiUrl === null) {
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    // Get base path: go up from /schedule/charge_schedule.php to get root
    $basePath = dirname(dirname($scriptName));
    // Ensure basePath is not empty and handle root case
    if ($basePath === '/' || $basePath === '\\' || $basePath === '.') {
        $basePath = '';
    }
    $dataApiUrl = $scheme . '://' . $host . $basePath . '/data/api/data_api.php?type=zendure';
    $p1ApiUrl = $scheme . '://' . $host . $basePath . '/data/api/data_api.php?type=zendure_p1';
}

// Charge level constants (available throughout the partials)
$MIN_CHARGE_LEVEL = 20; // Minimum charge level (percent)
$MAX_CHARGE_LEVEL = 90; // Maximum charge level (percent)
$TOTAL_CAPACITY_KWH = 5.76; // Total battery capacity in kWh (57600 Wh / 1000)

try {
    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'ignore_errors' => true,
            'method' => 'GET',
            'header' => 'User-Agent: Charge-Schedule-Page'
        ]
    ]);

    $jsonData = @file_get_contents($dataApiUrl, false, $context);

    if ($jsonData === false || empty($jsonData)) {
        // Try alternative: direct file path (for local file access)
        $apiFilePath = __DIR__ . '/../../data/api/data_api.php';
        if (file_exists($apiFilePath)) {
            // Temporarily set GET parameters and capture output
            $originalGet = $_GET;
            $_GET['type'] = 'zendure';

            ob_start();
            include $apiFilePath;
            $jsonData = ob_get_clean();

            $_GET = $originalGet;
        }
    }

    if (!empty($jsonData)) {
        $apiResponse = json_decode($jsonData, true);
        if ($apiResponse && isset($apiResponse['success']) && $apiResponse['success'] && isset($apiResponse['data'])) {
            $zendureData = $apiResponse['data'];
            // Get timestamp from data or API response
            if (isset($apiResponse['timestamp'])) {
                $lastUpdate = is_numeric($apiResponse['timestamp']) ? $apiResponse['timestamp'] : strtotime($apiResponse['timestamp']);
            } elseif (isset($zendureData['timestamp'])) {
                $lastUpdate = is_numeric($zendureData['timestamp']) ? $zendureData['timestamp'] : strtotime($zendureData['timestamp']);
            }
            $chargeStatusError = null;
        } else {
            $errorMsg = isset($apiResponse['error']) ? $apiResponse['error'] : 'Unknown error';
            $chargeStatusError = 'Failed to load charge status: ' . htmlspecialchars($errorMsg);
        }
    } else {
        $chargeStatusError = 'Charge status unavailable (no data returned from API).';
    }

    // Fetch P1 data from API
    $p1JsonData = @file_get_contents($p1ApiUrl, false, $context);

    if ($p1JsonData === false || empty($p1JsonData)) {
        // Try alternative: direct file path (for local file access)
        $apiFilePath = __DIR__ . '/../../data/api/data_api.php';
        if (file_exists($apiFilePath)) {
            // Temporarily set GET parameters and capture output
            $originalGet = $_GET;
            $_GET['type'] = 'zendure_p1';

            ob_start();
            include $apiFilePath;
            $p1JsonData = ob_get_clean();

            $_GET = $originalGet;
        }
    }

    if (!empty($p1JsonData)) {
        $p1ApiResponse = json_decode($p1JsonData, true);
        if ($p1ApiResponse && isset($p1ApiResponse['success']) && $p1ApiResponse['success'] && isset($p1ApiResponse['data'])) {
            $p1Data = $p1ApiResponse['data'];
        }
    }
} catch (Exception $e) {
    $chargeStatusError = 'Charge status unavailable: ' . htmlspecialchars($e->getMessage());
}

