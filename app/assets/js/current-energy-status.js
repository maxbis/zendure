(function () {
    "use strict";

    const config = Object.freeze({
        statusUrl: "../main/api/charge_status_all_proxy.php",
        automationStatusUrl: "../main/api/automation_status_proxy.php?type=all&limit=20",
        refreshIntervalMs: 20000,
        boostRefreshIntervalMs: 5100,
        boostTickCount: 8,
        staleAfterMs: 90000,
        pendingWindowSeconds: 35,
        pendingMatchToleranceW: 50,
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
        powerViewToggles: component.querySelectorAll(".app-power-view-toggle"),
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
        batteryBack: component.querySelector('[data-role="battery-back"]'),
        batteryViewToggles: component.querySelectorAll(".app-battery-view-toggle"),
        batterySimplePercent: component.querySelector('[data-battery-simple-percent]'),
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
        gridViewToggles: component.querySelectorAll(".app-grid-view-toggle"),
        gridSimpleState: component.querySelector('[data-role="grid-simple-state"]'),
        gridSimpleValue: component.querySelector('[data-role="grid-simple-value"]'),
        gridSimpleFlow: component.querySelector('[data-role="grid-simple-flow"]'),
        gridSimpleCaption: component.querySelector('[data-role="grid-simple-caption"]'),
        gridSegments: component.querySelectorAll('[data-grid-segment]')
    };
    const batteryPopover = document.createElement("div");
    batteryPopover.id = "app-battery-energy-popover";
    batteryPopover.className = "app-battery-popover";
    batteryPopover.setAttribute("role", "dialog");
    batteryPopover.setAttribute("aria-modal", "false");
    batteryPopover.setAttribute("aria-label", "Battery details");
    batteryPopover.hidden = true;
    document.body.appendChild(batteryPopover);

    let refreshTimer = null;
    let activeController = null;
    let hasRenderedData = false;
    let latestModel = null;
    let currentPriceEurPerKwh = null;
    let remainingBoostTicks = 0;
    let activeRefreshIntervalMs = Math.max(1000, Number(config.refreshIntervalMs) || 20000);
    const boostRefreshIntervalMs = Math.max(1000, Number(config.boostRefreshIntervalMs) || 5100);
    const boostTickCount = Math.max(1, Math.floor(Number(config.boostTickCount) || 8));
    const pendingWindowSeconds = Math.max(1, Number(config.pendingWindowSeconds) || 35);
    const pendingMatchToleranceW = Math.max(0, Number(config.pendingMatchToleranceW) || 50);
    const batteryPopoverDetails = new Map();
    let activeBatteryPopoverTrigger = null;
    let activeBatteryPopoverView = "energy";
    const batteryViewCookie = "zendure_battery_view";
    const powerViewCookie = "zendure_power_view";
    const gridViewCookie = "zendure_grid_view";

    function finiteNumber(value, fallback = null) {
        if (value === null || value === undefined || value === "") return fallback;
        const number = Number(value);
        return Number.isFinite(number) ? number : fallback;
    }

    function requiredSharedNumber(key) {
        const number = config[key];
        if (!Number.isFinite(number)) {
            throw new Error(`Missing required shared setting: ${key}.`);
        }
        return number;
    }

    function clamp(value, minimum, maximum) {
        return Math.min(maximum, Math.max(minimum, value));
    }

    function usableBatteryPercent(percent, minimum, maximum) {
        if (!Number.isFinite(percent) || maximum <= minimum) return 0;
        return clamp(((percent - minimum) / (maximum - minimum)) * 100, 0, 100);
    }

    function batterySegmentFillPercent(percent, minimum, maximum, segmentIndex) {
        const usablePercent = usableBatteryPercent(percent, minimum, maximum);
        return clamp((usablePercent - (segmentIndex * 10)) * 10, 0, 100);
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

    function setFlippedView(card, front, toggles, flipped, transferFocus = false, back = null) {
        back = back || card.querySelector('[class*="card__face--back"]');
        const incomingFace = flipped ? back : front;
        const outgoingFace = flipped ? front : back;
        const incomingToggles = Array.from(toggles).filter((toggle) => incomingFace.contains(toggle));
        const outgoingToggles = Array.from(toggles).filter((toggle) => outgoingFace.contains(toggle));
        const incomingToggle = incomingToggles[0];
        const focusIsLeaving = outgoingFace.contains(document.activeElement);

        incomingFace.inert = false;
        incomingToggles.forEach((toggle) => {
            toggle.tabIndex = 0;
        });
        card.dataset.flipped = String(flipped);

        if (focusIsLeaving) {
            document.activeElement.blur();
        }

        outgoingToggles.forEach((toggle) => {
            toggle.tabIndex = -1;
        });
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

    function setFlipToggleLabels(toggles, front, frontLabel, backLabel) {
        toggles.forEach((toggle) => {
            toggle.setAttribute("aria-label", front.contains(toggle) ? frontLabel : backLabel);
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
            transferFocus,
            elements.batteryBack
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

    function activeFlowChevronCount(actualPercent, isFlowing) {
        if (!isFlowing) return 0;
        const dynamicChevrons = Math.min(9, Math.round(clamp(actualPercent, 0, 100) / 10));
        return 1 + dynamicChevrons;
    }

    function renderFlowChevrons(segments, activeCount, startsFromRight) {
        segments.forEach((segment, index) => {
            const flowIndex = startsFromRight
                ? segments.length - 1 - index
                : index;
            segment.dataset.active = String(flowIndex < activeCount);
            segment.style.setProperty("--app-segment-delay", `${flowIndex * 60}ms`);
        });
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

    function formatKwh(value) {
        return Number.isFinite(value) ? `${value.toFixed(2)} kWh` : "—";
    }

    function formatTemperature(value) {
        return Number.isFinite(value) ? `${value.toFixed(1)}°C` : "—";
    }

    function temperatureFromHyperTmp(value) {
        const hyperTmp = finiteNumber(value);
        return hyperTmp === null ? null : (hyperTmp - 2731) / 10;
    }

    function batteryEnergyValues(model, batteryPercent = model.batteryPercent) {
        if (!Number.isFinite(batteryPercent)) return null;
        const capacityKwh = model.capacityWh / 1000;
        const storedKwh = (batteryPercent / 100) * capacityKwh;
        const usableKwh = Math.max(0, ((batteryPercent - model.minimumPercent) / 100) * capacityKwh);
        const emptyKwh = Math.max(0, ((model.maximumPercent - batteryPercent) / 100) * capacityKwh);
        return { storedKwh, usableKwh, emptyKwh };
    }

    function batteryHealthValues(model) {
        return {
            wifiRssi: model.wifiRssi,
            systemTemperature: model.systemTemperature,
            batteryPacks: model.batteryPacks
        };
    }

    function batteryProjectionAfterHours(model, hours) {
        if (!Number.isFinite(model.batteryPercent) || !Number.isFinite(model.capacityWh) || model.capacityWh <= 0) {
            return null;
        }
        const durationHours = Math.max(0, Number(hours) || 0);
        const storedWh = (model.batteryPercent / 100) * model.capacityWh;
        const lowerWh = (model.minimumPercent / 100) * model.capacityWh;
        const upperWh = (model.maximumPercent / 100) * model.capacityWh;
        const powerW = Number.isFinite(model.powerW) ? model.powerW : 0;
        const projectedWh = clamp(
            storedWh + (powerW * durationHours),
            model.mode === "discharging" ? lowerWh : 0,
            model.mode === "charging" ? upperWh : model.capacityWh
        );
        const projectedPercent = (projectedWh / model.capacityWh) * 100;
        return {
            ...batteryEnergyValues(model, projectedPercent),
            percent: projectedPercent,
            usablePercent: usableBatteryPercent(projectedPercent, model.minimumPercent, model.maximumPercent)
        };
    }

    function batteryProjectionAtHourEnd(model, now = new Date()) {
        if (!Number.isFinite(model.batteryPercent)) return null;
        const secondsIntoHour = (now.getMinutes() * 60) + now.getSeconds() + (now.getMilliseconds() / 1000);
        const hoursLeft = Math.max(0, (3600 - secondsIntoHour) / 3600);
        const projection = batteryProjectionAfterHours(model, hoursLeft);
        if (!projection) return null;
        const nextHour = new Date(now);
        nextHour.setHours(now.getHours() + 1, 0, 0, 0);
        return {
            ...projection,
            time: `${String(nextHour.getHours()).padStart(2, "0")}:00`
        };
    }

    function eventInsideBatteryPopover(target) {
        return target instanceof Node && batteryPopover.contains(target);
    }

    function createBatteryPopoverCloseButton() {
        const button = document.createElement("button");
        button.className = "gsd-icon-btn app-battery-popover__close";
        button.type = "button";
        button.setAttribute("aria-label", "Close");
        button.title = "Close";
        button.dataset.gsdDialogClose = "";
        const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        const use = document.createElementNS("http://www.w3.org/2000/svg", "use");
        svg.classList.add("gsd-icon");
        svg.setAttribute("aria-hidden", "true");
        use.setAttribute("href", "../themes/graphite-signal-dark/assets/icons/sprite.svg#close");
        svg.appendChild(use);
        button.appendChild(svg);
        button.addEventListener("click", (event) => {
            event.preventDefault();
            event.stopPropagation();
            const trigger = activeBatteryPopoverTrigger;
            hideBatteryPopover(trigger);
            trigger?.focus?.({ preventScroll: true });
        });
        return button;
    }

    function hideBatteryPopover(trigger = null) {
        if (trigger && trigger !== activeBatteryPopoverTrigger) return;
        if (activeBatteryPopoverTrigger) {
            activeBatteryPopoverTrigger.setAttribute("aria-expanded", "false");
        }
        activeBatteryPopoverTrigger = null;
        activeBatteryPopoverView = "energy";
        batteryPopover.hidden = true;
        batteryPopover.style.removeProperty("left");
        batteryPopover.style.removeProperty("top");
        batteryPopover.style.removeProperty("visibility");
    }

    function positionBatteryPopover(trigger) {
        const gap = 8;
        const viewportPadding = 12;
        const triggerRect = trigger.getBoundingClientRect();
        const popoverRect = batteryPopover.getBoundingClientRect();
        let left = triggerRect.left + ((triggerRect.width - popoverRect.width) / 2);
        left = Math.max(viewportPadding, Math.min(left, window.innerWidth - popoverRect.width - viewportPadding));
        let top = triggerRect.bottom + gap;
        if (top + popoverRect.height > window.innerHeight - viewportPadding) {
            top = triggerRect.top - popoverRect.height - gap;
        }
        top = Math.max(viewportPadding, top);
        batteryPopover.style.left = `${Math.round(left)}px`;
        batteryPopover.style.top = `${Math.round(top)}px`;
        batteryPopover.style.visibility = "visible";
    }

    function appendBatteryPopoverRows(container, rows) {
        rows.forEach(([label, value]) => {
            const row = document.createElement("p");
            const name = document.createElement("span");
            const amount = document.createElement("strong");
            name.textContent = label;
            if (value instanceof Node) {
                amount.appendChild(value);
            } else if (value && typeof value === "object" && Object.prototype.hasOwnProperty.call(value, "text")) {
                amount.textContent = value.text;
                if (value.color) amount.style.color = value.color;
            } else {
                amount.textContent = value;
            }
            row.append(name, amount);
            container.appendChild(row);
        });
    }

    function healthMetricColor(kind, value) {
        const scale = window.GraphiteHealthMetricColorScale;
        if (!scale) return null;
        if (kind === "temperature") return scale.temperatureColor(value);
        if (kind === "wifi") return scale.wifiRssiColor(value);
        return null;
    }

    function coloredHealthMetric(text, color) {
        return color ? { text, color } : text;
    }

    function packHealthValue(pack) {
        const percentText = Number.isFinite(pack.percent) ? `${Math.round(pack.percent)}%` : "—";
        const temperatureText = formatTemperature(pack.temperature);
        const temperatureColor = healthMetricColor("temperature", pack.temperature);
        if (!temperatureColor) return `${percentText} · ${temperatureText}`;

        const value = document.createDocumentFragment();
        value.appendChild(document.createTextNode(`${percentText} · `));
        const temperature = document.createElement("span");
        temperature.textContent = temperatureText;
        temperature.style.color = temperatureColor;
        value.appendChild(temperature);
        return value;
    }

    function createBatteryPopoverSummaryShell({
        ariaLabel,
        color,
        currentText,
        secondaryText,
        fillPercent,
        projectedFillPercent = null,
        minimumText,
        maximumText
    }) {
        const summary = document.createElement("div");
        summary.className = "app-battery-popover__summary";
        summary.setAttribute("role", "img");
        summary.setAttribute("aria-label", ariaLabel);
        if (color) summary.style.setProperty("--app-battery-color", color);

        const readout = document.createElement("div");
        readout.className = "app-battery-popover__summary-readout";
        const current = document.createElement("strong");
        current.textContent = currentText;
        const secondary = document.createElement("span");
        secondary.textContent = secondaryText;
        readout.append(current, secondary);

        const track = document.createElement("div");
        track.className = "app-battery-popover__summary-track";
        track.setAttribute("aria-hidden", "true");
        const hasProjection = Number.isFinite(projectedFillPercent)
            && Math.abs(projectedFillPercent - fillPercent) >= 0.05;
        if (hasProjection) {
            const projection = document.createElement("i");
            projection.className = "app-battery-popover__summary-projection";
            projection.style.width = `${Math.max(fillPercent, projectedFillPercent).toFixed(2)}%`;
            track.appendChild(projection);
        }
        const fill = document.createElement("i");
        fill.className = "app-battery-popover__summary-fill";
        fill.style.width = `${(hasProjection ? Math.min(fillPercent, projectedFillPercent) : fillPercent).toFixed(2)}%`;
        const marker = document.createElement("i");
        marker.className = "app-battery-popover__summary-marker";
        marker.style.left = `${fillPercent.toFixed(2)}%`;
        track.append(fill, marker);

        const limits = document.createElement("div");
        limits.className = "app-battery-popover__summary-limits";
        limits.setAttribute("aria-hidden", "true");
        const minimum = document.createElement("span");
        minimum.textContent = minimumText;
        const maximum = document.createElement("span");
        maximum.textContent = maximumText;
        limits.append(minimum, maximum);

        summary.append(readout, track, limits);
        return summary;
    }

    function createBatteryPopoverEnergySummary(detail) {
        const batteryPercent = Number(detail?.batteryPercent);
        const minimumPercent = Number(detail?.minimumPercent);
        const maximumPercent = Number(detail?.maximumPercent);
        if (
            !Number.isFinite(batteryPercent)
            || !Number.isFinite(minimumPercent)
            || !Number.isFinite(maximumPercent)
            || maximumPercent <= minimumPercent
        ) {
            return null;
        }

        const usablePercent = usableBatteryPercent(batteryPercent, minimumPercent, maximumPercent);
        const color = window.GraphiteBatteryColorScale
            ? window.GraphiteBatteryColorScale.colorFor(usablePercent)
            : null;
        const projectionIn60 = batteryProjectionAfterHours({
            batteryPercent,
            minimumPercent,
            maximumPercent,
            capacityWh: detail.capacityWh,
            powerW: detail.powerW,
            mode: detail.mode
        }, 1);
        const projectedUsablePercent = projectionIn60?.usablePercent;
        const hasProjection = Number.isFinite(projectedUsablePercent)
            && Math.abs(projectedUsablePercent - usablePercent) >= 0.05;
        const ariaProjection = hasProjection
            ? ` Projected ${Math.round(projectedUsablePercent)} percent of the usable range in 60 minutes at the current battery power.`
            : "";

        return createBatteryPopoverSummaryShell({
            ariaLabel: `Battery ${Math.round(batteryPercent)} percent. ${Math.round(usablePercent)} percent of the usable range between ${Math.round(minimumPercent)} and ${Math.round(maximumPercent)} percent.${ariaProjection}`,
            color,
            currentText: `${Math.round(batteryPercent)}%`,
            secondaryText: hasProjection
                ? `${Math.round(usablePercent)}% now · ${Math.round(projectedUsablePercent)}% in 60 min`
                : `${Math.round(usablePercent)}% of usable range`,
            fillPercent: usablePercent,
            projectedFillPercent: hasProjection ? projectedUsablePercent : null,
            minimumText: `Min ${Math.round(minimumPercent)}%`,
            maximumText: `Max ${Math.round(maximumPercent)}%`
        });
    }

    function createBatteryPopoverTemperatureSummary(detail) {
        const temperature = Number(detail?.systemTemperature);
        if (!Number.isFinite(temperature)) return null;

        const minimumTemp = -5;
        const maximumTemp = 50;
        const fillPercent = clamp(((temperature - minimumTemp) / (maximumTemp - minimumTemp)) * 100, 0, 100);
        const color = healthMetricColor("temperature", temperature);

        return createBatteryPopoverSummaryShell({
            ariaLabel: `Controller temperature ${temperature.toFixed(1)} degrees Celsius on a scale from ${minimumTemp} to ${maximumTemp} degrees.`,
            color,
            currentText: formatTemperature(temperature),
            secondaryText: "Controller temperature",
            fillPercent,
            minimumText: `${minimumTemp}°C`,
            maximumText: `${maximumTemp > 0 ? "+" : ""}${maximumTemp}°C`
        });
    }

    function createBatteryPopoverPanel(view, detail, rows) {
        const panel = document.createElement("div");
        panel.className = "app-battery-popover__panel";
        panel.dataset.view = view;
        const summary = view === "health"
            ? createBatteryPopoverTemperatureSummary(detail)
            : createBatteryPopoverEnergySummary(detail);
        if (summary) panel.appendChild(summary);
        const details = document.createElement("div");
        details.className = "app-battery-popover__details";
        appendBatteryPopoverRows(details, rows);
        panel.appendChild(details);
        return panel;
    }

    function showBatteryPopover(detail, trigger, initialView = "energy") {
        if (!detail || !trigger) return;
        hideBatteryPopover();
        activeBatteryPopoverTrigger = trigger;
        activeBatteryPopoverView = initialView === "health" ? "health" : "energy";
        trigger.setAttribute("aria-controls", batteryPopover.id);
        trigger.setAttribute("aria-haspopup", "dialog");
        trigger.setAttribute("aria-expanded", "true");

        const header = document.createElement("div");
        header.className = "app-battery-popover__header";
        const title = document.createElement("strong");
        title.id = "app-battery-popover-title";
        header.append(title, createBatteryPopoverCloseButton());

        const viewport = document.createElement("div");
        viewport.className = "app-battery-popover__viewport";
        const slider = document.createElement("div");
        slider.className = "app-battery-popover__slider";

        const capacityKwh = Number.isFinite(detail.capacityWh) ? detail.capacityWh / 1000 : null;
        const usableRangeFraction = (
            Number.isFinite(detail.minimumPercent)
            && Number.isFinite(detail.maximumPercent)
            && detail.maximumPercent > detail.minimumPercent
        )
            ? (detail.maximumPercent - detail.minimumPercent) / 100
            : null;
        const fivePercentUsableKwh = (
            capacityKwh !== null
            && usableRangeFraction !== null
        )
            ? capacityKwh * usableRangeFraction * 0.05
            : null;
        const fivePercentRealKwh = capacityKwh !== null ? capacityKwh * 0.05 : null;
        const fivePercentCopy = (
            Number.isFinite(fivePercentUsableKwh)
            && Number.isFinite(fivePercentRealKwh)
        )
            ? `${fivePercentUsableKwh.toFixed(2)}/${fivePercentRealKwh.toFixed(2)} kWh`
            : "—";
        const energyPanel = createBatteryPopoverPanel("energy", detail, [
            ["5% usable/real range equals", fivePercentCopy],
            ["Usable energy left", formatKwh(detail.usableKwh)],
            ["Chargeable energy available", formatKwh(detail.emptyKwh)],
            ["Current battery flow rate", detail.rate],
            [
                `Usable energy @ ${detail.projectionTime}`,
                `${formatKwh(detail.projectedUsableKwh)} (${Math.round(detail.projectedUsablePercent)}%)`
            ]
        ]);

        const healthRows = [
            [
                "Controller temperature",
                coloredHealthMetric(
                    formatTemperature(detail.systemTemperature),
                    healthMetricColor("temperature", detail.systemTemperature)
                )
            ]
        ];
        healthRows.push(...detail.batteryPacks.map((pack, index) => [
            `Battery ${index + 1}`,
            packHealthValue(pack)
        ]));
        if (detail.batteryPacks.length === 0) healthRows.push(["Battery packs", "Unavailable"]);
        healthRows.push([
            "Wi-Fi signal",
            coloredHealthMetric(
                Number.isFinite(detail.wifiRssi) ? `${Math.round(detail.wifiRssi)} dBm` : "—",
                healthMetricColor("wifi", detail.wifiRssi)
            )
        ]);
        const healthPanel = createBatteryPopoverPanel("health", detail, healthRows);

        slider.append(energyPanel, healthPanel);
        viewport.appendChild(slider);

        const footer = document.createElement("div");
        footer.className = "app-battery-popover__footer";
        const navigation = document.createElement("button");
        navigation.className = "gsd-btn gsd-btn--quiet app-battery-popover__navigation";
        navigation.type = "button";
        footer.appendChild(navigation);

        function setPopoverView(view) {
            activeBatteryPopoverView = view === "health" ? "health" : "energy";
            const showingHealth = activeBatteryPopoverView === "health";
            batteryPopover.dataset.view = activeBatteryPopoverView;
            title.textContent = showingHealth ? "Battery health" : detail.title;
            navigation.replaceChildren();

            const icon = document.createElementNS("http://www.w3.org/2000/svg", "svg");
            icon.classList.add("gsd-icon");
            icon.setAttribute("aria-hidden", "true");
            const use = document.createElementNS("http://www.w3.org/2000/svg", "use");
            use.setAttribute("href", `../themes/graphite-signal-dark/assets/icons/sprite.svg#chevron-${showingHealth ? "left" : "right"}`);
            icon.appendChild(use);

            const label = document.createElement("span");
            label.textContent = showingHealth ? "Energy" : "Health";
            if (showingHealth) navigation.append(icon, label);
            else navigation.append(label, icon);
            navigation.setAttribute("aria-label", showingHealth ? "Back to battery energy" : "Show battery health");
            energyPanel.setAttribute("aria-hidden", String(showingHealth));
            healthPanel.setAttribute("aria-hidden", String(!showingHealth));
        }

        navigation.addEventListener("click", () => {
            setPopoverView(activeBatteryPopoverView === "energy" ? "health" : "energy");
        });

        batteryPopover.replaceChildren(header, viewport, footer);
        batteryPopover.setAttribute("aria-labelledby", title.id);
        setPopoverView(activeBatteryPopoverView);
        batteryPopover.hidden = false;
        batteryPopover.style.visibility = "hidden";
        positionBatteryPopover(trigger);
    }

    function bindBatteryPopover(trigger) {
        trigger.setAttribute("aria-expanded", "false");
        trigger.setAttribute("aria-controls", batteryPopover.id);
        trigger.setAttribute("aria-haspopup", "dialog");
        trigger.addEventListener("click", () => {
            if (activeBatteryPopoverTrigger === trigger && !batteryPopover.hidden) {
                hideBatteryPopover(trigger);
                return;
            }
            showBatteryPopover(batteryPopoverDetails.get(trigger), trigger);
        });
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
        const packData = Array.isArray(readings.packData) ? readings.packData : [];
        const p1Readings = payload?.p1?.readings || payload?.p1?.data || null;
        const powerW = calculateBatteryPower(properties);
        const acMode = finiteNumber(properties.acMode, 0);
        const inputLimit = finiteNumber(properties.inputLimit, 0);
        const outputLimit = finiteNumber(properties.outputLimit, 0);
        const deviceCommandedW = inputLimit > 0 ? inputLimit : (outputLimit > 0 ? -outputLimit : 0);
        const batteryPercent = finiteNumber(properties.electricLevel);
        const gridPowerW = p1Readings ? finiteNumber(p1Readings.total_power) : null;
        const timestampMs = timestampToMs(zendure.timestamp);
        const mode = determineMode(powerW);
        const capacityWh = requiredSharedNumber("capacityWh");
        const minimumPercent = requiredSharedNumber("minChargePercent");
        const maximumPercent = requiredSharedNumber("maxChargePercent");
        if (
            capacityWh <= 0
            || minimumPercent < 0
            || maximumPercent > 100
            || minimumPercent >= maximumPercent
        ) {
            throw new Error("The shared battery settings are invalid.");
        }
        const wifiRssi = finiteNumber(properties.rssi);
        const systemTemperature = temperatureFromHyperTmp(properties.hyperTmp);
        const batteryPacks = packData.map((pack) => ({
            percent: finiteNumber(pack?.socLevel),
            temperature: temperatureFromHyperTmp(pack?.maxTemp)
        }));

        let remainingHours = null;
        if (batteryPercent !== null && powerW > 0) {
            remainingHours = (((maximumPercent - batteryPercent) / 100) * capacityWh) / powerW;
        } else if (batteryPercent !== null && powerW < 0) {
            remainingHours = (((batteryPercent - minimumPercent) / 100) * capacityWh) / Math.abs(powerW);
        }

        return {
            mode,
            powerW,
            acMode,
            deviceCommandedW,
            batteryPercent: batteryPercent === null ? null : clamp(batteryPercent, 0, 100),
            gridPowerW,
            timestampMs,
            stale: timestampMs !== null && Date.now() - timestampMs > config.staleAfterMs,
            pending: false,
            remainingTime: formatDuration(remainingHours),
            capacityWh,
            minimumPercent,
            maximumPercent,
            wifiRssi,
            systemTemperature,
            batteryPacks
        };
    }

    function parsePendingCommandedPower(newValue) {
        if (newValue === null || newValue === undefined) return null;
        if (newValue === "netzero") return -250;
        if (newValue === "netzero+") return 250;
        const parsed = Number(newValue);
        return Number.isFinite(parsed) ? parsed : null;
    }

    function isPowerPending(automationData, model) {
        if (!automationData || automationData.success === false) return false;

        const changes = (automationData.lastChanges || []).filter((entry) => entry.type === "change");
        if (changes.length === 0) return false;

        const latest = changes[0];
        const entryAge = Math.floor(Date.now() / 1000) - (latest.timestamp || 0);
        if (entryAge > pendingWindowSeconds) return false;

        const commandedW = parsePendingCommandedPower(latest.newValue);
        if (commandedW === null || !Number.isFinite(model.powerW)) return false;

        const withinTolerance = Math.abs(commandedW - model.powerW) <= pendingMatchToleranceW;
        const commandModeMatches = (
            (commandedW > 0 && model.acMode === 1)
            || (commandedW < 0 && model.acMode === 2)
            || (commandedW === 0 && (model.acMode === 0 || !Number.isFinite(model.acMode)))
        );
        const deviceCommandMatches = (
            Number.isFinite(model.deviceCommandedW)
            && Math.abs(commandedW - model.deviceCommandedW) <= pendingMatchToleranceW
            && commandModeMatches
        );

        return !(withinTolerance || deviceCommandMatches);
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
        const colorConfig = window.GraphiteGridExchangeColorScale?.config || {};
        const exportBoundaryW = finiteNumber(colorConfig.exportGreenBelowW, -10);
        const importBoundaryW = finiteNumber(colorConfig.importRedAboveW, 10);
        if (gridPowerW >= exportBoundaryW && gridPowerW <= importBoundaryW) {
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

    function isBurstActive() {
        return (
            !document.hidden
            && remainingBoostTicks > 0
            && activeRefreshIntervalMs === boostRefreshIntervalMs
        );
    }

    function updateBurstVisual() {
        if (!elements.powerHero) return;
        elements.powerHero.classList.toggle("fast-refresh-active", isBurstActive());
    }

    function pulseFreshnessTick() {
        [elements.freshnessLabel, elements.powerSimpleFreshness].forEach((pill) => {
            if (!pill) return;
            if (pill._tickClearTimer) {
                window.clearTimeout(pill._tickClearTimer);
                pill._tickClearTimer = null;
            }
            pill.classList.remove("app-state-pill--tick");
            void pill.offsetWidth;
            pill.classList.add("app-state-pill--tick");
            // Hold the yellow peak briefly, then remove so the longer base transition fades out.
            pill._tickClearTimer = window.setTimeout(() => {
                pill.classList.remove("app-state-pill--tick");
                pill._tickClearTimer = null;
            }, 260);
        });
    }

    function applyFreshnessUi(model, { updateFailed = false } = {}) {
        if (!elements.freshnessLabel) return;

        let state = "live";
        let pillLabel = "Live";
        let badgeLabel = "Live";

        if (updateFailed) {
            state = "offline";
            pillLabel = "Offline";
            badgeLabel = "Offline";
        } else if (model.pending) {
            state = "pending";
            pillLabel = "Pending";
            badgeLabel = "Pending";
        } else if (model.stale) {
            state = "stale";
            pillLabel = "Stale";
            badgeLabel = "Stale";
        }

        elements.freshnessLabel.textContent = pillLabel;
        elements.freshnessLabel.dataset.state = state;
        if (elements.powerSimpleFreshness) {
            elements.powerSimpleFreshness.textContent = pillLabel;
            elements.powerSimpleFreshness.dataset.state = state;
        }
        elements.powerHero.classList.toggle("power-pending", Boolean(model.pending) && !updateFailed);
        setConnectionState(
            updateFailed ? "offline" : state === "live" ? "online" : state,
            badgeLabel
        );
    }

    function publishBatteryForecastState(model) {
        const detail = Object.freeze({
            percent: model.batteryPercent,
            capacityWh: model.capacityWh,
            minimumPercent: model.minimumPercent,
            maximumPercent: model.maximumPercent,
            timestampMs: model.timestampMs,
            stale: model.stale
        });
        window.GRAPHITE_BATTERY_FORECAST_STATE = detail;
        document.dispatchEvent(new CustomEvent("graphite:battery-forecast-state", { detail }));
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
        elements.modeLabel.title = copy.label;
        elements.powerValue.innerHTML = `${formatSignedWatts(model.powerW).replace(" W", "")} <span class="app-power-value__unit">W</span>`;
        elements.powerSimpleMode.textContent = copy.label;
        elements.powerSimpleMode.title = copy.label;
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
        const activePowerSegments = activeFlowChevronCount(
            powerScale.actualPercent,
            model.mode !== "standby"
        );
        renderFlowChevrons(
            elements.powerSegments,
            activePowerSegments,
            model.mode === "discharging"
        );
        setFlipToggleLabels(
            elements.powerViewToggles,
            elements.powerFront,
            `${copy.label}, ${formatSignedWatts(model.powerW)}. Show simplified charging status view`,
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
                segment.style.setProperty("--app-battery-segment-fill", "0%");
            });
            [elements.batteryIcon, elements.batteryTarget, elements.batterySimpleTarget].forEach((trigger) => {
                if (activeBatteryPopoverTrigger === trigger) hideBatteryPopover(trigger);
                batteryPopoverDetails.delete(trigger);
                trigger.disabled = true;
            });
            elements.batteryIcon.setAttribute("aria-label", "Battery energy unavailable");
            setFlipToggleLabels(
                elements.batteryViewToggles,
                elements.batteryFront,
                "Battery level unavailable. Show simplified battery view",
                "Battery level unavailable. Show detailed battery view"
            );
        } else {
            const storedKwh = (model.batteryPercent / 100) * (model.capacityWh / 1000);
            const capacityKwh = model.capacityWh / 1000;
            const energy = batteryEnergyValues(model);
            const projection = batteryProjectionAtHourEnd(model);
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
            elements.batterySegments.forEach((segment, index) => {
                const fillPercent = batterySegmentFillPercent(
                    model.batteryPercent,
                    model.minimumPercent,
                    model.maximumPercent,
                    index
                );
                segment.dataset.active = String(fillPercent > 0);
                segment.style.setProperty("--app-battery-segment-fill", `${fillPercent.toFixed(2)}%`);
            });
            batteryPopoverDetails.set(elements.batteryIcon, {
                title: "Battery usable energy",
                ...batteryHealthValues(model),
                batteryPercent: model.batteryPercent,
                minimumPercent: model.minimumPercent,
                maximumPercent: model.maximumPercent,
                capacityWh: model.capacityWh,
                powerW: model.powerW,
                mode: model.mode,
                usableKwh: energy.usableKwh,
                emptyKwh: energy.emptyKwh,
                storedKwh: energy.storedKwh,
                rate: formatSignedWatts(model.powerW),
                projectedUsableKwh: projection.usableKwh,
                projectedUsablePercent: projection.usablePercent,
                projectionTime: projection.time
            });
            elements.batteryIcon.disabled = false;
            elements.batteryIcon.setAttribute(
                "aria-label",
                `Show battery energy details. ${formatKwh(energy.storedKwh)} total stored energy, ${formatKwh(energy.usableKwh)} usable energy, ${formatKwh(energy.emptyKwh)} chargeable energy, and ${formatKwh(projection.usableKwh)}, ${Math.round(projection.usablePercent)} percent of the usable range, projected at ${projection.time}.`
            );
            if (model.remainingTime && projection) {
                const projectionDetail = {
                    title: `Projected at ${projection.time}`,
                    ...batteryHealthValues(model),
                    batteryPercent: model.batteryPercent,
                    minimumPercent: model.minimumPercent,
                    maximumPercent: model.maximumPercent,
                    capacityWh: model.capacityWh,
                    powerW: model.powerW,
                    mode: model.mode,
                    usableKwh: energy.usableKwh,
                    emptyKwh: energy.emptyKwh,
                    storedKwh: energy.storedKwh,
                    rate: formatSignedWatts(model.powerW),
                    projectedUsableKwh: projection.usableKwh,
                    projectedUsablePercent: projection.usablePercent,
                    projectionTime: projection.time
                };
                [elements.batteryTarget, elements.batterySimpleTarget].forEach((trigger) => {
                    batteryPopoverDetails.set(trigger, projectionDetail);
                    trigger.disabled = false;
                });
            } else {
                [elements.batteryTarget, elements.batterySimpleTarget].forEach((trigger) => {
                    if (activeBatteryPopoverTrigger === trigger) hideBatteryPopover(trigger);
                    batteryPopoverDetails.delete(trigger);
                    trigger.disabled = true;
                });
            }
            const projectionDescription = model.remainingTime && projection
                ? ` At ${projection.time}, projected usable energy is ${formatKwh(projection.usableKwh)}, ${Math.round(projection.usablePercent)} percent of the usable range, and total stored energy is ${formatKwh(projection.storedKwh)}.`
                : "";
            setFlipToggleLabels(
                elements.batteryViewToggles,
                elements.batteryFront,
                `Battery ${Math.round(model.batteryPercent)} percent. Show simplified battery view`,
                `Usable battery ${Math.round(usablePercent)} percent, with ${formatKwh(energy.usableKwh)} usable and ${formatKwh(energy.storedKwh)} stored.${projectionDescription} Show detailed battery view`
            );
        }

        elements.batteryTarget.textContent = batteryCountdown.label;
        elements.batteryTarget.setAttribute(
            "aria-label",
            model.remainingTime ? `${batteryCountdown.description}. Show projected battery energy` : batteryCountdown.description
        );
        elements.batteryTarget.removeAttribute("title");
        elements.batteryTarget.dataset.mode = model.mode;
        elements.batterySimpleTarget.textContent = batteryCountdown.label;
        elements.batterySimpleTarget.setAttribute(
            "aria-label",
            model.remainingTime ? `${batteryCountdown.description}. Show projected battery energy` : batteryCountdown.description
        );
        elements.batterySimpleTarget.removeAttribute("title");
        elements.batterySimpleTarget.dataset.mode = model.mode;
        if (activeBatteryPopoverTrigger && !batteryPopover.hidden) {
            showBatteryPopover(
                batteryPopoverDetails.get(activeBatteryPopoverTrigger),
                activeBatteryPopoverTrigger,
                activeBatteryPopoverView
            );
        }
        elements.batteryMinMarker.style.setProperty("--app-marker", `${model.minimumPercent}%`);
        elements.batteryTargetMarker.style.setProperty("--app-marker", `${model.maximumPercent}%`);
        elements.batteryMinMarker.dataset.active = model.mode === "discharging" ? "true" : "false";
        elements.batteryTargetMarker.dataset.active = model.mode === "charging" ? "true" : "false";
        elements.batteryMinLabel.textContent = `Minimum ${model.minimumPercent}%`;
        elements.batteryTargetLabel.textContent = `Maximum ${model.maximumPercent}%`;
        elements.batterySimpleRange.textContent = `Within useable range ${model.minimumPercent}%–${model.maximumPercent}%`;

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
        const activeGridSegments = activeFlowChevronCount(
            gridScale.actualPercent,
            grid.direction !== "balanced"
        );
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
        renderFlowChevrons(
            elements.gridSegments,
            activeGridSegments,
            grid.direction === "import"
        );
        setFlipToggleLabels(
            elements.gridViewToggles,
            elements.gridFront,
            `${grid.label}, ${model.gridPowerW === null ? "grid reading unavailable" : formatSignedWatts(gridValue)}. Show simplified grid exchange view`,
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

        elements.lastUpdate.textContent = formatRelativeTime(model.timestampMs);
        applyFreshnessUi(model);
        publishBatteryForecastState(model);
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

        if (hasRenderedData) {
            applyFreshnessUi(latestModel || { pending: false, stale: true }, { updateFailed: true });
            elements.lastUpdate.textContent = "Live update failed";
            GraphiteFlash.warning("The latest energy reading could not be loaded. Showing the previous values.", {
                title: "Status update failed",
                duration: 5000
            });
            return;
        }

        setConnectionState("offline", "Offline");

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

    async function fetchJson(url, signal) {
        const response = await fetch(url, {
            method: "GET",
            headers: { Accept: "application/json" },
            cache: "no-store",
            signal
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok) {
            const error = new Error(payload?.error || `Request failed with HTTP ${response.status}.`);
            error.status = response.status;
            throw error;
        }
        return payload;
    }

    async function fetchStatusBundle() {
        if (activeController) activeController.abort();
        activeController = new AbortController();
        const timeout = window.setTimeout(() => activeController.abort(), 8000);

        try {
            const statusPromise = fetchJson(config.statusUrl, activeController.signal);
            const automationPromise = config.automationStatusUrl
                ? fetchJson(config.automationStatusUrl, activeController.signal).catch(() => null)
                : Promise.resolve(null);
            const [payload, automationData] = await Promise.all([statusPromise, automationPromise]);
            return { payload, automationData };
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

        // Capture before the async fetch — interval ticks used to clear burst first.
        const shouldPulseTick = isBurstActive();

        elements.refresh.setAttribute("aria-busy", "true");
        elements.refresh.disabled = true;
        if (!hasRenderedData) renderLoading();

        try {
            const { payload, automationData } = await fetchStatusBundle();
            const model = normalizePayload(payload);
            model.pending = isPowerPending(automationData, model);
            renderModel(model);
            if (shouldPulseTick) {
                // Wait a frame so the pill is painted after render/loading swap.
                window.requestAnimationFrame(() => pulseFreshnessTick());
            }
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
        if (document.hidden) {
            updateBurstVisual();
            return;
        }

        refreshTimer = window.setInterval(() => {
            const burstTick = remainingBoostTicks > 0;
            Promise.resolve(refresh()).finally(() => {
                if (!burstTick || remainingBoostTicks <= 0) {
                    updateBurstVisual();
                    return;
                }
                remainingBoostTicks -= 1;
                if (remainingBoostTicks === 0) {
                    activeRefreshIntervalMs = Math.max(1000, Number(config.refreshIntervalMs) || 20000);
                    updateBurstVisual();
                    startRefreshTimer();
                    return;
                }
                updateBurstVisual();
            });
        }, activeRefreshIntervalMs);
        updateBurstVisual();
    }

    function stopRefreshTimer() {
        if (refreshTimer !== null) {
            window.clearInterval(refreshTimer);
            refreshTimer = null;
        }
        updateBurstVisual();
    }

    function restartFastRefreshBurst(immediateRefresh = false, { manual = false } = {}) {
        remainingBoostTicks = boostTickCount;
        activeRefreshIntervalMs = boostRefreshIntervalMs;
        updateBurstVisual();

        if (document.hidden) return;

        if (immediateRefresh) {
            refresh({ manual });
        }
        startRefreshTimer();
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
        restartFastRefreshBurst(true, { manual: true });
    });
    [elements.freshnessLabel, elements.powerSimpleFreshness].forEach((pill) => {
        if (!pill) return;
        pill.addEventListener("click", () => {
            restartFastRefreshBurst(true, { manual: true });
        });
    });
    elements.retry.addEventListener("click", () => refresh({ manual: true }));
    [elements.batteryIcon, elements.batteryTarget, elements.batterySimpleTarget].forEach(bindBatteryPopover);
    document.addEventListener("pointerdown", (event) => {
        if (!activeBatteryPopoverTrigger) return;
        if (
            !activeBatteryPopoverTrigger.contains(event.target) &&
            !eventInsideBatteryPopover(event.target)
        ) {
            hideBatteryPopover(activeBatteryPopoverTrigger);
        }
    });
    document.addEventListener("keydown", (event) => {
        if (event.key !== "Escape" || !activeBatteryPopoverTrigger) return;
        const trigger = activeBatteryPopoverTrigger;
        hideBatteryPopover(trigger);
        trigger.focus({ preventScroll: true });
    });
    window.addEventListener("resize", () => hideBatteryPopover());
    window.addEventListener("scroll", (event) => {
        if (eventInsideBatteryPopover(event.target)) return;
        hideBatteryPopover();
    }, true);
    document.addEventListener("touchmove", (event) => {
        if (eventInsideBatteryPopover(event.target)) return;
        hideBatteryPopover();
    }, {
        capture: true,
        passive: true
    });
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
        restartFastRefreshBurst(true);
    });
    setPowerView(storedPowerViewIsSimple());
    setBatteryView(storedBatteryViewIsSimple());
    setGridView(storedGridViewIsSimple());
    renderLoading();
    restartFastRefreshBurst(true);
})();
