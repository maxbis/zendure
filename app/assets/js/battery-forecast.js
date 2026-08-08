(function (root, factory) {
    "use strict";

    const api = factory();
    if (typeof module === "object" && module.exports) module.exports = api;
    if (root) root.GraphiteBatteryForecast = api;
})(typeof window !== "undefined" ? window : globalThis, function () {
    "use strict";

    const BATTERY_FIELDS = new Set(["electricity_level", "electric_level", "electricLevel"]);

    function finiteNumber(value) {
        if (value === null || value === undefined || value === "") return null;
        const number = Number(value);
        return Number.isFinite(number) ? number : null;
    }

    function clamp(value, minimum, maximum) {
        return Math.min(maximum, Math.max(minimum, value));
    }

    function pad(value) {
        return String(value).padStart(2, "0");
    }

    function dateForHour(dateKey, hour) {
        if (!/^\d{8}$/.test(String(dateKey || ""))) return null;
        return new Date(
            Number(dateKey.slice(0, 4)),
            Number(dateKey.slice(4, 6)) - 1,
            Number(dateKey.slice(6, 8)),
            hour,
            0,
            0,
            0
        );
    }

    function normalizedMode(value) {
        const normalized = String(value ?? "auto").trim().toLowerCase();
        if (normalized === "netzero-plus") return "netzero+";
        if (normalized === "netzero-minus") return "netzero-";
        return normalized;
    }

    function batteryConditions(slot) {
        return (Array.isArray(slot?.runtime_conditions) ? slot.runtime_conditions : [])
            .filter((condition) => condition && BATTERY_FIELDS.has(String(condition.field || "")));
    }

    function conservativeRuntimePower(slot, hour, householdUsageWByHour) {
        const conditions = batteryConditions(slot);
        if (!conditions.length) return null;
        const primaryPower = effectivePower(slot, hour, householdUsageWByHour, true);
        const fallbackSlot = {
            value: Object.prototype.hasOwnProperty.call(slot || {}, "fallback_value")
                ? slot.fallback_value
                : 0
        };
        const fallbackPower = effectivePower(fallbackSlot, hour, householdUsageWByHour, false);
        return {
            power: {
                powerW: Math.min(0, Math.max(primaryPower.powerW, fallbackPower.powerW)),
                source: "runtime_condition_conservative",
                mode: "runtime-conservative"
            },
            primaryPower,
            fallbackPower
        };
    }

    function householdUsageForHour(hour, usageByHour) {
        const value = Array.isArray(usageByHour) ? finiteNumber(usageByHour[hour]) : null;
        return value !== null && value >= 0 ? value : 0;
    }

    function effectivePower(slot, hour, householdUsageWByHour, primary) {
        const numeric = finiteNumber(slot?.value);
        let powerW = 0;
        let source = "automatic_unavailable";
        const mode = normalizedMode(slot?.value);

        if (numeric !== null) {
            powerW = numeric;
            source = "scheduled_power";
        } else if (mode === "netzero-") {
            powerW = -householdUsageForHour(hour, householdUsageWByHour);
            source = "household_profile";
        } else if (mode === "netzero") {
            powerW = 0;
            source = "bidirectional_neutral";
        } else if (mode === "netzero+") {
            powerW = 0;
            source = "solar_forecast_unavailable";
        } else if (mode === "standby") {
            source = "scheduled_power";
        }

        if (primary && ["netzero", "netzero-", "netzero+"].includes(mode)) {
            const minimum = finiteNumber(slot?.min_power);
            const maximum = finiteNumber(slot?.max_power);
            if (minimum !== null) powerW = Math.max(powerW, minimum);
            if (maximum !== null) powerW = Math.min(powerW, maximum);
        }

        return { powerW, source, mode };
    }

    function forecastDeltaPercent(powerW, durationHours, capacityWh, efficiency) {
        if (powerW < 0) return -((Math.abs(powerW) * durationHours / efficiency) / capacityWh) * 100;
        if (powerW > 0) return ((powerW * durationHours * efficiency) / capacityWh) * 100;
        return 0;
    }

    function constrainedEndPercent(startPercent, deltaPercent, minimumPercent, maximumPercent) {
        if (deltaPercent < 0 && startPercent <= minimumPercent) return startPercent;
        if (deltaPercent > 0 && startPercent >= maximumPercent) return startPercent;
        if (deltaPercent === 0) return startPercent;
        return clamp(startPercent + deltaPercent, minimumPercent, maximumPercent);
    }

    function validBatteryState(battery) {
        const percent = finiteNumber(battery?.percent);
        const capacityWh = finiteNumber(battery?.capacityWh);
        const minimumPercent = finiteNumber(battery?.minimumPercent);
        const maximumPercent = finiteNumber(battery?.maximumPercent);
        if (
            percent === null
            || capacityWh === null
            || capacityWh <= 0
            || minimumPercent === null
            || maximumPercent === null
            || minimumPercent < 0
            || maximumPercent > 100
            || minimumPercent >= maximumPercent
            || battery?.stale === true
        ) return null;
        return { percent: clamp(percent, 0, 100), capacityWh, minimumPercent, maximumPercent };
    }

    function buildForecast({
        now = new Date(),
        battery,
        days = [],
        householdUsageWByHour,
        efficiency
    } = {}) {
        const normalizedBattery = validBatteryState(battery);
        const validEfficiency = finiteNumber(efficiency);
        if (!normalizedBattery || validEfficiency === null || validEfficiency <= 0 || validEfficiency > 1) return {};

        const currentTime = now instanceof Date ? now : new Date(now);
        if (!Number.isFinite(currentTime.getTime())) return {};

        let runningPercent = normalizedBattery.percent;
        const forecast = {};

        days.forEach((day) => {
            const slots = Array.isArray(day?.slots) ? day.slots : [];
            for (let hour = 0; hour < 24; hour += 1) {
                const slotStart = dateForHour(day?.date, hour);
                if (!slotStart) continue;
                const slotEnd = new Date(slotStart.getTime() + 60 * 60 * 1000);
                if (slotEnd <= currentTime) continue;

                const durationHours = slotStart <= currentTime
                    ? clamp((slotEnd.getTime() - currentTime.getTime()) / (60 * 60 * 1000), 0, 1)
                    : 1;
                if (durationHours <= 0) continue;

                const originalSlot = slots[hour] || { value: "auto" };
                const plannedTargetMode = String(originalSlot?.planning?.mode || "");
                const isCalculatedTarget = ["empty_at_solar_charge", "full_at_netzero_minus"].includes(plannedTargetMode);
                const conservativeRuntime = isCalculatedTarget
                    ? null
                    : conservativeRuntimePower(originalSlot, hour, householdUsageWByHour);
                const power = conservativeRuntime?.power
                    ?? effectivePower(originalSlot, hour, householdUsageWByHour, true);
                const startPercent = runningPercent;
                const rawDeltaPercent = forecastDeltaPercent(
                    power.powerW,
                    durationHours,
                    normalizedBattery.capacityWh,
                    validEfficiency
                );
                let endPercent = constrainedEndPercent(
                    startPercent,
                    rawDeltaPercent,
                    normalizedBattery.minimumPercent,
                    normalizedBattery.maximumPercent
                );
                endPercent = clamp(endPercent, 0, 100);

                const key = `${day.date}${pad(hour)}00`;
                forecast[key] = {
                    key,
                    date: day.date,
                    hour,
                    startPercent,
                    endPercent,
                    deltaPercent: endPercent - startPercent,
                    estimatedPowerW: Math.abs(endPercent - startPercent) < 1e-9
                        ? 0
                        : power.powerW,
                    durationHours,
                    source: power.source,
                    mode: power.mode,
                    usedFallback: false,
                    transitionedToFallback: false,
                    primaryPowerW: conservativeRuntime?.primaryPower.powerW ?? null,
                    fallbackPowerW: conservativeRuntime?.fallbackPower.powerW ?? null,
                    primaryDurationHours: null,
                    fallbackDurationHours: null,
                    currentHour: slotStart <= currentTime && slotEnd > currentTime
                };
                runningPercent = endPercent;
            }
        });

        return forecast;
    }

    return Object.freeze({
        buildForecast
    });
});
