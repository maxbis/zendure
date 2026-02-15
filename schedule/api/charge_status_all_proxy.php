<?php
// schedule/api/charge_status_all_proxy.php
// Same-origin proxy for unified charge status API to avoid CORS in browsers.

date_default_timezone_set('Europe/Amsterdam');

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

// Handle OPTIONS preflight (harmless for same-origin, but safe)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

const CHARGE_STATUS_ALL_API_URL = 'http://81.204.237.36:1611/api/all';

try {
    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'ignore_errors' => true,
            'method' => 'GET',
            'header' => 'User-Agent: Charge-Schedule-Proxy'
        ]
    ]);

    $jsonData = @file_get_contents(CHARGE_STATUS_ALL_API_URL, false, $context);

    if ($jsonData === false || $jsonData === '') {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to fetch unified API response'
        ]);
        exit();
    }

    // Validate JSON before returning
    $decoded = json_decode($jsonData, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'error' => 'Unified API returned invalid JSON'
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
