<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/common/php/system_config.php';

const TARGET_BATTERY_MODE = 'empty_at_solar_charge';
const TARGET_BATTERY_ANCHOR = 'next_solar_capable_netzero';
const TARGET_CHARGE_MODE = 'full_at_netzero_minus';
const TARGET_CHARGE_ANCHOR = 'next_netzero_minus';

function tbp_system_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = loadSystemConfig();
    }
    return $config;
}

function tbp_shared_power_step_w(): int
{
    return (int) tbp_system_config()['schedule']['powerStepW'];
}

function tbp_clamp(float $value, float $minimum, float $maximum): float
{
    return min($maximum, max($minimum, $value));
}

function tbp_normalize_mode($value): string
{
    return strtolower(trim((string) ($value ?? 'auto')));
}

function tbp_fallback_value(array $slot)
{
    if (array_key_exists('fallback_value', $slot)) {
        return $slot['fallback_value'];
    }
    return ($slot['value'] ?? null) === TARGET_CHARGE_MODE ? 'netzero+' : 'netzero-';
}

function tbp_slot_datetime(string $date, string $time, DateTimeZone $timezone): ?DateTimeImmutable
{
    $normalizedTime = str_pad(preg_replace('/\D/', '', $time) ?? '', 4, '0', STR_PAD_LEFT);
    $dateTime = DateTimeImmutable::createFromFormat('!Ymd Hi', $date . ' ' . $normalizedTime, $timezone);
    return $dateTime instanceof DateTimeImmutable ? $dateTime : null;
}

/** @return array<int, array{date:string,time:string,start:DateTimeImmutable,end:DateTimeImmutable,slot:array}> */
function tbp_flatten_days(array $days, DateTimeZone $timezone): array
{
    $flat = [];
    foreach ($days as $day) {
        if (!is_array($day) || !isset($day['date']) || !isset($day['items']) || !is_array($day['items'])) {
            continue;
        }
        foreach ($day['items'] as $slot) {
            if (!is_array($slot) || !isset($slot['time'])) {
                continue;
            }
            $time = str_pad((string) $slot['time'], 4, '0', STR_PAD_LEFT);
            $start = tbp_slot_datetime((string) $day['date'], $time, $timezone);
            if ($start === null) {
                continue;
            }
            $flat[] = [
                'date' => (string) $day['date'],
                'time' => $time,
                'start' => $start,
                'end' => $start->modify('+1 hour'),
                'slot' => $slot,
            ];
        }
    }
    usort($flat, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);
    foreach ($flat as $index => &$entry) {
        $next = $flat[$index + 1]['start'] ?? null;
        if ($next instanceof DateTimeImmutable && $next > $entry['start'] && $next < $entry['end']) {
            $entry['end'] = $next;
        }
    }
    unset($entry);
    return $flat;
}

function tbp_runtime_condition_matches(array $condition, float $batteryPercent): bool
{
    $field = (string) ($condition['field'] ?? '');
    if (!in_array($field, ['electricity_level', 'electric_level', 'electricLevel'], true)) {
        return true;
    }
    if (!isset($condition['value']) || !is_numeric($condition['value'])) {
        return true;
    }
    $expected = (float) $condition['value'];
    return match ((string) ($condition['op'] ?? '==')) {
        '>' => $batteryPercent > $expected,
        '>=' => $batteryPercent >= $expected,
        '<' => $batteryPercent < $expected,
        '<=' => $batteryPercent <= $expected,
        '!=' => $batteryPercent !== $expected,
        default => $batteryPercent === $expected,
    };
}

function tbp_effective_value(array $slot, float $batteryPercent)
{
    $conditions = isset($slot['runtime_conditions']) && is_array($slot['runtime_conditions'])
        ? $slot['runtime_conditions']
        : [];
    foreach ($conditions as $condition) {
        if (is_array($condition) && !tbp_runtime_condition_matches($condition, $batteryPercent)) {
            return tbp_fallback_value($slot);
        }
    }
    if (($slot['value'] ?? null) === TARGET_BATTERY_MODE) {
        return tbp_fallback_value($slot);
    }
    return $slot['value'] ?? 0;
}

