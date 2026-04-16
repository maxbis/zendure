<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/report_api_common.php';
require_once dirname(__DIR__, 2) . '/main/includes/price_conversion.php';

date_default_timezone_set('Europe/Amsterdam');

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo dailyReportJsonEncode(['success' => false, 'error' => 'Method not allowed. Use GET.']);
    exit();
}

try {
    echo dailyReportJsonEncode(buildMonthlyReportPayload());
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo dailyReportJsonEncode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo dailyReportJsonEncode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * @return array<string, mixed>
 */
function buildMonthlyReportPayload(): array
{
    $tz = dailyReportTimezone();
    $requestedMonth = requestMonth($tz);
    $today = new DateTimeImmutable('now', $tz);
    $currentMonth = $today->format('Y-m');
    $monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $requestedMonth . '-01', $tz);

    if (!$monthStart instanceof DateTimeImmutable) {
        throw new InvalidArgumentException('Invalid month. Expected YYYY-MM.');
    }

    $currentMonthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $currentMonth . '-01', $tz);
    if (!$currentMonthStart instanceof DateTimeImmutable) {
        throw new RuntimeException('Failed to resolve current month.');
    }

    if ($monthStart > $currentMonthStart) {
        throw new InvalidArgumentException('Future months are not supported.');
    }

    $monthEnd = $monthStart->modify('first day of next month')->modify('-1 day');
    $isCurrentMonth = $requestedMonth === $currentMonth;
    $lastIncludedDate = $isCurrentMonth
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $today->format('Y-m-d'), $tz)
        : $monthEnd;

    if (!$lastIncludedDate instanceof DateTimeImmutable) {
        throw new RuntimeException('Failed to resolve last included date.');
    }

    $days = [];
    $savedDayCount = 0;
    $generatedDayCount = 0;
    $missingPriceDayCount = 0;
    $costCoverageDayCount = 0;

    $totalChargedWh = 0.0;
    $totalDischargedWh = 0.0;
    $totalGridFromWh = 0.0;
    $totalGridToWh = 0.0;
    $totalGridFromCost = 0.0;
    $totalGridToCost = 0.0;
    $totalNetCost = 0.0;
    $totalSavings = 0.0;
    $totalChargeCost = 0.0;
    $totalSpotNetCost = 0.0;
    $totalSpotChargeCost = 0.0;

    $hasGridFromCost = false;
    $hasGridToCost = false;
    $hasNetCost = false;
    $hasSavings = false;
    $hasChargeCost = false;
    $hasSpotNetCost = false;
    $hasSpotChargeCost = false;

    $monthBattery = [
        'start' => null,
        'end' => null,
        'min' => null,
        'max' => null,
    ];

    for ($cursor = $monthStart; $cursor <= $lastIncludedDate; $cursor = $cursor->modify('+1 day')) {
        $date = $cursor->format('Y-m-d');
        $loaded = dailyReportLoadOrGenerate($date, $date === $today->format('Y-m-d'));

        if ($loaded['generated']) {
            $generatedDayCount += 1;
        } else {
            $savedDayCount += 1;
        }

        /** @var array<string, mixed> $report */
        $report = $loaded['report'];
        $hours = is_array($report['hours'] ?? null) ? $report['hours'] : [];
        $totals = is_array($report['totals'] ?? null) ? $report['totals'] : [];

        $priceFileFound = (bool)($report['price_file_found'] ?? false);
        if ($priceFileFound) {
            $costCoverageDayCount += 1;
        } else {
            $missingPriceDayCount += 1;
        }

        $chargedWh = monthlyReportFloat($totals['charged_wh'] ?? null) ?? 0.0;
        $dischargedWh = monthlyReportFloat($totals['discharged_wh'] ?? null) ?? 0.0;
        $gridFromWh = monthlyReportFloat($totals['grid_from_wh'] ?? null) ?? 0.0;
        $gridToWh = monthlyReportFloat($totals['grid_to_wh'] ?? null) ?? 0.0;

        $totalChargedWh += $chargedWh;
        $totalDischargedWh += $dischargedWh;
        $totalGridFromWh += $gridFromWh;
        $totalGridToWh += $gridToWh;

        $gridFromCost = monthlyReportFloat($totals['grid_from_cost'] ?? null);
        $gridToCost = monthlyReportFloat($totals['grid_to_cost'] ?? null);
        $netCost = monthlyReportFloat($totals['net_cost'] ?? null);
        $savings = monthlyReportFloat($totals['savings_eur'] ?? null);
        $chargeCost = monthlyReportFloat($totals['charge_cost_eur'] ?? null);
        $spotNetCost = computeSpotNetCostFromHours($hours);
        $spotChargeCost = computeSpotChargeCostFromHours($hours);

        monthlyReportAccumulate($gridFromCost, $totalGridFromCost, $hasGridFromCost);
        monthlyReportAccumulate($gridToCost, $totalGridToCost, $hasGridToCost);
        monthlyReportAccumulate($netCost, $totalNetCost, $hasNetCost);
        monthlyReportAccumulate($savings, $totalSavings, $hasSavings);
        monthlyReportAccumulate($chargeCost, $totalChargeCost, $hasChargeCost);
        monthlyReportAccumulate($spotNetCost, $totalSpotNetCost, $hasSpotNetCost);
        monthlyReportAccumulate($spotChargeCost, $totalSpotChargeCost, $hasSpotChargeCost);

        $batteryStats = computeBatteryStatsFromHours($hours);
        updateAggregateBatteryStats($monthBattery, $batteryStats);

        $days[] = [
            'date' => $date,
            'is_partial_day' => (bool)($report['is_partial_day'] ?? false),
            'charged_wh' => monthlyReportRound($chargedWh, 2),
            'discharged_wh' => monthlyReportRound($dischargedWh, 2),
            'grid_from_wh' => monthlyReportRound($gridFromWh, 2),
            'grid_to_wh' => monthlyReportRound($gridToWh, 2),
            'grid_from_cost' => monthlyReportRound($gridFromCost, 4),
            'grid_to_cost' => monthlyReportRound($gridToCost, 4),
            'net_cost' => monthlyReportRound($netCost, 4),
            'savings_eur' => monthlyReportRound($savings, 4),
            'charge_cost_eur' => monthlyReportRound($chargeCost, 4),
            'spot_net_cost_eur' => monthlyReportRound($spotNetCost, 4),
            'spot_charge_cost_eur' => monthlyReportRound($spotChargeCost, 4),
            'pnl_eur' => monthlyReportRound(computePnl($chargeCost, $savings, $netCost), 4),
            'spot_pnl_eur' => monthlyReportRound(computePnl($spotChargeCost, $savings, $spotNetCost), 4),
            'battery_pct_delta_total' => monthlyReportRound(monthlyReportFloat($totals['battery_pct_delta_total'] ?? null), 2),
            'battery_start_pct' => monthlyReportRound($batteryStats['start'], 2),
            'battery_end_pct' => monthlyReportRound($batteryStats['end'], 2),
            'battery_min_pct' => monthlyReportRound($batteryStats['min'], 2),
            'battery_max_pct' => monthlyReportRound($batteryStats['max'], 2),
            'battery_range_pct' => monthlyReportRound(monthlyReportRange($batteryStats['min'], $batteryStats['max']), 2),
            'price_file_found' => $priceFileFound,
            'price_hours_available' => (int)($report['price_hours_available'] ?? 0),
        ];
    }

    $totals = [
        'charged_wh' => monthlyReportRound($totalChargedWh, 2),
        'discharged_wh' => monthlyReportRound($totalDischargedWh, 2),
        'grid_from_wh' => monthlyReportRound($totalGridFromWh, 2),
        'grid_to_wh' => monthlyReportRound($totalGridToWh, 2),
        'grid_from_cost' => $hasGridFromCost ? monthlyReportRound($totalGridFromCost, 4) : null,
        'grid_to_cost' => $hasGridToCost ? monthlyReportRound($totalGridToCost, 4) : null,
        'net_cost' => $hasNetCost ? monthlyReportRound($totalNetCost, 4) : null,
        'savings_eur' => $hasSavings ? monthlyReportRound($totalSavings, 4) : null,
        'charge_cost_eur' => $hasChargeCost ? monthlyReportRound($totalChargeCost, 4) : null,
        'spot_net_cost_eur' => $hasSpotNetCost ? monthlyReportRound($totalSpotNetCost, 4) : null,
        'spot_charge_cost_eur' => $hasSpotChargeCost ? monthlyReportRound($totalSpotChargeCost, 4) : null,
    ];
    $totals['pnl_eur'] = monthlyReportRound(
        computePnl(
            monthlyReportFloat($totals['charge_cost_eur']),
            monthlyReportFloat($totals['savings_eur']),
            monthlyReportFloat($totals['net_cost'])
        ),
        4
    );
    $totals['spot_pnl_eur'] = monthlyReportRound(
        computePnl(
            monthlyReportFloat($totals['spot_charge_cost_eur']),
            monthlyReportFloat($totals['savings_eur']),
            monthlyReportFloat($totals['spot_net_cost_eur'])
        ),
        4
    );

    return [
        'success' => true,
        'requestedMonth' => $requestedMonth,
        'savedAt' => (new DateTimeImmutable('now', $tz))->format(DATE_ATOM),
        'report' => [
            'month' => $requestedMonth,
            'timezone' => $tz->getName(),
            'startDate' => $monthStart->format('Y-m-d'),
            'endDate' => $monthEnd->format('Y-m-d'),
            'lastIncludedDate' => $lastIncludedDate->format('Y-m-d'),
            'isPartialMonth' => $isCurrentMonth,
            'includedDayCount' => count($days),
            'savedDayCount' => $savedDayCount,
            'generatedDayCount' => $generatedDayCount,
            'missingPriceDayCount' => $missingPriceDayCount,
            'costCoverageDayCount' => $costCoverageDayCount,
            'totals' => $totals,
            'battery' => [
                'start_pct' => monthlyReportRound($monthBattery['start'], 2),
                'end_pct' => monthlyReportRound($monthBattery['end'], 2),
                'min_pct' => monthlyReportRound($monthBattery['min'], 2),
                'max_pct' => monthlyReportRound($monthBattery['max'], 2),
                'range_pct' => monthlyReportRound(monthlyReportRange($monthBattery['min'], $monthBattery['max']), 2),
            ],
            'days' => $days,
        ],
    ];
}

