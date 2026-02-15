<?php
// schedule/api/automation_status_proxy.php
// Same-origin proxy for automation status API to avoid CORS in browsers.

date_default_timezone_set('Europe/Amsterdam');

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

// Handle OPTIONS preflight (harmless for same-origin, but safe)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

const AUTOMATION_STATUS_API_URL = 'http://81.204.237.36:1611/api/automation_status';

try {
    $query = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== ''
        ? ('?' . $_SERVER['QUERY_STRING'])
        : '';
    $url = AUTOMATION_STATUS_API_URL . $query;

    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'ignore_errors' => true,
            'method' => 'GET',
            'header' => 'User-Agent: Automation-Status-Proxy'
        ]
    ]);

    $jsonData = @file_get_contents($url, false, $context);

    if ($jsonData === false || $jsonData === '') {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to fetch automation status API response'
        ]);
        exit();
    }

    // Validate JSON before returning
    $decoded = json_decode($jsonData, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'error' => 'Automation status API returned invalid JSON'
        ]);
        exit();
    }

    echo $jsonData;
} catch (Exception $e) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'error' => 'Proxy error: ' . $e->getMessage()
    ]);
}
