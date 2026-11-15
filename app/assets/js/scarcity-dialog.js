(() => {
    "use strict";

    const dialog = document.querySelector('[data-component="scarcity-dialog"]');
    const trigger = document.querySelector('[data-role="scarcity-trigger"]');
    if (!(dialog instanceof HTMLDialogElement) || !(trigger instanceof HTMLButtonElement)) return;

    const config = window.GRAPHITE_APP_CONFIG || {};
    const batteryRoot = dialog.querySelector('[data-component="scarcity-battery"]');
    if (!(batteryRoot instanceof HTMLElement)) return;

    const get = (role, scope = dialog) => scope.querySelector(`[data-role="${role}"]`);
    const elements = {
        loading: get("scarcity-loading"),
        error: get("scarcity-error"),
        errorMessage: get("scarcity-error-message"),
        retry: get("scarcity-retry"),
        content: get("scarcity-content"),
        bandsTrigger: get("scarcity-bands-trigger"),
        bandsDialog: document.getElementById("app-scarcity-bands-dialog"),
        bandsList: get("scarcity-bands-list"),
        refillPill: get("scarcity-refill-pill"),
        refillDialog: document.getElementById("app-scarcity-refill-dialog"),
        refillExplanation: get("scarcity-refill-explanation"),
        refillStartingSoc: get("scarcity-refill-starting-soc"),
        refillMaximumSoc: get("scarcity-refill-maximum-soc"),
        refillAvailableGap: get("scarcity-refill-available-gap"),
        refillScheduledGain: get("scarcity-refill-scheduled-gain"),
        refillEquation: get("scarcity-refill-equation"),
        currentSoc: get("current-soc", batteryRoot),
        battery: get("battery", batteryRoot),
        currentLabel: get("current-label", batteryRoot),
        scheduledLabel: get("scheduled-label", batteryRoot),
        scarcityLegend: get("scarcity-legend", batteryRoot),
        scarcityLabel: get("scarcity-label", batteryRoot),
        reserveLegend: get("reserve-legend", batteryRoot),
        reserveLabel: get("reserve-label", batteryRoot),
        batteryCaption: get("battery-caption", batteryRoot),
        priceRefresh: document.querySelector('[data-role="price-refresh"]')
    };
    const dotGroups = [
        get("scarcity-trigger-dots", document),
        get("scarcity-dialog-dots", dialog)
    ].filter(Boolean);
    const segments = Array.from(batteryRoot.querySelectorAll("[data-segment]"));
    const scarcityClips = Array.from(batteryRoot.querySelectorAll("[data-scarcity-segment]"));
    let controller = null;
    let refreshTimer = null;
    let lastLoadedAt = 0;
    let activeDetailClose = null;

    const finite = (value) => value !== null && value !== undefined && value !== "" && Number.isFinite(Number(value))
        ? Number(value)
        : null;
    const percent = (value) => finite(value) === null ? "—" : `${Number(value).toFixed(1)}%`;
    const wholePercent = (value) => finite(value) === null ? "—" : `${Math.round(Number(value))}%`;
    const points = (value) => finite(value) === null ? "—" : `${Number(value) >= 0 ? "+" : "−"}${Math.abs(Number(value)).toFixed(1)} pp`;
    const money = (value) => finite(value) === null ? "Unavailable" : `€${Number(value).toFixed(4)}/kWh`;

    function setView(view, message = "") {
        dialog.dataset.state = view;
        dialog.setAttribute("aria-busy", String(view === "loading"));
        elements.loading.hidden = view !== "loading";
        elements.error.hidden = view !== "error";
        elements.content.hidden = view !== "ready";
        if (message) elements.errorMessage.textContent = message;
    }

    function renderDots(bands, state = "ready") {
        dotGroups.forEach((group) => group.replaceChildren());

        if (state !== "ready" || !bands.length) {
            const dot = document.createElement("i");
            dot.dataset.state = state;
            dotGroups.forEach((group) => group.appendChild(dot.cloneNode(true)));
            return;
        }

        [...bands]
            .sort((left, right) => Number(left.from_soc_percent) - Number(right.from_soc_percent))
            .forEach((band) => {
                const dot = document.createElement("i");
                dot.dataset.state = band.applied ? "on" : "off";
                dotGroups.forEach((group) => group.appendChild(dot.cloneNode(true)));
            });
    }

    function renderBandDetails(bands) {
        elements.bandsList.replaceChildren();
        [...bands]
            .sort((left, right) => Number(left.from_soc_percent) - Number(right.from_soc_percent))
            .forEach((band) => {
                const row = document.createElement("div");
                row.className = "scarcity-v1__band-row";
                row.dataset.state = band.applied ? "on" : "off";

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
        const lowestBandStart = appliedBands.length
            ? Math.min(...appliedBands.map((band) => Number(band.from_soc_percent)))
            : null;
        const reserveEnd = minimum ?? lowestBandStart;
        const hasReserve = reserveEnd !== null && reserveEnd > 0;
        const hasUpperBuffer = maximum !== null && maximum < 100;

        segments.forEach((segment, index) => {
            segment.style.setProperty("--scheduled-fill", `${segmentFill(scheduledLevel, index)}%`);
            segment.style.setProperty("--current-fill", `${segmentFill(current, index)}%`);
        });

        scarcityClips.forEach((clip, index) => {
            clip.replaceChildren();
            [
                ["lower", 0, hasReserve ? reserveEnd : 0],
                ["upper", hasUpperBuffer ? maximum : 100, 100]
            ].forEach(([modifier, from, to]) => {
                const range = scarcitySegmentRange(Number(from), Number(to), index);
                if (range.width <= 0) return;
                const buffer = document.createElement("span");
                buffer.className = `scarcity-v1__battery-tile-buffer scarcity-v1__battery-tile-buffer--${modifier}`;
                buffer.style.setProperty("--buffer-start", `${range.start}%`);
                buffer.style.setProperty("--buffer-fill", `${range.width}%`);
                clip.appendChild(buffer);
            });
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

        elements.reserveLegend.hidden = !hasReserve && !hasUpperBuffer;
        elements.reserveLabel.textContent = hasReserve && hasUpperBuffer
            ? `Operating range ${Math.max(0, Math.min(100, reserveEnd)).toFixed(0)}–${Math.max(0, Math.min(100, maximum)).toFixed(0)}%`
            : hasReserve
                ? `Minimum ${Math.max(0, Math.min(100, reserveEnd)).toFixed(0)}%`
                : hasUpperBuffer
                    ? `Maximum ${Math.max(0, Math.min(100, maximum)).toFixed(0)}%`
                    : "Operating range —";

        elements.scarcityLegend.hidden = !hasScarcityBand;
        elements.scarcityLabel.textContent = hasScarcityBand
            ? `Scarcity ${appliedBands.length} band${appliedBands.length === 1 ? "" : "s"}`
            : "Scarcity —";

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

    function render(payload) {
        const scarcity = payload.scarcity || {};
        const scheduled = scarcity.scheduled_refill || {};
        const outcome = payload.optimizerOutcome || {};
        const bands = Array.isArray(scarcity.bands) ? scarcity.bands : [];
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

        renderDots(bands);
        renderBandDetails(bands);
        renderBattery(currentSoc, maximumScheduledSoc, minimumSoc, maximumSoc, appliedBands);

        const appliedCount = bands.filter((band) => band.applied).length;
        trigger.setAttribute(
            "aria-label",
            `Open scarcity overview. ${appliedCount} of ${bands.length} band${bands.length === 1 ? "" : "s"} active${payload.stale ? ". Data is stale" : ""}.`
        );
        trigger.dataset.state = payload.stale ? "warning" : appliedCount ? "active" : "ready";
        elements.currentSoc.textContent = wholePercent(currentSoc);
        elements.currentLabel.textContent = `Current ${percent(currentSoc)}`;
        elements.scheduledLabel.textContent = `Scheduled ${percent(maximumScheduledSoc)}`;
        elements.refillPill.textContent = refillPercent === null ? "No schedule" : `${percent(refillPercent)} of gap`;
        elements.refillPill.dataset.state = maximumScheduledSoc === null || payload.stale ? "warning" : "ready";
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

        lastLoadedAt = Date.now();
        setView("ready");
    }

    function attachDetailDialog(triggerElement, detailDialog, align = "left") {
        if (!(triggerElement instanceof HTMLElement) || !(detailDialog instanceof HTMLDialogElement)) return;
        const close = () => {
            if (detailDialog.open) detailDialog.close("dismiss");
            triggerElement.setAttribute("aria-expanded", "false");
            if (activeDetailClose === close) activeDetailClose = null;
        };
        const position = () => {
            const gap = 8;
            const viewportPadding = 12;
            const triggerRect = triggerElement.getBoundingClientRect();
            const detailRect = detailDialog.getBoundingClientRect();
            let left = align === "right" ? triggerRect.right - detailRect.width : triggerRect.left;
            left = Math.max(viewportPadding, Math.min(left, window.innerWidth - detailRect.width - viewportPadding));
            let top = triggerRect.bottom + gap;
            if (top + detailRect.height > window.innerHeight - viewportPadding) {
                top = triggerRect.top - detailRect.height - gap;
            }
            detailDialog.style.left = `${Math.round(left)}px`;
            detailDialog.style.top = `${Math.max(viewportPadding, Math.round(top))}px`;
        };
        const toggle = () => {
            if (detailDialog.open) {
                close();
                return;
            }

            activeDetailClose?.();
            detailDialog.dataset.displayMode = "desktop";
            detailDialog.show();
            position();
            triggerElement.setAttribute("aria-expanded", "true");
            activeDetailClose = close;
        };

        triggerElement.addEventListener("click", toggle);
        detailDialog.addEventListener("close", () => {
            triggerElement.setAttribute("aria-expanded", "false");
            detailDialog.style.removeProperty("left");
            detailDialog.style.removeProperty("top");
            if (activeDetailClose === close) activeDetailClose = null;
        });
        window.addEventListener("resize", () => {
            if (detailDialog.open) position();
        });
    }

    async function load({ preserveContent = false } = {}) {
        controller?.abort();
        controller = new AbortController();
        if (!preserveContent || elements.content.hidden) setView("loading");

        try {
            const response = await fetch(new URL(config.scarcityUrl || "api/optimizer_scarcity.php", document.baseURI).href, {
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
            renderDots([], "error");
            trigger.dataset.state = "warning";
            trigger.setAttribute("aria-label", "Open scarcity overview. Scarcity information is unavailable.");
            setView("error", error.message || "The scarcity API could not be loaded.");
        }
    }

    trigger.addEventListener("click", () => {
        window.GraphiteDialog.open(dialog, { trigger });
        if (Date.now() - lastLoadedAt > 60000) load({ preserveContent: !elements.content.hidden });
    });
    dialog.addEventListener("close", () => activeDetailClose?.());
    elements.retry.addEventListener("click", () => load());
    elements.priceRefresh?.addEventListener("click", () => load({ preserveContent: !elements.content.hidden }));
    attachDetailDialog(elements.bandsTrigger, elements.bandsDialog, "left");
    attachDetailDialog(elements.refillPill, elements.refillDialog, "right");

    load();
    refreshTimer = window.setInterval(() => load({ preserveContent: !elements.content.hidden }), 60000);
    window.addEventListener("pagehide", () => {
        controller?.abort();
        window.clearInterval(refreshTimer);
    }, { once: true });
})();
