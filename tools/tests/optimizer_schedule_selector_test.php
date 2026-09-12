<?php

declare(strict_types=1);

$temporary = sys_get_temp_dir() . '/zendure-optimizer-selector-' . bin2hex(random_bytes(5));
mkdir($temporary, 0775, true);
putenv('OPTIMIZER_SCHEDULE_LATEST_PATH=' . $temporary . '/latest.json');
putenv('OPTIMIZER_SCHEDULE_MODE_PATH=' . $temporary . '/mode.json');
putenv('OPTIMIZER_SCHEDULE_AUDIT_PATH=' . $temporary . '/audit.log');

require_once dirname(__DIR__, 2) . '/app/includes/optimizer_schedule.php';

function selectorAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$timezone = new DateTimeZone('Europe/Amsterdam');
$now = new DateTimeImmutable('2026-09-12T18:30:00+02:00');
$systemConfig = ['schedule' => ['minPowerW' => -1800, 'maxPowerW' => 1200, 'powerStepW' => 100]];
$payload = [
    'type' => 'optimizer_executable_schedule',
    'version' => 1,
    'plan' => [
        'generated_at' => '2026-09-12T18:25:00+02:00',
        'horizon_start' => '2026-09-12T18:25:00+02:00',
        'horizon_end' => '2026-09-12T20:00:00+02:00',
        'decisions' => [
            ['start' => '2026-09-12T18:25:00+02:00', 'end' => '2026-09-12T19:00:00+02:00', 'schedule_value' => 1200, 'reason' => 'cheap'],
            ['start' => '2026-09-12T19:00:00+02:00', 'end' => '2026-09-12T20:00:00+02:00', 'schedule_value' => 'netzero-', 'min_power' => -900, 'max_power' => 0, 'reason' => 'load'],
        ],
    ],
];
selectorAssert(optimizerScheduleWriteJsonAtomic(optimizerScheduleLatestPath(), $payload), 'Latest plan write failed.');
selectorAssert(optimizerScheduleWriteJsonAtomic(optimizerScheduleModePath(), ['requested_mode' => 'optimizer']), 'Mode write failed.');
selectorAssert(optimizerScheduleValidateLatest($now, $systemConfig)['valid'] === true, 'A valid plan was rejected.');
selectorAssert(optimizerScheduleStatus($now, $systemConfig)['activeSource'] === 'optimizer', 'Optimizer was not selected.');

$sixtyMinutePlan = $payload;
$sixtyMinutePlan['plan']['generated_at'] = '2026-09-12T17:30:00+02:00';
optimizerScheduleWriteJsonAtomic(optimizerScheduleLatestPath(), $sixtyMinutePlan);
selectorAssert(optimizerScheduleStatus($now, $systemConfig)['activeSource'] === 'optimizer', 'A 60-minute-old plan should remain active during dual testing.');
optimizerScheduleWriteJsonAtomic(optimizerScheduleLatestPath(), $payload);

$rules = [
    ['time' => '1800', 'value' => 'netzero', 'key' => '********1800'],
    ['time' => '1900', 'value' => 0, 'key' => '202609121900'],
];
$resolved = optimizerScheduleApplyToDay($rules, '20260912', $payload, $timezone);
selectorAssert($resolved[0]['value'] === 1200 && $resolved[0]['source'] === 'optimizer', 'Wildcard rule was not replaced.');
selectorAssert($resolved[1]['value'] === 0 && $resolved[1]['key'] === '202609121900', 'Exact manual override did not win.');

$stale = $payload;
$stale['plan']['generated_at'] = '2026-09-12T17:00:00+02:00';
optimizerScheduleWriteJsonAtomic(optimizerScheduleLatestPath(), $stale);
$fallback = optimizerScheduleStatus($now, $systemConfig);
selectorAssert($fallback['activeSource'] === 'rules' && $fallback['fallbackActive'] === true, 'Stale optimizer plan did not fall back to rules.');

array_map('unlink', glob($temporary . '/*') ?: []);
rmdir($temporary);
echo "Optimizer schedule selector tests passed.\n";
