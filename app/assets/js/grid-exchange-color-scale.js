(function (root) {
    "use strict";

    /**
     * Grid-exchange colour boundaries.
     *
     * Negative power means export; positive power means import. Adjust the
     * watt boundaries or colours here to fine-tune the visual states.
     */
    const GRID_EXCHANGE_COLOR_CONFIG = Object.freeze({
        exportGreenBelowW: -10,
        balancedAtW: 0,
        importRedAboveW: 10,
        colors: Object.freeze({
            exporting: "#79d484",
            exportNearZero: "#f2a84a",
            importNearZero: "#c5ca62",
            importing: "#ff625f"
        })
    });

    function colorFor(powerW, config = GRID_EXCHANGE_COLOR_CONFIG) {
        const power = Number(powerW);
        if (!Number.isFinite(power)) return null;

        if (power < config.exportGreenBelowW) return config.colors.exporting;
        if (power < config.balancedAtW) return config.colors.exportNearZero;
        if (power <= config.importRedAboveW) return config.colors.importNearZero;
        return config.colors.importing;
    }

    root.GraphiteGridExchangeColorScale = Object.freeze({
        config: GRID_EXCHANGE_COLOR_CONFIG,
        colorFor
    });
})(window);
