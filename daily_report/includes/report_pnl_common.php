<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/main/includes/price_conversion.php';

function dailyReportFloat(mixed $value): ?float
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

function dailyReportRound(?float $value, int $digits): ?float
{
    return $value === null ? null : round($value, $digits);
}

function dailyReportPnlInt(mixed $value): ?int
{
    if ($value === null || is_bool($value) || !is_numeric($value)) {
        return null;
    }
    return (int)$value;
}

/**
 * @param array<int, mixed> $hours
 */
function dailyReportComputeSpotChargeCostFromHours(array $hours): ?float
{
    $total = 0.0;
    $hasAny = false;

    foreach ($hours as $row) {
        if (!is_array($row)) {
            continue;
        }

        $chargedWh = dailyReportFloat($row['charged_wh'] ?? null);
        $consumerPrice = dailyReportFloat($row['price_eur_per_kwh'] ?? null);
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
function dailyReportComputeSpotNetCostFromHours(array $hours): ?float
{
    $total = 0.0;
    $hasAny = false;

    foreach ($hours as $row) {
        if (!is_array($row)) {
            continue;
        }

        $gridFromWh = dailyReportFloat($row['grid_from_wh'] ?? null);
        $gridToWh = dailyReportFloat($row['grid_to_wh'] ?? null);
        $consumerPrice = dailyReportFloat($row['price_eur_per_kwh'] ?? null);
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

function dailyReportComputePnl(?float $chargeCost, ?float $savings, ?float $netCost): ?float
{
    if ($chargeCost === null || $savings === null || $netCost === null) {
        return null;
    }
    return ($chargeCost - $savings + $netCost) * -1.0;
}

function dailyReportResolveSource(bool $generated, string $date, DateTimeInterface $today): string
{
    if (!$generated) {
        return 'saved';
    }

    return $date === $today->format('Y-m-d')
        ? 'regenerated_today'
        : 'generated_on_demand';
}

/**
 * @param array{
 *   generated: bool,
 *   path: string,
 *   savedAt: string,
 *   report: array<string, mixed>
 * } $loaded
 * @return array<string, mixed>
 */
function dailyReportBuildPnlDayPayload(string $date, array $loaded, string $source): array
{
    /** @var array<string, mixed> $report */
    $report = $loaded['report'];
    $hours = is_array($report['hours'] ?? null) ? $report['hours'] : [];
    $totals = is_array($report['totals'] ?? null) ? $report['totals'] : [];

    $netCost = dailyReportFloat($totals['net_cost'] ?? null);
    $savings = dailyReportFloat($totals['savings_eur'] ?? null);
    $chargeCost = dailyReportFloat($totals['charge_cost_eur'] ?? null);
    $spotNetCost = dailyReportComputeSpotNetCostFromHours($hours);
    $spotChargeCost = dailyReportComputeSpotChargeCostFromHours($hours);
    $batteryChargeCostMilliEur = dailyReportPnlInt($totals['battery_charge_cost_milli_eur'] ?? null);
    $batteryHomeSavingsMilliEur = dailyReportPnlInt($totals['battery_home_savings_milli_eur'] ?? null);
    $batteryExportRevenueMilliEur = dailyReportPnlInt($totals['battery_export_revenue_milli_eur'] ?? null);
    $batteryFlowPnlMilliEur = dailyReportPnlInt($totals['battery_flow_pnl_milli_eur'] ?? null);

    return [
        'date' => $date,
        'savedAt' => $loaded['savedAt'],
        'source' => $source,
        'price_file_found' => (bool)($report['price_file_found'] ?? false),
        'price_hours_available' => (int)($report['price_hours_available'] ?? 0),
        'pnl_eur' => dailyReportRound(dailyReportComputePnl($chargeCost, $savings, $netCost), 4),
        'spot_pnl_eur' => dailyReportRound(dailyReportComputePnl($spotChargeCost, $savings, $spotNetCost), 4),
        'net_cost' => dailyReportRound($netCost, 4),
        'spot_net_cost_eur' => dailyReportRound($spotNetCost, 4),
        'savings_eur' => dailyReportRound($savings, 4),
        'charge_cost_eur' => dailyReportRound($chargeCost, 4),
        'spot_charge_cost_eur' => dailyReportRound($spotChargeCost, 4),
        'battery_charge_grid_wh' => dailyReportPnlInt($totals['battery_charge_grid_wh'] ?? null),
        'battery_charge_surplus_wh' => dailyReportPnlInt($totals['battery_charge_surplus_wh'] ?? null),
        'battery_discharge_home_wh' => dailyReportPnlInt($totals['battery_discharge_home_wh'] ?? null),
        'battery_discharge_export_wh' => dailyReportPnlInt($totals['battery_discharge_export_wh'] ?? null),
        'battery_charge_cost_milli_eur' => $batteryChargeCostMilliEur,
        'battery_home_savings_milli_eur' => $batteryHomeSavingsMilliEur,
        'battery_export_revenue_milli_eur' => $batteryExportRevenueMilliEur,
        'battery_flow_pnl_milli_eur' => $batteryFlowPnlMilliEur,
        'battery_flow_pnl_eur' => $batteryFlowPnlMilliEur === null
            ? null
            : dailyReportRound($batteryFlowPnlMilliEur / 1000.0, 3),
        'battery_pnl_status' => is_string($totals['battery_pnl_status'] ?? null)
            ? $totals['battery_pnl_status']
            : null,
        'battery_pnl_method_version' => dailyReportPnlInt($totals['battery_pnl_method_version'] ?? null),
    ];
}
