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
        powerDescription: component.querySelector('[data-role="power-description"]'),
        freshnessLabel: component.querySelector('[data-role="freshness-label"]'),
        powerFlow: component.querySelector('[data-role="power-flow"]'),
        powerFlowNegativeFill: component.querySelector('[data-role="power-flow-fill-negative"]'),
        powerFlowPositiveFill: component.querySelector('[data-role="power-flow-fill-positive"]'),
        powerMinLabel: component.querySelector('[data-role="power-min-label"]'),
        powerMaxLabel: component.querySelector('[data-role="power-max-label"]'),
        batteryPercent: component.querySelector('[data-role="battery-percent"]'),
        batteryEnergy: component.querySelector('[data-role="battery-energy"]'),
        batteryTarget: component.querySelector('[data-role="battery-target"]'),
        batteryProgress: component.querySelector('[data-role="battery-progress"]'),
        batteryProgressFill: component.querySelector('[data-role="battery-progress-fill"]'),
        batteryMinMarker: component.querySelector('[data-role="battery-min-marker"]'),
        batteryTargetMarker: component.querySelector('[data-role="battery-target-marker"]'),
        batteryMinLabel: component.querySelector('[data-role="battery-min-label"]'),
        batteryTargetLabel: component.querySelector('[data-role="battery-target-label"]'),
        gridPower: component.querySelector('[data-role="grid-power"]'),
        gridDescription: component.querySelector('[data-role="grid-description"]'),
        gridState: component.querySelector('[data-role="grid-state"]'),
        gridFlow: component.querySelector('[data-role="grid-flow"]'),
        gridFlowFill: component.querySelector('[data-role="grid-flow-fill"]'),
        gridMinLabel: component.querySelector('[data-role="grid-min-label"]'),
        gridMaxLabel: component.querySelector('[data-role="grid-max-label"]')
    };

    let refreshTimer = null;
    let activeController = null;
    let hasRenderedData = false;

    function finiteNumber(value, fallback = null) {
        if (value === null || value === undefined || value === "") return fallback;
        const number = Number(value);
        return Number.isFinite(number) ? number : fallback;
    }

    function clamp(value, minimum, maximum) {
        return Math.min(maximum, Math.max(minimum, value));
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

    function determineMode(acMode, powerW) {
        if (acMode === 1 || powerW > 0) return "charging";
        if (acMode === 2 || powerW < 0) return "discharging";
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
        const acMode = finiteNumber(properties.acMode, 0);
        const batteryPercent = finiteNumber(properties.electricLevel);
        const gridPowerW = p1Readings ? finiteNumber(p1Readings.total_power) : null;
        const timestampMs = timestampToMs(zendure.timestamp);
        const mode = determineMode(acMode, powerW);
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
            return {
                label: "Charging now",
                description: model.remainingTime
                    ? `To <strong>${model.maximumPercent}%</strong> in ~<strong>${model.remainingTime}</strong>`
                    : `Charging toward the <strong>${model.maximumPercent}%</strong> target`
            };
        }

        if (model.mode === "discharging") {
            return {
                label: "Discharging now",
                description: model.remainingTime
                    ? `To <strong>${model.minimumPercent}%</strong> in ~<strong>${model.remainingTime}</strong>`
                    : `Powering the home above the <strong>${model.minimumPercent}%</strong> reserve`
            };
        }

        return {
            label: "Standby",
            description: "No active battery power flow"
        };
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

    function setConnectionState(state, label) {
        elements.connectionBadge.dataset.state = state;
        elements.connectionBadge.textContent = label;
    }

    function renderModel(model) {
        hasRenderedData = true;
        component.dataset.state = model.stale ? "stale" : "ready";
        component.setAttribute("aria-busy", "false");
        elements.loading.hidden = true;
        elements.error.hidden = true;
        elements.content.hidden = false;

        const copy = modeCopy(model);
        elements.powerHero.dataset.mode = model.mode;
        elements.modeLabel.textContent = copy.label;
        elements.powerValue.innerHTML = `${formatSignedWatts(model.powerW).replace(" W", "")} <span class="app-power-value__unit">W</span>`;
        elements.powerDescription.innerHTML = copy.description;

        const minPower = Math.min(-1, finiteNumber(config.powerMinW, -1200));
        const maxPower = Math.max(1, finiteNumber(config.powerMaxW, 1200));
        const axisLimit = model.powerW >= 0 ? maxPower : Math.abs(minPower);
        const powerScale = calculateVisualBarScale(model.powerW, axisLimit);
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
        } else {
            const storedKwh = (model.batteryPercent / 100) * (model.capacityWh / 1000);
            const capacityKwh = model.capacityWh / 1000;
            const batteryColor = window.GraphiteBatteryColorScale
                ? window.GraphiteBatteryColorScale.colorFor(model.batteryPercent)
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
        }

        const batteryCountdown = batteryCountdownCopy(model);
        elements.batteryTarget.textContent = batteryCountdown.label;
        elements.batteryTarget.setAttribute("aria-label", batteryCountdown.description);
        elements.batteryTarget.title = batteryCountdown.description;
        elements.batteryTarget.dataset.mode = model.mode;
        elements.batteryMinMarker.style.setProperty("--app-marker", `${model.minimumPercent}%`);
        elements.batteryTargetMarker.style.setProperty("--app-marker", `${model.maximumPercent}%`);
        elements.batteryMinMarker.dataset.active = model.mode === "discharging" ? "true" : "false";
        elements.batteryTargetMarker.dataset.active = model.mode === "charging" ? "true" : "false";
        elements.batteryMinLabel.textContent = `Minimum ${model.minimumPercent}%`;
        elements.batteryTargetLabel.textContent = `Maximum ${model.maximumPercent}%`;

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

    elements.refresh.addEventListener("click", () => refresh({ manual: true }));
    elements.retry.addEventListener("click", () => refresh({ manual: true }));
    document.addEventListener("visibilitychange", () => {
        if (document.hidden) {
            stopRefreshTimer();
            return;
        }
        refresh();
        startRefreshTimer();
    });

    renderLoading();
    refresh();
    startRefreshTimer();
})();
