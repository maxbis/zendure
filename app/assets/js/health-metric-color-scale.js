(function (root) {
    "use strict";

    /**
     * Health-metric colours aligned with the old GUI
     * (`main/assets/js/schedule_renderer.js`).
     *
     * Temperature: getTempColorEnhancedJS, clamped −10°C…40°C.
     * Wi-Fi: RSSI mapped to the discrete 0–10 signal-quality bands used by
     * the battery Health dialog.
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

    function wifiRssiDetails(rssi) {
        if (rssi === null || rssi === undefined || rssi === "") return null;
        const value = Number(rssi);
        if (!Number.isFinite(value)) return null;

        if (value < -90) return { score: 0, description: "No reception", color: "#6B7280" };
        if (value <= -86) return { score: 1, description: "Almost none", color: "#991B1B" };
        if (value <= -83) return { score: 2, description: "Extremely poor", color: "#DC2626" };
        if (value <= -81) return { score: 3, description: "Very poor", color: "#EA580C" };
        if (value <= -76) return { score: 4, description: "Poor", color: "#F97316" };
        if (value <= -71) return { score: 5, description: "Just enough", color: "#F59E0B" };
        if (value <= -68) return { score: 6, description: "Enough", color: "#EAB308" };
        if (value <= -64) return { score: 7, description: "Good", color: "#84CC16" };
        if (value <= -58) return { score: 8, description: "Very good", color: "#22C55E" };
        if (value <= -50) return { score: 9, description: "Excellent", color: "#16A34A" };
        return { score: 10, description: "Near perfect", color: "#15803D" };
    }

    function wifiRssiScore(rssi) {
        return wifiRssiDetails(rssi)?.score ?? null;
    }

    function wifiRssiColor(rssi) {
        return wifiRssiDetails(rssi)?.color ?? null;
    }

    root.GraphiteHealthMetricColorScale = Object.freeze({
        temperatureColor,
        wifiRssiDetails,
        wifiRssiScore,
        wifiRssiColor
    });
})(window);
