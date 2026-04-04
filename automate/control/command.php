<?php
/**
 * Generic command proxy for control actions (restart, pause, resume, ...).
 *
 * Authenticates via login/validate.php, resolves apiBaseUrlPiControl from config,
 * and forwards whitelisted commands to the automation HTTP API.
 */

require_once __DIR__ . '/../../login/validate.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/commands.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit();
}

$commands = restartCommandDefinitions();

function responseJson(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit();
}

function availableCommands(array $commands): array {
    $out = [];
    foreach ($commands as $key => $cfg) {
        $out[] = [
            'command' => $key,
            'method' => $cfg['method'] ?? 'POST',
            'path' => $cfg['path'] ?? '',
            'label' => $cfg['label'] ?? $key,
            'description' => $cfg['description'] ?? '',
        ];
    }
    return $out;
}

function parseJsonBody(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function extractCommand(array $body): string {
    if (isset($body['command']) && is_string($body['command'])) {
        return trim($body['command']);
    }
    if (isset($_POST['command']) && is_string($_POST['command'])) {
        return trim($_POST['command']);
    }
    if (isset($_GET['command']) && is_string($_GET['command'])) {
        return trim($_GET['command']);
    }
    return '';
}

function extractIntegerValue(array $body): ?int {
    $raw = null;
    if (array_key_exists('value', $body)) {
        $raw = $body['value'];
    } elseif (array_key_exists('value', $_POST)) {
        $raw = $_POST['value'];
    } elseif (array_key_exists('value', $_GET)) {
        $raw = $_GET['value'];
    }
    if ($raw === null) {
        return null;
    }
    if (is_int($raw)) {
        return $raw;
    }
    if (is_string($raw) && preg_match('/^-?\d+$/', trim($raw))) {
        return (int) trim($raw);
    }
    return null;
}

$body = parseJsonBody();
$command = extractCommand($body);

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    responseJson(405, [
        'ok' => false,
        'error' => 'Method not allowed. Use GET or POST.',
        'commands' => availableCommands($commands),
    ]);
}

$baseUrl = restartApiBaseUrl();
if ($baseUrl === '') {
    responseJson(502, [
        'ok' => false,
        'error' => 'apiBaseUrlPiControl is not configured.',
        'commands' => availableCommands($commands),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $command === '') {
    responseJson(200, [
        'ok' => true,
        'message' => 'Command endpoint help',
        'usage' => [
            'method' => 'POST',
            'body' => ['command' => 'pause_on'],
            'or_query' => '?command=pause_on',
        ],
        'commands' => availableCommands($commands),
    ]);
}

if ($command === '' || !isset($commands[$command])) {
    responseJson(400, [
        'ok' => false,
        'error' => 'Missing or invalid command.',
        'commands' => availableCommands($commands),
    ]);
}

$cfg = $commands[$command];
$method = strtoupper((string) ($cfg['method'] ?? 'POST'));
$path = (string) ($cfg['path'] ?? '');
if (!empty($cfg['parameterized'])) {
    $value = extractIntegerValue($body);
    if ($value === null) {
        responseJson(400, [
            'ok' => false,
            'command' => $command,
            'error' => 'Missing or invalid integer value.',
        ]);
    }
    $path .= '?value=' . rawurlencode((string) $value);
}
$upstreamUrl = rtrim($baseUrl, '/') . $path;

$context = stream_context_create([
    'http' => [
        'timeout' => 6,
        'ignore_errors' => true,
        'method' => $method,
        'header' => "Accept: application/json\r\nUser-Agent: restart-command-proxy",
    ],
]);

$raw = @file_get_contents($upstreamUrl, false, $context);
$httpStatus = 0;
if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
    $httpStatus = (int) $m[1];
}

if (($raw === false || $raw === '') && !empty($cfg['expect_disconnect'])) {
    responseJson(200, [
        'ok' => true,
        'command' => $command,
        'message' => 'Command sent. Connection closed while service is restarting (expected).',
        'upstream' => $upstreamUrl,
    ]);
}

if ($raw === false || $raw === '') {
    responseJson(502, [
        'ok' => false,
        'command' => $command,
        'error' => 'No response from upstream command API.',
        'upstream' => $upstreamUrl,
    ]);
}

$decoded = json_decode($raw, true);
$decodedIsArray = is_array($decoded);

if ($httpStatus >= 400) {
    responseJson($httpStatus, [
        'ok' => false,
        'command' => $command,
        'error' => $decodedIsArray ? ($decoded['error'] ?? 'Command failed.') : 'Command failed.',
        'upstreamStatus' => $httpStatus,
        'upstreamBody' => $decodedIsArray ? $decoded : $raw,
    ]);
}

responseJson(200, [
    'ok' => true,
    'command' => $command,
    'message' => $decodedIsArray
        ? (string) ($decoded['message'] ?? ($decoded['ok'] ?? false ? 'Command completed.' : 'Command sent.'))
        : 'Command sent.',
    'upstreamStatus' => $httpStatus > 0 ? $httpStatus : 200,
    'upstreamBody' => $decodedIsArray ? $decoded : $raw,
]);
