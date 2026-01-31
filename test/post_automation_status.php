<?php
/**
 * Test script: POST an entry to the automation status API.
 * Use this to verify the API (and compression) in schedule/api/automation_status_api.php.
 *
 * Usage (browser): Open this file in the browser, optionally with query params:
 *   ?type=change&oldValue=0&newValue=0
 *   ?type=Rescan
 *   ?type=change&oldValue=100&newValue=200
 *
 * Usage (CLI): php post_automation_status.php [type] [oldValue] [newValue]
 *   If BASE_URL is not set for CLI, edit the $baseUrl fallback below.
 */

$type = $_GET['type'] ?? null;
$oldValue = array_key_exists('oldValue', $_GET) ? $_GET['oldValue'] : null;
$newValue = array_key_exists('newValue', $_GET) ? $_GET['newValue'] : null;

if (php_sapi_name() === 'cli') {
    $type = $argv[1] ?? 'change';
    $oldValue = $argv[2] ?? null;
    $newValue = $argv[3] ?? null;
}

if ($type === null || $type === '') {
    $type = 'change';
}

// Parse numeric GET params (browser sends strings)
if ($oldValue !== null && is_numeric($oldValue)) {
    $oldValue = strpos($oldValue, '.') !== false ? (float) $oldValue : (int) $oldValue;
}
if ($newValue !== null && is_numeric($newValue)) {
    $newValue = strpos($newValue, '.') !== false ? (float) $newValue : (int) $newValue;
}

// Build API URL
if (php_sapi_name() === 'cli') {
    $baseUrl = getenv('BASE_URL') ?: 'http://localhost/zendure';
} else {
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $baseUrl = $scheme . '://' . $host . dirname(dirname($scriptName));
}
$apiUrl = rtrim($baseUrl, '/') . '/schedule/api/automation_status_api.php';

$payload = [
    'type' => $type,
    'timestamp' => time(),
    'oldValue' => $oldValue,
    'newValue' => $newValue
];

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => json_encode($payload),
        'timeout' => 10,
        'ignore_errors' => true
    ]
]);

$responseBody = @file_get_contents($apiUrl, false, $context);

if ($responseBody === false) {
    echo "Request failed (could not reach $apiUrl).\n";
    if (php_sapi_name() === 'cli') {
        echo "For CLI, set BASE_URL or edit \$baseUrl in this script.\n";
    }
    exit(1);
}

$response = json_decode($responseBody, true);

if (php_sapi_name() === 'cli') {
    echo "API URL: $apiUrl\n";
    echo "Payload: " . json_encode($payload, JSON_PRETTY_PRINT) . "\n";
    echo "Response: " . json_encode($response, JSON_PRETTY_PRINT) . "\n";
    exit(isset($response['success']) && $response['success'] ? 0 : 1);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'request' => ['url' => $apiUrl, 'payload' => $payload],
    'response' => $response
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
