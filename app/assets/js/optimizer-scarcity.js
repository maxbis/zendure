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
        refillBar: get("refill-bar"), requiredRefill: get("required-refill"), solarRefill: get("solar-refill"),
        gridRefill: get("grid-refill"), totalRefill: get("total-refill"), refillFactor: get("refill-factor"), horizon: get("horizon"),
        terminalPrice: get("terminal-price"), cheapPrice: get("cheap-price"), historySource: get("history-source"),
        historyPeriod: get("history-period"), sampleCount: get("sample-count"), halfLife: get("half-life"), bands: get("bands")
    };
    let controller = null;

    const finite = (value) => Number.isFinite(Number(value)) ? Number(value) : null;
    const money = (value) => finite(value) === null ? "—" : `€${Number(value).toFixed(4)}/kWh`;
    const energy = (value) => finite(value) === null ? "—" : `${Number(value).toFixed(2)} kWh`;
    const percent = (value) => finite(value) === null ? "—" : `${Number(value).toFixed(1)}%`;
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
        subtitle.textContent = `Historical P${Number(band.price_percentile).toFixed(0)} energy`;
        copy.append(title, subtitle);
        const badge = document.createElement("span");
        badge.className = "scarcity-band__badge";
        badge.textContent = band.applied ? "Applied" : band.active ? "Active only" : "Inactive";
        header.append(copy, badge);
        const list = document.createElement("dl");
        [
            ["Reference price", money(band.reference_price_eur_per_kwh)],
            ["Additional scarcity", money(band.additional_scarcity_value_eur_per_kwh)],
            ["Activates below refill", percent(band.activate_when_expected_refill_below_percent)],
            ["Condition active", band.active ? "Yes" : "No"],
            ["Cost applied", band.applied ? "Yes" : "No"]
        ].forEach(([label, value]) => {
            const row = document.createElement("div");
            const dt = document.createElement("dt"); dt.textContent = label;
            const dd = document.createElement("dd"); dd.textContent = value;
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
        const bands = Array.isArray(scarcity.bands) ? scarcity.bands : [];
        const applied = bands.filter((band) => band.applied).length;
        const refillValue = finite(refill.expected_refill_percent);
        elements.algorithm.textContent = scarcity.algorithm_enabled ? "Enabled" : "Disabled";
        elements.refillPercent.textContent = percent(refillValue);
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
        elements.terminalPrice.textContent = money(scarcity.normal_terminal_price_eur_per_kwh);
        elements.cheapPrice.textContent = money(scarcity.cheap_grid_charge_price_eur_per_kwh);
        elements.historySource.textContent = sourceLabel(history.source);
        elements.historyPeriod.textContent = history.from ? `${history.from} → ${history.through}` : "—";
        elements.sampleCount.textContent = finite(history.sample_count) === null ? "—" : `${history.sample_count} hours`;
        elements.halfLife.textContent = finite(history.half_life_days) === null ? "—" : `${history.half_life_days} days`;
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
    load();
    window.setInterval(() => { if (!document.hidden) load(); }, Number(config.refreshIntervalMs) || 60000);
})();
