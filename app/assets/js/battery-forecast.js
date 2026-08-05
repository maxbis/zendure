(function (root, factory) {
    "use strict";

    const api = factory();
    if (typeof module === "object" && module.exports) module.exports = api;
    if (root) root.GraphiteBatteryForecast = api;
})(typeof window !== "undefined" ? window : globalThis, function () {
    "use strict";

    const DEFAULT_HOUSEHOLD_USAGE_W_BY_HOUR = Object.freeze([
        100, 100, 100, 100, 100, 100, 100, 100,
        220, 220, 220, 220, 220, 220, 220, 220,
        220, 220, 220, 220, 220, 220, 220, 220
    ]);
    const POWER_EFFICIENCY = 0.9;
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

    function conditionMatches(condition, batteryPercent) {
        const expected = finiteNumber(condition.value);
        if (expected === null) return true;
        switch (String(condition.op || "==")) {
            case ">": return batteryPercent > expected;
            case ">=": return batteryPercent >= expected;
            case "<": return batteryPercent < expected;
            case "<=": return batteryPercent <= expected;
            case "!=": return batteryPercent !== expected;
            case "==":
            case "=": return batteryPercent === expected;
            default: return true;
        }
    }

    function resolveRuntimeSlot(slot, batteryPercent) {
        const conditions = batteryConditions(slot);
        if (!conditions.length || conditions.every((condition) => conditionMatches(condition, batteryPercent))) {
            return { slot, primary: true, usedFallback: false, conditions };
        }

        return {
            slot: { value: Object.prototype.hasOwnProperty.call(slot || {}, "fallback_value") ? slot.fallback_value : 0 },
            primary: false,
            usedFallback: true,
            conditions
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
        } else if (mode === "netzero" || mode === "netzero-") {
            powerW = -householdUsageForHour(hour, householdUsageWByHour);
            source = "household_profile";
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

    function runtimeBoundary(conditions, powerW) {
        if (powerW < 0) {
            const floors = conditions
                .filter((condition) => [">", ">="].includes(String(condition.op || "")))
                .map((condition) => finiteNumber(condition.value))
                .filter((value) => value !== null);
            return floors.length ? { type: "floor", percent: Math.max(...floors) } : null;
        }
        if (powerW > 0) {
            const ceilings = conditions
                .filter((condition) => ["<", "<="].includes(String(condition.op || "")))
                .map((condition) => finiteNumber(condition.value))
                .filter((value) => value !== null);
            return ceilings.length ? { type: "ceiling", percent: Math.min(...ceilings) } : null;
        }
        return null;
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

    function transitionToFallback({
        boundary,
        startPercent,
        durationHours,
        primaryPower,
        originalSlot,
        hour,
        householdUsageWByHour,
        capacityWh,
        efficiency,
        minimumPercent,
        maximumPercent
    }) {
        if (!boundary || primaryPower.powerW === 0) return null;
        if (boundary.type === "floor" && boundary.percent < minimumPercent) return null;
        if (boundary.type === "ceiling" && boundary.percent > maximumPercent) return null;

        const percentPerHour = forecastDeltaPercent(primaryPower.powerW, 1, capacityWh, efficiency);
        const distancePercent = boundary.type === "floor"
            ? startPercent - boundary.percent
            : boundary.percent - startPercent;
        const ratePercentPerHour = Math.abs(percentPerHour);
        if (distancePercent < 0 || ratePercentPerHour <= 0) return null;

        const primaryDurationHours = distancePercent / ratePercentPerHour;
        if (primaryDurationHours > durationHours) return null;

        const fallbackDurationHours = Math.max(0, durationHours - primaryDurationHours);
        if (fallbackDurationHours <= 1e-9) return null;

        const fallbackSlot = {
            value: Object.prototype.hasOwnProperty.call(originalSlot || {}, "fallback_value")
                ? originalSlot.fallback_value
                : 0
        };
        const fallbackPower = effectivePower(fallbackSlot, hour, householdUsageWByHour, false);
        const fallbackDeltaPercent = forecastDeltaPercent(
            fallbackPower.powerW,
            fallbackDurationHours,
            capacityWh,
            efficiency
        );
        const endPercent = constrainedEndPercent(
            boundary.percent,
            fallbackDeltaPercent,
            minimumPercent,
            maximumPercent
        );

        return {
            endPercent,
            estimatedPowerW: (
                (primaryPower.powerW * primaryDurationHours)
                + (fallbackPower.powerW * fallbackDurationHours)
            ) / durationHours,
            source: fallbackPower.source,
            mode: fallbackPower.mode,
            usedFallback: true,
            transitionedToFallback: true,
            primaryPowerW: primaryPower.powerW,
            fallbackPowerW: fallbackPower.powerW,
            primaryDurationHours,
            fallbackDurationHours
        };
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
        householdUsageWByHour = DEFAULT_HOUSEHOLD_USAGE_W_BY_HOUR,
        efficiency = POWER_EFFICIENCY
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
                const resolved = resolveRuntimeSlot(originalSlot, runningPercent);
                const power = effectivePower(
                    resolved.slot,
                    hour,
                    householdUsageWByHour,
                    resolved.primary
                );
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

                const boundary = resolved.primary ? runtimeBoundary(resolved.conditions, power.powerW) : null;
                const fallbackTransition = resolved.primary ? transitionToFallback({
                    boundary,
                    startPercent,
                    durationHours,
                    primaryPower: power,
                    originalSlot,
                    hour,
                    householdUsageWByHour,
                    capacityWh: normalizedBattery.capacityWh,
                    efficiency: validEfficiency,
                    minimumPercent: normalizedBattery.minimumPercent,
                    maximumPercent: normalizedBattery.maximumPercent
                }) : null;
                if (fallbackTransition) endPercent = fallbackTransition.endPercent;
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
                        : fallbackTransition?.estimatedPowerW ?? power.powerW,
                    durationHours,
                    source: fallbackTransition?.source ?? power.source,
                    mode: fallbackTransition?.mode ?? power.mode,
                    usedFallback: fallbackTransition?.usedFallback ?? resolved.usedFallback,
                    transitionedToFallback: fallbackTransition?.transitionedToFallback ?? false,
                    primaryPowerW: fallbackTransition?.primaryPowerW ?? null,
                    fallbackPowerW: fallbackTransition?.fallbackPowerW ?? (resolved.usedFallback ? power.powerW : null),
                    primaryDurationHours: fallbackTransition?.primaryDurationHours ?? null,
                    fallbackDurationHours: fallbackTransition?.fallbackDurationHours ?? (resolved.usedFallback ? durationHours : null),
                    currentHour: slotStart <= currentTime && slotEnd > currentTime
                };
                runningPercent = endPercent;
            }
        });

        return forecast;
    }

    return Object.freeze({
        DEFAULT_HOUSEHOLD_USAGE_W_BY_HOUR,
        POWER_EFFICIENCY,
        buildForecast
    });
});