function requestMonth(DateTimeZone $tz): string
{
    $raw = $_GET['month'] ?? '';
    if (!is_string($raw) || trim($raw) === '') {
        return (new DateTimeImmutable('now', $tz))->format('Y-m');
    }

    $month = trim($raw);
    $dt = DateTimeImmutable::createFromFormat('!Y-m', $month, $tz);
    if (!$dt instanceof DateTimeImmutable || $dt->format('Y-m') !== $month) {
        throw new InvalidArgumentException('Invalid month. Expected YYYY-MM.');
    }

    return $month;
}

function monthlyReportFloat(mixed $value): ?float
{
    if ($value === null || is_bool($value)) {
        return null;
    }
    if (is_int($value) || is_float($value)) {
        return is_finite((float)$value) ? (float)$value : null;
    }
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }
        $parsed = (float)$trimmed;
        return is_finite($parsed) ? $parsed : null;
    }
    return null;
}

function monthlyReportRound(?float $value, int $digits): ?float
{
    return $value === null ? null : round($value, $digits);
}

function monthlyReportRange(?float $min, ?float $max): ?float
{
    if ($min === null || $max === null) {
        return null;
    }
    return abs($max - $min);
}

function monthlyReportAccumulate(?float $value, float &$total, bool &$hasAny): void
{
    if ($value === null) {
        return;
    }
    $total += $value;
    $hasAny = true;
}

