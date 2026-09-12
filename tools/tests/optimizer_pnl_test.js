"use strict";

const assert = require("node:assert/strict");
const { estimateDailyComparison } = require("../../app/assets/js/optimizer-pnl.js");

const decisions = [
    {
        start: "2026-09-12T22:00:00+02:00",
        end: "2026-09-12T23:00:00+02:00",
        load_w: 100,
        pv_w: 0,
        import_price_eur_per_kwh: 1,
        export_price_eur_per_kwh: 0.5,
        expected_cost_eur: 0,
        start_soc_percent: 100,
        end_soc_percent: 90,
    },
    {
        start: "2026-09-13T00:00:00+02:00",
        end: "2026-09-14T00:00:00+02:00",
        load_w: 0,
        pv_w: 100,
        import_price_eur_per_kwh: 1,
        export_price_eur_per_kwh: 0.5,
        expected_cost_eur: -1.2,
        start_soc_percent: 90,
        end_soc_percent: 90,
    },
];
const schedules = {
    20260912: [{ time: "2200", value: 0 }],
    20260913: [{ time: "0000", value: "netzero+" }],
};
const result = estimateDailyComparison(decisions, schedules, {
    capacityWh: 1000,
    minSocPercent: 0,
    maxSocPercent: 100,
    startingSocPercent: 100,
    maxChargePowerW: 100,
    maxDischargePowerW: 100,
    roundTripEfficiency: 1,
});

assert.equal(result.length, 2);
assert.equal(result[0].date, "2026-09-12");
assert.equal(result[0].currentPnlEur, -0.1);
assert.equal(result[0].optimizedPnlEur, 0);
assert.equal(result[0].differenceEur, 0.1);
assert.equal(result[0].isPartialDay, true);
assert.equal(result[1].currentPnlEur, 1.2);
assert.equal(result[1].optimizedPnlEur, 1.2);
assert.equal(result[1].differenceEur, 0);
assert.equal(result[1].isPartialDay, false);
assert.equal(result[1].currentEndSocPercent, 100);

console.log("Optimizer daily P&L tests passed.");
