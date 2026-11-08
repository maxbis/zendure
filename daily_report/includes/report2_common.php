<?php

declare(strict_types=1);

require_once __DIR__ . '/report_smart_common.php';
require_once dirname(__DIR__, 2) . '/main/includes/app_energy_history.php';

const DAILY_REPORT2_BATTERY_METHOD_VERSION = 3;

/** @return array<string, mixed> */
function dailyReport2BuildDay(string $date): array
{
    $timezone = dailyReportTimezone();
    dailyReportValidateDate($date, $timezone);
    $today = (new DateTimeImmutable('today', $timezone))->format('Y-m-d');
    if ($date > $today) {
        throw new InvalidArgumentException('Future dates are not supported.');
    }
    $loaded = dailyReportLoadSmart($date, false);
    $report = is_array($loaded['report'] ?? null) ? $loaded['report'] : [];
    $systemConfig = dailyReportSystemConfig();
    $rows = dailyReport2RowsFromReport($report, $date, $date === $today, $timezone);
    $history = appEnergyHistoryBuildPayload(
        $rows,
        0,
        (string)($loaded['source'] ?? 'daily_report'),
        false,
        is_array($systemConfig['battery'] ?? null) ? $systemConfig['battery'] : [],
        $date === $today ? $date : null
    );

    $day = is_array($history['whPerDay'][$date] ?? null) ? $history['whPerDay'][$date] : [];
    $flow = is_array($day['batteryFlowTotals'] ?? null) ? $day['batteryFlowTotals'] : [];
    $stored = is_array($day['storedEnergyValue'] ?? null) ? $day['storedEnergyValue'] : [];
    $grid = is_array($day['gridPriceTotals'] ?? null) ? $day['gridPriceTotals'] : [];
    $gridImport = is_array($grid['import'] ?? null) ? $grid['import'] : [];
    $gridExport = is_array($grid['export'] ?? null) ? $grid['export'] : [];
    $flowComplete = ($flow['complete'] ?? false) === true;
    $storedComplete = ($stored['complete'] ?? false) === true;
    $flowPnlEur = is_numeric($flow['pnlMilliEur'] ?? null)
        ? ((float)$flow['pnlMilliEur']) / 1000.0
        : null;
    $storedValueEur = $storedComplete && is_numeric($stored['valueEur'] ?? null)
        ? (float)$stored['valueEur']
        : null;
    $hasContributionComponent = $flowPnlEur !== null || $storedValueEur !== null;
    $contributionEur = $hasContributionComponent
        ? round(($flowPnlEur ?? 0.0) + ($storedValueEur ?? 0.0), 6)
        : null;
    $contributionComplete = $flowComplete && $storedComplete && $contributionEur !== null;
    $gridImportEur = ($gridImport['complete'] ?? false) === true && is_numeric($gridImport['eur'] ?? null)
        ? (float)$gridImport['eur']
        : null;
    $gridExportEur = ($gridExport['complete'] ?? false) === true && is_numeric($gridExport['eur'] ?? null)
        ? (float)$gridExport['eur']
        : null;
    $netGridCostEur = $gridImportEur !== null && $gridExportEur !== null
        ? round($gridImportEur - $gridExportEur, 6)
        : null;

    $expectedHours = $date === $today
        ? ((int)(new DateTimeImmutable('now', $timezone))->format('G')) + 1
        : 24;
    $priceHours = count(array_filter($rows, static fn(array $row): bool => is_numeric($row['consumer_eur_per_kwh'] ?? null)));
    $completeHours = count(array_filter(
        $rows,
        static fn(array $row): bool => (string)($row['battery_pnl_status'] ?? '') === 'complete'
            && (int)($row['battery_pnl_method_version'] ?? 0) === DAILY_REPORT2_BATTERY_METHOD_VERSION
    ));
    $missingValuationHours = max(0, $expectedHours - $completeHours);
    $unvaluedDischargeWh = round(array_reduce(
        $rows,
        static function (float $sum, array $row): float {
            $valued = (string)($row['battery_pnl_status'] ?? '') === 'complete'
                && (int)($row['battery_pnl_method_version'] ?? 0) === DAILY_REPORT2_BATTERY_METHOD_VERSION;
            return $sum + ($valued || !is_numeric($row['discharged_wh'] ?? null) ? 0.0 : (float)$row['discharged_wh']);
        },
        0.0
    ), 2);
    $isPartialDay = $date === $today || count($rows) < 24;
    $status = $contributionComplete
        ? ($isPartialDay ? 'complete_so_far' : 'complete')
        : ($contributionEur !== null ? 'partial' : 'unavailable');

    return [
        'date' => $date,
        'timezone' => $timezone->getName(),
        'savedAt' => $loaded['savedAt'] ?? null,
        'source' => (string)($loaded['source'] ?? 'daily_report'),
        'isPartialDay' => $isPartialDay,
        'coverage' => [
            'availableHours' => count($rows),
            'expectedHours' => $expectedHours,
            'priceHours' => $priceHours,
            'batteryValuationHours' => $completeHours,
            'missingBatteryValuationHours' => $missingValuationHours,
            'unvaluedDischargeWh' => $unvaluedDischargeWh,
            'status' => $status,
        ],
        'energy' => [
            'chargedWh' => dailyReport2SumRows($rows, 'charged_wh'),
            'dischargedWh' => dailyReport2SumRows($rows, 'discharged_wh'),
            'gridImportWh' => dailyReport2SumRows($rows, 'grid_from_wh'),
            'gridExportWh' => dailyReport2SumRows($rows, 'grid_to_wh'),
            'openingPct' => dailyReport2FirstNumber($rows, 'battery_pct_start'),
            'closingPct' => dailyReport2LastNumber($rows, 'battery_pct_end'),
        ],
        'gridCost' => [
            'importEur' => $gridImportEur,
            'exportEur' => $gridExportEur,
            'netEur' => $netGridCostEur,
            'complete' => $netGridCostEur !== null,
        ],
        'batteryEconomics' => [
            'chargeGridWh' => dailyReport2NullableInt($flow['chargeGridWh'] ?? null),
            'chargeSurplusWh' => dailyReport2NullableInt($flow['chargeSurplusWh'] ?? null),
            'dischargeHomeWh' => dailyReport2NullableInt($flow['dischargeHomeWh'] ?? null),
            'dischargeExportWh' => dailyReport2NullableInt($flow['dischargeExportWh'] ?? null),
            'chargeCostEur' => dailyReport2MilliEur($flow['chargeCostMilliEur'] ?? null),
            'homeValueEur' => dailyReport2MilliEur($flow['homeSavingsMilliEur'] ?? null),
            'exportValueEur' => dailyReport2MilliEur($flow['exportRevenueMilliEur'] ?? null),
            'flowPnlEur' => $flowPnlEur,
            'storedValueEur' => $storedValueEur,
            'contributionEur' => $contributionEur,
            'openingPct' => dailyReport2NullableFloat($stored['openingPct'] ?? null),
            'closingPct' => dailyReport2NullableFloat($stored['closingPct'] ?? null),
            'storedDeltaWh' => dailyReport2NullableFloat($stored['storedDeltaWh'] ?? null),
            'averageConsumerEurPerKwh' => dailyReport2NullableFloat($stored['averageConsumerEurPerKwh'] ?? null),
            'complete' => $contributionComplete,
            'flowComplete' => $flowComplete,
            'storedValueComplete' => $storedComplete,
            'partial' => !$contributionComplete && $contributionEur !== null,
            'estimationBasis' => $flow['estimationBasis'] ?? null,
            'confidence' => $flow['confidence'] ?? null,
            'methodVersion' => DAILY_REPORT2_BATTERY_METHOD_VERSION,
            'missingHours' => array_values($flow['missingHours'] ?? []),
            'reasons' => array_values($flow['reasons'] ?? []),
        ],
        'hours' => dailyReport2BuildHourRows($rows),
    ];
}

