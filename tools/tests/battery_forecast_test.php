<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/main/data/target_battery_planner.php';

function forecastTestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function forecastTestNear(float $actual, float $expected, float $tolerance = 0.000001): void
{
    forecastTestAssert(abs($actual - $expected) <= $tolerance, "{$actual} is not within {$tolerance} of {$expected}");
}

function forecastTestDay(string $date, $value = 0): array
{
    $items = [];
    for ($hour = 0; $hour < 24; $hour++) {
        $items[] = ['time' => sprintf('%02d00', $hour), 'value' => $value];
    }
    return ['date' => $date, 'items' => $items];
}

$config = tbp_system_config();
$usage = $config['forecast']['defaultHouseholdUsageWByHour'];
$efficiency = (float) $config['battery']['efficiency'];
$timezone = new DateTimeZone('Europe/Amsterdam');
$date = '20260805';
$battery = [
    'percent' => 50.0,
    'capacity_wh' => 5760.0,
    'minimum_percent' => 15.0,
    'maximum_percent' => 91.0,
];
$options = ['usage_w_by_hour' => $usage, 'efficiency' => $efficiency];

$dischargeForecast = tbp_build_hourly_forecast(
    [forecastTestDay($date, 'netzero-')],
    $battery,
    new DateTimeImmutable('2026-08-05 07:30:00', $timezone),
    $options
);
$current = $dischargeForecast[$date . '0700'];
$next = $dischargeForecast[$date . '0800'];
forecastTestNear((float) $current['durationHours'], 0.5);
forecastTestNear((float) $current['estimatedPowerW'], -100.0);
forecastTestNear((float) $current['endPercent'], 50.0 - ((100.0 * 0.5 / $efficiency) / 5760.0) * 100.0);
forecastTestNear((float) $next['startPercent'], (float) $current['endPercent']);
forecastTestAssert($current['source'] === 'household_profile', 'NZ- must use the household profile.');
forecastTestAssert($current['currentHour'] === true, 'Partial current hour must be identified.');

$fixedDay = forecastTestDay($date);
$fixedDay['items'][10] = ['time' => '1000', 'value' => -500];
$fixed = tbp_build_hourly_forecast(
    [$fixedDay],
    $battery,
    new DateTimeImmutable('2026-08-05 10:00:00', $timezone),
    $options
);
forecastTestAssert($fixed[$date . '1000']['estimatedPowerW'] === -500.0, 'Fixed power must be forecast exactly.');
forecastTestAssert($fixed[$date . '1000']['source'] === 'scheduled_power', 'Fixed power source must be reported.');

$neutralDay = forecastTestDay($date, 'netzero');
$neutralDay['items'][13] = ['time' => '1300', 'value' => 'netzero', 'min_power' => 300, 'max_power' => 800];
$neutral = tbp_build_hourly_forecast(
    [$neutralDay],
    $battery,
    new DateTimeImmutable('2026-08-05 12:00:00', $timezone),
    $options
);
forecastTestAssert($neutral[$date . '1200']['estimatedPowerW'] === 0.0, 'Unbounded NZ± must be neutral.');
forecastTestAssert($neutral[$date . '1200']['source'] === 'bidirectional_neutral', 'Neutral source must be reported.');
forecastTestAssert($neutral[$date . '1300']['estimatedPowerW'] === 300.0, 'NZ± minimum must apply to the neutral baseline.');

$runtimeDay = forecastTestDay($date);
$runtimeDay['items'][12] = [
    'time' => '1200',
    'value' => 'netzero-',
    'runtime_conditions' => [['field' => 'electricity_level', 'op' => '>', 'value' => 30]],
    'fallback_value' => 0,
];
$runtime = tbp_build_hourly_forecast(
    [$runtimeDay],
    [...$battery, 'percent' => 31.0],
    new DateTimeImmutable('2026-08-05 12:00:00', $timezone),
    $options
);
$runtimeHour = $runtime[$date . '1200'];
forecastTestAssert($runtimeHour['estimatedPowerW'] === 0.0, 'Runtime uncertainty must use least guaranteed discharge.');
forecastTestAssert($runtimeHour['source'] === 'runtime_condition_conservative', 'Runtime forecast source must be explicit.');
forecastTestAssert($runtimeHour['primaryPowerW'] === -220.0, 'Runtime primary power must be exposed.');
forecastTestAssert($runtimeHour['fallbackPowerW'] === 0.0, 'Runtime fallback power must be exposed.');

$targetDay = forecastTestDay($date);
$targetDay['items'][12] = [
    'time' => '1200',
    'value' => -900,
    'runtime_conditions' => [['field' => 'electricity_level', 'op' => '>', 'value' => 50]],
    'fallback_value' => 'netzero-',
    'planning' => ['mode' => TARGET_BATTERY_MODE],
];
$target = tbp_build_hourly_forecast(
    [$targetDay],
    $battery,
    new DateTimeImmutable('2026-08-05 12:00:00', $timezone),
    $options
);
forecastTestAssert($target[$date . '1200']['estimatedPowerW'] === -900.0, 'Calculated targets must use their deterministic planned action.');

$chargeDay = forecastTestDay($date);
$chargeDay['items'][14] = ['time' => '1400', 'value' => 'netzero+', 'min_power' => 100, 'max_power' => 400];
$charge = tbp_build_hourly_forecast(
    [$chargeDay],
    $battery,
    new DateTimeImmutable('2026-08-05 14:00:00', $timezone),
    $options
);
forecastTestAssert($charge[$date . '1400']['estimatedPowerW'] === 100.0, 'NZ+ minimum must be applied by the authoritative forecaster.');
forecastTestNear((float) $charge[$date . '1400']['endPercent'], 50.0 + ((100.0 * $efficiency) / 5760.0) * 100.0);

echo "Authoritative battery forecast tests passed.\n";
