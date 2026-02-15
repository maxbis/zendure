<?php
/**
 * Shared data bootstrap for Charge/Discharge Status partials (v2).
 * Fetches all data from the unified API endpoint (P1, Zendure, status in one response).
 *
 * Provides:
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

// Unified API endpoint (P1 + Zendure + status in one response)
// Remote API used by server-side PHP fetch
const CHARGE_STATUS_ALL_API_URL = 'http://81.204.237.36:1611/api/all';

// Include required functions for temperature conversion and color calculation
require_once __DIR__ . '/../includes/formatters.php';
require_once __DIR__ . '/../includes/colors.php';

// Ensure ConfigLoader is available
if (!class_exists('ConfigLoader')) {
    require_once __DIR__ . '/../includes/config_loader.php';
}

// Fetch from unified API
$zendureData = null;
$p1Data = null;
$chargeStatusError = null;
$lastUpdate = null;

// Charge level constants (available throughout the partials)
$MIN_CHARGE_LEVEL = (int) ConfigLoader::get('MIN_CHARGE_LEVEL', 20);
$MAX_CHARGE_LEVEL = (int) ConfigLoader::get('MAX_CHARGE_LEVEL', 90);
$MIN_CHARGE_LEVEL = max(0, min(100, $MIN_CHARGE_LEVEL));
$MAX_CHARGE_LEVEL = max(0, min(100, $MAX_CHARGE_LEVEL));
if ($MIN_CHARGE_LEVEL > $MAX_CHARGE_LEVEL) {
    $MAX_CHARGE_LEVEL = $MIN_CHARGE_LEVEL;
}
$TOTAL_CAPACITY_KWH = 5.76; // Total battery capacity in kWh (57600 Wh / 1000)

// Build same-origin proxy URL for browser fetches (avoid CORS)
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = dirname($scriptName);
if ($basePath === '/' || $basePath === '\\' || $basePath === '.') {
    $basePath = '';
}
$proxyApiUrl = $scheme . '://' . $host . $basePath . '/api/charge_status_all_proxy.php';

// Store API URL and levels for JavaScript
echo '<script>';
echo 'const CHARGE_STATUS_ALL_API_URL = ' . json_encode($proxyApiUrl, JSON_UNESCAPED_SLASHES) . ';';
echo 'const CHARGE_STATUS_MIN_CHARGE_LEVEL = ' . json_encode($MIN_CHARGE_LEVEL) . ';';
echo 'const CHARGE_STATUS_MAX_CHARGE_LEVEL = ' . json_encode($MAX_CHARGE_LEVEL) . ';';
echo '</script>';

$chargeStatus = [
    'zendure' => null,
    'p1'      => null,
    'lastUpdate' => null,
    'error'   => null
];

try {
    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'ignore_errors' => true,
            'method' => 'GET',
            'header' => 'User-Agent: Charge-Schedule-Page'
        ]
    ]);

    $jsonData = @file_get_contents(CHARGE_STATUS_ALL_API_URL, false, $context);

    if (!empty($jsonData)) {
        $response = json_decode($jsonData, true);
        if ($response && isset($response['zendure']['readings'])) {
            $zendureReadings = $response['zendure']['readings'];
            $chargeStatus['zendure'] = [
                'properties' => $zendureReadings['properties'] ?? [],
                'packData' => $zendureReadings['packData'] ?? [],
                'timestamp' => $response['zendure']['timestamp'] ?? null
            ];

            $p1Readings = $response['p1']['readings'] ?? [];
            $chargeStatus['p1'] = [
                'total_power' => $p1Readings['total_power'] ?? 0
            ];

            $tsZ = $response['zendure']['timestamp'] ?? 0;
            $tsP1 = $response['p1']['timestamp'] ?? 0;
            $tsStatus = isset($response['status']['timestamp']) ? $response['status']['timestamp'] : 0;
            $lastUpdateVal = max($tsZ, $tsP1, $tsStatus);
            if ($lastUpdateVal > 0) {
                $chargeStatus['lastUpdate'] = is_numeric($lastUpdateVal) ? (int) $lastUpdateVal : strtotime($lastUpdateVal);
            } else {
                $chargeStatus['lastUpdate'] = null;
            }

            $chargeStatus['error'] = null;
        } else {
            $errorMsg = isset($response['error']) ? $response['error'] : 'Invalid response from unified API';
            $chargeStatus['error'] = 'Failed to load charge status: ' . htmlspecialchars($errorMsg);
        }
    } else {
        $chargeStatus['error'] = 'Charge status unavailable (no data returned from API).';
    }
} catch (Exception $e) {
    $chargeStatus['error'] = 'Charge status unavailable: ' . htmlspecialchars($e->getMessage());
}

$zendureData       = $chargeStatus['zendure'];
$p1Data            = $chargeStatus['p1'];
$lastUpdate        = $chargeStatus['lastUpdate'];
$chargeStatusError = $chargeStatus['error'];
