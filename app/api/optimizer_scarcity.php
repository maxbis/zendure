<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/login/validate.php';
require_once dirname(__DIR__) . '/includes/optimizer_schedule.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET.']);
    exit();
}

$payload = optimizerScheduleReadJson(optimizerScheduleLatestPath());
if (!is_array($payload) || !is_array($payload['plan'] ?? null)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'No optimizer schedule is available.']);
    exit();
}

$plan = $payload['plan'];
$inputs = is_array($payload['inputs'] ?? null) ? $payload['inputs'] : [];
$scarcity = is_array($plan['scarcity'] ?? null) ? $plan['scarcity'] : null;
$generatedAt = isset($plan['generated_at']) ? (string)$plan['generated_at'] : null;
$ageSeconds = null;
if ($generatedAt !== null) {
    try {
        $generated = new DateTimeImmutable($generatedAt);
        $ageSeconds = max(0, time() - $generated->getTimestamp());
    } catch (Throwable) {
        $ageSeconds = null;
    }
}

echo json_encode([
    'success' => true,
    'generatedAt' => $generatedAt,
    'ageSeconds' => $ageSeconds,
    'stale' => $ageSeconds === null || $ageSeconds > OPTIMIZER_SCHEDULE_MAX_AGE_SECONDS,
    'optimizerOutcome' => [
        'starting_soc_percent' => is_numeric($plan['starting_soc_percent'] ?? null)
            ? (float)$plan['starting_soc_percent']
            : null,
        'ending_soc_percent' => is_numeric($plan['ending_soc_percent'] ?? null)
            ? (float)$plan['ending_soc_percent']
            : null,
        'minimum_soc_percent' => is_numeric($inputs['min_soc_percent'] ?? null)
            ? (float)$inputs['min_soc_percent']
            : null,
        'maximum_soc_percent' => is_numeric($inputs['max_soc_percent'] ?? null)
            ? (float)$inputs['max_soc_percent']
            : null,
        'battery_capacity_kwh' => is_numeric($inputs['battery_capacity_wh'] ?? null)
            ? (float)$inputs['battery_capacity_wh'] / 1000.0
            : null,
        'horizon_start' => isset($plan['horizon_start']) ? (string)$plan['horizon_start'] : null,
        'horizon_end' => isset($plan['horizon_end']) ? (string)$plan['horizon_end'] : null,
    ],
    'scarcity' => $scarcity ?? [
        'algorithm_enabled' => false,
        'calculation_available' => false,
        'unavailable_reason' => 'not_calculated',
        'bands' => [],
    ],
], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
