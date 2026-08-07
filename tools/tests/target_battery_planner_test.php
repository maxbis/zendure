<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/main/data/target_battery_planner.php';

function plannerTestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function plannerTestDays(): array
{
    $days = [];
    foreach (['20260805', '20260806'] as $date) {
        $items = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $items[] = ['time' => sprintf('%02d00', $hour), 'value' => 0];
        }
        $days[] = ['date' => $date, 'items' => $items];
    }
    $days[0]['items'][20] = [
        'time' => '2000',
        'value' => TARGET_BATTERY_MODE,
        'target_soc_percent' => 15,
        'fallback_value' => 'netzero-',
        'rule_name' => 'Empty at solar',
    ];
    for ($hour = 21; $hour < 24; $hour++) {
        $days[0]['items'][$hour]['value'] = 'netzero-';
    }
    for ($hour = 0; $hour < 8; $hour++) {
        $days[1]['items'][$hour]['value'] = 'netzero-';
    }
    $days[1]['items'][8]['value'] = 'netzero+';
    return $days;
}

function plannerChargeTestDays(): array
{
    $days = [];
    foreach (['20260805', '20260806'] as $date) {
        $items = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $items[] = ['time' => sprintf('%02d00', $hour), 'value' => 0];
        }
        $days[] = ['date' => $date, 'items' => $items];
    }
    foreach ([10, 11, 13] as $hour) {
        $days[0]['items'][$hour] = [
            'time' => sprintf('%02d00', $hour),
            'value' => TARGET_CHARGE_MODE,
            'target_anchor' => TARGET_CHARGE_ANCHOR,
            'rule_id' => 'rule-cheap-solar',
            'rule_name' => 'Fill before discharge',
        ];
    }
    $days[0]['items'][15]['value'] = 'netzero-';
    return $days;
}

$timezone = new DateTimeZone('Europe/Amsterdam');
$now = new DateTimeImmutable('2026-08-05 18:00:00', $timezone);
$battery = [
    'percent' => 60.0,
    'capacity_wh' => 5760.0,
    'minimum_percent' => 15.0,
    'maximum_percent' => 91.0,
];

$planned = tbp_materialize_horizon(plannerTestDays(), $battery, $now, ['max_discharge_power_w' => 1600]);
$targetSlot = $planned[0]['items'][20];
plannerTestAssert(is_int($targetSlot['value']), 'Target mode must materialize to integer watts.');
plannerTestAssert($targetSlot['value'] < 0, 'Target mode must calculate discharge power.');
plannerTestAssert($targetSlot['value'] === -900, 'Calculated discharge power must round to the nearest 100 W.');
plannerTestAssert($targetSlot['planning']['calculated_power_w'] === -900, 'Planning metadata must report the rounded discharge power.');
plannerTestAssert($targetSlot['planning']['power_step_w'] === 100, 'Planning metadata must report the 100 W discharge step.');
plannerTestAssert($targetSlot['planning']['status'] === 'achievable', 'Expected an achievable target.');
plannerTestAssert($targetSlot['planning']['anchor_date'] === '20260806', 'Expected tomorrow solar-charge anchor.');
plannerTestAssert($targetSlot['planning']['anchor_time'] === '0800', 'Expected 08:00 solar-charge anchor.');
plannerTestAssert($targetSlot['planning']['predicted_anchor_soc_percent'] <= 15.3, 'Prediction should reach target tolerance.');

$bidirectionalDays = plannerTestDays();
$bidirectionalDays[1]['items'][8]['value'] = 'netzero';
$bidirectional = tbp_materialize_horizon($bidirectionalDays, $battery, $now, ['max_discharge_power_w' => 1600]);
plannerTestAssert($bidirectional[0]['items'][20]['planning']['anchor_time'] === '0800', 'Unrestricted NZ± must qualify as a solar-charge anchor.');

$boundedBidirectionalDays = plannerTestDays();
$boundedBidirectionalDays[1]['items'][8] = ['time' => '0800', 'value' => 'netzero', 'max_power' => 400];
$boundedBidirectional = tbp_materialize_horizon($boundedBidirectionalDays, $battery, $now, ['max_discharge_power_w' => 1600]);
plannerTestAssert($boundedBidirectional[0]['items'][20]['planning']['anchor_time'] === '0800', 'NZ± with a positive maximum must qualify as a solar-charge anchor.');

