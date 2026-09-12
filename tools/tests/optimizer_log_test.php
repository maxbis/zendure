<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/includes/optimizer_log.php';

function optimizerLogTestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$path = tempnam(sys_get_temp_dir(), 'zendure-optimizer-log-');
if ($path === false) {
    throw new RuntimeException('Unable to create optimizer log test file.');
}

try {
    $lines = [
        json_encode(['type' => 'optimizer_shadow_plan', 'plan' => ['generated_at' => 'first']]),
        '{invalid-json',
        json_encode(['type' => 'optimizer_shadow_error', 'error' => 'upstream unavailable']),
        json_encode(['type' => 'optimizer_shadow_plan', 'plan' => ['generated_at' => 'second']]),
        json_encode(['type' => 'optimizer_shadow_plan', 'plan' => ['generated_at' => 'third']]),
    ];
    file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL);

    $result = optimizerLogReadPlans($path, 2);
    optimizerLogTestAssert(count($result['records']) === 2, 'The requested plan limit must be applied.');
    optimizerLogTestAssert(($result['records'][0]['plan']['generated_at'] ?? null) === 'third', 'Newest plan must be first.');
    optimizerLogTestAssert(($result['records'][1]['plan']['generated_at'] ?? null) === 'second', 'Second-newest plan must follow.');

    $allPlans = optimizerLogReadPlans($path, 3);
    optimizerLogTestAssert($allPlans['invalid_lines'] === 1, 'Malformed JSON lines must be counted and skipped.');

    optimizerLogTestAssert(optimizerLogTailLines($path . '.missing', 5) === [], 'A missing log must return no lines.');
} finally {
    @unlink($path);
}

echo "Optimizer log reader tests passed.\n";