/**
 * @param array<int, mixed> $hours
 * @return array{start:?float,end:?float,min:?float,max:?float}
 */
function computeBatteryStatsFromHours(array $hours): array
{
    $start = null;
    $end = null;
    $min = null;
    $max = null;

    foreach ($hours as $row) {
        if (!is_array($row)) {
            continue;
        }

        $startValue = monthlyReportFloat($row['battery_pct_start'] ?? null);
        $endValue = monthlyReportFloat($row['battery_pct_end'] ?? null);

        if ($start === null) {
            $start = $startValue ?? $endValue;
        }

        foreach ([$startValue, $endValue] as $value) {
            if ($value === null) {
                continue;
            }
            $min = $min === null ? $value : min($min, $value);
            $max = $max === null ? $value : max($max, $value);
        }
    }

    for ($index = count($hours) - 1; $index >= 0; $index -= 1) {
        $row = $hours[$index] ?? null;
        if (!is_array($row)) {
            continue;
        }

        $endValue = monthlyReportFloat($row['battery_pct_end'] ?? null);
        $fallbackEnd = monthlyReportFloat($row['battery_pct_start'] ?? null);
        if ($endValue !== null) {
            $end = $endValue;
            break;
        }
        if ($fallbackEnd !== null) {
            $end = $fallbackEnd;
            break;
        }
    }

    return [
        'start' => $start,
        'end' => $end,
        'min' => $min,
        'max' => $max,
    ];
}

