(function () {
    'use strict';

    function getPriceConversionNumberConfig(key, fallback) {
        const source = window.PRICE_CONVERSION_CONFIG || {};
        const value = source[key];
        return typeof value === 'number' && Number.isFinite(value) ? value : fallback;
    }

    function getPriceConversionPrecision(precision, fallback) {
        const value = Number.isInteger(precision) ? precision : fallback;
        return Math.max(0, value);
    }

    window.getPriceConversionConfig = function getPriceConversionConfig() {
        return {
            supplierMarkupEurPerKwh: getPriceConversionNumberConfig('supplierMarkupEurPerKwh', 0.0219),
            energyTaxEurPerKwh: getPriceConversionNumberConfig('energyTaxEurPerKwh', 0.0898),
            vatMultiplier: Math.max(getPriceConversionNumberConfig('vatMultiplier', 1.21), Number.EPSILON),
            consumerPrecision: getPriceConversionPrecision(
                window.PRICE_CONVERSION_CONFIG ? window.PRICE_CONVERSION_CONFIG.consumerPrecision : undefined,
                4
            ),
            spotPrecision: getPriceConversionPrecision(
                window.PRICE_CONVERSION_CONFIG ? window.PRICE_CONVERSION_CONFIG.spotPrecision : undefined,
                6
            )
        };
    };

    window.convertSpotToConsumerPrice = function convertSpotToConsumerPrice(value, precision) {
        if (value == null || typeof value !== 'number' || Number.isNaN(value)) return null;
        const config = window.getPriceConversionConfig();
        const digits = getPriceConversionPrecision(precision, config.consumerPrecision);
        const consumer = (value + config.supplierMarkupEurPerKwh + config.energyTaxEurPerKwh) * config.vatMultiplier;
        return Number(consumer.toFixed(digits));
    };

    window.convertConsumerToSpotPrice = function convertConsumerToSpotPrice(value, precision) {
        if (value == null || typeof value !== 'number' || Number.isNaN(value)) return null;
        const config = window.getPriceConversionConfig();
        const digits = getPriceConversionPrecision(precision, config.spotPrecision);
        const spot = (value / config.vatMultiplier) - config.supplierMarkupEurPerKwh - config.energyTaxEurPerKwh;
        return Number(spot.toFixed(digits));
    };
}());
