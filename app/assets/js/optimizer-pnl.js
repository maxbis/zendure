(() => {
    "use strict";

    function numberOr(value, fallback = 0) {
        const number = Number(value);
        return Number.isFinite(number) ? number : fallback;
    }

    function clamp(value, minimum, maximum) {
        return Math.min(maximum, Math.max(minimum, value));
    }

    function dateKey(isoValue) {
        return String(isoValue || "").slice(0, 10);
    }

    function compactDateKey(isoValue) {
        return dateKey(isoValue).replaceAll("-", "");
    }

    function hourKey(isoValue) {
        return `${String(isoValue || "").slice(11, 13)}00`;
    }

    function durationHours(decision) {
        const start = new Date(decision.start).getTime();
        const end = new Date(decision.end).getTime();
        if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start) return 0;
        return (end - start) / 3600000;
    }

    function currentSlot(decision, schedules) {
        const slots = schedules?.[compactDateKey(decision.start)] || [];
        const time = hourKey(decision.start);
        return slots.find((slot) => String(slot?.time ?? "").padStart(4, "0") === time) || null;
    }

    function desiredCurrentPower(slot, decision) {
        if (!slot) return { powerW: 0, uncertain: true };
        const numericValue = Number(slot.value);
        let powerW;
        let uncertain = false;
        if (slot.value !== null && slot.value !== "" && Number.isFinite(numericValue)) {
            powerW = numericValue;
        } else {
            const netLoadW = numberOr(decision.load_w) - numberOr(decision.pv_w);
            const netzeroPowerW = -netLoadW;
            if (slot.value === "netzero+") {
                powerW = Math.max(0, netzeroPowerW);
            } else if (slot.value === "netzero-") {
                powerW = Math.min(0, netzeroPowerW);
            } else if (slot.value === "netzero") {
                powerW = netzeroPowerW;
            } else {
                powerW = 0;
                uncertain = true;
            }
        }

        if (slot.min_power !== null && slot.min_power !== undefined && Number.isFinite(Number(slot.min_power))) {
            powerW = Math.max(powerW, Number(slot.min_power));
        }
        if (slot.max_power !== null && slot.max_power !== undefined && Number.isFinite(Number(slot.max_power))) {
            powerW = Math.min(powerW, Number(slot.max_power));
        }
        return { powerW, uncertain };
    }

    function realizedBatteryPower(desiredPowerW, energyWh, duration, options) {
        const maxChargePowerW = Math.max(0, numberOr(options.maxChargePowerW));
        const maxDischargePowerW = Math.max(0, numberOr(options.maxDischargePowerW));
        let powerW = clamp(desiredPowerW, -maxDischargePowerW, maxChargePowerW);
        if (duration <= 0) return { powerW: 0, energyWh };

        if (powerW > 0) {
            const roomWh = Math.max(0, options.maxEnergyWh - energyWh);
            powerW = Math.min(powerW, roomWh / (duration * options.chargeEfficiency));
            energyWh += powerW * duration * options.chargeEfficiency;
        } else if (powerW < 0) {
            const availableWh = Math.max(0, energyWh - options.minEnergyWh);
            powerW = -Math.min(Math.abs(powerW), availableWh * options.dischargeEfficiency / duration);
            energyWh -= Math.abs(powerW) * duration / options.dischargeEfficiency;
        }
        return { powerW, energyWh: clamp(energyWh, options.minEnergyWh, options.maxEnergyWh) };
    }

    function gridPnl(gridPowerW, duration, importPrice, exportPrice) {
        const gridKwh = gridPowerW * duration / 1000;
        if (gridKwh >= 0) return -(gridKwh * importPrice);
        return -(gridKwh * exportPrice);
    }

    function estimateComparison(decisions, schedules, rawOptions) {
        const options = {
            capacityWh: Math.max(1, numberOr(rawOptions?.capacityWh, 1)),
            minSocPercent: clamp(numberOr(rawOptions?.minSocPercent), 0, 100),
            maxSocPercent: clamp(numberOr(rawOptions?.maxSocPercent, 100), 0, 100),
            startingSocPercent: clamp(numberOr(rawOptions?.startingSocPercent), 0, 100),
            maxChargePowerW: Math.max(0, numberOr(rawOptions?.maxChargePowerW)),
            maxDischargePowerW: Math.max(0, numberOr(rawOptions?.maxDischargePowerW)),
        };
        const efficiency = Math.sqrt(clamp(numberOr(rawOptions?.roundTripEfficiency, 1), 0.01, 1));
        options.chargeEfficiency = efficiency;
        options.dischargeEfficiency = efficiency;
        options.minEnergyWh = options.capacityWh * options.minSocPercent / 100;
        options.maxEnergyWh = options.capacityWh * options.maxSocPercent / 100;

        let currentEnergyWh = clamp(
            options.capacityWh * options.startingSocPercent / 100,
            options.minEnergyWh,
            options.maxEnergyWh
        );
        const days = new Map();
        const slots = [];

        (Array.isArray(decisions) ? decisions : []).forEach((decision) => {
            const dayKey = dateKey(decision.start);
            if (!days.has(dayKey)) {
                days.set(dayKey, {
                    date: dayKey,
                    currentPnlEur: 0,
                    optimizedPnlEur: 0,
                    currentStartSocPercent: currentEnergyWh / options.capacityWh * 100,
                    currentEndSocPercent: currentEnergyWh / options.capacityWh * 100,
                    optimizedStartSocPercent: numberOr(decision.start_soc_percent),
                    optimizedEndSocPercent: numberOr(decision.end_soc_percent),
                    uncertainSlots: 0,
                    segmentCount: 0,
                    periodStart: decision.start,
                    periodEnd: decision.end,
                });
            }
            const day = days.get(dayKey);
            const duration = durationHours(decision);
            const slot = currentSlot(decision, schedules);
            const desired = desiredCurrentPower(slot, decision);
            const currentStartSocPercent = currentEnergyWh / options.capacityWh * 100;
            const realized = realizedBatteryPower(desired.powerW, currentEnergyWh, duration, options);
            currentEnergyWh = realized.energyWh;
            const currentGridW = numberOr(decision.load_w) - numberOr(decision.pv_w) + realized.powerW;
            day.currentPnlEur += gridPnl(
                currentGridW,
                duration,
                numberOr(decision.import_price_eur_per_kwh),
                numberOr(decision.export_price_eur_per_kwh)
            );
            day.optimizedPnlEur += -numberOr(decision.expected_cost_eur);
            day.currentEndSocPercent = currentEnergyWh / options.capacityWh * 100;
            day.optimizedEndSocPercent = numberOr(decision.end_soc_percent);
            day.uncertainSlots += desired.uncertain ? 1 : 0;
            day.segmentCount++;
            day.periodEnd = decision.end;
            slots.push({
                start: decision.start,
                end: decision.end,
                currentBatteryPowerW: realized.powerW,
                currentGridPowerW: currentGridW,
                currentStartSocPercent,
                currentEndSocPercent: currentEnergyWh / options.capacityWh * 100,
                optimizedBatteryPowerW: numberOr(decision.battery_power_w),
                optimizedGridPowerW: numberOr(decision.grid_power_w),
                optimizedStartSocPercent: numberOr(decision.start_soc_percent),
                optimizedEndSocPercent: numberOr(decision.end_soc_percent),
                uncertain: desired.uncertain,
            });
        });

        const daily = [...days.values()].map((day) => ({
            ...day,
            differenceEur: day.optimizedPnlEur - day.currentPnlEur,
            isPartialDay: String(day.periodStart).slice(11, 19) !== "00:00:00"
                || !(
                    dateKey(day.periodEnd) !== day.date
                    && String(day.periodEnd).slice(11, 19) === "00:00:00"
                ),
        }));
        return { days: daily, slots };
    }

    function estimateDailyComparison(decisions, schedules, rawOptions) {
        return estimateComparison(decisions, schedules, rawOptions).days;
    }

    function estimateHourlyComparison(decisions, schedules, rawOptions) {
        return estimateComparison(decisions, schedules, rawOptions).slots;
    }

    const api = Object.freeze({ estimateDailyComparison, estimateHourlyComparison });
    if (typeof window !== "undefined") window.OptimizerPnl = api;
    if (typeof module === "object" && module.exports) module.exports = api;
})();