function tbp_power_for_slot(array $slot, int $hour, float $batteryPercent, array $usageByHour): float
{
    $usesPrimaryValue = ($slot['value'] ?? null) !== TARGET_BATTERY_MODE;
    $conditions = isset($slot['runtime_conditions']) && is_array($slot['runtime_conditions'])
        ? $slot['runtime_conditions']
        : [];
    foreach ($conditions as $condition) {
        if (is_array($condition) && !tbp_runtime_condition_matches($condition, $batteryPercent)) {
            $usesPrimaryValue = false;
            break;
        }
    }
    $value = tbp_effective_value($slot, $batteryPercent);
    if (is_numeric($value)) {
        return (float) $value;
    }
    $mode = tbp_normalize_mode($value);
    if (in_array($mode, ['netzero', 'netzero-', 'netzero+'], true)) {
        $power = 0.0;
        if ($mode !== 'netzero+') {
            $usage = isset($usageByHour[$hour]) && is_numeric($usageByHour[$hour])
                ? max(0.0, (float) $usageByHour[$hour])
                : 0.0;
            $power = -$usage;
        }
        if ($usesPrimaryValue) {
            if (isset($slot['min_power']) && is_numeric($slot['min_power'])) {
                $power = max($power, (float) $slot['min_power']);
            }
            if (isset($slot['max_power']) && is_numeric($slot['max_power'])) {
                $power = min($power, (float) $slot['max_power']);
            }
        }
        return $power;
    }
    return 0.0;
}

function tbp_apply_power(
    float $batteryPercent,
    float $powerW,
    float $durationHours,
    float $capacityWh,
    float $efficiency,
    float $minimumPercent,
    float $maximumPercent
): float {
    if ($durationHours <= 0 || $capacityWh <= 0) {
        return $batteryPercent;
    }
    $deltaPercent = $powerW < 0
        ? -(abs($powerW) * $durationHours / $efficiency / $capacityWh) * 100
        : ($powerW * $durationHours * $efficiency / $capacityWh) * 100;
    return tbp_clamp($batteryPercent + $deltaPercent, $minimumPercent, $maximumPercent);
}

function tbp_forecast_to_index(
    array $flat,
    int $endIndex,
    DateTimeImmutable $now,
    array $battery,
    array $usageByHour,
    float $efficiency
): float {
    $percent = (float) $battery['percent'];
    for ($index = 0; $index < $endIndex; $index++) {
        $entry = $flat[$index];
        if ($entry['end'] <= $now) {
            continue;
        }
        $segmentStart = $entry['start'] < $now ? $now : $entry['start'];
        if ($segmentStart >= $entry['end']) {
            continue;
        }
        $durationHours = ($entry['end']->getTimestamp() - $segmentStart->getTimestamp()) / 3600;
        $powerW = tbp_power_for_slot($entry['slot'], (int) $entry['start']->format('G'), $percent, $usageByHour);
        $percent = tbp_apply_power(
            $percent,
            $powerW,
            $durationHours,
            (float) $battery['capacity_wh'],
            $efficiency,
            (float) $battery['minimum_percent'],
            (float) $battery['maximum_percent']
        );
    }
    return $percent;
}

function tbp_slot_allows_solar_charge(array $slot): bool
{
    $mode = tbp_normalize_mode($slot['value'] ?? null);
    if ($mode === 'netzero+' || $mode === TARGET_CHARGE_MODE) {
        return true;
    }
    if ($mode !== 'netzero') {
        return false;
    }
    return !isset($slot['max_power'])
        || !is_numeric($slot['max_power'])
        || (float) $slot['max_power'] > 0;
}

function tbp_find_next_solar_charge(array $flat, int $targetIndex, DateTimeImmutable $now): ?int
{
    for ($index = $targetIndex + 1, $count = count($flat); $index < $count; $index++) {
        if ($flat[$index]['start'] <= $now) {
            continue;
        }
        if (tbp_slot_allows_solar_charge($flat[$index]['slot'])) {
            return $index;
        }
    }
    return null;
}

