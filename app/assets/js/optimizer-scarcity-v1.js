(() => {
    "use strict";

    const root = document.querySelector('[data-component="optimizer-scarcity-v1"]');
    if (!root) return;

    const config = window.OPTIMIZER_SCARCITY_V1_CONFIG || {};
    const get = (role) => root.querySelector(`[data-role="${role}"]`);
    const elements = {
        loading: get("loading"),
        error: get("error"),
        errorMessage: get("error-message"),
        retry: get("retry"),
        content: get("content"),
        refresh: get("refresh"),
        lastUpdate: get("last-update"),
        connectionBadge: get("connection-badge"),
        bandDotsTrigger: get("band-dots-trigger"),
        bandDots: get("band-dots"),
        bandsList: get("bands-list"),
        statusPill: get("status-pill"),
        statusValue: get("status-value"),
        firstLimit: get("first-limit"),
        firstLimitBoundary: get("first-limit-boundary"),
        firstLimitPrice: get("first-limit-price"),
        statusCaption: get("status-caption"),
        refillPill: get("refill-pill"),
        refillExplanation: get("refill-explanation"),
        refillStartingSoc: get("refill-starting-soc"),
        refillMaximumSoc: get("refill-maximum-soc"),
        refillAvailableGap: get("refill-available-gap"),
        refillScheduledGain: get("refill-scheduled-gain"),
        refillEquation: get("refill-equation"),
        currentSoc: get("current-soc"),
        battery: get("battery"),
        currentLabel: get("current-label"),
        scheduledLabel: get("scheduled-label"),
        scarcityLegend: get("scarcity-legend"),
        scarcityLabel: get("scarcity-label"),
        reserveLegend: get("reserve-legend"),
        reserveLabel: get("reserve-label"),
        batteryReserve: get("battery-reserve"),
        batteryUpperBuffer: get("battery-upper-buffer"),
        batteryCaption: get("battery-caption")
    };

    const segments = Array.from(root.querySelectorAll("[data-segment]"));
    const scarcityClips = Array.from(root.querySelectorAll("[data-scarcity-segment]"));
    const bandsDialog = document.getElementById("scarcity-v1-bands-dialog");
    const refillDialog = document.getElementById("scarcity-v1-refill-dialog");
    const desktopHover = window.matchMedia("(hover: hover) and (pointer: fine)");
    let controller = null;
    let lastUpdatedAt = null;
    let refreshTimer = null;
    let clockTimer = null;
    let dialogCloseTimer = null;
    let refillDialogCloseTimer = null;

    const finite = (value) => value !== null && value !== undefined && value !== "" && Number.isFinite(Number(value))
        ? Number(value)
        : null;
    const percent = (value) => finite(value) === null ? "—" : `${Number(value).toFixed(1)}%`;
    const wholePercent = (value) => finite(value) === null ? "—" : `${Math.round(Number(value))}%`;
    const points = (value) => finite(value) === null ? "—" : `${Number(value) >= 0 ? "+" : "−"}${Math.abs(Number(value)).toFixed(1)} pp`;
    const money = (value) => finite(value) === null ? "Unavailable" : `€${Number(value).toFixed(4)}/kWh`;
    const cents = (value) => {
        const number = finite(value);
        if (number === null) return "Unavailable";
        const formatted = (number * 100).toFixed(2).replace(/\.?0+$/, "");
        return `${formatted} ct/kWh`;
    };

    function ageText(milliseconds) {
        const seconds = Math.max(0, Math.floor(milliseconds / 1000));
        if (seconds < 60) return `${seconds} sec${seconds === 1 ? "" : "s"} ago`;
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) return `${minutes} min ago`;
        const hours = Math.floor(minutes / 60);
        return `${hours} hr${hours === 1 ? "" : "s"} ago`;
    }

    function updateClock() {
        if (lastUpdatedAt === null) return;
        elements.lastUpdate.textContent = `Updated ${ageText(Date.now() - lastUpdatedAt)}`;
    }

    function setView(view, message = "") {
        root.dataset.state = view;
        root.setAttribute("aria-busy", String(view === "loading"));
        elements.loading.hidden = view !== "loading";
        elements.error.hidden = view !== "error";
        elements.content.hidden = view !== "ready";
        elements.refresh.disabled = view === "loading";
        if (message) elements.errorMessage.textContent = message;
    }

    function segmentFill(level, index) {
        if (level === null) return 0;
        return Math.max(0, Math.min(100, (level - index * 10) * 10));
    }

    function scarcitySegmentRange(fromSoc, toSoc, index) {
        const segmentStart = index * 10;
        const segmentEnd = segmentStart + 10;
        const overlapStart = Math.max(fromSoc, segmentStart);
        const overlapEnd = Math.min(toSoc, segmentEnd);
        if (overlapEnd <= overlapStart) return { start: 0, width: 0 };
        return {
            start: (overlapStart - segmentStart) * 10,
            width: (overlapEnd - overlapStart) * 10
        };
    }

    function appendBatteryTileMarker(value, modifier) {
        const number = finite(value);
        if (number === null || !scarcityClips.length) return;
        const clamped = Math.max(0, Math.min(100, number));
        const onTileBoundary = clamped > 0 && clamped % 10 === 0;
        const index = clamped >= 100
            ? scarcityClips.length - 1
            : onTileBoundary
                ? Math.max(0, Math.floor(clamped / 10) - 1)
                : Math.min(scarcityClips.length - 1, Math.floor(clamped / 10));
        const position = onTileBoundary || clamped >= 100
            ? 100
            : (clamped - index * 10) * 10;
        const marker = document.createElement("span");
        marker.className = `scarcity-v1__battery-tile-marker scarcity-v1__battery-tile-marker--${modifier}`;
        marker.style.setProperty("--marker-position", `${position}%`);
        marker.style.setProperty("--marker-shift", position >= 100 ? "-100%" : position <= 0 ? "0" : "-50%");
        scarcityClips[index].appendChild(marker);
    }

    function renderBattery(currentSoc, maximumScheduledSoc, minimumSoc, maximumSoc, appliedBands) {
        const current = finite(currentSoc);
        const scheduled = finite(maximumScheduledSoc);
        const minimum = finite(minimumSoc);
        const maximum = finite(maximumSoc);
        const scheduledLevel = current === null ? scheduled : scheduled === null ? current : Math.max(current, scheduled);
        const firstAppliedBand = appliedBands[0] || null;
        const bandFrom = finite(firstAppliedBand?.from_soc_percent);
        const bandTo = finite(firstAppliedBand?.to_soc_percent);
        const hasScarcityBand = bandFrom !== null && bandTo !== null && bandTo > bandFrom;

        segments.forEach((segment, index) => {
            segment.style.setProperty("--scheduled-fill", `${segmentFill(scheduledLevel, index)}%`);
            segment.style.setProperty("--current-fill", `${segmentFill(current, index)}%`);
        });

        scarcityClips.forEach((clip, index) => {
            clip.replaceChildren();
            appliedBands.forEach((band, rank) => {
                const from = finite(band.from_soc_percent);
                const to = finite(band.to_soc_percent);
                if (from === null || to === null || to <= from) return;
                const range = scarcitySegmentRange(from, to, index);
                if (range.width <= 0) return;
                const overlay = document.createElement("span");
                overlay.className = "scarcity-v1__battery-scarcity";
                overlay.dataset.bandRank = String(rank + 1);
                overlay.style.setProperty("--scarcity-start", `${range.start}%`);
                overlay.style.setProperty("--scarcity-fill", `${range.width}%`);
                overlay.style.setProperty("--scarcity-height", `${(rank + 1) * 5}px`);
                clip.appendChild(overlay);
            });
        });

        const lowestBandStart = appliedBands.length
            ? Math.min(...appliedBands.map((band) => Number(band.from_soc_percent)))
            : null;
        const reserveEnd = minimum ?? lowestBandStart;
        const hasReserve = reserveEnd !== null && reserveEnd > 0;
        const hasUpperBuffer = maximum !== null && maximum < 100;
        elements.batteryReserve.hidden = !hasReserve;
        elements.batteryUpperBuffer.hidden = !hasUpperBuffer;
        elements.reserveLegend.hidden = !hasReserve && !hasUpperBuffer;
        if (hasReserve) {
            const clampedReserve = Math.max(0, Math.min(100, reserveEnd));
            elements.batteryReserve.style.setProperty("--reserve-end", `${clampedReserve}%`);
        } else {
            elements.batteryReserve.style.removeProperty("--reserve-end");
        }
        if (hasUpperBuffer) {
            const clampedMaximum = Math.max(0, Math.min(100, maximum));
            elements.batteryUpperBuffer.style.setProperty("--upper-start", `${clampedMaximum}%`);
        } else {
            elements.batteryUpperBuffer.style.removeProperty("--upper-start");
        }
        elements.reserveLabel.textContent = hasReserve && hasUpperBuffer
            ? `Operating range ${Math.max(0, Math.min(100, reserveEnd)).toFixed(0)}–${Math.max(0, Math.min(100, maximum)).toFixed(0)}%`
            : hasReserve
                ? `Minimum ${Math.max(0, Math.min(100, reserveEnd)).toFixed(0)}%`
                : hasUpperBuffer
                    ? `Maximum ${Math.max(0, Math.min(100, maximum)).toFixed(0)}%`
                    : "Operating range —";

        elements.scarcityLegend.hidden = !hasScarcityBand;
        if (hasScarcityBand) {
            elements.scarcityLabel.textContent = `Scarcity ${appliedBands.length} band${appliedBands.length === 1 ? "" : "s"}`;
        } else {
            elements.scarcityLabel.textContent = "Scarcity —";
        }

        if (hasReserve) appendBatteryTileMarker(reserveEnd, "reserve");
        if (hasUpperBuffer) appendBatteryTileMarker(maximum, "upper");
        if (hasScarcityBand) appendBatteryTileMarker(bandTo, "limit");

        elements.battery.setAttribute("aria-valuenow", String(current ?? 0));
        elements.battery.setAttribute(
            "aria-valuetext",
            current === null || scheduled === null
                ? "Battery schedule unavailable"
                : `${percent(current)} current, ${percent(scheduled)} maximum scheduled${hasReserve ? `, minimum reserve ${percent(reserveEnd)}` : ""}${hasUpperBuffer ? `, maximum operating level ${percent(maximum)}` : ""}${hasScarcityBand ? `, ${appliedBands.length} applied scarcity band${appliedBands.length === 1 ? "" : "s"}` : ""}`
        );
    }

    function renderBands(bands) {
        elements.bandDots.replaceChildren();
        elements.bandsList.replaceChildren();

        [...bands]
            .sort((left, right) => Number(left.from_soc_percent) - Number(right.from_soc_percent))
            .forEach((band) => {
            const dot = document.createElement("span");
            const applied = Boolean(band.applied);
            dot.className = "scarcity-v1__band-dot";
            dot.dataset.state = applied ? "on" : "off";
            elements.bandDots.appendChild(dot);

            const row = document.createElement("div");
            row.className = "scarcity-v1__band-row";
            row.dataset.state = applied ? "on" : "off";
            const label = document.createElement("span");
            label.className = "scarcity-v1__band-row-label";
            const indicator = document.createElement("i");
            indicator.setAttribute("aria-hidden", "true");
            const range = document.createElement("span");
            range.textContent = `SoC ${Number(band.from_soc_percent).toFixed(0)}%–${Number(band.to_soc_percent).toFixed(0)}%`;
            label.append(indicator, range);
            const price = document.createElement("span");
            price.className = "scarcity-v1__band-row-price";
            price.textContent = `Min. price ${money(band.reference_price_eur_per_kwh)}`;
            row.append(label, price);
            elements.bandsList.appendChild(row);
        });

        elements.bandDotsTrigger.setAttribute(
            "aria-label",
            `Show all scarcity bands. ${bands.filter((band) => band.applied).length} of ${bands.length} are on.`
        );
    }

    function cancelDialogClose() {
        window.clearTimeout(dialogCloseTimer);
        dialogCloseTimer = null;
    }

    function closeBandsDialog() {
        cancelDialogClose();
        if (bandsDialog?.open) bandsDialog.close("hover-leave");
        elements.bandDotsTrigger.setAttribute("aria-expanded", "false");
    }

    function scheduleDialogClose() {
        cancelDialogClose();
        dialogCloseTimer = window.setTimeout(() => {
            if (
                desktopHover.matches
                && (elements.bandDotsTrigger.matches(":hover")
                    || bandsDialog.matches(":hover")
                    || bandsDialog.contains(document.activeElement))
            ) {
                return;
            }
            closeBandsDialog();
        }, 140);
    }

    function positionDesktopDialog() {
        const gap = 8;
        const viewportPadding = 12;
        const triggerRect = elements.bandDotsTrigger.getBoundingClientRect();
        const dialogRect = bandsDialog.getBoundingClientRect();
        let left = triggerRect.left;
        left = Math.max(viewportPadding, Math.min(left, window.innerWidth - dialogRect.width - viewportPadding));
        let top = triggerRect.bottom + gap;
        if (top + dialogRect.height > window.innerHeight - viewportPadding) {
            top = triggerRect.top - dialogRect.height - gap;
        }
        bandsDialog.style.left = `${Math.round(left)}px`;
        bandsDialog.style.top = `${Math.max(viewportPadding, Math.round(top))}px`;
    }

    function openDesktopDialog() {
        if (!desktopHover.matches || !bandsDialog) return;
        cancelDialogClose();
        bandsDialog.dataset.displayMode = "desktop";
        if (!bandsDialog.open) bandsDialog.show();
        positionDesktopDialog();
        elements.bandDotsTrigger.setAttribute("aria-expanded", "true");
    }

    function openMobileDialog() {
        if (!bandsDialog || desktopHover.matches) return;
        bandsDialog.dataset.displayMode = "mobile";
        bandsDialog.style.removeProperty("left");
        bandsDialog.style.removeProperty("top");
        window.GraphiteDialog.open(bandsDialog, { trigger: elements.bandDotsTrigger });
        elements.bandDotsTrigger.setAttribute("aria-expanded", "true");
    }

    function cancelRefillDialogClose() {
        window.clearTimeout(refillDialogCloseTimer);
        refillDialogCloseTimer = null;
    }

    function closeRefillDialog() {
        cancelRefillDialogClose();
        if (refillDialog?.open) refillDialog.close("hover-leave");
        elements.refillPill.setAttribute("aria-expanded", "false");
    }

    function scheduleRefillDialogClose() {
        cancelRefillDialogClose();
        refillDialogCloseTimer = window.setTimeout(() => {
            if (
                desktopHover.matches
                && (elements.refillPill.matches(":hover")
                    || refillDialog.matches(":hover")
                    || refillDialog.contains(document.activeElement))
            ) {
                return;
            }
            closeRefillDialog();
        }, 140);
    }

    function positionRefillDialog() {
        const gap = 8;
        const viewportPadding = 12;
        const triggerRect = elements.refillPill.getBoundingClientRect();
        const dialogRect = refillDialog.getBoundingClientRect();
        let left = triggerRect.right - dialogRect.width;
        left = Math.max(viewportPadding, Math.min(left, window.innerWidth - dialogRect.width - viewportPadding));
        let top = triggerRect.bottom + gap;
        if (top + dialogRect.height > window.innerHeight - viewportPadding) {
            top = triggerRect.top - dialogRect.height - gap;
        }
        refillDialog.style.left = `${Math.round(left)}px`;
        refillDialog.style.top = `${Math.max(viewportPadding, Math.round(top))}px`;
    }

    function openDesktopRefillDialog() {
        if (!desktopHover.matches || !refillDialog) return;
        cancelRefillDialogClose();
        refillDialog.dataset.displayMode = "desktop";
        if (!refillDialog.open) refillDialog.show();
        positionRefillDialog();
        elements.refillPill.setAttribute("aria-expanded", "true");
    }

    function openMobileRefillDialog() {
        if (!refillDialog || desktopHover.matches) return;
        refillDialog.dataset.displayMode = "mobile";
        refillDialog.style.removeProperty("left");
        refillDialog.style.removeProperty("top");
        window.GraphiteDialog.open(refillDialog, { trigger: elements.refillPill });
        elements.refillPill.setAttribute("aria-expanded", "true");
    }

    function render(payload) {
        const scarcity = payload.scarcity || {};
        const activation = scarcity.refill_activation || {};
        const scheduled = scarcity.scheduled_refill || {};
        const outcome = payload.optimizerOutcome || {};
        const bands = Array.isArray(scarcity.bands) ? scarcity.bands : [];
        const appliedCount = bands.filter((band) => band.applied).length;
        const totalCount = bands.length;
        const currentSoc = finite(outcome.starting_soc_percent ?? scheduled.starting_soc_percent);
        const maximumScheduledSoc = finite(outcome.maximum_scheduled_soc_percent ?? scheduled.maximum_scheduled_soc_percent);
        const minimumSoc = finite(outcome.minimum_soc_percent);
        const maximumSoc = finite(outcome.maximum_soc_percent);
        const scheduledGain = finite(scheduled.scheduled_gain_percent_points);
        const refillPercent = finite(scheduled.scheduled_refill_percent);
        const availableGap = currentSoc === null || maximumSoc === null
            ? null
            : Math.max(0, maximumSoc - currentSoc);

        const appliedBands = bands
            .filter((band) => {
                const from = finite(band.from_soc_percent);
                const to = finite(band.to_soc_percent);
                return band.applied && from !== null && to !== null && to > from;
            })
            .sort((left, right) => Number(right.to_soc_percent) - Number(left.to_soc_percent));
        const firstAppliedBand = appliedBands[0] || null;
        renderBands(bands);
        renderBattery(currentSoc, maximumScheduledSoc, minimumSoc, maximumSoc, appliedBands);

        elements.statusValue.textContent = totalCount ? `${appliedCount}/${totalCount}` : "—";
        if (firstAppliedBand) {
            elements.firstLimit.dataset.state = "applied";
            elements.firstLimitBoundary.textContent = `${Number(firstAppliedBand.to_soc_percent).toFixed(0)}%`;
            elements.firstLimitPrice.textContent = cents(firstAppliedBand.reference_price_eur_per_kwh);
        } else {
            elements.firstLimit.dataset.state = "empty";
            elements.firstLimitBoundary.textContent = "—";
            elements.firstLimitPrice.textContent = "No limit applied";
        }
        elements.statusCaption.textContent = finite(activation.percent) === null
            ? "Activation input unavailable"
            : `Activation input ${percent(activation.percent)} · previous published schedule`;

        const calculationReady = Boolean(scarcity.calculation_available) && !payload.stale;
        const algorithmEnabled = Boolean(scarcity.algorithm_enabled);
        const statusState = calculationReady && algorithmEnabled ? "ready" : algorithmEnabled ? "warning" : "off";
        elements.statusValue.dataset.state = statusState;
        elements.statusPill.dataset.state = statusState;
        elements.connectionBadge.dataset.state = calculationReady ? "ready" : "warning";

        if (!algorithmEnabled) {
            elements.statusPill.textContent = "Disabled";
            elements.connectionBadge.textContent = "Disabled";
        } else if (payload.stale) {
            elements.statusPill.textContent = "Stale";
            elements.connectionBadge.textContent = "Stale";
        } else if (!scarcity.calculation_available) {
            elements.statusPill.textContent = "Unavailable";
            elements.connectionBadge.textContent = "Warning";
        } else {
            elements.statusPill.textContent = appliedCount ? "Active" : "Ready";
            elements.connectionBadge.textContent = "Live";
        }

        elements.currentSoc.textContent = wholePercent(currentSoc);
        elements.currentLabel.textContent = `Current ${percent(currentSoc)}`;
        elements.scheduledLabel.textContent = `Scheduled ${percent(maximumScheduledSoc)}`;
        elements.refillPill.textContent = refillPercent === null ? "No schedule" : `${percent(refillPercent)} of gap`;
        elements.refillPill.dataset.state = maximumScheduledSoc === null ? "warning" : "ready";
        elements.refillPill.setAttribute(
            "aria-label",
            refillPercent === null
                ? "Explain scheduled refill. No scheduled refill is available."
                : `Explain scheduled refill: ${percent(refillPercent)} of the available battery gap.`
        );
        elements.refillExplanation.textContent = refillPercent === null
            ? "No scheduled refill is available for the current optimizer plan. This percentage normally shows how much of the available space up to the maximum SoC is expected to be refilled."
            : `The optimizer plans to refill ${percent(refillPercent)} of the available space between the starting battery level and the configured maximum SoC.`;
        elements.refillStartingSoc.textContent = percent(currentSoc);
        elements.refillMaximumSoc.textContent = percent(maximumSoc);
        elements.refillAvailableGap.textContent = availableGap === null ? "—" : `${availableGap.toFixed(1)} pp`;
        elements.refillScheduledGain.textContent = scheduledGain === null ? "—" : `${scheduledGain.toFixed(1)} pp`;
        elements.refillEquation.textContent = scheduledGain === null || availableGap === null || refillPercent === null
            ? "Scheduled refill calculation unavailable"
            : `${scheduledGain.toFixed(1)} pp ÷ ${availableGap.toFixed(1)} pp = ${refillPercent.toFixed(1)}% of gap`;
        elements.batteryCaption.textContent = maximumScheduledSoc === null
            ? "Maximum scheduled battery level unavailable"
            : `Expected maximum ${percent(maximumScheduledSoc)}${scheduledGain === null ? "" : ` · ${points(scheduledGain)} scheduled`}`;

        lastUpdatedAt = Date.now();
        updateClock();
        setView("ready");
    }

    async function load() {
        controller?.abort();
        controller = new AbortController();
        if (elements.content.hidden) setView("loading");
        try {
            const response = await fetch(new URL(config.apiUrl, document.baseURI).href, {
                cache: "no-store",
                credentials: "same-origin",
                headers: { Accept: "application/json" },
                signal: controller.signal
            });
            const contentType = response.headers.get("content-type") || "";
            const payload = contentType.includes("application/json") ? await response.json() : null;
            if (!response.ok || !payload?.success) {
                throw new Error(payload?.error || `Request failed with HTTP ${response.status}.`);
            }
            render(payload);
        } catch (error) {
            if (error.name === "AbortError") return;
            elements.connectionBadge.textContent = "Offline";
            elements.connectionBadge.dataset.state = "warning";
            setView("error", error.message || "The scarcity API could not be loaded.");
        }
    }

    elements.refresh.addEventListener("click", load);
    elements.retry.addEventListener("click", load);
    elements.bandDotsTrigger.addEventListener("pointerenter", openDesktopDialog);
    elements.bandDotsTrigger.addEventListener("pointerleave", scheduleDialogClose);
    elements.bandDotsTrigger.addEventListener("focus", openDesktopDialog);
    elements.bandDotsTrigger.addEventListener("blur", () => {
        if (desktopHover.matches && !bandsDialog.matches(":hover")) scheduleDialogClose();
    });
    elements.bandDotsTrigger.addEventListener("click", openMobileDialog);
    bandsDialog.addEventListener("pointerenter", cancelDialogClose);
    bandsDialog.addEventListener("pointerleave", () => {
        if (desktopHover.matches) scheduleDialogClose();
    });
    bandsDialog.addEventListener("close", () => {
        elements.bandDotsTrigger.setAttribute("aria-expanded", "false");
        bandsDialog.style.removeProperty("left");
        bandsDialog.style.removeProperty("top");
    });
    elements.refillPill.addEventListener("pointerenter", openDesktopRefillDialog);
    elements.refillPill.addEventListener("pointerleave", scheduleRefillDialogClose);
    elements.refillPill.addEventListener("focus", openDesktopRefillDialog);
    elements.refillPill.addEventListener("blur", () => {
        if (desktopHover.matches && !refillDialog.matches(":hover")) scheduleRefillDialogClose();
    });
    elements.refillPill.addEventListener("click", openMobileRefillDialog);
    refillDialog.addEventListener("pointerenter", cancelRefillDialogClose);
    refillDialog.addEventListener("pointerleave", () => {
        if (desktopHover.matches) scheduleRefillDialogClose();
    });
    refillDialog.addEventListener("close", () => {
        elements.refillPill.setAttribute("aria-expanded", "false");
        refillDialog.style.removeProperty("left");
        refillDialog.style.removeProperty("top");
    });
    window.addEventListener("resize", () => {
        if (bandsDialog.open && desktopHover.matches) positionDesktopDialog();
        if (refillDialog.open && desktopHover.matches) positionRefillDialog();
    });

    load();
    refreshTimer = window.setInterval(load, Math.max(10000, Number(config.refreshIntervalMs) || 60000));
    clockTimer = window.setInterval(updateClock, 1000);

    window.addEventListener("pagehide", () => {
        controller?.abort();
        cancelDialogClose();
        cancelRefillDialogClose();
        window.clearInterval(refreshTimer);
        window.clearInterval(clockTimer);
    }, { once: true });
})();