/** @return array<string, mixed> */
function dailyReport2BuildMonth(string $month): array
{
    $timezone = dailyReportTimezone();
    $monthStart = DateTimeImmutable::createFromFormat('!Y-m', $month, $timezone);
    if (!$monthStart instanceof DateTimeImmutable || $monthStart->format('Y-m') !== $month) {
        throw new InvalidArgumentException('Invalid month. Expected YYYY-MM.');
    }
    $today = new DateTimeImmutable('today', $timezone);
    $currentMonth = $today->format('Y-m');
    if ($month > $currentMonth) {
        throw new InvalidArgumentException('Future months are not supported.');
    }
    $monthEnd = $monthStart->modify('first day of next month')->modify('-1 day');
    $lastDate = $month === $currentMonth ? $today : $monthEnd;
    $days = [];
    $totals = [
        'chargedWh' => 0.0,
        'dischargedWh' => 0.0,
        'gridImportWh' => 0.0,
        'gridExportWh' => 0.0,
        'gridImportEur' => 0.0,
        'gridExportEur' => 0.0,
        'netGridCostEur' => 0.0,
        'flowPnlEur' => 0.0,
        'storedValueEur' => 0.0,
        'contributionEur' => 0.0,
    ];
    $costComplete = true;
    $contributionComplete = true;
    $hasFlowPnl = false;
    $hasStoredValue = false;
    $hasContribution = false;
    $confidence = null;
    $coverageHours = 0;
    $expectedHours = 0;
    $valuedHours = 0;
    $missingValuationHours = 0;
    $unvaluedDischargeWh = 0.0;
    $incompleteDays = [];

    for ($cursor = $monthStart; $cursor <= $lastDate; $cursor = $cursor->modify('+1 day')) {
        $day = dailyReport2BuildDay($cursor->format('Y-m-d'));
        $days[] = dailyReport2MonthDay($day);
        $coverageHours += (int)$day['coverage']['availableHours'];
        $expectedHours += (int)$day['coverage']['expectedHours'];
        $valuedHours += (int)$day['coverage']['batteryValuationHours'];
        $missingValuationHours += (int)$day['coverage']['missingBatteryValuationHours'];
        $unvaluedDischargeWh += (float)$day['coverage']['unvaluedDischargeWh'];
        foreach (['chargedWh', 'dischargedWh', 'gridImportWh', 'gridExportWh'] as $key) {
            $totals[$key] += (float)$day['energy'][$key];
        }
        if (($day['gridCost']['complete'] ?? false) === true) {
            $totals['gridImportEur'] += (float)$day['gridCost']['importEur'];
            $totals['gridExportEur'] += (float)$day['gridCost']['exportEur'];
            $totals['netGridCostEur'] += (float)$day['gridCost']['netEur'];
        } else {
            $costComplete = false;
        }
        $flowPnl = dailyReport2NullableFloat($day['batteryEconomics']['flowPnlEur'] ?? null);
        $storedValue = dailyReport2NullableFloat($day['batteryEconomics']['storedValueEur'] ?? null);
        $contribution = dailyReport2NullableFloat($day['batteryEconomics']['contributionEur'] ?? null);
        if ($flowPnl !== null) {
            $totals['flowPnlEur'] += $flowPnl;
            $hasFlowPnl = true;
        }
        if ($storedValue !== null) {
            $totals['storedValueEur'] += $storedValue;
            $hasStoredValue = true;
        }
        if ($contribution !== null) {
            $totals['contributionEur'] += $contribution;
            $hasContribution = true;
            $confidence = dailyReport2LowestConfidence($confidence, $day['batteryEconomics']['confidence'] ?? null);
        }
        if (($day['batteryEconomics']['complete'] ?? false) !== true) {
            $contributionComplete = false;
            $incompleteDays[] = [
                'date' => $day['date'],
                'missingHours' => (int)$day['coverage']['missingBatteryValuationHours'],
                'unvaluedDischargeWh' => (float)$day['coverage']['unvaluedDischargeWh'],
                'reasons' => array_values($day['batteryEconomics']['reasons'] ?? []),
            ];
        }
    }

    foreach ($totals as $key => $value) {
        $totals[$key] = round($value, str_ends_with($key, 'Wh') ? 2 : 6);
    }
    if (!$costComplete) {
        foreach (['gridImportEur', 'gridExportEur', 'netGridCostEur'] as $key) $totals[$key] = null;
    }
    if (!$hasFlowPnl) $totals['flowPnlEur'] = null;
    if (!$hasStoredValue) $totals['storedValueEur'] = null;
    if (!$hasContribution) $totals['contributionEur'] = null;

    return [
        'month' => $month,
        'timezone' => $timezone->getName(),
        'isPartialMonth' => $month === $currentMonth,
        'lastIncludedDate' => $lastDate->format('Y-m-d'),
        'includedDayCount' => count($days),
        'coverage' => [
            'availableHours' => $coverageHours,
            'expectedHours' => $expectedHours,
            'batteryValuationHours' => $valuedHours,
            'missingBatteryValuationHours' => $missingValuationHours,
            'unvaluedDischargeWh' => round($unvaluedDischargeWh, 2),
            'incompleteDayCount' => count($incompleteDays),
            'incompleteDays' => $incompleteDays,
            'costComplete' => $costComplete,
            'contributionComplete' => $contributionComplete,
            'contributionStatus' => $contributionComplete ? 'complete' : ($hasContribution ? 'partial' : 'unavailable'),
            'confidence' => $confidence,
        ],
        'totals' => $totals,
        'days' => $days,
        'note' => 'The displayed contribution is the value accounted for by available hourly valuations and stored-value components. Missing hours are not treated as zero. It is not a formal inventory revaluation.',
    ];
}

