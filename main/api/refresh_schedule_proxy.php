<?php
// Same-origin proxy for the automation refresh-schedule command.

date_default_timezone_set('Europe/Amsterdam');

require_once __DIR__ . '/../../login/validate.php';
require_once __DIR__ . '/../../automate/control/commands.php';
require_once __DIR__ . '/../includes/config_loader.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'error' => 'Method not allowed. Use POST.',
    ], JSON_UNESCAPED_SLASHES);
    exit();
}

$commands = restartCommandDefinitions();
$cfg = $commands['refresh_schedule'] ?? null;
if (!is_array($cfg)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'refresh_schedule command is not available.',
    ], JSON_UNESCAPED_SLASHES);
    exit();
}

$baseUrl = ConfigLoader::get('apiBaseUrlPiControl', '');
if (!is_string($baseUrl) || trim($baseUrl) === '') {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => 'apiBaseUrlPiControl not configured',
    ], JSON_UNESCAPED_SLASHES);
    exit();
}

$method = strtoupper((string) ($cfg['method'] ?? 'GET'));
$path = (string) ($cfg['path'] ?? '');
$upstreamUrl = rtrim(trim($baseUrl), '/') . $path;

$context = stream_context_create([
    'http' => [
        'timeout' => 6,
        'ignore_errors' => true,
        'method' => $method,
        'header' => "Accept: application/json\r\nUser-Agent: main-refresh-schedule-proxy",
    ],
]);

$raw = @file_get_contents($upstreamUrl, false, $context);
$httpStatus = 0;
if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
    $httpStatus = (int) $m[1];
}

if ($raw === false || $raw === '') {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'command' => 'refresh_schedule',
        'error' => 'No response from upstream command API.',
        'upstream' => $upstreamUrl,
    ], JSON_UNESCAPED_SLASHES);
    exit();
}

$decoded = json_decode($raw, true);
$decodedIsArray = is_array($decoded);

if ($httpStatus >= 400) {
    http_response_code($httpStatus);
    echo json_encode([
        'ok' => false,
        'command' => 'refresh_schedule',
        'error' => $decodedIsArray ? ($decoded['error'] ?? 'Command failed.') : 'Command failed.',
        'upstreamStatus' => $httpStatus,
        'upstreamBody' => $decodedIsArray ? $decoded : $raw,
    ], JSON_UNESCAPED_SLASHES);
    exit();
}

echo json_encode([
    'ok' => true,
    'command' => 'refresh_schedule',
    'message' => $decodedIsArray
        ? (string) ($decoded['message'] ?? ($decoded['ok'] ?? false ? 'Command completed.' : 'Command sent.'))
        : 'Command sent.',
    'upstreamStatus' => $httpStatus > 0 ? $httpStatus : 200,
    'upstreamBody' => $decodedIsArray ? $decoded : $raw,
], JSON_UNESCAPED_SLASHES);