/**
 * @param array{start:?float,end:?float,min:?float,max:?float} $aggregate
 * @param array{start:?float,end:?float,min:?float,max:?float} $daily
 */
function updateAggregateBatteryStats(array &$aggregate, array $daily): void
{
    if ($aggregate['start'] === null && $daily['start'] !== null) {
        $aggregate['start'] = $daily['start'];
    }
    if ($daily['end'] !== null) {
        $aggregate['end'] = $daily['end'];
    }
    if ($daily['min'] !== null) {
        $aggregate['min'] = $aggregate['min'] === null ? $daily['min'] : min($aggregate['min'], $daily['min']);
    }
    if ($daily['max'] !== null) {
        $aggregate['max'] = $aggregate['max'] === null ? $daily['max'] : max($aggregate['max'], $daily['max']);
    }
}

/**
 * @param array<int, mixed> $hours
 */
function computeSpotChargeCostFromHours(array $hours): ?float
{
    $total = 0.0;
    $hasAny = false;

    foreach ($hours as $row) {
        if (!is_array($row)) {
            continue;
        }

        $chargedWh = monthlyReportFloat($row['charged_wh'] ?? null);
        $consumerPrice = monthlyReportFloat($row['price_eur_per_kwh'] ?? null);
        $spotPrice = convertConsumerToSpotPrice($consumerPrice);

        if ($chargedWh === null || $spotPrice === null) {
            continue;
        }

        $total += ($chargedWh / 1000.0) * $spotPrice;
        $hasAny = true;
    }

    return $hasAny ? $total : null;
}

/**
 * @param array<int, mixed> $hours
 */
function computeSpotNetCostFromHours(array $hours): ?float
{
    $total = 0.0;
    $hasAny = false;

    foreach ($hours as $row) {
        if (!is_array($row)) {
            continue;
        }

        $gridFromWh = monthlyReportFloat($row['grid_from_wh'] ?? null);
        $gridToWh = monthlyReportFloat($row['grid_to_wh'] ?? null);
        $consumerPrice = monthlyReportFloat($row['price_eur_per_kwh'] ?? null);
        $spotPrice = convertConsumerToSpotPrice($consumerPrice);

        $gridFromCost = $gridFromWh !== null && $consumerPrice !== null
            ? ($gridFromWh / 1000.0) * $consumerPrice
            : null;
        $gridToCostSpot = $gridToWh !== null && $spotPrice !== null
            ? -1.0 * (($gridToWh / 1000.0) * $spotPrice)
            : null;

        if ($gridFromCost === null && $gridToCostSpot === null) {
            continue;
        }

        $total += ($gridFromCost ?? 0.0) + ($gridToCostSpot ?? 0.0);
        $hasAny = true;
    }

    return $hasAny ? $total : null;
}

function computePnl(?float $chargeCost, ?float $savings, ?float $netCost): ?float
{
    if ($chargeCost === null || $savings === null || $netCost === null) {
        return null;
    }
    return ($chargeCost - $savings + $netCost) * -1.0;
}