function tbp_find_next_netzero_minus(array $flat, int $targetIndex, DateTimeImmutable $now): ?int
{
    for ($index = $targetIndex + 1, $count = count($flat); $index < $count; $index++) {
        if ($flat[$index]['start'] <= $now) {
            continue;
        }
        if (tbp_normalize_mode($flat[$index]['slot']['value'] ?? null) === 'netzero-') {
            return $index;
        }
    }
    return null;
}

function tbp_target_charge_group_key(array $slot): string
{
    foreach (['rule_id', 'rule_index', 'rule_name'] as $field) {
        if (isset($slot[$field]) && trim((string) $slot[$field]) !== '') {
            return $field . ':' . trim((string) $slot[$field]);
        }
    }
    return 'anonymous-target-charge';
}

function tbp_round_charge_minimum(float $powerW, int $maximumPowerW, ?int $stepW = null): int
{
    if ($powerW <= 0 || $maximumPowerW <= 0) {
        return 0;
    }
    $stepW = max(1, $stepW ?? tbp_shared_power_step_w());
    $rounded = (int) (ceil($powerW / $stepW) * $stepW);
    return min($maximumPowerW, $rounded);
}

function tbp_round_discharge_power(float $powerW, int $maximumPowerW, ?int $stepW = null): int
{
    if ($powerW >= 0 || $maximumPowerW <= 0) {
        return 0;
    }
    $stepW = max(1, $stepW ?? tbp_shared_power_step_w());
    $roundedMagnitude = (int) (round(abs($powerW) / $stepW) * $stepW);
    $maximumSteppedMagnitude = (int) (floor($maximumPowerW / $stepW) * $stepW);
    return -min($roundedMagnitude, $maximumSteppedMagnitude);
}

function tbp_target_charge_metadata(
    array $entry,
    ?array $anchor,
    float $targetPercent,
    string $status,
    ?float $currentPercent = null,
    ?float $remainingDurationHours = null,
    ?float $requiredEnergyWh = null,
    ?int $calculatedMinimumW = null,
    ?int $maximumPowerW = null,
    ?string $reason = null,
    array $forecast = []
): array {
    $metadata = [
        'mode' => TARGET_CHARGE_MODE,
        'target_soc_percent' => round($targetPercent, 1),
        'target_anchor' => TARGET_CHARGE_ANCHOR,
        'status' => $status,
        'rule_date' => $entry['date'],
        'rule_time' => $entry['time'],
        'power_step_w' => tbp_shared_power_step_w(),
    ];
    if ($anchor !== null) {
        $metadata['anchor_date'] = $anchor['date'];
        $metadata['anchor_time'] = $anchor['time'];
    }
    if ($currentPercent !== null) {
        $metadata['current_soc_percent'] = round($currentPercent, 1);
    }
    if ($remainingDurationHours !== null) {
        $metadata['remaining_eligible_hours'] = round($remainingDurationHours, 3);
    }
    if ($requiredEnergyWh !== null) {
        $metadata['required_energy_wh'] = round($requiredEnergyWh, 1);
    }
    if ($calculatedMinimumW !== null) {
        $metadata['calculated_min_power_w'] = $calculatedMinimumW;
    }
    if ($maximumPowerW !== null) {
        $metadata['max_power_w'] = $maximumPowerW;
    }
    if ($reason !== null) {
        $metadata['reason'] = $reason;
    }
    foreach ([
        'predicted_start_soc_percent',
        'baseline_anchor_soc_percent',
        'predicted_anchor_soc_percent',
        'efficiency',
    ] as $field) {
        if (isset($forecast[$field]) && is_numeric($forecast[$field])) {
            $metadata[$field] = round((float) $forecast[$field], 3);
        }
    }
    if (isset($forecast['calculated_at']) && is_string($forecast['calculated_at'])) {
        $metadata['calculated_at'] = $forecast['calculated_at'];
    }
    return $metadata;
}

