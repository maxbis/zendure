<?php
/**
 * Shared price conversion helpers.
 *
 * Converts between source/spot price (EUR/kWh) and consumer price (EUR/kWh)
 * using config-backed supplier markup, energy tax, and VAT settings.
 */

declare(strict_types=1);

require_once __DIR__ . '/config_loader.php';

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

    $defaults = [
        'supplierMarkupEurPerKwh' => 0.0219,
        'energyTaxEurPerKwh' => 0.0898,
        'vatMultiplier' => 1.21,
        'consumerPrecision' => 4,
        'spotPrecision' => 6,
    ];

    $config = [
        'supplierMarkupEurPerKwh' => getPriceConversionFloatConfig('supplierMarkupEurPerKwh', $defaults['supplierMarkupEurPerKwh']),
        'energyTaxEurPerKwh' => getPriceConversionFloatConfig('energyTaxEurPerKwh', $defaults['energyTaxEurPerKwh']),
        'vatMultiplier' => getPriceConversionPositiveFloatConfig('vatMultiplier', $defaults['vatMultiplier']),
        'consumerPrecision' => getPriceConversionPrecisionConfig('consumerPrecision', $defaults['consumerPrecision']),
        'spotPrecision' => getPriceConversionPrecisionConfig('spotPrecision', $defaults['spotPrecision']),
    ];

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

function getPriceConversionFloatConfig(string $key, float $fallback): float {
    $value = ConfigLoader::get('priceConversion.' . $key, $fallback);
    if (is_int($value) || is_float($value)) {
        return (float)$value;
    }
    if (is_string($value) && is_numeric(trim($value))) {
        return (float)$value;
    }
    return $fallback;
}

function getPriceConversionPositiveFloatConfig(string $key, float $fallback): float {
    $value = getPriceConversionFloatConfig($key, $fallback);
    return $value > 0 ? $value : $fallback;
}

function getPriceConversionPrecisionConfig(string $key, int $fallback): int {
    $value = ConfigLoader::get('priceConversion.' . $key, $fallback);
    if (is_int($value)) {
        return normalizePriceConversionPrecision($value);
    }
    if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
        return normalizePriceConversionPrecision((int)$value);
    }
    return normalizePriceConversionPrecision($fallback);
}

function normalizePriceConversionPrecision(int $precision): int {
    return max(0, $precision);
}