/** @param array<string, mixed> $day @return array<string, mixed> */
function dailyReport2MonthDay(array $day): array
{
    return [
        'date' => $day['date'],
        'isPartialDay' => $day['isPartialDay'],
        'coverageStatus' => $day['coverage']['status'],
        'batteryValuationHours' => $day['coverage']['batteryValuationHours'],
        'expectedHours' => $day['coverage']['expectedHours'],
        'missingHours' => $day['coverage']['missingBatteryValuationHours'],
        'unvaluedDischargeWh' => $day['coverage']['unvaluedDischargeWh'],
        'chargedWh' => $day['energy']['chargedWh'],
        'dischargedWh' => $day['energy']['dischargedWh'],
        'gridImportWh' => $day['energy']['gridImportWh'],
        'gridExportWh' => $day['energy']['gridExportWh'],
        'netGridCostEur' => $day['gridCost']['netEur'],
        'flowPnlEur' => $day['batteryEconomics']['flowPnlEur'],
        'storedValueEur' => $day['batteryEconomics']['storedValueEur'],
        'contributionEur' => $day['batteryEconomics']['contributionEur'],
        'confidence' => $day['batteryEconomics']['confidence'],
    ];
}

/** @param array<string, mixed> $report @return array<int, array<string, mixed>> */
function dailyReport2RowsFromReport(array $report, string $date, bool $isToday, DateTimeZone $timezone): array
{
    $currentHour = (int)(new DateTimeImmutable('now', $timezone))->format('G');
    $rows = [];
    foreach (($report['hours'] ?? []) as $hourRow) {
        if (!is_array($hourRow) || !is_numeric($hourRow['hour'] ?? null)) continue;
        $hour = (int)$hourRow['hour'];
        if ($hour < 0 || $hour > 23 || ($isToday && $hour > $currentHour)) continue;
        $rows[] = [
            'local_date' => $date,
            'local_hour' => $hour,
            'charged_wh' => $hourRow['charged_wh'] ?? 0,
            'discharged_wh' => $hourRow['discharged_wh'] ?? 0,
            'grid_from_wh' => $hourRow['grid_from_wh'] ?? null,
            'grid_to_wh' => $hourRow['grid_to_wh'] ?? null,
            'battery_pct_start' => $hourRow['battery_pct_start'] ?? null,
            'battery_pct_end' => $hourRow['battery_pct_end'] ?? null,
            'consumer_eur_per_kwh' => $hourRow['price_eur_per_kwh'] ?? null,
            'spot_eur_per_kwh' => $hourRow['spot_eur_per_kwh'] ?? null,
            'battery_charge_grid_wh' => $hourRow['battery_charge_grid_wh'] ?? null,
            'battery_charge_surplus_wh' => $hourRow['battery_charge_surplus_wh'] ?? null,
            'battery_discharge_home_wh' => $hourRow['battery_discharge_home_wh'] ?? null,
            'battery_discharge_export_wh' => $hourRow['battery_discharge_export_wh'] ?? null,
            'battery_charge_cost_milli_eur' => $hourRow['battery_charge_cost_milli_eur'] ?? null,
            'battery_home_savings_milli_eur' => $hourRow['battery_home_savings_milli_eur'] ?? null,
            'battery_export_revenue_milli_eur' => $hourRow['battery_export_revenue_milli_eur'] ?? null,
            'battery_flow_pnl_milli_eur' => $hourRow['battery_flow_pnl_milli_eur'] ?? null,
            'battery_pnl_status' => $hourRow['battery_pnl_status'] ?? null,
            'battery_pnl_method_version' => $hourRow['battery_pnl_method_version'] ?? null,
            'battery_pnl_estimation_basis' => $hourRow['battery_pnl_estimation_basis'] ?? null,
            'battery_pnl_confidence' => $hourRow['battery_pnl_confidence'] ?? null,
        ];
    }
    return $rows;
}