function tbp_planning_metadata(
    array $entry,
    ?array $anchor,
    float $targetPercent,
    string $status,
    ?float $baselinePercent = null,
    ?float $predictedPercent = null,
    ?int $calculatedPowerW = null,
    ?string $reason = null
): array {
    $metadata = [
        'mode' => TARGET_BATTERY_MODE,
        'target_soc_percent' => $targetPercent,
        'target_anchor' => TARGET_BATTERY_ANCHOR,
        'status' => $status,
        'rule_date' => $entry['date'],
        'rule_time' => $entry['time'],
        'power_step_w' => tbp_shared_power_step_w(),
    ];
    if ($anchor !== null) {
        $metadata['anchor_date'] = $anchor['date'];
        $metadata['anchor_time'] = $anchor['time'];
    }
    if ($baselinePercent !== null) {
        $metadata['baseline_anchor_soc_percent'] = round($baselinePercent, 1);
    }
    if ($predictedPercent !== null) {
        $metadata['predicted_anchor_soc_percent'] = round($predictedPercent, 1);
    }
    if ($calculatedPowerW !== null) {
        $metadata['calculated_power_w'] = $calculatedPowerW;
    }
    if ($reason !== null) {
        $metadata['reason'] = $reason;
    }
    return $metadata;
}

/**
 * Materialize every target rule to an existing schedule value.
 *
 * @param array<int, array{date:string,items:array}> $days
 * @param array{percent:float,capacity_wh:float,minimum_percent:float,maximum_percent:float}|null $battery
 * @return array<int, array{date:string,items:array}>
 */
