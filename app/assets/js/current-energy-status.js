(function () {
    "use strict";

    const config = Object.freeze({
        statusUrl: "../main/api/charge_status_all_proxy.php",
        refreshIntervalMs: 20000,
        staleAfterMs: 90000,
        minChargePercent: 20,
        maxChargePercent: 90,
        capacityWh: 5760,
        powerMinW: -1200,
        powerMaxW: 1200,
        ...(window.GRAPHITE_APP_CONFIG || {})
    });

    const component = document.querySelector('[data-component="current-energy-status"]');
    if (!component) return;

    const elements = {
        component,
        loading: component.querySelector('[data-role="loading-state"]'),
        error: component.querySelector('[data-role="error-state"]'),
        content: component.querySelector('[data-role="energy-content"]'),
        errorTitle: component.querySelector('[data-role="error-title"]'),
        errorMessage: component.querySelector('[data-role="error-message"]'),
        retry: component.querySelector('[data-role="retry"]'),
        refresh: document.querySelector('[data-role="refresh"]'),
        connectionBadge: document.querySelector('[data-role="connection-badge"]'),
        lastUpdate: document.querySelector('[data-role="last-update"]'),
        powerHero: component.querySelector(".app-power-hero"),
        modeLabel: component.querySelector('[data-role="mode-label"]'),
        powerValue: component.querySelector('[data-role="power-value"]'),
        freshnessLabel: component.querySelector('[data-role="freshness-label"]'),
        powerFlow: component.querySelector('[data-role="power-flow"]'),
        powerFlowNegativeFill: component.querySelector('[data-role="power-flow-fill-negative"]'),
        powerFlowPositiveFill: component.querySelector('[data-role="power-flow-fill-positive"]'),
        powerMinLabel: component.querySelector('[data-role="power-min-label"]'),
        powerMaxLabel: component.querySelector('[data-role="power-max-label"]'),
        powerCard: component.querySelector(".app-power-card"),
        powerFront: component.querySelector('[data-role="power-front"]'),
        powerViewToggles: component.querySelectorAll('[data-role="power-view-toggle"]'),
        powerSimpleMode: component.querySelector('[data-role="power-simple-mode"]'),
        powerSimpleFreshness: component.querySelector('[data-role="power-simple-freshness"]'),
        powerSimpleValue: component.querySelector('[data-role="power-simple-value"]'),
        powerSimpleFlow: component.querySelector('[data-role="power-simple-flow"]'),
        powerSimpleCaption: component.querySelector('[data-role="power-simple-caption"]'),
        powerSegments: component.querySelectorAll('[data-power-segment]'),
        batteryPercent: component.querySelector('[data-role="battery-percent"]'),
        batteryEnergy: component.querySelector('[data-role="battery-energy"]'),
        batteryTarget: component.querySelector('[data-role="battery-target"]'),
        batteryProgress: component.querySelector('[data-role="battery-progress"]'),
        batteryProgressFill: component.querySelector('[data-role="battery-progress-fill"]'),
        batteryMinMarker: component.querySelector('[data-role="battery-min-marker"]'),
        batteryTargetMarker: component.querySelector('[data-role="battery-target-marker"]'),
        batteryMinLabel: component.querySelector('[data-role="battery-min-label"]'),
        batteryTargetLabel: component.querySelector('[data-role="battery-target-label"]'),
        batteryCard: component.querySelector(".app-battery-card"),
        batteryFront: component.querySelector('[data-role="battery-front"]'),
        batteryViewToggles: component.querySelectorAll('[data-role="battery-view-toggle"]'),
        batterySimplePercent: component.querySelector('[data-role="battery-simple-percent"]'),
        batterySimpleTarget: component.querySelector('[data-role="battery-simple-target"]'),
        batterySimpleRange: component.querySelector('[data-role="battery-simple-range"]'),
        batteryIcon: component.querySelector('[data-role="battery-icon"]'),
        batterySegments: component.querySelectorAll('[data-battery-segment]'),
        gridPower: component.querySelector('[data-role="grid-power"]'),
        gridDescription: component.querySelector('[data-role="grid-description"]'),
        gridState: component.querySelector('[data-role="grid-state"]'),
        gridFlow: component.querySelector('[data-role="grid-flow"]'),
        gridFlowFill: component.querySelector('[data-role="grid-flow-fill"]'),
        gridMinLabel: component.querySelector('[data-role="grid-min-label"]'),
        gridMaxLabel: component.querySelector('[data-role="grid-max-label"]'),
        gridCard: component.querySelector(".app-grid-card"),
        gridFront: component.querySelector('[data-role="grid-front"]'),
        gridViewToggles: component.querySelectorAll('[data-role="grid-view-toggle"]'),
        gridSimpleState: component.querySelector('[data-role="grid-simple-state"]'),
        gridSimpleValue: component.querySelector('[data-role="grid-simple-value"]'),
        gridSimpleFlow: component.querySelector('[data-role="grid-simple-flow"]'),
        gridSimpleCaption: component.querySelector('[data-role="grid-simple-caption"]'),
        gridSegments: component.querySelectorAll('[data-grid-segment]')
    };

    let refreshTimer = null;
    let activeController = null;
    let hasRenderedData = false;
    let latestModel = null;
    let currentPriceEurPerKwh = null;
    const batteryViewCookie = "zendure_battery_view";
    const powerViewCookie = "zendure_power_view";
    const gridViewCookie = "zendure_grid_view";

    function finiteNumber(value, fallback = null) {
        if (value === null || value === undefined || value === "") return fallback;
        const number = Number(value);
        return Number.isFinite(number) ? number : fallback;
    }

    function clamp(value, minimum, maximum) {
        return Math.min(maximum, Math.max(minimum, value));
    }

    function usableBatteryPercent(percent, minimum, maximum) {
        if (!Number.isFinite(percent) || maximum <= minimum) return 0;
        return clamp(((percent - minimum) / (maximum - minimum)) * 100, 0, 100);
    }

    function batterySegmentsInRange(percent, minimum, maximum) {
        return Math.round(usableBatteryPercent(percent, minimum, maximum) / 10);
    }

    function storedBatteryViewIsSimple() {
        return document.cookie
            .split(";")
            .map((cookie) => cookie.trim())
            .some((cookie) => cookie === `${batteryViewCookie}=simple`);
    }

    function rememberBatteryView(flipped) {
        const oneYearInSeconds = 60 * 60 * 24 * 365;
        document.cookie = `${batteryViewCookie}=${flipped ? "simple" : "detailed"}; Max-Age=${oneYearInSeconds}; Path=/; SameSite=Lax`;
    }

    function storedPowerViewIsSimple() {
        return document.cookie
            .split(";")
            .map((cookie) => cookie.trim())
            .some((cookie) => cookie === `${powerViewCookie}=simple`);
    }

    function rememberPowerView(flipped) {
        const oneYearInSeconds = 60 * 60 * 24 * 365;
        document.cookie = `${powerViewCookie}=${flipped ? "simple" : "detailed"}; Max-Age=${oneYearInSeconds}; Path=/; SameSite=Lax`;
    }

    function storedGridViewIsSimple() {
        return document.cookie
            .split(";")
            .map((cookie) => cookie.trim())
            .some((cookie) => cookie === `${gridViewCookie}=simple`);
    }

    function rememberGridView(flipped) {
        const oneYearInSeconds = 60 * 60 * 24 * 365;
        document.cookie = `${gridViewCookie}=${flipped ? "simple" : "detailed"}; Max-Age=${oneYearInSeconds}; Path=/; SameSite=Lax`;
    }

    function setFlippedView(card, front, toggles, flipped, transferFocus = false) {
        const frontToggle = toggles[0];
        const backToggle = toggles[1];
        const incomingFace = flipped ? backToggle : front;
        const outgoingFace = flipped ? front : backToggle;
        const incomingToggle = flipped ? backToggle : frontToggle;
        const outgoingToggle = flipped ? frontToggle : backToggle;
        const focusIsLeaving = outgoingFace.contains(document.activeElement);

        incomingFace.inert = false;
        incomingToggle.tabIndex = 0;
        card.dataset.flipped = String(flipped);

        if (focusIsLeaving) {
            outgoingToggle.blur();
        }

        outgoingToggle.tabIndex = -1;
        outgoingFace.inert = true;

        if (focusIsLeaving && transferFocus) {
            window.setTimeout(() => {
                const viewIsStillCurrent = card.dataset.flipped === String(flipped);
                if (viewIsStillCurrent && !incomingFace.inert) {
                    incomingToggle.focus({ preventScroll: true });
                }
            }, 100);
        }

        toggles.forEach((toggle) => {
            toggle.setAttribute("aria-pressed", String(flipped));
        });
    }

    function setPowerView(flipped, transferFocus = false) {
        setFlippedView(
            elements.powerCard,
            elements.powerFront,
            elements.powerViewToggles,
            flipped,
            transferFocus
        );
    }

    function setGridView(flipped, transferFocus = false) {
        setFlippedView(
            elements.gridCard,
            elements.gridFront,
            elements.gridViewToggles,
            flipped,
            transferFocus
        );
    }

    function setBatteryView(flipped, transferFocus = false) {
        setFlippedView(
            elements.batteryCard,
            elements.batteryFront,
            elements.batteryViewToggles,
            flipped,
            transferFocus
        );
    }

    function calculateVisualBarScale(value, axisLimit) {
        const safeLimit = Math.max(1, Math.abs(axisLimit));
        const actualPercent = clamp((Math.abs(value) / safeLimit) * 100, 0, 100);
        const displayPercent = window.GraphitePowerBarScale
            ? window.GraphitePowerBarScale.translate(actualPercent)
            : actualPercent;

        return {
            actualPercent,
            displayPercent,
            // Each direction occupies one half of the complete bidirectional bar.
            fullBarWidthPercent: displayPercent / 2
        };
    }

    function timestampToMs(timestamp) {
        const numeric = finiteNumber(timestamp);
        if (numeric === null) return null;
        return numeric > 1e12 ? numeric : numeric * 1000;
    }

    function formatSignedWatts(value) {
        const rounded = Math.round(value);
        const sign = rounded > 0 ? "+" : rounded < 0 ? "−" : "";
        return `${sign}${Math.abs(rounded).toLocaleString()} W`;
    }

    function formatAbsoluteWatts(value) {
        return `${Math.abs(Math.round(value)).toLocaleString()} W`;
    }

    function setSimpleValue(element, value, unit, spaceBeforeUnit = false) {
        const unitElement = document.createElement("span");
        unitElement.className = "app-simple-value__unit";
        unitElement.textContent = unit;
        element.replaceChildren(
            document.createTextNode(`${value}${spaceBeforeUnit ? " " : ""}`),
            unitElement
        );
    }

    function powerValueSize(value) {
        const absoluteValue = Math.abs(Math.round(value));
        if (absoluteValue >= 1000) return "wide";
        if (absoluteValue >= 100) return "medium";
        return "compact";
    }

    function formatAxisWatts(value) {
        const rounded = Math.round(value);
        const sign = rounded > 0 ? "+" : rounded < 0 ? "−" : "";
        return `${sign}${Math.abs(rounded).toLocaleString()} W`;
    }

    function formatDuration(hours) {
        if (!Number.isFinite(hours) || hours <= 0) return null;
        const totalMinutes = Math.round(hours * 60);
        if (totalMinutes < 1) return "<1m";
        if (totalMinutes < 60) return `${totalMinutes}m`;

        const wholeHours = Math.floor(totalMinutes / 60);
        const minutes = totalMinutes % 60;
        return minutes > 0 ? `${wholeHours}h${minutes}m` : `${wholeHours}h`;
    }

    function formatRelativeTime(timestampMs) {
        if (!timestampMs) return "Updated just now";
        const seconds = Math.max(0, Math.floor((Date.now() - timestampMs) / 1000));
        if (seconds < 10) return "Updated just now";
        if (seconds < 60) return `Updated ${seconds}s ago`;
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) return `Updated ${minutes}m ago`;
        return `Updated ${Math.floor(minutes / 60)}h ago`;
    }

    function determineMode(powerW) {
        const roundedPower = Math.round(powerW);
        if (roundedPower > 0) return "charging";
        if (roundedPower < 0) return "discharging";
        return "standby";
    }

    function calculateBatteryPower(properties) {
        const outputPackPower = finiteNumber(properties.outputPackPower, 0);
        const outputHomePower = finiteNumber(properties.outputHomePower, 0);
        const acMode = finiteNumber(properties.acMode, 0);
        const inputLimit = finiteNumber(properties.inputLimit, 0);
        const outputLimit = finiteNumber(properties.outputLimit, 0);

        let powerW = outputPackPower > 0
            ? outputPackPower
            : outputHomePower > 0
                ? -outputHomePower
                : 0;

        if (powerW === 0) {
            if (acMode === 1 && inputLimit > 0) powerW = inputLimit;
            if (acMode === 2 && outputLimit > 0) powerW = -outputLimit;
        }

        return powerW;
    }

    function normalizePayload(payload) {
        const zendure = payload?.zendure || {};
        const readings = zendure.readings || zendure.data;
        if (!readings || !readings.properties) {
            throw new Error("The controller response does not contain Zendure readings.");
        }

        const properties = readings.properties;
        const p1Readings = payload?.p1?.readings || payload?.p1?.data || null;
        const powerW = calculateBatteryPower(properties);
        const batteryPercent = finiteNumber(properties.electricLevel);
        const gridPowerW = p1Readings ? finiteNumber(p1Readings.total_power) : null;
        const timestampMs = timestampToMs(zendure.timestamp);
        const mode = determineMode(powerW);
        const capacityWh = Math.max(0, finiteNumber(config.capacityWh, 5760));
        const minimumPercent = clamp(finiteNumber(config.minChargePercent, 20), 0, 100);
        const maximumPercent = clamp(
            finiteNumber(config.maxChargePercent, 90),
            minimumPercent,
            100
        );

        let remainingHours = null;
        if (batteryPercent !== null && powerW > 0) {
            remainingHours = (((maximumPercent - batteryPercent) / 100) * capacityWh) / powerW;
        } else if (batteryPercent !== null && powerW < 0) {
            remainingHours = (((batteryPercent - minimumPercent) / 100) * capacityWh) / Math.abs(powerW);
        }

        return {
            mode,
            powerW,
            batteryPercent: batteryPercent === null ? null : clamp(batteryPercent, 0, 100),
            gridPowerW,
            timestampMs,
            stale: timestampMs !== null && Date.now() - timestampMs > config.staleAfterMs,
            remainingTime: formatDuration(remainingHours),
            capacityWh,
            minimumPercent,
            maximumPercent
        };
    }

    function modeCopy(model) {
        if (model.mode === "charging") {
            return { label: "Charging now" };
        }

        if (model.mode === "discharging") {
            return { label: "Discharging now" };
        }

        return { label: "Standby" };
    }

    function batteryCountdownCopy(model) {
        if (model.mode === "charging") {
            if (model.batteryPercent !== null && model.batteryPercent >= model.maximumPercent) {
                return {
                    label: "At maximum",
                    description: `Battery is at the ${model.maximumPercent}% maximum`
                };
            }
            return {
                label: model.remainingTime || "Estimating",
                description: model.remainingTime
                    ? `${model.remainingTime} until the ${model.maximumPercent}% maximum at the current charging rate`
                    : `Time to the ${model.maximumPercent}% maximum is unavailable`
            };
        }

        if (model.mode === "discharging") {
            if (model.batteryPercent !== null && model.batteryPercent <= model.minimumPercent) {
                return {
                    label: "At minimum",
                    description: `Battery is at the ${model.minimumPercent}% minimum`
                };
            }
            return {
                label: model.remainingTime || "Estimating",
                description: model.remainingTime
                    ? `${model.remainingTime} until the ${model.minimumPercent}% minimum at the current discharging rate`
                    : `Time to the ${model.minimumPercent}% minimum is unavailable`
            };
        }

        return {
            label: "At rest",
            description: "Battery is not currently charging or discharging"
        };
    }

    function gridCopy(gridPowerW) {
        if (gridPowerW === null) {
            return { label: "Unknown", description: "P1 reading unavailable", direction: "balanced" };
        }
        if (Math.abs(gridPowerW) <= 25) {
            return { label: "Balanced", description: "Balanced near zero", direction: "balanced" };
        }
        if (gridPowerW > 0) {
            return { label: "Importing", description: "Drawing power from the grid", direction: "import" };
        }
        return { label: "Exporting", description: "Sending power to the grid", direction: "export" };
    }

    function formatCents(value) {
        const absoluteValue = Math.abs(value);
        if (absoluteValue > 0 && absoluteValue < 0.1) return "<0.1";
        return new Intl.NumberFormat(undefined, { maximumFractionDigits: 1 })
            .format(value)
            .replace("-", "−");
    }

    function gridFinancialCopy(gridPowerW) {
        if (gridPowerW === null || currentPriceEurPerKwh === null) return null;

        const priceCentsPerKwh = currentPriceEurPerKwh * 100;
        const hourlyValueCents = -(gridPowerW / 1000) * currentPriceEurPerKwh * 100;
        const priceLabel = `At ${formatCents(priceCentsPerKwh)} ct/kWh`;

        if (hourlyValueCents === 0) {
            return {
                priceLabel,
                valueLabel: "0 ct/h",
                ariaLabel: `At ${formatCents(priceCentsPerKwh)} cents per kilowatt-hour, zero cents per hour`
            };
        }

        const outcome = hourlyValueCents > 0 ? "earning" : "costing";
        const value = formatCents(Math.abs(hourlyValueCents));
        return {
            priceLabel,
            valueLabel: `${outcome} ${value} ct/h`,
            ariaLabel: `At ${formatCents(priceCentsPerKwh)} cents per kilowatt-hour, ${outcome} ${value} cents per hour`
        };
    }

    function renderGridSimpleCaption(grid, gridPowerW) {
        const financialCopy = gridFinancialCopy(gridPowerW);
        if (!financialCopy) {
            elements.gridSimpleCaption.textContent = grid.description;
            return null;
        }

        const value = document.createElement("span");
        value.className = "app-grid-simple__caption-value";
        value.textContent = financialCopy.valueLabel;
        elements.gridSimpleCaption.replaceChildren(
            document.createTextNode(`${financialCopy.priceLabel} · `),
            value
        );
        return financialCopy;
    }

    function setConnectionState(state, label) {
        elements.connectionBadge.dataset.state = state;
        elements.connectionBadge.textContent = label;
    }

    function renderModel(model) {
        latestModel = model;
        hasRenderedData = true;
        component.dataset.state = model.stale ? "stale" : "ready";
        component.setAttribute("aria-busy", "false");
        elements.loading.hidden = true;
        elements.error.hidden = true;
        elements.content.hidden = false;

        const copy = modeCopy(model);
        const batteryCountdown = batteryCountdownCopy(model);
        elements.powerHero.dataset.mode = model.mode;
        elements.modeLabel.textContent = copy.label;
        elements.powerValue.innerHTML = `${formatSignedWatts(model.powerW).replace(" W", "")} <span class="app-power-value__unit">W</span>`;
        elements.powerSimpleMode.textContent = copy.label;
        setSimpleValue(
            elements.powerSimpleValue,
            Math.abs(Math.round(model.powerW)).toLocaleString(),
            "W",
            true
        );
        elements.powerSimpleFlow.dataset.direction = model.mode;
        elements.powerSimpleFlow.dataset.valueSize = powerValueSize(model.powerW);
        elements.powerSimpleCaption.textContent = model.mode === "charging"
            ? "Energy flowing into the battery"
            : model.mode === "discharging"
                ? "Energy flowing from the battery"
                : "No active battery power flow";

        const minPower = Math.min(-1, finiteNumber(config.powerMinW, -1200));
        const maxPower = Math.max(1, finiteNumber(config.powerMaxW, 1200));
        const axisLimit = model.powerW >= 0 ? maxPower : Math.abs(minPower);
        const powerScale = calculateVisualBarScale(model.powerW, axisLimit);
        const activePowerSegments = Math.round(powerScale.actualPercent / 10);
        elements.powerSegments.forEach((segment, index) => {
            segment.dataset.active = String(index < activePowerSegments);
        });
        elements.powerViewToggles[0].setAttribute(
            "aria-label",
            `${copy.label}, ${formatSignedWatts(model.powerW)}. Show simplified charging status view`
        );
        elements.powerViewToggles[1].setAttribute(
            "aria-label",
            `${copy.label}, ${formatAbsoluteWatts(model.powerW)}, ${activePowerSegments} of 10 power segments. Show detailed charging status view`
        );
        elements.powerFlowNegativeFill.style.setProperty(
            "--app-flow-width",
            model.powerW < 0 ? `${powerScale.displayPercent}%` : "0%"
        );
        elements.powerFlowPositiveFill.style.setProperty(
            "--app-flow-width",
            model.powerW > 0 ? `${powerScale.displayPercent}%` : "0%"
        );
        elements.powerFlow.dataset.actualPercent = powerScale.actualPercent.toFixed(2);
        elements.powerFlow.dataset.displayPercent = powerScale.displayPercent.toFixed(2);
        elements.powerFlow.dataset.direction = model.powerW < 0 ? "negative" : "positive";
        elements.powerFlow.setAttribute("aria-valuemin", String(minPower));
        elements.powerFlow.setAttribute("aria-valuemax", String(maxPower));
        elements.powerFlow.setAttribute("aria-valuenow", String(Math.round(model.powerW)));
        elements.powerFlow.setAttribute("aria-valuetext", formatSignedWatts(model.powerW));
        elements.powerMinLabel.textContent = formatAxisWatts(minPower);
        elements.powerMaxLabel.textContent = formatAxisWatts(maxPower);

        if (model.batteryPercent === null) {
            elements.batteryPercent.textContent = "--%";
            elements.batteryPercent.style.removeProperty("color");
            delete elements.batteryPercent.dataset.levelColor;
            elements.batteryEnergy.textContent = "Battery level unavailable";
            elements.batteryProgress.setAttribute("aria-valuenow", "0");
            elements.batteryProgress.removeAttribute("aria-valuetext");
            elements.batteryProgressFill.style.setProperty("--app-battery-level", "0%");
            setSimpleValue(elements.batterySimplePercent, "--", "%");
            elements.batterySimplePercent.style.removeProperty("color");
            elements.batteryIcon.style.removeProperty("--app-battery-color");
            elements.batterySegments.forEach((segment) => {
                segment.dataset.active = "false";
            });
            elements.batteryViewToggles[0].setAttribute(
                "aria-label",
                "Battery level unavailable. Show simplified battery view"
            );
            elements.batteryViewToggles[1].setAttribute(
                "aria-label",
                "Battery level unavailable. Show detailed battery view"
            );
        } else {
            const storedKwh = (model.batteryPercent / 100) * (model.capacityWh / 1000);
            const capacityKwh = model.capacityWh / 1000;
            const usablePercent = usableBatteryPercent(
                model.batteryPercent,
                model.minimumPercent,
                model.maximumPercent
            );
            const batteryColor = window.GraphiteBatteryColorScale
                ? window.GraphiteBatteryColorScale.colorFor(model.batteryPercent)
                : null;
            const usableBatteryColor = window.GraphiteBatteryColorScale
                ? window.GraphiteBatteryColorScale.colorFor(usablePercent)
                : null;
            elements.batteryPercent.textContent = `${Math.round(model.batteryPercent)}%`;
            if (batteryColor) {
                elements.batteryPercent.style.color = batteryColor;
                elements.batteryPercent.dataset.levelColor = batteryColor;
            } else {
                elements.batteryPercent.style.removeProperty("color");
                delete elements.batteryPercent.dataset.levelColor;
            }
            elements.batteryEnergy.textContent = `${storedKwh.toFixed(2)} of ${capacityKwh.toFixed(2)} kWh`;
            elements.batteryProgress.setAttribute("aria-valuenow", String(model.batteryPercent));
            elements.batteryProgress.setAttribute("aria-valuetext", `${Math.round(model.batteryPercent)} percent`);
            elements.batteryProgressFill.style.setProperty("--app-battery-level", `${model.batteryPercent}%`);
            setSimpleValue(elements.batterySimplePercent, Math.round(usablePercent), "%");
            if (usableBatteryColor) {
                elements.batterySimplePercent.style.color = usableBatteryColor;
                elements.batteryIcon.style.setProperty("--app-battery-color", usableBatteryColor);
            } else {
                elements.batterySimplePercent.style.removeProperty("color");
                elements.batteryIcon.style.removeProperty("--app-battery-color");
            }
            const activeSegments = batterySegmentsInRange(
                model.batteryPercent,
                model.minimumPercent,
                model.maximumPercent
            );
            elements.batterySegments.forEach((segment, index) => {
                segment.dataset.active = String(index < activeSegments);
            });
            elements.batteryViewToggles[0].setAttribute(
                "aria-label",
                `Battery ${Math.round(model.batteryPercent)} percent. Show simplified battery view`
            );
            elements.batteryViewToggles[1].setAttribute(
                "aria-label",
                `Usable battery ${Math.round(usablePercent)} percent, based on a real battery level of ${Math.round(model.batteryPercent)} percent within the ${model.minimumPercent} to ${model.maximumPercent} percent operating range. Show detailed battery view`
            );
        }

        elements.batteryTarget.textContent = batteryCountdown.label;
        elements.batteryTarget.setAttribute("aria-label", batteryCountdown.description);
        elements.batteryTarget.title = batteryCountdown.description;
        elements.batteryTarget.dataset.mode = model.mode;
        elements.batterySimpleTarget.textContent = batteryCountdown.label;
        elements.batterySimpleTarget.setAttribute("aria-label", batteryCountdown.description);
        elements.batterySimpleTarget.title = batteryCountdown.description;
        elements.batterySimpleTarget.dataset.mode = model.mode;
        elements.batteryMinMarker.style.setProperty("--app-marker", `${model.minimumPercent}%`);
        elements.batteryTargetMarker.style.setProperty("--app-marker", `${model.maximumPercent}%`);
        elements.batteryMinMarker.dataset.active = model.mode === "discharging" ? "true" : "false";
        elements.batteryTargetMarker.dataset.active = model.mode === "charging" ? "true" : "false";
        elements.batteryMinLabel.textContent = `Minimum ${model.minimumPercent}%`;
        elements.batteryTargetLabel.textContent = `Maximum ${model.maximumPercent}%`;
        elements.batterySimpleRange.textContent = `Operating range ${model.minimumPercent}%–${model.maximumPercent}%`;

        const grid = gridCopy(model.gridPowerW);
        const gridValue = model.gridPowerW ?? 0;
        elements.gridPower.textContent = model.gridPowerW === null ? "-- W" : formatSignedWatts(gridValue);
        const gridValueColor = model.gridPowerW === null || !window.GraphiteGridExchangeColorScale
            ? null
            : window.GraphiteGridExchangeColorScale.colorFor(gridValue);
        if (gridValueColor) {
            elements.gridPower.style.color = gridValueColor;
            elements.gridPower.dataset.exchangeColor = gridValueColor;
        } else {
            elements.gridPower.style.removeProperty("color");
            delete elements.gridPower.dataset.exchangeColor;
        }
        elements.gridDescription.textContent = grid.description;
        elements.gridState.textContent = grid.label;
        elements.gridFlowFill.dataset.direction = grid.direction;
        const gridAxis = gridValue >= 0 ? maxPower : Math.abs(minPower);
        const gridScale = calculateVisualBarScale(gridValue, gridAxis);
        const activeGridSegments = grid.direction === "balanced"
            ? 0
            : Math.round(gridScale.actualPercent / 10);
        elements.gridCard.style.setProperty("--app-grid-color", gridValueColor || "var(--gsd-neutral)");
        elements.gridSimpleState.textContent = grid.label;
        setSimpleValue(
            elements.gridSimpleValue,
            model.gridPowerW === null ? "--" : Math.abs(Math.round(gridValue)).toLocaleString(),
            "W",
            true
        );
        elements.gridSimpleFlow.dataset.direction = grid.direction;
        elements.gridSimpleFlow.dataset.valueSize = powerValueSize(gridValue);
        const gridFinancial = renderGridSimpleCaption(grid, model.gridPowerW);
        elements.gridSegments.forEach((segment, index) => {
            segment.dataset.active = String(index < activeGridSegments);
        });
        elements.gridViewToggles[0].setAttribute(
            "aria-label",
            `${grid.label}, ${model.gridPowerW === null ? "grid reading unavailable" : formatSignedWatts(gridValue)}. Show simplified grid exchange view`
        );
        elements.gridViewToggles[1].setAttribute(
            "aria-label",
            `${grid.label}, ${model.gridPowerW === null ? "grid reading unavailable" : formatAbsoluteWatts(gridValue)}, ${activeGridSegments} of 10 exchange segments${gridFinancial ? `, ${gridFinancial.ariaLabel}` : ""}. Show detailed grid exchange view`
        );
        const gridWidth = grid.direction === "balanced" ? 0 : gridScale.fullBarWidthPercent;
        elements.gridFlowFill.style.setProperty("--app-grid-width", `${gridWidth}%`);
        elements.gridFlowFill.dataset.actualPercent = gridScale.actualPercent.toFixed(2);
        elements.gridFlowFill.dataset.displayPercent = grid.direction === "balanced"
            ? "0.00"
            : gridScale.displayPercent.toFixed(2);
        elements.gridFlow.setAttribute("aria-valuemin", String(minPower));
        elements.gridFlow.setAttribute("aria-valuemax", String(maxPower));
        elements.gridFlow.setAttribute("aria-valuenow", String(Math.round(gridValue)));
        elements.gridFlow.setAttribute(
            "aria-valuetext",
            model.gridPowerW === null ? "Grid reading unavailable" : formatSignedWatts(gridValue)
        );
        elements.gridMinLabel.textContent = formatAxisWatts(minPower);
        elements.gridMaxLabel.textContent = formatAxisWatts(maxPower);

        const freshness = model.stale ? "Stale data" : "Live";
        elements.freshnessLabel.textContent = freshness;
        elements.freshnessLabel.dataset.state = model.stale ? "stale" : "live";
        elements.powerSimpleFreshness.textContent = freshness;
        elements.powerSimpleFreshness.dataset.state = model.stale ? "stale" : "live";
        elements.lastUpdate.textContent = formatRelativeTime(model.timestampMs);
        setConnectionState(model.stale ? "stale" : "online", model.stale ? "Stale" : "Live");
    }

    function renderLoading() {
        component.dataset.state = "loading";
        component.setAttribute("aria-busy", "true");
        elements.loading.hidden = false;
        elements.error.hidden = true;
        elements.content.hidden = true;
        setConnectionState("loading", "Connecting");
    }

    function renderError(error) {
        component.dataset.state = "error";
        component.setAttribute("aria-busy", "false");
        setConnectionState("offline", "Offline");

        if (hasRenderedData) {
            elements.freshnessLabel.textContent = "Update failed";
            elements.freshnessLabel.dataset.state = "stale";
            elements.lastUpdate.textContent = "Live update failed";
            GraphiteFlash.warning("The latest energy reading could not be loaded. Showing the previous values.", {
                title: "Status update failed",
                duration: 5000
            });
            return;
        }

        const isBackendError = error.status === 502;
        elements.loading.hidden = true;
        elements.content.hidden = true;
        elements.error.hidden = false;
        elements.errorTitle.textContent = isBackendError
            ? "Energy controller unavailable"
            : "Energy status unavailable";
        elements.errorMessage.textContent = isBackendError
            ? "The existing status proxy could not reach the controller."
            : error.message || "The live energy status could not be loaded.";
    }

    async function fetchStatus() {
        if (activeController) activeController.abort();
        activeController = new AbortController();
        const timeout = window.setTimeout(() => activeController.abort(), 8000);

        try {
            const response = await fetch(config.statusUrl, {
                method: "GET",
                headers: { Accept: "application/json" },
                cache: "no-store",
                signal: activeController.signal
            });

            const payload = await response.json().catch(() => null);
            if (!response.ok) {
                const error = new Error(payload?.error || `Status request failed with HTTP ${response.status}.`);
                error.status = response.status;
                throw error;
            }
            return payload;
        } catch (error) {
            if (error.name === "AbortError") {
                throw new Error("The energy controller did not respond in time.");
            }
            throw error;
        } finally {
            window.clearTimeout(timeout);
            activeController = null;
        }
    }

    async function refresh({ manual = false } = {}) {
        if (elements.refresh.hasAttribute("aria-busy")) return;

        elements.refresh.setAttribute("aria-busy", "true");
        elements.refresh.disabled = true;
        if (!hasRenderedData) renderLoading();

        try {
            const payload = await fetchStatus();
            renderModel(normalizePayload(payload));
            if (manual) {
                GraphiteFlash.success("Charging, battery, and grid status updated.", {
                    title: "Live status refreshed",
                    duration: 3000
                });
            }
        } catch (error) {
            renderError(error);
        } finally {
            elements.refresh.removeAttribute("aria-busy");
            elements.refresh.disabled = false;
        }
    }

    function startRefreshTimer() {
        stopRefreshTimer();
        if (document.hidden) return;
        refreshTimer = window.setInterval(() => refresh(), config.refreshIntervalMs);
    }

    function stopRefreshTimer() {
        if (refreshTimer !== null) {
            window.clearInterval(refreshTimer);
            refreshTimer = null;
        }
    }

    const FULL_RELOAD_HOLD_MS = 900;
    let fullReloadTimer = null;
    let suppressRefreshClick = false;

    function cancelFullReloadHold() {
        if (fullReloadTimer !== null) {
            window.clearTimeout(fullReloadTimer);
            fullReloadTimer = null;
        }
        delete elements.refresh.dataset.longPress;
    }

    function startFullReloadHold(event) {
        if (event.pointerType === "mouse" && event.button !== 0) return;
        if (elements.refresh.disabled || elements.refresh.hasAttribute("aria-busy")) return;

        cancelFullReloadHold();
        suppressRefreshClick = false;
        elements.refresh.dataset.longPress = "true";
        fullReloadTimer = window.setTimeout(() => {
            fullReloadTimer = null;
            suppressRefreshClick = true;
            elements.refresh.dataset.longPress = "complete";
            elements.refresh.setAttribute("aria-label", "Reloading app and styles");
            if (navigator.vibrate) navigator.vibrate(35);

            const reloadUrl = new URL(window.location.href);
            reloadUrl.searchParams.set("_reload", String(Date.now()));
            window.location.assign(reloadUrl.href);
        }, FULL_RELOAD_HOLD_MS);
    }

    elements.refresh.addEventListener("pointerdown", startFullReloadHold);
    elements.refresh.addEventListener("pointerup", cancelFullReloadHold);
    elements.refresh.addEventListener("pointercancel", cancelFullReloadHold);
    elements.refresh.addEventListener("pointerleave", cancelFullReloadHold);
    elements.refresh.addEventListener("contextmenu", (event) => event.preventDefault());
    elements.refresh.addEventListener("click", () => {
        if (suppressRefreshClick) {
            suppressRefreshClick = false;
            return;
        }
        refresh({ manual: true });
    });
    elements.retry.addEventListener("click", () => refresh({ manual: true }));
    document.addEventListener("graphite:current-price", (event) => {
        currentPriceEurPerKwh = finiteNumber(event.detail?.eurPerKwh);
        if (latestModel) renderModel(latestModel);
    });
    elements.powerViewToggles.forEach((toggle) => {
        toggle.addEventListener("click", (event) => {
            const flipped = elements.powerCard.dataset.flipped !== "true";
            setPowerView(flipped, event.detail === 0);
            rememberPowerView(flipped);
        });
    });
    elements.batteryViewToggles.forEach((toggle) => {
        toggle.addEventListener("click", (event) => {
            const flipped = elements.batteryCard.dataset.flipped !== "true";
            setBatteryView(flipped, event.detail === 0);
            rememberBatteryView(flipped);
        });
    });
    elements.gridViewToggles.forEach((toggle) => {
        toggle.addEventListener("click", (event) => {
            const flipped = elements.gridCard.dataset.flipped !== "true";
            setGridView(flipped, event.detail === 0);
            rememberGridView(flipped);
        });
    });
    document.addEventListener("visibilitychange", () => {
        if (document.hidden) {
            stopRefreshTimer();
            return;
        }
        refresh();
        startRefreshTimer();
    });

    setPowerView(storedPowerViewIsSimple());
    setBatteryView(storedBatteryViewIsSimple());
    setGridView(storedGridViewIsSimple());
    renderLoading();
    refresh();
    startRefreshTimer();
})();
