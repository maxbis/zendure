<?php
/**
 * Shared price conversion helpers.
 *
 * Converts between source/spot price (EUR/kWh) and consumer price (EUR/kWh)
 * using the shared system supplier markup, energy tax, and VAT settings.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/common/php/system_config.php';

/**
 * @return array{
 *   supplierMarkupEurPerKwh: float,
 *   energyTaxEurPerKwh: float,
 *   vatMultiplier: float,
 *   consumerPrecision: int,
 *   spotPrecision: int
 * }
 */
function getPriceConversionConfig(): array {
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $systemConfig = loadSystemConfig();
    $config = $systemConfig['priceConversion'];

    return $config;
}

function convertSpotToConsumerPrice(?float $spotEurPerKwh, ?int $precision = null): ?float {
    if ($spotEurPerKwh === null || !is_finite($spotEurPerKwh)) {
        return null;
    }

    $config = getPriceConversionConfig();
    $digits = normalizePriceConversionPrecision($precision ?? $config['consumerPrecision']);

    return round(
        ($spotEurPerKwh + $config['supplierMarkupEurPerKwh'] + $config['energyTaxEurPerKwh']) * $config['vatMultiplier'],
        $digits
    );
}

function convertConsumerToSpotPrice(?float $consumerEurPerKwh, ?int $precision = null): ?float {
    if ($consumerEurPerKwh === null || !is_finite($consumerEurPerKwh)) {
        return null;
    }

    $config = getPriceConversionConfig();
    $digits = normalizePriceConversionPrecision($precision ?? $config['spotPrecision']);

    return round(
        ($consumerEurPerKwh / $config['vatMultiplier']) - $config['supplierMarkupEurPerKwh'] - $config['energyTaxEurPerKwh'],
        $digits
    );
}

function normalizePriceConversionPrecision(int $precision): int {
    return max(0, $precision);
}
