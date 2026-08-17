<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/login/validate.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function autoProfileResponse(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    autoProfileResponse(405, ['success' => false, 'error' => 'Method not allowed. Use POST.']);
}

$body = json_decode((string) file_get_contents('php://input'), true);
$includeToday = is_array($body) && !empty($body['include_today']);
$root = dirname(__DIR__, 2);
$script = $root . '/tools/evaluate_auto_profiles.py';
$command = 'python3 ' . escapeshellarg($script);
if ($includeToday) {
    $command .= ' --include-today';
}
$command .= ' 2>&1';

$lines = [];
$exitCode = 1;
exec($command, $lines, $exitCode);
$raw = trim(implode("\n", $lines));
$decoded = json_decode($raw, true);
if ($exitCode !== 0 || !is_array($decoded) || empty($decoded['success'])) {
    autoProfileResponse(500, [
        'success' => false,
        'error' => is_array($decoded) ? ($decoded['error'] ?? 'Automatic profile evaluation failed.') : ($raw !== '' ? $raw : 'Automatic profile evaluation failed.'),
    ]);
}

autoProfileResponse(200, $decoded);