$chargeTargetAnchorDays = plannerTestDays();
$chargeTargetAnchorDays[1]['items'][8] = [
    'time' => '0800',
    'value' => TARGET_CHARGE_MODE,
    'rule_id' => 'future-charge-target',
];
$chargeTargetAnchor = tbp_materialize_horizon($chargeTargetAnchorDays, $battery, $now, ['max_discharge_power_w' => 1600]);
plannerTestAssert($chargeTargetAnchor[0]['items'][20]['planning']['anchor_time'] === '0800', 'Target @ next NZ- must qualify as a solar-charge anchor before it materializes to NZ+.');
plannerTestAssert($chargeTargetAnchor[1]['items'][8]['value'] === 'netzero+', 'Target @ next NZ- must still materialize to NZ+.');

$dischargeOnlyDays = plannerTestDays();
$dischargeOnlyDays[1]['items'][8] = ['time' => '0800', 'value' => 'netzero', 'max_power' => 0];
$dischargeOnly = tbp_materialize_horizon($dischargeOnlyDays, $battery, $now);
plannerTestAssert($dischargeOnly[0]['items'][20]['value'] === 'netzero-', 'Discharge-only NZ± must not be used as a solar-charge anchor.');
plannerTestAssert($dischargeOnly[0]['items'][20]['planning']['status'] === 'unavailable', 'Discharge-only NZ± must leave the target anchor unavailable.');

$limitedDays = plannerTestDays();
$limitedDays[0]['items'][20]['max_discharge_power'] = 300;
$limited = tbp_materialize_horizon($limitedDays, $battery, $now, ['max_discharge_power_w' => 1600]);
plannerTestAssert($limited[0]['items'][20]['value'] === -300, 'Rule discharge cap must be applied.');
plannerTestAssert($limited[0]['items'][20]['planning']['status'] === 'best_effort', 'Capped target should report best effort.');

$nonSteppedLimitDays = plannerTestDays();
$nonSteppedLimitDays[0]['items'][20]['max_discharge_power'] = 350;
$nonSteppedLimit = tbp_materialize_horizon($nonSteppedLimitDays, $battery, $now, ['max_discharge_power_w' => 1600]);
plannerTestAssert($nonSteppedLimit[0]['items'][20]['value'] === -300, 'A non-stepped discharge cap must use the highest allowed 100 W multiple.');

$partialNow = new DateTimeImmutable('2026-08-05 20:30:00', $timezone);
$partial = tbp_materialize_horizon(plannerTestDays(), $battery, $partialNow, ['max_discharge_power_w' => 1600]);
plannerTestAssert($partial[0]['items'][20]['value'] === -1600, 'Partial current target hour should use the remaining duration and clamp safely.');
plannerTestAssert($partial[0]['items'][20]['planning']['status'] === 'best_effort', 'Insufficient partial hour should report best effort.');

$minimumBattery = $battery;
$minimumBattery['percent'] = 15.0;
$alreadySatisfied = tbp_materialize_horizon(plannerTestDays(), $minimumBattery, $now);
plannerTestAssert($alreadySatisfied[0]['items'][20]['value'] === 'netzero-', 'Already-satisfied target should keep the fallback action.');
plannerTestAssert($alreadySatisfied[0]['items'][20]['planning']['status'] === 'already_satisfied', 'Minimum battery should report an already-satisfied target.');

$unavailable = tbp_materialize_horizon(plannerTestDays(), null, $now);
plannerTestAssert($unavailable[0]['items'][20]['value'] === 'netzero-', 'Missing battery must use safe fallback.');
plannerTestAssert($unavailable[0]['items'][20]['planning']['status'] === 'unavailable', 'Missing battery should be unavailable.');

$noAnchorDays = plannerTestDays();
$noAnchorDays[1]['items'][8]['value'] = 0;
$noAnchor = tbp_materialize_horizon($noAnchorDays, $battery, $now);
plannerTestAssert($noAnchor[0]['items'][20]['value'] === 'netzero-', 'Missing solar-charge anchor must use fallback.');
plannerTestAssert($noAnchor[0]['items'][20]['planning']['status'] === 'unavailable', 'Missing solar-charge anchor should be unavailable.');

