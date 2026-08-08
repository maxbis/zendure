(function (root) {
    "use strict";

    /**
     * Health-metric colours aligned with the old GUI
     * (`main/assets/js/schedule_renderer.js`).
     *
     * Temperature: getTempColorEnhancedJS, clamped −10°C…40°C.
     * Wi-Fi: RSSI score from −90…−30 dBm mapped to a 0–10 scale, then banded.
     */
    function clamp(value, minimum, maximum) {
        return Math.min(maximum, Math.max(minimum, value));
    }

    function temperatureColor(celsius) {
        const temp = Number(celsius);
        if (!Number.isFinite(temp)) return null;
        const clamped = clamp(temp, -10, 40);

        if (clamped <= 0) return "#4fc3f7";
        if (clamped <= 5) return "#fff176";
        if (clamped <= 15) return "#ffe500";
        if (clamped <= 25) return "#81c784";
        if (clamped <= 30) return "#ff9800";
        return "#e57373";
    }

    function wifiRssiScore(rssi) {
        const value = Number(rssi);
        if (!Number.isFinite(value)) return null;
        return clamp(((value - (-90)) / ((-30) - (-90))) * 10, 0, 10);
    }

    function wifiRssiColor(rssi) {
        const score = wifiRssiScore(rssi);
        if (score === null) return null;
        if (score >= 8) return "#81c784";
        if (score >= 5) return "#fff176";
        if (score >= 3) return "#ff9800";
        return "#e57373";
    }

    root.GraphiteHealthMetricColorScale = Object.freeze({
        temperatureColor,
        wifiRssiScore,
        wifiRssiColor
    });
})(window);
