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
    ];
}