$chargeBattery = [
    'percent' => 60.0,
    'capacity_wh' => 10000.0,
    'minimum_percent' => 15.0,
    'maximum_percent' => 90.0,
];
$chargeNow = new DateTimeImmutable('2026-08-05 09:30:00', $timezone);
$chargePlanned = tbp_materialize_horizon(plannerChargeTestDays(), $chargeBattery, $chargeNow, [
    'max_charge_power_w' => 1600,
    'charge_power_step_w' => 100,
]);
foreach ([10, 11, 13] as $hour) {
    $slot = $chargePlanned[0]['items'][$hour];
    plannerTestAssert($slot['value'] === 'netzero+', 'Target charge slots must materialize to NZ+.');
    plannerTestAssert($slot['min_power'] === 1000, 'Three eligible hours must share a 1000 W minimum.');
    plannerTestAssert($slot['max_power'] === 1600, 'Configured maximum charge power must be emitted.');
    plannerTestAssert($slot['planning']['anchor_time'] === '1500', 'Expected the first future NZ- anchor.');
    plannerTestAssert($slot['planning']['remaining_eligible_hours'] === 3.0, 'Only matching rule slots may count as eligible time.');
}

$partialChargeNow = new DateTimeImmutable('2026-08-05 10:30:00', $timezone);
$partialCharge = tbp_materialize_horizon(plannerChargeTestDays(), $chargeBattery, $partialChargeNow, [
    'max_charge_power_w' => 1600,
]);
plannerTestAssert($partialCharge[0]['items'][10]['min_power'] === 1200, 'Partial current hour must reduce remaining duration to 2.5 hours.');
plannerTestAssert($partialCharge[0]['items'][11]['min_power'] === 1200, 'All remaining grouped slots must receive the recalculated minimum.');

$roundedBattery = $chargeBattery;
$roundedBattery['percent'] = 70.0;
$roundedBattery['capacity_wh'] = 5760.0;
$roundedCharge = tbp_materialize_horizon(plannerChargeTestDays(), $roundedBattery, $chargeNow, [
    'max_charge_power_w' => 1600,
]);
plannerTestAssert($roundedCharge[0]['items'][10]['min_power'] === 400, 'Calculated charge minimum must round upward to the next 100 W.');

$fullBattery = $chargeBattery;
$fullBattery['percent'] = 90.0;
$alreadyFull = tbp_materialize_horizon(plannerChargeTestDays(), $fullBattery, $chargeNow, [
    'max_charge_power_w' => 1600,
]);
plannerTestAssert($alreadyFull[0]['items'][10]['value'] === 'netzero+', 'An already-full target must remain charge-only.');
plannerTestAssert($alreadyFull[0]['items'][10]['min_power'] === 0, 'An already-full target must not force grid charging.');
plannerTestAssert($alreadyFull[0]['items'][10]['planning']['status'] === 'already_satisfied', 'An already-full target must report satisfied.');

$limitedChargeBattery = $chargeBattery;
$limitedChargeBattery['percent'] = 15.0;
$limitedCharge = tbp_materialize_horizon(plannerChargeTestDays(), $limitedChargeBattery, $chargeNow, [
    'max_charge_power_w' => 800,
]);
plannerTestAssert($limitedCharge[0]['items'][10]['min_power'] === 800, 'Required charge must clamp to configured maximum power.');
plannerTestAssert($limitedCharge[0]['items'][10]['planning']['status'] === 'best_effort', 'A clamped charge target must report best effort.');

$unavailableCharge = tbp_materialize_horizon(plannerChargeTestDays(), null, $chargeNow, [
    'max_charge_power_w' => 1600,
]);
plannerTestAssert($unavailableCharge[0]['items'][10]['value'] === 'netzero+', 'Missing live battery data must use safe NZ+ fallback.');
plannerTestAssert(!array_key_exists('min_power', $unavailableCharge[0]['items'][10]), 'Unavailable charge target must not force a guessed minimum.');

$noMinusDays = plannerChargeTestDays();
$noMinusDays[0]['items'][15]['value'] = 0;
$noMinus = tbp_materialize_horizon($noMinusDays, $chargeBattery, $chargeNow, ['max_charge_power_w' => 1600]);
plannerTestAssert($noMinus[0]['items'][10]['value'] === 'netzero+', 'Missing NZ- must use safe NZ+ fallback.');
plannerTestAssert($noMinus[0]['items'][10]['planning']['status'] === 'unavailable', 'Missing NZ- must report unavailable.');

echo "target battery planner: OK\n";
