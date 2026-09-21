<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/includes/optimizer_runtime_log.php';

function runtimeLogTestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$path = tempnam(sys_get_temp_dir(), 'optimizer-runtime-');
if ($path === false) {
    throw new RuntimeException('Unable to create optimizer runtime log test file.');
}

$events = [
    ['observed_at' => '2026-09-15T23:45:00+02:00', 'event' => 'SAMPLE', 'actual_soc' => 40],
    ['observed_at' => '2026-09-16T00:02:00+02:00', 'event' => 'HOUR_CLOSED', 'hour_start' => '2026-09-15T23:00:00+02:00'],
    ['observed_at' => '2026-09-16T08:00:00+02:00', 'event' => 'HOUR_OPENED'],
    ['observed_at' => '2026-09-16T08:15:00+02:00', 'event' => 'SAMPLE', 'actual_soc' => 50],
    ['observed_at' => '2026-09-16T09:02:00+02:00', 'event' => 'HOUR_CLOSED', 'hour_start' => '2026-09-16T08:00:00+02:00'],
    ['observed_at' => '2026-09-16T09:15:00+02:00', 'event' => 'SAMPLE', 'actual_soc' => 55],
];

foreach ([90, 100, 110, 120, 130, 900, 140, 150] as $dayOffset => $usageWh) {
    $day = 8 + $dayOffset;
    $events[] = [
        'observed_at' => sprintf('2026-09-%02dT07:02:00+02:00', $day),
        'event' => 'HOUR_CLOSED',
        'hour_start' => sprintf('2026-09-%02dT06:00:00+02:00', $day),
        'status' => 'complete',
        'coverage_pct' => 98.0,
        'predicted_solar_wh' => 0,
        'actual_usage_wh' => $usageWh,
    ];
}

$lines = array_map(
    static fn (array $event): string => json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
    $events
);
$lines[] = '{not valid json';
file_put_contents($path, implode("\n", $lines) . "\n");

try {
    $timezone = new DateTimeZone('Europe/Amsterdam');
    $now = new DateTimeImmutable('2026-09-16T10:00:00+02:00');

    $hours = optimizerRuntimeLogRead($path, $timezone, $now, 'hours', '24h', null, 50);
    runtimeLogTestAssert($hours['matching_count'] === 2, 'The 24-hour summary view should contain two closed hours.');
    runtimeLogTestAssert(count($hours['events']) === 2, 'Both closed-hour events should be returned.');
    runtimeLogTestAssert($hours['events'][0]['observed_at'] === '2026-09-16T09:02:00+02:00', 'Events should be newest first.');
    runtimeLogTestAssert($hours['invalid_lines'] === 1, 'Malformed JSON should be counted and ignored.');
    runtimeLogTestAssert($hours['scanned_lines'] === 15, 'Every non-empty line should be scanned.');
    runtimeLogTestAssert($hours['latest_at'] === '2026-09-16T09:15:00+02:00', 'Latest timestamp should cover all valid event types.');

    $dateSamples = optimizerRuntimeLogRead($path, $timezone, $now, 'samples', 'date', '2026-09-16', 1);
    runtimeLogTestAssert($dateSamples['matching_count'] === 2, 'Date filtering should use installation-local dates.');
    runtimeLogTestAssert(count($dateSamples['events']) === 1, 'The result limit should retain only one event.');
    runtimeLogTestAssert($dateSamples['events'][0]['actual_soc'] === 55, 'The result limit should retain the newest matching event.');

    $all = optimizerRuntimeLogRead($path, $timezone, $now, 'all', 'all', null, 250);
    runtimeLogTestAssert($all['matching_count'] === 14, 'All history should include all recognized runtime event types.');

    $medianEvent = $all['events'][0];
    runtimeLogTestAssert($medianEvent['_household_rolling_median_samples'] === 7, 'The current hour should be excluded from its rolling median.');
    runtimeLogTestAssert($medianEvent['_household_rolling_median_wh'] === 120.0, 'The rolling median should resist the 900 Wh outlier.');
} finally {
    unlink($path);
}

echo "Optimizer runtime log reader tests passed.\n";
