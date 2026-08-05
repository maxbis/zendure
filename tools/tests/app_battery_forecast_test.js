"use strict";

const assert = require("node:assert/strict");
const {
    DEFAULT_HOUSEHOLD_USAGE_W_BY_HOUR,
    POWER_EFFICIENCY,
    buildForecast
} = require("../../app/assets/js/battery-forecast.js");

const DATE = "20260805";
const BATTERY = {
    percent: 50,
    capacityWh: 5760,
    minimumPercent: 15,
    maximumPercent: 91,
    stale: false
};

function slots(value = 0) {
    return Array.from({ length: 24 }, () => ({ value }));
}

function near(actual, expected, tolerance = 0.000001) {
    assert.ok(Math.abs(actual - expected) <= tolerance, `${actual} is not within ${tolerance} of ${expected}`);
}

assert.deepEqual(DEFAULT_HOUSEHOLD_USAGE_W_BY_HOUR.slice(0, 8), Array(8).fill(100));
assert.deepEqual(DEFAULT_HOUSEHOLD_USAGE_W_BY_HOUR.slice(8), Array(16).fill(220));
assert.equal(Object.isFrozen(DEFAULT_HOUSEHOLD_USAGE_W_BY_HOUR), true);

{
    const forecast = buildForecast({
        now: new Date(2026, 7, 5, 7, 30),
        battery: BATTERY,
        days: [{ date: DATE, slots: slots("netzero-") }]
    });
    const current = forecast[`${DATE}0700`];
    const next = forecast[`${DATE}0800`];
    near(current.durationHours, 0.5);
    near(current.estimatedPowerW, -100);
    near(current.endPercent, 50 - ((100 * 0.5 / POWER_EFFICIENCY) / 5760) * 100);
    near(next.startPercent, current.endPercent);
    near(next.endPercent, next.startPercent - ((220 / POWER_EFFICIENCY) / 5760) * 100);
    assert.equal(current.source, "household_profile");
}

{
    const fixedSlots = slots(0);
    fixedSlots[10] = { value: -500 };
    const forecast = buildForecast({
        now: new Date(2026, 7, 5, 10, 0),
        battery: BATTERY,
        days: [{ date: DATE, slots: fixedSlots }]
    });
    const hour = forecast[`${DATE}1000`];
    assert.equal(hour.estimatedPowerW, -500);
    assert.equal(hour.source, "scheduled_power");
}

{
    const runtimeSlots = slots(0);
    runtimeSlots[12] = {
        value: "netzero-",
        runtime_conditions: [{ field: "electricity_level", op: ">", value: 30 }],
        fallback_value: 0
    };
    const forecast = buildForecast({
        now: new Date(2026, 7, 5, 12, 0),
        battery: { ...BATTERY, percent: 31 },
        days: [{ date: DATE, slots: runtimeSlots }]
    });
    near(forecast[`${DATE}1200`].endPercent, 30);
    near(forecast[`${DATE}1300`].startPercent, 30);
}

{
    const limitedSlots = slots(0);
    limitedSlots[14] = { value: "netzero+", min_power: 100, max_power: 400 };
    const forecast = buildForecast({
        now: new Date(2026, 7, 5, 14, 0),
        battery: BATTERY,
        days: [{ date: DATE, slots: limitedSlots }]
    });
    const hour = forecast[`${DATE}1400`];
    assert.equal(hour.estimatedPowerW, 100);
    near(hour.endPercent, 50 + ((100 * POWER_EFFICIENCY) / 5760) * 100);
}

{
    const forecast = buildForecast({
        now: new Date(2026, 7, 5, 20, 0),
        battery: { ...BATTERY, stale: true },
        days: [{ date: DATE, slots: slots("netzero-") }]
    });
    assert.deepEqual(forecast, {});
}

console.log("app battery forecast: OK");