/** @param array<int, array<string, mixed>> $rows @return array<int, array<string, mixed>> */
function dailyReport2BuildHourRows(array $rows): array
{
    return array_map(static function (array $row): array {
        $consumer = dailyReport2NullableFloat($row['consumer_eur_per_kwh'] ?? null);
        $spot = dailyReport2NullableFloat($row['spot_eur_per_kwh'] ?? null);
        $importWh = dailyReport2NullableFloat($row['grid_from_wh'] ?? null);
        $exportWh = dailyReport2NullableFloat($row['grid_to_wh'] ?? null);
        $netCost = $consumer !== null && $spot !== null && $importWh !== null && $exportWh !== null
            ? round(($importWh / 1000 * $consumer) - ($exportWh / 1000 * $spot), 6)
            : null;
        return [
            'hour' => str_pad((string)$row['local_hour'], 2, '0', STR_PAD_LEFT),
            'chargedWh' => dailyReport2NullableFloat($row['charged_wh'] ?? null) ?? 0.0,
            'dischargedWh' => dailyReport2NullableFloat($row['discharged_wh'] ?? null) ?? 0.0,
            'gridImportWh' => $importWh,
            'gridExportWh' => $exportWh,
            'batteryStartPct' => dailyReport2NullableFloat($row['battery_pct_start'] ?? null),
            'batteryEndPct' => dailyReport2NullableFloat($row['battery_pct_end'] ?? null),
            'consumerEurPerKwh' => $consumer,
            'spotEurPerKwh' => $spot,
            'netGridCostEur' => $netCost,
            'batteryFlowPnlEur' => dailyReport2MilliEur($row['battery_flow_pnl_milli_eur'] ?? null),
            'estimationBasis' => $row['battery_pnl_estimation_basis'] ?? null,
            'confidence' => $row['battery_pnl_confidence'] ?? null,
            'status' => $row['battery_pnl_status'] ?? null,
        ];
    }, $rows);
}

