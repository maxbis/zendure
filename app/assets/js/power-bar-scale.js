(function (root) {
    "use strict";

    /**
     * Nonlinear visual scale for bidirectional power bars.
     *
     * Percentages are relative to one side of the configured power range.
     * For example, with a 1600 W limit, 160 W is 10 actual percent.
     *
     * Add, remove, or adjust anchor points here to fine-tune the visual
     * translation. Values between anchors are linearly interpolated.
     */
    const POWER_BAR_SCALE_ANCHORS = Object.freeze([
        Object.freeze({ actualPercent: 0, displayPercent: 0 }),
        Object.freeze({ actualPercent: 1, displayPercent: 10 }),
        Object.freeze({ actualPercent: 5, displayPercent: 20 }),
        Object.freeze({ actualPercent: 10, displayPercent: 25 }),
        Object.freeze({ actualPercent: 20, displayPercent: 30 }),
        Object.freeze({ actualPercent: 30, displayPercent: 40 }),
        Object.freeze({ actualPercent: 50, displayPercent: 50 }),
        Object.freeze({ actualPercent: 75, displayPercent: 75 }),
        Object.freeze({ actualPercent: 100, displayPercent: 100 })
    ]);

    function clampPercent(value) {
        const numeric = Number(value);
        if (!Number.isFinite(numeric)) return 0;
        return Math.min(100, Math.max(0, numeric));
    }

    function translate(actualPercent, anchors = POWER_BAR_SCALE_ANCHORS) {
        const actual = clampPercent(actualPercent);
        if (!Array.isArray(anchors) || anchors.length < 2) return actual;

        if (actual <= anchors[0].actualPercent) {
            return clampPercent(anchors[0].displayPercent);
        }

        for (let index = 1; index < anchors.length; index += 1) {
            const lower = anchors[index - 1];
            const upper = anchors[index];

            if (actual <= upper.actualPercent) {
                const actualSpan = upper.actualPercent - lower.actualPercent;
                if (actualSpan <= 0) return clampPercent(upper.displayPercent);

                const progress = (actual - lower.actualPercent) / actualSpan;
                const displayed = lower.displayPercent
                    + progress * (upper.displayPercent - lower.displayPercent);
                return clampPercent(displayed);
            }
        }

        return clampPercent(anchors[anchors.length - 1].displayPercent);
    }

    root.GraphitePowerBarScale = Object.freeze({
        anchors: POWER_BAR_SCALE_ANCHORS,
        translate
    });
})(window);

