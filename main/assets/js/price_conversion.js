(function () {
    'use strict';

    function getPriceConversionNumberConfig(key) {
        const source = window.PRICE_CONVERSION_CONFIG;
        const value = source[key];
        if (typeof value !== 'number' || !Number.isFinite(value)) {
            throw new Error(`Missing required shared price-conversion setting: ${key}.`);
        }
        return value;
    }

    function getPriceConversionPrecision(precision, fallback) {
        const value = Number.isInteger(precision) ? precision : fallback;
        return Math.max(0, value);
    }

    window.getPriceConversionConfig = function getPriceConversionConfig() {
        if (!window.PRICE_CONVERSION_CONFIG || typeof window.PRICE_CONVERSION_CONFIG !== 'object') {
            throw new Error('Missing required shared price-conversion settings.');
        }
        const supplierMarkupEurPerKwh = getPriceConversionNumberConfig('supplierMarkupEurPerKwh');
        const energyTaxEurPerKwh = getPriceConversionNumberConfig('energyTaxEurPerKwh');
        const vatMultiplier = getPriceConversionNumberConfig('vatMultiplier');
        const consumerPrecision = window.PRICE_CONVERSION_CONFIG.consumerPrecision;
        const spotPrecision = window.PRICE_CONVERSION_CONFIG.spotPrecision;
        if (
            supplierMarkupEurPerKwh < 0
            || energyTaxEurPerKwh < 0
            || vatMultiplier <= 0
            || !Number.isInteger(consumerPrecision)
            || !Number.isInteger(spotPrecision)
            || consumerPrecision < 0
            || consumerPrecision > 12
            || spotPrecision < 0
            || spotPrecision > 12
        ) {
            throw new Error('The shared price-conversion settings are invalid.');
        }
        return {
            supplierMarkupEurPerKwh,
            energyTaxEurPerKwh,
            vatMultiplier,
            consumerPrecision,
            spotPrecision
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