/** @param array<int, array<string, mixed>> $rows */
function dailyReport2SumRows(array $rows, string $key): float
{
    return round(array_reduce($rows, static fn(float $sum, array $row): float => $sum + (is_numeric($row[$key] ?? null) ? (float)$row[$key] : 0.0), 0.0), 2);
}

/** @param array<int, array<string, mixed>> $rows */
function dailyReport2FirstNumber(array $rows, string $key): ?float
{
    foreach ($rows as $row) {
        $value = dailyReport2NullableFloat($row[$key] ?? null);
        if ($value !== null) return $value;
    }
    return null;
}

/** @param array<int, array<string, mixed>> $rows */
function dailyReport2LastNumber(array $rows, string $key): ?float
{
    for ($index = count($rows) - 1; $index >= 0; $index--) {
        $value = dailyReport2NullableFloat($rows[$index][$key] ?? null);
        if ($value !== null) return $value;
    }
    return null;
}

function dailyReport2NullableFloat(mixed $value): ?float
{
    return is_numeric($value) && is_finite((float)$value) ? (float)$value : null;
}

function dailyReport2NullableInt(mixed $value): ?int
{
    return is_numeric($value) ? (int)round((float)$value) : null;
}

function dailyReport2MilliEur(mixed $value): ?float
{
    return is_numeric($value) ? round(((float)$value) / 1000.0, 6) : null;
}

function dailyReport2LowestConfidence(?string $current, mixed $candidate): ?string
{
    if (!is_string($candidate) || !in_array($candidate, ['low', 'medium', 'high'], true)) return $current;
    if ($current === null) return $candidate;
    $rank = ['low' => 0, 'medium' => 1, 'high' => 2];
    return $rank[$candidate] < $rank[$current] ? $candidate : $current;
}
