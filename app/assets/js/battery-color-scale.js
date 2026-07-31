(function (root) {
    "use strict";

    /**
     * Battery percentage colour anchors.
     *
     * Adjust the percentages or colours here to fine-tune the transition.
     * Values between anchors are linearly interpolated in RGB space.
     */
    const BATTERY_COLOR_STOPS = Object.freeze([
        Object.freeze({ percent: 0, color: "#ff625f", label: "red" }),
        Object.freeze({ percent: 20, color: "#ff625f", label: "red" }),
        Object.freeze({ percent: 30, color: "#f2a84a", label: "orange" }),
        Object.freeze({ percent: 40, color: "#c5ca62", label: "yellow-green" }),
        Object.freeze({ percent: 50, color: "#9ed17a", label: "light-green" }),
        Object.freeze({ percent: 60, color: "#79d484", label: "green" }),
        Object.freeze({ percent: 100, color: "#79d484", label: "green" })
    ]);

    function clampPercent(value) {
        const numeric = Number(value);
        if (!Number.isFinite(numeric)) return 0;
        return Math.min(100, Math.max(0, numeric));
    }

    function hexToRgb(hex) {
        const value = hex.replace("#", "");
        return {
            red: Number.parseInt(value.slice(0, 2), 16),
            green: Number.parseInt(value.slice(2, 4), 16),
            blue: Number.parseInt(value.slice(4, 6), 16)
        };
    }

    function toHex(value) {
        return Math.round(value).toString(16).padStart(2, "0");
    }

    function mixColors(lowerColor, upperColor, progress) {
        const lower = hexToRgb(lowerColor);
        const upper = hexToRgb(upperColor);
        const mix = (start, end) => start + (end - start) * progress;

        return `#${toHex(mix(lower.red, upper.red))}${toHex(mix(lower.green, upper.green))}${toHex(mix(lower.blue, upper.blue))}`;
    }

    function colorFor(percent, stops = BATTERY_COLOR_STOPS) {
        const actual = clampPercent(percent);
        if (!Array.isArray(stops) || stops.length < 2) return "#79d484";

        if (actual <= stops[0].percent) return stops[0].color;

        for (let index = 1; index < stops.length; index += 1) {
            const lower = stops[index - 1];
            const upper = stops[index];

            if (actual <= upper.percent) {
                const span = upper.percent - lower.percent;
                if (span <= 0) return upper.color;
                return mixColors(lower.color, upper.color, (actual - lower.percent) / span);
            }
        }

        return stops[stops.length - 1].color;
    }

    root.GraphiteBatteryColorScale = Object.freeze({
        stops: BATTERY_COLOR_STOPS,
        colorFor
    });
})(window);
