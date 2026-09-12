<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/login/validate.php';
require_once dirname(__DIR__, 2) . '/common/php/system_config.php';
require_once dirname(__DIR__) . '/includes/optimizer_schedule.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function optimizerModeResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $systemConfig = loadSystemConfig();
    $timezone = new DateTimeZone($systemConfig['installation']['timezone']);
    $now = new DateTimeImmutable('now', $timezone);
} catch (Throwable $error) {
    optimizerModeResponse(500, ['success' => false, 'error' => $error->getMessage()]);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    optimizerModeResponse(200, ['success' => true, 'status' => optimizerScheduleStatus($now, $systemConfig)]);
}
if ($method !== 'POST') {
    optimizerModeResponse(405, ['success' => false, 'error' => 'Method not allowed. Use GET or POST.']);
}

$body = json_decode((string) file_get_contents('php://input'), true);
$mode = is_array($body) ? ($body['mode'] ?? null) : null;
$csrf = is_array($body) ? ($body['csrfToken'] ?? null) : null;
if (!is_string($csrf) || !isset($_SESSION['optimizer_csrf']) || !hash_equals((string) $_SESSION['optimizer_csrf'], $csrf)) {
    optimizerModeResponse(403, ['success' => false, 'error' => 'The page security token is invalid. Reload the page and try again.']);
}
if (!in_array($mode, ['rules', 'optimizer'], true)) {
    optimizerModeResponse(422, ['success' => false, 'error' => 'Mode must be rules or optimizer.']);
}

$previousMode = optimizerScheduleRequestedMode();
$refreshOutput = null;
if ($mode === 'optimizer') {
    $repoRoot = dirname(__DIR__, 2);
    $command = 'cd ' . escapeshellarg($repoRoot) . ' && /usr/bin/env python3 -m planner.shadow --once 2>&1';
    $lines = [];
    $exitCode = 1;
    exec($command, $lines, $exitCode);
    $refreshOutput = trim(implode("\n", $lines));
    $now = new DateTimeImmutable('now', $timezone);
    $validation = optimizerScheduleValidateLatest($now, $systemConfig);
    if ($exitCode !== 0 || !$validation['valid']) {
        $failureReason = $exitCode !== 0 && $refreshOutput !== ''
            ? $refreshOutput
            : ($validation['reason'] ?? 'The optimizer could not produce a valid current schedule.');
        optimizerScheduleAppendAudit([
            'changed_at' => $now->format(DateTimeInterface::ATOM),
            'requested_mode' => $mode,
            'previous_mode' => $previousMode,
            'result' => 'rejected',
            'reason' => $failureReason,
            'remote_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
        optimizerModeResponse(409, [
            'success' => false,
            'error' => $failureReason,
            'status' => optimizerScheduleStatus($now, $systemConfig),
        ]);
    }
}

$state = [
    'version' => 1,
    'requested_mode' => $mode,
    'changed_at' => $now->format(DateTimeInterface::ATOM),
    'changed_from' => $previousMode,
    'remote_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
];
if (!optimizerScheduleWriteJsonAtomic(optimizerScheduleModePath(), $state)) {
    optimizerModeResponse(500, ['success' => false, 'error' => 'The active schedule mode could not be saved.']);
}
optimizerScheduleAppendAudit($state + ['result' => 'changed']);

optimizerModeResponse(200, [
    'success' => true,
    'message' => $mode === 'optimizer'
        ? 'Optimizer mode is active with a newly calculated schedule.'
        : 'Rule-based mode is active.',
    'status' => optimizerScheduleStatus(new DateTimeImmutable('now', $timezone), $systemConfig),
    'optimizerRefresh' => $refreshOutput,
]);
