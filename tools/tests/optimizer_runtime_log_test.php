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
    runtimeLogTestAssert($hours['scanned_lines'] === 7, 'Every non-empty line should be scanned.');
    runtimeLogTestAssert($hours['latest_at'] === '2026-09-16T09:15:00+02:00', 'Latest timestamp should cover all valid event types.');

    $dateSamples = optimizerRuntimeLogRead($path, $timezone, $now, 'samples', 'date', '2026-09-16', 1);
    runtimeLogTestAssert($dateSamples['matching_count'] === 2, 'Date filtering should use installation-local dates.');
    runtimeLogTestAssert(count($dateSamples['events']) === 1, 'The result limit should retain only one event.');
    runtimeLogTestAssert($dateSamples['events'][0]['actual_soc'] === 55, 'The result limit should retain the newest matching event.');

    $all = optimizerRuntimeLogRead($path, $timezone, $now, 'all', 'all', null, 250);
    runtimeLogTestAssert($all['matching_count'] === 6, 'All history should include all recognized runtime event types.');
} finally {
    unlink($path);
}

echo "Optimizer runtime log reader tests passed.\n";
