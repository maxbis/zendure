<?php
// schedule/api/automation_status_proxy.php
// Same-origin proxy for automation status API to avoid CORS in browsers.

require_once __DIR__ . '/../includes/config_loader.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

// Handle OPTIONS preflight (harmless for same-origin, but safe)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Resolve automation status API URL from config
$rawUrl = ConfigLoader::get('automationStatusApi');
if (empty($rawUrl) || !is_string($rawUrl)) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'error'   => 'automationStatusApi not configured'
    ]);
    exit();
}

$baseUrl = ConfigLoader::get('apiBaseUrlPiControl');
if (empty($baseUrl) || !is_string($baseUrl)) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'error'   => 'apiBaseUrlPiControl not configured'
    ]);
    exit();
}

$upstreamUrl = str_replace('${apiBaseUrlPiControl}', $baseUrl, $rawUrl);

try {
    $context = stream_context_create([
        'http' => [
            'timeout'      => 5,
            'ignore_errors'=> true,
            'method'       => 'GET',
            'header'       => 'User-Agent: Automation-Status-Proxy'
        ]
    ]);

    $jsonData = @file_get_contents($upstreamUrl, false, $context);

    if ($jsonData === false || $jsonData === '') {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'error'   => 'Failed to fetch automation status API response'
        ]);
        exit();
    }

    // Validate JSON before returning
    $decoded = json_decode($jsonData, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(502);
        
        echo json_encode([
            'success' => false,
            'error'   => 'Automation status API returned invalid JSON'
        ]);
        exit();
    }

    echo $jsonData;
} catch (Exception $e) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'error'   => 'Proxy error: ' . $e->getMessage()
    ]);
}