function tbp_materialize_horizon(
    array $days,
    ?array $battery,
    DateTimeImmutable $now,
    array $options = []
): array {
    $sharedConfig = tbp_system_config();
    $timezone = $now->getTimezone();
    $flat = tbp_flatten_days($days, $timezone);
    $usageByHour = isset($options['usage_w_by_hour']) && is_array($options['usage_w_by_hour'])
        ? $options['usage_w_by_hour']
        : $sharedConfig['forecast']['defaultHouseholdUsageWByHour'];
    $efficiency = isset($options['efficiency']) && is_numeric($options['efficiency'])
        ? tbp_clamp((float) $options['efficiency'], 0.01, 1.0)
        : (float) $sharedConfig['battery']['efficiency'];
    $defaultMaxDischargeW = isset($options['max_discharge_power_w']) && is_numeric($options['max_discharge_power_w'])
        ? max(1, (int) $options['max_discharge_power_w'])
        : abs((int) $sharedConfig['schedule']['minPowerW']);

    foreach ($flat as $targetIndex => &$entry) {
        if (($entry['slot']['value'] ?? null) !== TARGET_BATTERY_MODE) {
            continue;
        }
        $targetPercent = isset($entry['slot']['target_soc_percent']) && is_numeric($entry['slot']['target_soc_percent'])
            ? (float) $entry['slot']['target_soc_percent']
            : ($battery !== null ? (float) $battery['minimum_percent'] : 15.0);
        $fallback = tbp_fallback_value($entry['slot']);
        $anchorIndex = tbp_find_next_solar_charge($flat, $targetIndex, $now);
        $anchor = $anchorIndex !== null ? $flat[$anchorIndex] : null;

        if ($entry['end'] <= $now) {
            $entry['slot']['value'] = $fallback;
            $entry['slot']['planning'] = tbp_planning_metadata($entry, $anchor, $targetPercent, 'past', null, null, null, 'Rule hour has already ended.');
            continue;
        }
        if ($battery === null) {
            $entry['slot']['value'] = $fallback;
            $entry['slot']['planning'] = tbp_planning_metadata($entry, $anchor, $targetPercent, 'unavailable', null, null, null, 'Live battery level is unavailable.');
            continue;
        }
        if ($anchorIndex === null) {
            $entry['slot']['value'] = $fallback;
            $entry['slot']['planning'] = tbp_planning_metadata($entry, null, $targetPercent, 'unavailable', null, null, null, 'No future NZ+ or charging-capable NZ± slot was found in the loaded schedule through tomorrow. Prices may still be available because price data does not define the solar-charge anchor.');
            continue;
        }

        $targetPercent = tbp_clamp($targetPercent, (float) $battery['minimum_percent'], (float) $battery['maximum_percent']);
        $baselinePercent = tbp_forecast_to_index($flat, $anchorIndex, $now, $battery, $usageByHour, $efficiency);
        $segmentStart = $entry['start'] < $now ? $now : $entry['start'];
        $durationHours = max(0.0, ($entry['end']->getTimestamp() - $segmentStart->getTimestamp()) / 3600);

        if ($durationHours <= 0 || $baselinePercent <= $targetPercent + 0.05) {
            $entry['slot']['value'] = $fallback;
            $status = $baselinePercent <= $targetPercent + 0.05 ? 'already_satisfied' : 'unavailable';
            $reason = $status === 'already_satisfied'
                ? 'Baseline forecast is already at or below the requested target.'
                : 'No usable time remains in the rule slot.';
            $entry['slot']['planning'] = tbp_planning_metadata($entry, $anchor, $targetPercent, $status, $baselinePercent, $baselinePercent, null, $reason);
            continue;
        }

        $baselinePowerW = tbp_power_for_slot($entry['slot'], (int) $entry['start']->format('G'), tbp_forecast_to_index($flat, $targetIndex, $now, $battery, $usageByHour, $efficiency), $usageByHour);
        $extraOutputWh = (($baselinePercent - $targetPercent) / 100.0) * (float) $battery['capacity_wh'] * $efficiency;
        $rawCalculatedPowerW = $baselinePowerW - ($extraOutputWh / $durationHours);
        $ruleMax = isset($entry['slot']['max_discharge_power']) && is_numeric($entry['slot']['max_discharge_power'])
            ? max(1, (int) $entry['slot']['max_discharge_power'])
            : $defaultMaxDischargeW;
        $maxDischargeW = min($defaultMaxDischargeW, $ruleMax);
        $calculatedPowerW = tbp_round_discharge_power(
            $rawCalculatedPowerW,
            $maxDischargeW,
            (int) $sharedConfig['schedule']['powerStepW']
        );

        $entry['slot']['value'] = $calculatedPowerW;
        $flat[$targetIndex]['slot'] = $entry['slot'];
        $predictedPercent = tbp_forecast_to_index($flat, $anchorIndex, $now, $battery, $usageByHour, $efficiency);
        $status = $predictedPercent <= $targetPercent + 0.25 ? 'achievable' : 'best_effort';
        $reason = $status === 'achievable'
            ? 'Calculated discharge reaches the target within forecast tolerance.'
            : 'Power or available time is insufficient; maximum allowed discharge is scheduled.';
        $entry['slot']['planning'] = tbp_planning_metadata(
            $entry,
            $anchor,
            $targetPercent,
            $status,
            $baselinePercent,
            $predictedPercent,
            $calculatedPowerW,
            $reason
        );
        $flat[$targetIndex]['slot'] = $entry['slot'];
    }
    unset($entry);

    $processedChargeGroups = [];
    foreach ($flat as $targetIndex => &$entry) {
        if (($entry['slot']['value'] ?? null) !== TARGET_CHARGE_MODE) {
            continue;
        }

        $groupKey = tbp_target_charge_group_key($entry['slot']);
        $anchorIndex = tbp_find_next_netzero_minus($flat, $targetIndex, $now);
        $anchor = $anchorIndex !== null ? $flat[$anchorIndex] : null;
        $groupRunKey = $groupKey . '|anchor:' . ($anchor !== null ? $anchor['date'] . $anchor['time'] : 'none');
        if (isset($processedChargeGroups[$groupRunKey])) {
            continue;
        }
        $processedChargeGroups[$groupRunKey] = true;

        $groupIndexes = [];
        $scanEnd = $anchorIndex ?? count($flat);
        for ($scanIndex = $targetIndex; $scanIndex < $scanEnd; $scanIndex++) {
            $scanSlot = $flat[$scanIndex]['slot'];
            if (
                ($scanSlot['value'] ?? null) === TARGET_CHARGE_MODE &&
                tbp_target_charge_group_key($scanSlot) === $groupKey
            ) {
                $groupIndexes[] = $scanIndex;
            }
        }

        $fallbackGroup = static function (
            array &$flat,
            array $indexes,
            ?array $anchor,
            float $targetPercent,
            string $status,
            string $reason
        ): void {
            foreach ($indexes as $index) {
                $slotEntry = &$flat[$index];
                $fallback = tbp_fallback_value($slotEntry['slot']);
                $slotEntry['slot']['value'] = $fallback;
                unset($slotEntry['slot']['min_power'], $slotEntry['slot']['max_power']);
                $slotEntry['slot']['planning'] = tbp_target_charge_metadata(
                    $slotEntry,
                    $anchor,
                    $targetPercent,
                    $status,
                    null,
                    null,
                    null,
                    null,
                    null,
                    $reason
                );
                unset($slotEntry);
            }
        };

        $targetPercent = $battery !== null ? (float) $battery['maximum_percent'] : 100.0;
        if ($anchorIndex === null) {
            $fallbackGroup($flat, $groupIndexes, null, $targetPercent, 'unavailable', 'No future NZ- start was found in the planning horizon.');
            continue;
        }
        if ($battery === null) {
            $fallbackGroup($flat, $groupIndexes, $anchor, $targetPercent, 'unavailable', 'Live battery level is unavailable.');
            continue;
        }

        $currentPercent = tbp_clamp(
            (float) $battery['percent'],
            (float) $battery['minimum_percent'],
            (float) $battery['maximum_percent']
        );
        $targetPercent = (float) $battery['maximum_percent'];
        $remainingDurationHours = 0.0;
        $eligibleIndexes = [];
        foreach ($groupIndexes as $index) {
            if ($flat[$index]['end'] <= $now) {
                $flat[$index]['slot']['value'] = tbp_fallback_value($flat[$index]['slot']);
                unset($flat[$index]['slot']['min_power'], $flat[$index]['slot']['max_power']);
                $flat[$index]['slot']['planning'] = tbp_target_charge_metadata(
                    $flat[$index],
                    $anchor,
                    $targetPercent,
                    'past',
                    $currentPercent,
                    0.0,
                    0.0,
                    0,
                    null,
                    'Rule hour has already ended.'
                );
                continue;
            }
            $segmentStart = $flat[$index]['start'] < $now ? $now : $flat[$index]['start'];
            $durationHours = max(0.0, ($flat[$index]['end']->getTimestamp() - $segmentStart->getTimestamp()) / 3600);
            if ($durationHours <= 0) {
                continue;
            }
            $remainingDurationHours += $durationHours;
            $eligibleIndexes[] = $index;
        }

        if ($remainingDurationHours <= 0 || count($eligibleIndexes) === 0) {
            $fallbackGroup($flat, $groupIndexes, $anchor, $targetPercent, 'unavailable', 'No eligible charging time remains before NZ-.');
            continue;
        }

        $maximumPowerW = isset($options['max_charge_power_w']) && is_numeric($options['max_charge_power_w'])
            ? max(0, (int) $options['max_charge_power_w'])
            : (int) $sharedConfig['schedule']['maxPowerW'];
        $stepW = isset($options['charge_power_step_w']) && is_numeric($options['charge_power_step_w'])
            ? max(1, (int) $options['charge_power_step_w'])
            : (int) $sharedConfig['schedule']['powerStepW'];
        $candidateFlat = $flat;
        foreach ($eligibleIndexes as $index) {
            if (!array_key_exists('fallback_value', $candidateFlat[$index]['slot'])) {
                $candidateFlat[$index]['slot']['fallback_value'] = tbp_fallback_value($candidateFlat[$index]['slot']);
            }
            $candidateFlat[$index]['slot']['value'] = 'netzero+';
            $candidateFlat[$index]['slot']['min_power'] = 0;
            $candidateFlat[$index]['slot']['max_power'] = $maximumPowerW;
        }

        $predictedStartPercent = tbp_forecast_to_index(
            $candidateFlat,
            $eligibleIndexes[0],
            $now,
            $battery,
            $usageByHour,
            $efficiency
        );
        $baselineAnchorPercent = tbp_forecast_to_index(
            $candidateFlat,
            $anchorIndex,
            $now,
            $battery,
            $usageByHour,
            $efficiency
        );
        $requiredEnergyWh = (max(0.0, $targetPercent - $baselineAnchorPercent) / 100.0)
            * (float) $battery['capacity_wh'];
        $targetTolerancePercent = isset($options['target_tolerance_percent']) && is_numeric($options['target_tolerance_percent'])
            ? max(0.0, (float) $options['target_tolerance_percent'])
            : 0.25;
        $targetThresholdPercent = $targetPercent - $targetTolerancePercent;
        $calculatedMinimumW = 0;
        $predictedAnchorPercent = $baselineAnchorPercent;
        $status = 'already_satisfied';

        if ($baselineAnchorPercent < $targetThresholdPercent) {
            $status = 'best_effort';
            $candidates = [];
            for ($candidateW = $stepW; $candidateW < $maximumPowerW; $candidateW += $stepW) {
                $candidates[] = $candidateW;
            }
            if ($maximumPowerW > 0) {
                $candidates[] = $maximumPowerW;
            }

            foreach (array_values(array_unique($candidates)) as $candidateW) {
                foreach ($eligibleIndexes as $index) {
                    $candidateFlat[$index]['slot']['min_power'] = $candidateW;
                }
                $candidatePrediction = tbp_forecast_to_index(
                    $candidateFlat,
                    $anchorIndex,
                    $now,
                    $battery,
                    $usageByHour,
                    $efficiency
                );
                $calculatedMinimumW = $candidateW;
                $predictedAnchorPercent = $candidatePrediction;
                if ($candidatePrediction >= $targetThresholdPercent) {
                    $status = 'achievable';
                    break;
                }
            }
        }

        $reason = match ($status) {
            'already_satisfied' => 'The full schedule forecast already reaches the target at NZ- without a forced charge minimum.',
            'achievable' => 'The minimum is the lowest stepped charge power whose full schedule forecast reaches the target at NZ-.',
            default => 'The full schedule forecast cannot reach the target at NZ- within the configured maximum charge power.',
        };
        $forecastMetadata = [
            'predicted_start_soc_percent' => $predictedStartPercent,
            'baseline_anchor_soc_percent' => $baselineAnchorPercent,
            'predicted_anchor_soc_percent' => $predictedAnchorPercent,
            'efficiency' => $efficiency,
            'calculated_at' => $now->format(DateTimeInterface::ATOM),
        ];

        foreach ($eligibleIndexes as $index) {
            $slotEntry = &$flat[$index];
            if (!array_key_exists('fallback_value', $slotEntry['slot'])) {
                $slotEntry['slot']['fallback_value'] = tbp_fallback_value($slotEntry['slot']);
            }
            $slotEntry['slot']['value'] = 'netzero+';
            $slotEntry['slot']['min_power'] = $calculatedMinimumW;
            $slotEntry['slot']['max_power'] = $maximumPowerW;
            $slotEntry['slot']['planning'] = tbp_target_charge_metadata(
                $slotEntry,
                $anchor,
                $targetPercent,
                $status,
                $currentPercent,
                $remainingDurationHours,
                $requiredEnergyWh,
                $calculatedMinimumW,
                $maximumPowerW,
                $reason,
                $forecastMetadata
            );
            $flat[$index]['slot'] = $slotEntry['slot'];
            unset($slotEntry);
        }
    }
    unset($entry);

    $byKey = [];
    foreach ($flat as $entry) {
        $byKey[$entry['date'] . $entry['time']] = $entry['slot'];
    }
    foreach ($days as &$day) {
        if (!is_array($day) || !isset($day['date'], $day['items']) || !is_array($day['items'])) {
            continue;
        }
        foreach ($day['items'] as &$slot) {
            $key = (string) $day['date'] . str_pad((string) ($slot['time'] ?? ''), 4, '0', STR_PAD_LEFT);
            if (isset($byKey[$key])) {
                $slot = $byKey[$key];
            }
        }
        unset($slot);
    }
    unset($day);
    return $days;
}
