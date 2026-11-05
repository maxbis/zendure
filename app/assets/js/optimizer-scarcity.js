(() => {
    "use strict";

    const root = document.querySelector('[data-component="optimizer-scarcity"]');
    if (!root) return;
    const config = window.OPTIMIZER_SCARCITY_CONFIG || {};
    const get = (role) => root.querySelector(`[data-role="${role}"]`);
    const elements = {
        meta: get("page-meta"), refresh: get("refresh"), retry: get("retry"), loading: get("loading"),
        error: get("error"), errorMessage: get("error-message"), content: get("content"),
        banner: get("status-banner"), statusTitle: get("status-title"), statusCopy: get("status-copy"),
        algorithm: get("algorithm"), refillPercent: get("refill-percent"), appliedCount: get("applied-count"), age: get("age"),
        grossRefillPercent: get("gross-refill-percent"), startingSoc: get("starting-soc"), maximumSoc: get("maximum-soc"),
        currentGap: get("current-gap"), grossRefillPoints: get("gross-refill-points"), grossRefillCeiling: get("gross-refill-ceiling"),
        endingSoc: get("ending-soc"), outcomeTime: get("outcome-time"), outcomeChange: get("outcome-change"),
        refillBar: get("refill-bar"), requiredRefill: get("required-refill"), solarRefill: get("solar-refill"),
        gridRefill: get("grid-refill"), totalRefill: get("total-refill"), refillFactor: get("refill-factor"), horizon: get("horizon"),
        terminalPrice: get("terminal-price"), cheapPrice: get("cheap-price"), historySource: get("history-source"),
        historyPeriod: get("history-period"), sampleCount: get("sample-count"), halfLife: get("half-life"), bands: get("bands"),
        percentilePanel: get("percentile-panel"), percentileChart: get("percentile-chart"), percentileLegend: get("percentile-legend"),
        distributionPanel: get("distribution-panel"), distributionChart: get("distribution-chart"), distributionNote: get("distribution-note")
    };
    let controller = null;
    let chartState = null;
    let chartResizeTimer = null;
    let activeTooltipTrigger = null;
    let pinnedTooltipTrigger = null;
    let distributionMode = "weighted";
    const tooltip = document.createElement("div");
    tooltip.id = "scarcity-value-tooltip";
    tooltip.className = "scarcity-value-tooltip";
    tooltip.setAttribute("role", "tooltip");
    tooltip.hidden = true;
    document.body.appendChild(tooltip);

    const finite = (value) => Number.isFinite(Number(value)) ? Number(value) : null;
    const money = (value) => finite(value) === null ? "—" : `€${Number(value).toFixed(4)}/kWh`;
    const energy = (value) => finite(value) === null ? "—" : `${Number(value).toFixed(2)} kWh`;
    const percent = (value) => finite(value) === null ? "—" : `${Number(value).toFixed(1)}%`;
    const points = (value) => finite(value) === null ? "—" : `${Number(value).toFixed(1)} pp`;
    const signedPoints = (value) => {
        const number = finite(value);
        if (number === null) return "—";
        return `${number > 0 ? "+" : number < 0 ? "−" : ""}${Math.abs(number).toFixed(1)} pp`;
    };
    const dateTime = (value) => {
        if (!value) return "—";
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return String(value);
        return new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short", timeZone: config.timezone }).format(date);
    };
    const ageText = (seconds) => {
        const value = finite(seconds);
        if (value === null) return "Unknown";
        if (value < 60) return `${Math.round(value)} sec`;
        if (value < 3600) return `${Math.round(value / 60)} min`;
        return `${(value / 3600).toFixed(1)} hr`;
    };
    const sourceLabel = (source) => ({ price_ticks: "MariaDB price_ticks", json_price_files: "Local JSON price files" }[source] || source || "—");
    const SVG_NS = "http://www.w3.org/2000/svg";

    function hideTooltip(trigger = null) {
        if (trigger && activeTooltipTrigger !== trigger) return;
        if (activeTooltipTrigger) {
            activeTooltipTrigger.removeAttribute("aria-describedby");
            activeTooltipTrigger.setAttribute("aria-expanded", "false");
        }
        activeTooltipTrigger = null;
        if (!trigger || pinnedTooltipTrigger === trigger) pinnedTooltipTrigger = null;
        tooltip.hidden = true;
        tooltip.style.removeProperty("left");
        tooltip.style.removeProperty("top");
        tooltip.style.removeProperty("visibility");
    }

    function positionTooltip(trigger) {
        const gap = 8;
        const padding = 8;
        const anchor = trigger.getBoundingClientRect();
        const tip = tooltip.getBoundingClientRect();
        let left = anchor.left + ((anchor.width - tip.width) / 2);
        left = Math.max(padding, Math.min(left, window.innerWidth - tip.width - padding));
        const above = anchor.top - tip.height - gap;
        const top = above >= padding ? above : Math.min(window.innerHeight - tip.height - padding, anchor.bottom + gap);
        tooltip.style.left = `${Math.round(left)}px`;
        tooltip.style.top = `${Math.round(Math.max(padding, top))}px`;
        tooltip.style.visibility = "visible";
    }

    function showTooltip(trigger, pinned = false) {
        if (!trigger?.dataset.tooltipText) return;
        hideTooltip();
        activeTooltipTrigger = trigger;
        pinnedTooltipTrigger = pinned ? trigger : null;
        tooltip.textContent = trigger.dataset.tooltipText;
        tooltip.hidden = false;
        tooltip.style.visibility = "hidden";
        trigger.setAttribute("aria-describedby", tooltip.id);
        trigger.setAttribute("aria-expanded", String(pinned));
        positionTooltip(trigger);
    }

    function bindTooltip(trigger, text) {
        if (!trigger || !text) return;
        trigger.dataset.tooltipText = text;
        trigger.classList.add("scarcity-tooltip-trigger");
        trigger.setAttribute("tabindex", "0");
        trigger.setAttribute("aria-expanded", "false");
        if (trigger.dataset.tooltipBound === "true") return;
        trigger.dataset.tooltipBound = "true";
        trigger.addEventListener("pointerenter", () => {
            if (!pinnedTooltipTrigger) showTooltip(trigger);
        });
        trigger.addEventListener("pointerleave", () => {
            if (!pinnedTooltipTrigger && document.activeElement !== trigger) hideTooltip(trigger);
        });
        trigger.addEventListener("focus", () => showTooltip(trigger));
        trigger.addEventListener("blur", () => {
            if (pinnedTooltipTrigger !== trigger) hideTooltip(trigger);
        });
        trigger.addEventListener("click", (event) => {
            event.stopPropagation();
            if (pinnedTooltipTrigger === trigger) hideTooltip(trigger);
            else showTooltip(trigger, true);
        });
        trigger.addEventListener("keydown", (event) => {
            if (event.key === "Enter" || event.key === " ") {
                event.preventDefault();
                if (pinnedTooltipTrigger === trigger) hideTooltip(trigger);
                else showTooltip(trigger, true);
            }
        });
    }

    function bindRoleTooltip(role, text) {
        bindTooltip(get(role), text);
    }

    function svgElement(name, attributes = {}) {
        const element = document.createElementNS(SVG_NS, name);
        Object.entries(attributes).forEach(([key, value]) => element.setAttribute(key, String(value)));
        return element;
    }

    function renderPercentileChart(scarcity, bands) {
        chartState = { scarcity, bands };
        const curve = (Array.isArray(scarcity.price_percentile_curve) ? scarcity.price_percentile_curve : [])
            .map((point) => ({ percentile: finite(point.percentile), price: finite(point.price_eur_per_kwh) }))
            .filter((point) => point.percentile !== null && point.price !== null)
            .sort((a, b) => a.percentile - b.percentile);
        elements.percentileChart.replaceChildren();
        elements.percentileLegend.replaceChildren();
        if (curve.length < 2) {
            elements.percentilePanel.hidden = true;
            return;
        }

        const terminalPrice = finite(scarcity.normal_terminal_price_eur_per_kwh);
        const values = curve.map((point) => point.price);
        if (terminalPrice !== null) values.push(terminalPrice);
        let minimum = Math.min(...values);
        let maximum = Math.max(...values);
        const span = Math.max(0.01, maximum - minimum);
        minimum -= span * 0.12;
        maximum += span * 0.12;

        const compact = root.clientWidth <= 560;
        const width = Math.min(960, Math.max(320, root.clientWidth - (compact ? 42 : 60)));
        const height = compact ? 230 : 240;
        const margin = compact
            ? { top: 20, right: 12, bottom: 36, left: 54 }
            : { top: 24, right: 24, bottom: 40, left: 72 };
        const plotWidth = width - margin.left - margin.right;
        const plotHeight = height - margin.top - margin.bottom;
        const x = (percentile) => margin.left + (Math.max(0, Math.min(100, percentile)) / 100) * plotWidth;
        const y = (price) => margin.top + ((maximum - price) / (maximum - minimum)) * plotHeight;
        const svg = svgElement("svg", { viewBox: `0 0 ${width} ${height}`, role: "img", "aria-label": "Weighted historical consumer price percentile curve" });

        for (let index = 0; index <= 4; index += 1) {
            const price = minimum + ((maximum - minimum) * index / 4);
            const gridY = y(price);
            svg.appendChild(svgElement("line", { x1: margin.left, y1: gridY, x2: width - margin.right, y2: gridY, class: "scarcity-percentile__grid" }));
            const label = svgElement("text", { x: margin.left - 10, y: gridY + 4, "text-anchor": "end", class: "scarcity-percentile__axis-label" });
            label.textContent = `€${price.toFixed(3)}`;
            svg.appendChild(label);
        }
        [0, 25, 50, 75, 100].forEach((percentile) => {
            const gridX = x(percentile);
            svg.appendChild(svgElement("line", { x1: gridX, y1: margin.top, x2: gridX, y2: height - margin.bottom, class: "scarcity-percentile__grid scarcity-percentile__grid--vertical" }));
            const label = svgElement("text", { x: gridX, y: height - 14, "text-anchor": "middle", class: "scarcity-percentile__axis-label" });
            label.textContent = `P${percentile}`;
            svg.appendChild(label);
        });

        const areaPoints = [`${x(curve[0].percentile)},${height - margin.bottom}`]
            .concat(curve.map((point) => `${x(point.percentile)},${y(point.price)}`))
            .concat(`${x(curve[curve.length - 1].percentile)},${height - margin.bottom}`)
            .join(" ");
        svg.appendChild(svgElement("polygon", { points: areaPoints, class: "scarcity-percentile__area" }));
        svg.appendChild(svgElement("polyline", {
            points: curve.map((point) => `${x(point.percentile)},${y(point.price)}`).join(" "),
            class: "scarcity-percentile__line"
        }));

        if (terminalPrice !== null) {
            const terminalY = y(terminalPrice);
            svg.appendChild(svgElement("line", { x1: margin.left, y1: terminalY, x2: width - margin.right, y2: terminalY, class: "scarcity-percentile__terminal" }));
            const terminalLabel = svgElement("text", { x: margin.left + 6, y: terminalY - 7, "text-anchor": "start", class: "scarcity-percentile__terminal-label" });
            terminalLabel.textContent = `Horizon average ${money(terminalPrice)}`;
            svg.appendChild(terminalLabel);
            bindTooltip(terminalLabel, `Arithmetic mean of consumer/import prices across the current optimizer horizon. This is the baseline value assigned to stored energy before any scarcity-band premium is added.`);
        }

        const references = [{
            label: "Cheap-grid boundary",
            shortLabel: `P${Number(scarcity.cheap_grid_charge_percentile).toFixed(0)}`,
            percentile: finite(scarcity.cheap_grid_charge_percentile),
            price: finite(scarcity.cheap_grid_charge_price_eur_per_kwh),
            state: "cheap"
        }].concat(bands.map((band) => ({
            label: `${band.from_soc_percent}–${band.to_soc_percent}% SoC`,
            shortLabel: `P${Number(band.price_percentile).toFixed(0)}`,
            percentile: finite(band.price_percentile),
            price: finite(band.reference_price_eur_per_kwh),
            state: band.applied ? "applied" : "band"
        }))).filter((reference) => reference.percentile !== null && reference.price !== null);

        references.forEach((reference) => {
            const markerX = x(reference.percentile);
            const markerY = y(reference.price);
            const marker = svgElement("circle", { cx: markerX, cy: markerY, r: 5, class: `scarcity-percentile__marker scarcity-percentile__marker--${reference.state}` });
            const title = svgElement("title");
            title.textContent = `${reference.label}: ${reference.shortLabel}, ${money(reference.price)}`;
            marker.appendChild(title);
            svg.appendChild(marker);
            bindTooltip(marker, reference.state === "cheap"
                ? `${reference.shortLabel} is the recency-weighted historical consumer-price boundary used to identify cheap grid-charging opportunities. Current value: ${money(reference.price)}.`
                : `${reference.shortLabel} is the recency-weighted historical consumer price for the ${reference.label} scarcity band. Current reference: ${money(reference.price)}.`);

            const item = document.createElement("span");
            item.className = `scarcity-percentile__legend-item scarcity-percentile__legend-item--${reference.state}`;
            const dot = document.createElement("i");
            dot.setAttribute("aria-hidden", "true");
            const copy = document.createElement("span");
            copy.textContent = `${reference.shortLabel} ${reference.label} · ${money(reference.price)}`;
            item.append(dot, copy);
            bindTooltip(item, reference.state === "cheap"
                ? `${reference.shortLabel} is calculated from completed historical hourly consumer prices. Prices at or below ${money(reference.price)} can contribute to predicted cheap-grid refill.`
                : `${reference.shortLabel} is calculated from completed historical hourly consumer prices using the configured recency weighting. It values energy in the ${reference.label} band at ${money(reference.price)}.`);
            elements.percentileLegend.appendChild(item);
        });
        if (terminalPrice !== null) {
            const item = document.createElement("span");
            item.className = "scarcity-percentile__legend-item scarcity-percentile__legend-item--terminal";
            const dot = document.createElement("i");
            dot.setAttribute("aria-hidden", "true");
            const copy = document.createElement("span");
            copy.textContent = `Horizon average · ${money(terminalPrice)}`;
            item.append(dot, copy);
            bindTooltip(item, `Arithmetic mean of the consumer/import prices in all slots of the current optimizer horizon. It is the baseline value assigned to stored energy and is not derived from the historical percentile sample.`);
            elements.percentileLegend.appendChild(item);
        }
        elements.percentileChart.appendChild(svg);
        elements.percentilePanel.hidden = false;
    }

    function priceReferences(scarcity, bands) {
        return [{
            label: `P${Number(scarcity.cheap_grid_charge_percentile).toFixed(0)} cheap-grid boundary`,
            badgeLabel: `P${Number(scarcity.cheap_grid_charge_percentile).toFixed(0)} · Cheap`,
            price: finite(scarcity.cheap_grid_charge_price_eur_per_kwh),
            state: "cheap"
        }].concat(bands.map((band) => ({
            label: `P${Number(band.price_percentile).toFixed(0)} ${band.from_soc_percent}–${band.to_soc_percent}% SoC`,
            badgeLabel: `P${Number(band.price_percentile).toFixed(0)} · ${band.from_soc_percent}–${band.to_soc_percent}%`,
            price: finite(band.reference_price_eur_per_kwh),
            state: band.applied ? "applied" : "band"
        })), [{
            label: "Horizon average",
            badgeLabel: "Average",
            price: finite(scarcity.normal_terminal_price_eur_per_kwh),
            state: "terminal"
        }]).filter((reference) => reference.price !== null);
    }

    function renderPriceDistribution(scarcity, bands) {
        const distribution = scarcity.price_distribution || {};
        const buckets = (Array.isArray(distribution.buckets) ? distribution.buckets : []).map((bucket) => ({
            from: finite(bucket.from_eur_per_kwh),
            to: finite(bucket.to_eur_per_kwh),
            hours: finite(bucket.hour_count),
            rawPercent: finite(bucket.raw_percent),
            weightedPercent: finite(bucket.weighted_percent),
            cumulativeWeightedPercent: finite(bucket.cumulative_weighted_percent)
        })).filter((bucket) => bucket.from !== null && bucket.to !== null && bucket.hours !== null);
        elements.distributionChart.replaceChildren();
        if (!buckets.length) {
            elements.distributionPanel.hidden = true;
            return;
        }

        const compact = root.clientWidth <= 560;
        const width = Math.min(960, Math.max(320, root.clientWidth - (compact ? 42 : 60)));
        const baseHeight = compact ? 250 : 270;
        const baseMargin = compact
            ? { top: 20, right: 12, bottom: 48, left: 52 }
            : { top: 24, right: 24, bottom: 50, left: 66 };
        const visibleReferences = priceReferences(scarcity, bands)
            .filter((reference) => reference.price >= buckets[0].from && reference.price <= buckets[buckets.length - 1].to)
            .map((reference) => {
                const shortLabel = compact ? reference.badgeLabel.split(" · ")[0] : reference.badgeLabel;
                const badgeText = `${shortLabel} · ${(reference.price * 100).toFixed(1)} ct`;
                return { ...reference, badgeText, badgeWidth: Math.max(58, Math.min(126, 14 + badgeText.length * 5.5)) };
            });
        const referenceSpan = Math.max(0.000001, buckets[buckets.length - 1].to - buckets[0].from);
        const referenceX = (price) => baseMargin.left + ((price - buckets[0].from) / referenceSpan) * (width - baseMargin.left - baseMargin.right);
        const rowEnds = [];
        visibleReferences.sort((a, b) => a.price - b.price).forEach((reference) => {
            reference.markerX = referenceX(reference.price);
            reference.badgeLeft = Math.max(baseMargin.left, Math.min(reference.markerX - reference.badgeWidth / 2, width - baseMargin.right - reference.badgeWidth));
            let row = rowEnds.findIndex((right) => reference.badgeLeft >= right + 5);
            if (row < 0) row = rowEnds.length;
            reference.badgeRow = row;
            rowEnds[row] = reference.badgeLeft + reference.badgeWidth;
        });
        const badgeRows = Math.max(1, rowEnds.length);
        const margin = { ...baseMargin, top: 12 + badgeRows * 22 + 8 };
        const height = baseHeight + margin.top - baseMargin.top;
        const plotWidth = width - margin.left - margin.right;
        const plotHeight = height - margin.top - margin.bottom;
        const minimum = buckets[0].from;
        const maximum = buckets[buckets.length - 1].to;
        const values = buckets.map((bucket) => distributionMode === "hours" ? bucket.hours : bucket.weightedPercent || 0);
        const valueMaximum = Math.max(1, ...values) * 1.12;
        const x = (price) => margin.left + ((price - minimum) / Math.max(0.000001, maximum - minimum)) * plotWidth;
        const y = (value) => margin.top + (1 - value / valueMaximum) * plotHeight;
        const label = distributionMode === "hours" ? "actual observed hours" : "recency-weighted percentage share";
        const svg = svgElement("svg", { viewBox: `0 0 ${width} ${height}`, role: "img", "aria-label": `Historical consumer price distribution by ${label}` });

        for (let index = 0; index <= 4; index += 1) {
            const value = valueMaximum * index / 4;
            const gridY = y(value);
            svg.appendChild(svgElement("line", { x1: margin.left, y1: gridY, x2: width - margin.right, y2: gridY, class: "scarcity-percentile__grid" }));
            const gridLabel = svgElement("text", { x: margin.left - 9, y: gridY + 4, "text-anchor": "end", class: "scarcity-percentile__axis-label" });
            gridLabel.textContent = distributionMode === "hours" ? `${Math.round(value)}` : `${value.toFixed(1)}%`;
            svg.appendChild(gridLabel);
        }
        for (let index = 0; index <= 4; index += 1) {
            const price = minimum + (maximum - minimum) * index / 4;
            const gridX = x(price);
            const gridLabel = svgElement("text", { x: gridX, y: height - 17, "text-anchor": "middle", class: "scarcity-percentile__axis-label" });
            gridLabel.textContent = `${(price * 100).toFixed(0)} ct`;
            svg.appendChild(gridLabel);
        }

        buckets.forEach((bucket) => {
            const value = distributionMode === "hours" ? bucket.hours : bucket.weightedPercent || 0;
            const barX = x(bucket.from) + 1;
            const barWidth = Math.max(1, x(bucket.to) - x(bucket.from) - 2);
            const barY = y(value);
            const bar = svgElement("rect", {
                x: barX, y: barY, width: barWidth, height: Math.max(0, height - margin.bottom - barY),
                class: "scarcity-distribution__bar", tabindex: "0"
            });
            const range = `${(bucket.from * 100).toFixed(1)}–${(bucket.to * 100).toFixed(1)} ct/kWh`;
            const copy = `${range}: ${bucket.hours} of ${distribution.sample_count} hours (${Number(bucket.rawPercent || 0).toFixed(1)}% raw). Recency-weighted share ${Number(bucket.weightedPercent || 0).toFixed(1)}%; cumulative weighted share ${Number(bucket.cumulativeWeightedPercent || 0).toFixed(1)}%.`;
            const title = svgElement("title");
            title.textContent = copy;
            bar.appendChild(title);
            svg.appendChild(bar);
            bindTooltip(bar, copy);
        });

        visibleReferences.forEach((reference) => {
            const markerX = x(reference.price);
            const marker = svgElement("line", {
                x1: markerX, y1: margin.top, x2: markerX, y2: height - margin.bottom,
                class: `scarcity-distribution__reference scarcity-distribution__reference--${reference.state}`
            });
            svg.appendChild(marker);

            const badgeY = 12 + reference.badgeRow * 22;
            const badge = svgElement("g", {
                class: `scarcity-distribution__badge scarcity-distribution__badge--${reference.state}`,
                tabindex: "0", role: "img", "aria-label": `${reference.label}: ${money(reference.price)}`
            });
            badge.appendChild(svgElement("line", {
                x1: markerX, y1: badgeY + 18, x2: markerX, y2: margin.top,
                class: "scarcity-distribution__badge-stem"
            }));
            badge.appendChild(svgElement("rect", {
                x: reference.badgeLeft, y: badgeY, width: reference.badgeWidth, height: 18, rx: 9
            }));
            const badgeText = svgElement("text", {
                x: reference.badgeLeft + reference.badgeWidth / 2, y: badgeY + 12.2, "text-anchor": "middle"
            });
            badgeText.textContent = reference.badgeText;
            badge.appendChild(badgeText);
            svg.appendChild(badge);
            bindTooltip(badge, `${reference.label}: ${money(reference.price)}.`);
        });

        elements.distributionChart.appendChild(svg);
        elements.distributionNote.textContent = `${distribution.sample_count} historical hours · ${(Number(distribution.bin_width_eur_per_kwh) * 100).toFixed(1)} ct/kWh buckets · ${distributionMode === "hours" ? "bar height shows observed hours" : "bar height shows recency-weighted share"}.`;
        elements.distributionPanel.hidden = false;
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

    function appendBand(band) {
        const card = document.createElement("article");
        card.className = "gsd-card scarcity-band";
        const state = band.applied ? "applied" : band.active ? "active" : "inactive";
        card.dataset.state = state;
        const header = document.createElement("header");
        header.className = "scarcity-band__header";
        const copy = document.createElement("div");
        const title = document.createElement("h3");
        title.textContent = `${band.from_soc_percent}–${band.to_soc_percent}% SoC`;
        const subtitle = document.createElement("p");
        subtitle.textContent = `P${Number(band.price_percentile).toFixed(0)} historical price`;
        bindTooltip(title, `Configured battery state-of-charge range for this scarcity band. Stored energy between ${band.from_soc_percent}% and ${band.to_soc_percent}% SoC receives this band’s additional virtual value when the band is applied.`);
        bindTooltip(subtitle, `The band’s reference is the recency-weighted P${Number(band.price_percentile).toFixed(0)} consumer price from completed historical hourly samples.`);
        copy.append(title, subtitle);
        const badge = document.createElement("span");
        badge.className = "scarcity-band__badge";
        badge.textContent = band.applied ? "Applied" : band.active ? "Active only" : "Inactive";
        bindTooltip(badge, band.applied
            ? "The expected-refill condition is active and the master scarcity switch is enabled, so this band’s additional virtual cost affects optimizer discharge decisions."
            : band.active
                ? "The expected-refill condition is active, but the master scarcity switch is disabled, so the calculated value is diagnostic only."
                : "Expected refill is at or above this band’s activation boundary, so the band does not affect the current plan.");
        header.append(copy, badge);
        const list = document.createElement("dl");
        [
            ["Reference price", money(band.reference_price_eur_per_kwh), `Recency-weighted P${Number(band.price_percentile).toFixed(0)} historical consumer price, capped by the configured reference-price maximum. This is the target value for energy in this SoC band.`],
            ["Additional scarcity", money(band.additional_scarcity_value_eur_per_kwh), `Calculated as max(0, reference price − horizon-average terminal value). It is a virtual optimizer cost, not a charge on the electricity bill.`],
            ["Activates below refill", percent(band.activate_when_expected_refill_below_percent), `Configured expected-refill boundary for this band. The condition becomes active only when predicted refill is below ${percent(band.activate_when_expected_refill_below_percent)}.`]
        ].forEach(([label, value, detail]) => {
            const row = document.createElement("div");
            const dt = document.createElement("dt"); dt.textContent = label;
            const dd = document.createElement("dd"); dd.textContent = value;
            bindTooltip(dd, detail);
            row.append(dt, dd); list.appendChild(row);
        });
        const explanation = document.createElement("p");
        explanation.className = "scarcity-band__explanation";
        explanation.textContent = band.applied
            ? "Expected refill is below this band’s boundary, so its additional virtual value affects discharge decisions."
            : band.active
                ? "Refill conditions activate this band, but the master switch is off, so it does not affect decisions."
                : "Expected refill is sufficient for this band, so no scarcity value is applied.";
        card.append(header, list, explanation);
        elements.bands.appendChild(card);
    }

    function render(payload) {
        const scarcity = payload.scarcity || {};
        const refill = scarcity.refill || {};
        const history = scarcity.price_history || {};
        const outcome = payload.optimizerOutcome || {};
        const bands = Array.isArray(scarcity.bands) ? scarcity.bands : [];
        const applied = bands.filter((band) => band.applied).length;
        const refillValue = finite(refill.expected_refill_percent);
        elements.algorithm.textContent = scarcity.algorithm_enabled ? "Enabled" : "Disabled";
        elements.refillPercent.textContent = percent(refillValue);
        elements.grossRefillPercent.textContent = percent(refillValue);
        elements.appliedCount.textContent = `${applied} of ${bands.length}`;
        elements.age.textContent = ageText(payload.ageSeconds);
        elements.meta.textContent = `Calculated ${dateTime(payload.generatedAt)} · automatic refresh every minute`;
        elements.refillBar.style.width = `${Math.max(0, Math.min(100, refillValue || 0))}%`;
        elements.requiredRefill.textContent = energy(refill.energy_required_kwh);
        elements.solarRefill.textContent = energy(refill.predicted_solar_refill_kwh);
        elements.gridRefill.textContent = energy(refill.predicted_cheap_grid_refill_kwh);
        elements.totalRefill.textContent = energy(refill.predicted_total_refill_kwh);
        elements.refillFactor.textContent = finite(refill.expected_refill_factor) === null ? "—" : Number(refill.expected_refill_factor).toFixed(3);
        elements.horizon.textContent = refill.horizon_start ? `${dateTime(refill.horizon_start)} → ${dateTime(refill.horizon_end)}` : "—";
        const startingSoc = finite(outcome.starting_soc_percent);
        const maximumSoc = finite(outcome.maximum_soc_percent);
        const endingSoc = finite(outcome.ending_soc_percent);
        const capacityKwh = finite(outcome.battery_capacity_kwh);
        const totalRefillKwh = finite(refill.predicted_total_refill_kwh);
        const gapPoints = startingSoc === null || maximumSoc === null ? null : Math.max(0, maximumSoc - startingSoc);
        const refillPoints = capacityKwh === null || capacityKwh <= 0 || totalRefillKwh === null
            ? null
            : totalRefillKwh / capacityKwh * 100;
        const grossCeiling = startingSoc === null || refillPoints === null
            ? null
            : Math.min(maximumSoc ?? 100, startingSoc + refillPoints);
        elements.startingSoc.textContent = percent(startingSoc);
        elements.maximumSoc.textContent = percent(maximumSoc);
        elements.currentGap.textContent = points(gapPoints);
        elements.grossRefillPoints.textContent = points(refillPoints);
        elements.grossRefillCeiling.textContent = percent(grossCeiling);
        elements.endingSoc.textContent = percent(endingSoc);
        elements.outcomeTime.textContent = dateTime(outcome.horizon_end);
        elements.outcomeChange.textContent = startingSoc === null || endingSoc === null ? "—" : signedPoints(endingSoc - startingSoc);
        elements.terminalPrice.textContent = money(scarcity.normal_terminal_price_eur_per_kwh);
        elements.cheapPrice.textContent = money(scarcity.cheap_grid_charge_price_eur_per_kwh);
        elements.historySource.textContent = sourceLabel(history.source);
        elements.historyPeriod.textContent = history.from ? `${history.from} → ${history.through}` : "—";
        elements.sampleCount.textContent = finite(history.sample_count) === null ? "—" : `${history.sample_count} hours`;
        elements.halfLife.textContent = finite(history.half_life_days) === null ? "—" : `${history.half_life_days} days`;
        bindRoleTooltip("algorithm", `Master scarcity-band switch from the shared system configuration. When enabled, active bands add virtual discharge cost to optimizer decisions; when disabled, bands remain diagnostic only.`);
        bindRoleTooltip("refill-percent", `Gross available refill divided by the battery's current energy gap, capped at 100%. It does not subtract future scheduled battery use and is not a predicted ending SoC.`);
        bindRoleTooltip("gross-refill-percent", `Gross available refill divided by the battery's current energy gap. This is calculated before optimization and describes charging opportunity, not the final scheduled battery level.`);
        bindRoleTooltip("gross-refill-ceiling", `Starting SoC plus gross refill opportunity, capped at maximum SoC. This is a hypothetical ceiling that assumes no intervening battery use.`);
        bindRoleTooltip("ending-soc", `Optimizer-estimated absolute battery level at the end of the horizon after all planned charging and discharging decisions.`);
        bindRoleTooltip("applied-count", `Number of configured bands whose expected-refill condition is active and whose virtual cost is currently enabled by the master scarcity switch.`);
        bindRoleTooltip("age", `Elapsed time since the optimizer published this calculation. Derived from the plan’s generated timestamp and the current browser time.`);
        bindRoleTooltip("required-refill", `Usable battery capacity needed to move from the current live SoC to the configured maximum SoC: capacity × (maximum SoC − current SoC).`);
        bindRoleTooltip("solar-refill", `Forecast battery energy recoverable from PV surplus after predicted household load, maximum charging power and charging efficiency are applied.`);
        bindRoleTooltip("grid-refill", `Forecast battery energy available from grid charging in horizon slots whose consumer price is at or below the historical cheap-grid boundary.`);
        bindRoleTooltip("total-refill", `Solar refill plus cheap-grid refill, capped at the current energy gap. Future scheduled battery use is not subtracted.`);
        bindRoleTooltip("refill-factor", `Decimal coverage of the current gap: gross available refill ÷ current energy gap. A value of 1.000 means enough charging opportunity exists to restore the current gap, not that ending SoC will be 100%.`);
        bindRoleTooltip("horizon", `Start and end of the slots examined for predicted solar and cheap-grid refill. Derived from the current rolling optimizer plan.`);
        bindRoleTooltip("terminal-price", `Arithmetic mean of consumer/import prices across all slots in the current optimizer horizon. This forward-looking baseline values stored energy at the end of the forecast; it does not come from historical percentiles.`);
        bindRoleTooltip("cheap-price", `Recency-weighted P${Number(scarcity.cheap_grid_charge_percentile).toFixed(0)} consumer price from completed historical hours. Horizon prices at or below this value qualify as cheap-grid refill opportunities.`);
        bindRoleTooltip("history-source", history.source === "price_ticks"
            ? `Historical hourly consumer prices loaded from the MariaDB price_ticks table. The database is used when it contains at least the required 168 valid samples.`
            : `Historical hourly consumer prices loaded from local archived JSON price files because the database source was unavailable or did not contain enough valid samples.`);
        bindRoleTooltip("history-period", `Configured historical window of completed local dates used for percentile calculations. The current day is excluded because it is incomplete.`);
        bindRoleTooltip("sample-count", `Number of finite hourly consumer-price observations loaded for the historical window. At least 168 samples are required before scarcity percentiles are calculated.`);
        bindRoleTooltip("half-life", `Recency weighting applied to historical prices: weight = 0.5^(age in days ÷ half-life). A ${history.half_life_days}-day-old price receives half the weight of the newest completed day.`);
        renderPercentileChart(scarcity, bands);
        renderPriceDistribution(scarcity, bands);
        elements.bands.replaceChildren();
        if (bands.length) bands.forEach(appendBand);
        else {
            const empty = document.createElement("div");
            empty.className = "gsd-card scarcity-empty";
            empty.textContent = scarcity.unavailable_reason === "insufficient_price_history"
                ? `Bands need at least 168 historical hourly prices; ${history.sample_count || 0} are available.`
                : "No scarcity bands were calculated.";
            elements.bands.appendChild(empty);
        }
        if (payload.stale) {
            elements.banner.dataset.state = "warning";
            elements.statusTitle.textContent = "Published calculation is stale";
            elements.statusCopy.textContent = "The values below remain visible for diagnosis, but should not be treated as current.";
        } else if (!scarcity.calculation_available) {
            elements.banner.dataset.state = "warning";
            elements.statusTitle.textContent = "Scarcity calculation unavailable";
            elements.statusCopy.textContent = scarcity.unavailable_reason === "insufficient_price_history"
                ? `Only ${history.sample_count || 0} historical hours are available; at least 168 are required.`
                : `Reason: ${scarcity.unavailable_reason || "missing calculation inputs"}.`;
        } else {
            elements.banner.dataset.state = "ready";
            elements.statusTitle.textContent = scarcity.algorithm_enabled ? "Scarcity bands are enabled" : "Diagnostics ready · algorithm disabled";
            elements.statusCopy.textContent = scarcity.algorithm_enabled
                ? `${applied} band${applied === 1 ? " is" : "s are"} currently applied to optimizer discharge decisions.`
                : "Bands are calculated for inspection, but their virtual costs do not affect optimizer decisions.";
        }
        setView("ready");
    }

    async function load() {
        controller?.abort();
        controller = new AbortController();
        if (elements.content.hidden) setView("loading");
        try {
            const response = await fetch(new URL(config.apiUrl, document.baseURI).href, { cache: "no-store", credentials: "same-origin", headers: { Accept: "application/json" }, signal: controller.signal });
            const contentType = response.headers.get("content-type") || "";
            const payload = contentType.includes("application/json") ? await response.json() : null;
            if (!response.ok || !payload?.success) throw new Error(payload?.error || `Request failed with HTTP ${response.status}.`);
            render(payload);
        } catch (error) {
            if (error.name === "AbortError") return;
            setView("error", error.message || "The scarcity API could not be loaded.");
        }
    }

    elements.refresh.addEventListener("click", load);
    elements.retry.addEventListener("click", load);
    root.querySelectorAll("[data-distribution-mode]").forEach((button) => {
        button.addEventListener("click", () => {
            distributionMode = button.dataset.distributionMode === "hours" ? "hours" : "weighted";
            root.querySelectorAll("[data-distribution-mode]").forEach((candidate) => {
                candidate.setAttribute("aria-pressed", String(candidate.dataset.distributionMode === distributionMode));
            });
            if (chartState) renderPriceDistribution(chartState.scarcity, chartState.bands);
        });
    });
    document.addEventListener("pointerdown", (event) => {
        if (pinnedTooltipTrigger && !pinnedTooltipTrigger.contains(event.target)) hideTooltip();
    });
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") hideTooltip();
    });
    window.addEventListener("scroll", () => hideTooltip(), true);
    window.addEventListener("resize", () => {
        window.clearTimeout(chartResizeTimer);
        chartResizeTimer = window.setTimeout(() => {
            if (chartState && !elements.percentilePanel.hidden) {
                renderPercentileChart(chartState.scarcity, chartState.bands);
                renderPriceDistribution(chartState.scarcity, chartState.bands);
            }
        }, 120);
    });
    load();
    window.setInterval(() => { if (!document.hidden) load(); }, Number(config.refreshIntervalMs) || 60000);
})();
