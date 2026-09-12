(() => {
    "use strict";

    const root = document.querySelector('[data-component="optimizer-viewer"]');
    if (!root) return;

    const config = window.OPTIMIZER_VIEWER_CONFIG || {};
    const elements = {
        meta: root.querySelector('[data-role="viewer-meta"]'),
        refresh: root.querySelector('[data-role="refresh"]'),
        retry: root.querySelector('[data-role="retry"]'),
        select: root.querySelector('[data-role="run-select"]'),
        loading: root.querySelector('[data-role="loading"]'),
        error: root.querySelector('[data-role="error"]'),
        errorMessage: root.querySelector('[data-role="error-message"]'),
        content: root.querySelector('[data-role="content"]'),
        activeMode: root.querySelector('[data-role="active-mode"]'),
        planFreshness: root.querySelector('[data-role="plan-freshness"]'),
        horizon: root.querySelector('[data-role="horizon"]'),
        generated: root.querySelector('[data-role="generated"]'),
        soc: root.querySelector('[data-role="soc"]'),
        efficiency: root.querySelector('[data-role="efficiency"]'),
        cost: root.querySelector('[data-role="cost"]'),
        objective: root.querySelector('[data-role="objective"]'),
        differenceCount: root.querySelector('[data-role="difference-count"]'),
        priceStatus: root.querySelector('[data-role="price-status"]'),
        finalSoc: root.querySelector('[data-role="final-soc"]'),
        finalSocDetail: root.querySelector('[data-role="final-soc-detail"]'),
        forecastDifference: root.querySelector('[data-role="forecast-difference"]'),
        forecastDifferenceDetail: root.querySelector('[data-role="forecast-difference-detail"]'),
        dailyPnl: root.querySelector('[data-role="daily-pnl"]'),
        body: root.querySelector('[data-role="comparison-body"]'),
        footnote: root.querySelector('[data-role="footnote"]')
        ,modeBanner: root.querySelector('[data-role="mode-banner"]')
        ,modeBannerTitle: root.querySelector('[data-role="mode-banner-title"]')
        ,modeBannerCopy: root.querySelector('[data-role="mode-banner-copy"]')
        ,modeDetail: root.querySelector('[data-role="mode-detail"]')
        ,modeRules: root.querySelector('[data-role="mode-rules"]')
        ,modeOptimizer: root.querySelector('[data-role="mode-optimizer"]')
    };
    const state = { records: [], selectedIndex: 0, controller: null };

    function setView(view, message = "") {
        root.dataset.state = view;
        root.setAttribute("aria-busy", view === "loading" ? "true" : "false");
        elements.loading.hidden = view !== "loading";
        elements.error.hidden = view !== "error";
        elements.content.hidden = view !== "ready";
        elements.refresh.disabled = view === "loading";
        elements.refresh.setAttribute("aria-busy", view === "loading" ? "true" : "false");
        if (message) elements.errorMessage.textContent = message;
    }

    async function fetchJson(url, signal) {
        const response = await fetch(new URL(url, document.baseURI).href, {
            signal,
            cache: "no-store",
            headers: { Accept: "application/json" }
        });
        const contentType = response.headers.get("content-type") || "";
        const payload = contentType.includes("application/json") ? await response.json() : null;
        if (!response.ok || !payload) throw new Error(payload?.error || `Request failed with HTTP ${response.status}.`);
        return payload;
    }

    async function postJson(url, body) {
        const response = await fetch(new URL(url, document.baseURI).href, {
            method: "POST",
            cache: "no-store",
            credentials: "same-origin",
            headers: { Accept: "application/json", "Content-Type": "application/json" },
            body: JSON.stringify(body)
        });
        const contentType = response.headers.get("content-type") || "";
        const payload = contentType.includes("application/json") ? await response.json() : null;
        if (!response.ok || !payload?.success) throw new Error(payload?.error || `Request failed with HTTP ${response.status}.`);
        return payload;
    }

    function renderMode(status) {
        const requested = status?.requestedMode === "optimizer" ? "optimizer" : "rules";
        const active = status?.activeSource === "optimizer" ? "optimizer" : "rules";
        const fallback = Boolean(status?.fallbackActive);
        elements.modeRules.dataset.active = String(requested === "rules");
        elements.modeOptimizer.dataset.active = String(requested === "optimizer");
        elements.modeRules.setAttribute("aria-pressed", String(requested === "rules"));
        elements.modeOptimizer.setAttribute("aria-pressed", String(requested === "optimizer"));
        elements.modeBanner.dataset.source = fallback ? "fallback" : active;
        if (fallback) {
            elements.activeMode.textContent = "Rules fallback";
            elements.planFreshness.textContent = status.fallbackReason || "Optimizer plan unavailable";
            elements.modeBannerTitle.textContent = "Rules fallback is active";
            elements.modeBannerCopy.textContent = status.fallbackReason || "The optimizer schedule is unavailable or stale.";
            elements.modeDetail.textContent = "Optimizer was selected, but safety validation kept the rule-based schedule active.";
        } else if (active === "optimizer") {
            elements.activeMode.textContent = "Optimizer";
            elements.planFreshness.textContent = status.optimizerGeneratedAt
                ? `Active plan calculated ${formatRunTime(status.optimizerGeneratedAt)}`
                : "Validated optimizer plan active";
            elements.modeBannerTitle.textContent = "Optimizer schedule is active";
            elements.modeBannerCopy.textContent = "The battery controller receives the latest validated optimizer schedule. Exact manual overrides still take priority.";
            elements.modeDetail.textContent = status.optimizerGeneratedAt
                ? `Latest active plan: ${formatRunTime(status.optimizerGeneratedAt)}.`
                : "A validated optimizer plan is active.";
        } else {
            elements.activeMode.textContent = "Rules";
            elements.planFreshness.textContent = status.optimizerGeneratedAt
                ? `Latest comparison calculated ${formatRunTime(status.optimizerGeneratedAt)}`
                : "Optimizer continues in the background";
            elements.modeBannerTitle.textContent = "Rule-based schedule is active";
            elements.modeBannerCopy.textContent = "Optimizer calculations continue in the background for comparison.";
            elements.modeDetail.textContent = "Rules and exact manual overrides determine the battery schedule.";
        }
    }

    async function changeMode(mode) {
        const activating = mode === "optimizer";
        const confirmed = window.confirm(activating
            ? "Activate the optimizer schedule? A fresh plan will be calculated first and can affect battery charging and grid export."
            : "Switch back to the rule-based schedule?");
        if (!confirmed) return;
        elements.modeRules.disabled = true;
        elements.modeOptimizer.disabled = true;
        elements.modeDetail.textContent = activating ? "Calculating and validating a fresh optimizer plan…" : "Switching to rules…";
        try {
            const payload = await postJson(config.modeUrl, { mode, csrfToken: config.csrfToken });
            renderMode(payload.status);
            try {
                await postJson(config.refreshScheduleUrl, {});
            } catch (_refreshError) {
                elements.modeDetail.textContent += " The battery controller will pick up the change on its next scheduled refresh.";
            }
            await load({ preserveSelection: false });
        } catch (error) {
            try {
                const modePayload = await fetchJson(config.modeUrl);
                renderMode(modePayload.status);
            } catch (_statusError) {
                // Preserve the last visible status when even the status request fails.
            }
            elements.modeDetail.textContent = error.message || "The active schedule could not be changed.";
        } finally {
            elements.modeRules.disabled = false;
            elements.modeOptimizer.disabled = false;
        }
    }

    function dateFormatter(options) {
        return new Intl.DateTimeFormat(undefined, { timeZone: config.timezone, ...options });
    }

    function formatRunTime(value) {
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return "Unknown time";
        return dateFormatter({ weekday: "short", day: "numeric", month: "short", hour: "2-digit", minute: "2-digit", hourCycle: "h23" }).format(date);
    }

    function formatTime(value) {
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return "—";
        return dateFormatter({ hour: "2-digit", minute: "2-digit", hourCycle: "h23" }).format(date);
    }

    function formatDay(value) {
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return "—";
        return dateFormatter({ weekday: "short", day: "numeric", month: "short" }).format(date);
    }

    function formatDayAndTime(value) {
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return "—";
        return dateFormatter({ weekday: "short", day: "numeric", month: "short", hour: "2-digit", minute: "2-digit", hourCycle: "h23" }).format(date);
    }

    function localParts(value) {
        const date = new Date(value);
        const parts = dateFormatter({ year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", hourCycle: "h23" })
            .formatToParts(date)
            .reduce((result, part) => ({ ...result, [part.type]: part.value }), {});
        return { date: `${parts.year}${parts.month}${parts.day}`, time: `${parts.hour}00` };
    }

    function formatMoney(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) return "—";
        return new Intl.NumberFormat(undefined, { style: "currency", currency: "EUR", minimumFractionDigits: 2, maximumFractionDigits: 3 }).format(number);
    }

    function formatSignedMoney(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) return "—";
        const formatted = formatMoney(Math.abs(number));
        if (number > 0.0000005) return `+${formatted}`;
        if (number < -0.0000005) return `−${formatted}`;
        return formatMoney(0);
    }

    function pnlClass(value) {
        const number = Number(value);
        if (number > 0.0000005) return "gsd-positive";
        if (number < -0.0000005) return "gsd-negative";
        return "";
    }

    function formatPrice(value) {
        const number = Number(value);
        return Number.isFinite(number) ? `${number.toFixed(3)} €/kWh` : "—";
    }

    function formatPower(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) return "—";
        if (number > 0) return `+${Math.round(number)} W`;
        if (number < 0) return `−${Math.abs(Math.round(number))} W`;
        return "0 W";
    }

    function directionForPower(value) {
        const number = Number(value);
        if (number > 0) return "charge";
        if (number < 0) return "discharge";
        return "idle";
    }

    function modeLabel(value) {
        if (value === "netzero+") return "NZ+";
        if (value === "netzero-") return "NZ−";
        if (value === "netzero") return "NZ±";
        if (value === null || value === undefined || value === "auto") return "Auto";
        if (typeof value === "number" || /^-?\d+(\.\d+)?$/.test(String(value))) return formatPower(value);
        return String(value).replaceAll("_", " ");
    }

    function commandDetail(value, minimum, maximum) {
        const details = [];
        if (minimum !== undefined && minimum !== null) details.push(`min ${formatPower(minimum)}`);
        if (maximum !== undefined && maximum !== null) details.push(`max ${formatPower(maximum)}`);
        if (!details.length && (typeof value === "number" || /^-?\d+(\.\d+)?$/.test(String(value)))) return "Fixed power";
        return details.join(" · ") || "Dynamic mode";
    }

    function commandKey(value, minimum, maximum) {
        const normalized = typeof value === "number" ? value : String(value ?? "auto").toLowerCase();
        return JSON.stringify([normalized, minimum ?? null, maximum ?? null]);
    }

    async function fetchCurrentSchedules(record, signal) {
        const decisions = Array.isArray(record?.plan?.decisions) ? record.plan.decisions : [];
        const dates = [...new Set(decisions.map((decision) => localParts(decision.start).date))];
        const responses = await Promise.all(dates.map(async (date) => {
            const url = new URL(config.scheduleUrl, document.baseURI);
            url.searchParams.set("date", date);
            if (!url.searchParams.has("resolved") && !url.searchParams.has("format")) url.searchParams.set("resolved", "1");
            const payload = await fetchJson(url.href, signal);
            if (payload.success === false || !Array.isArray(payload.resolved)) throw new Error(payload.error || "Invalid current schedule response.");
            return [date, payload.resolved];
        }));
        return new Map(responses);
    }

    function currentSlotFor(decision, schedules) {
        const parts = localParts(decision.start);
        const slots = schedules.get(parts.date) || [];
        return slots.find((slot) => String(slot.time).padStart(4, "0") === parts.time) || null;
    }

    function makeCell(label, className = "") {
        const cell = document.createElement("td");
        cell.dataset.label = label;
        if (className) cell.className = className;
        return cell;
    }

    function appendText(parent, tag, text, className = "") {
        const node = document.createElement(tag);
        node.textContent = text;
        if (className) node.className = className;
        parent.appendChild(node);
        return node;
    }

    function renderRows(record, schedules) {
        const decisions = Array.isArray(record?.plan?.decisions) ? record.plan.decisions : [];
        elements.body.replaceChildren();
        let differences = 0;

        decisions.forEach((decision) => {
            const current = currentSlotFor(decision, schedules);
            const optimizerKey = commandKey(decision.schedule_value, decision.min_power, decision.max_power);
            const currentKey = commandKey(current?.value, current?.min_power, current?.max_power);
            const same = current !== null && optimizerKey === currentKey;
            if (!same) differences++;

            const row = document.createElement("tr");
            const timeCell = makeCell("Time", "optimizer-time");
            appendText(timeCell, "strong", `${formatTime(decision.start)}–${formatTime(decision.end)}`);
            appendText(timeCell, "span", formatDay(decision.start));

            const optimizerCell = makeCell("Optimizer", "optimizer-command");
            optimizerCell.dataset.direction = directionForPower(decision.battery_power_w);
            appendText(optimizerCell, "strong", modeLabel(decision.schedule_value));
            appendText(optimizerCell, "span", `${formatPower(decision.battery_power_w)} expected`);
            appendText(optimizerCell, "span", commandDetail(decision.schedule_value, decision.min_power, decision.max_power));

            const currentCell = makeCell("Rules", "optimizer-command");
            const currentPower = typeof current?.value === "number" ? current.value : 0;
            currentCell.dataset.direction = typeof current?.value === "number" ? directionForPower(currentPower) : "idle";
            appendText(currentCell, "strong", current ? modeLabel(current.value) : "Unavailable");
            appendText(currentCell, "span", current ? commandDetail(current.value, current.min_power, current.max_power) : "No matching hour");
            const difference = appendText(currentCell, "span", same ? "Same" : "Different", "optimizer-difference");
            difference.dataset.same = String(same);

            const socCell = makeCell("SoC");
            appendText(socCell, "strong", `${Number(decision.start_soc_percent).toFixed(1)}% → ${Number(decision.end_soc_percent).toFixed(1)}%`);
            appendText(socCell, "span", formatPower(decision.grid_power_w), "optimizer-cell-detail");

            const priceCell = makeCell("Prices");
            appendText(priceCell, "strong", formatPrice(decision.import_price_eur_per_kwh));
            appendText(priceCell, "span", `Sell ${formatPrice(decision.export_price_eur_per_kwh)}`, "optimizer-cell-detail");
            const source = appendText(priceCell, "span", decision.price_source === "official" ? "Official" : "Repeated today", "optimizer-price-source");
            source.dataset.provisional = String(decision.price_source !== "official");

            const forecastCell = makeCell("Forecast");
            appendText(forecastCell, "strong", `Solar ${formatPower(decision.pv_w)}`);
            appendText(forecastCell, "span", `Load ${formatPower(decision.load_w)}`, "optimizer-cell-detail");
            appendText(forecastCell, "span", formatMoney(decision.expected_cost_eur), "optimizer-cell-detail");

            row.append(timeCell, optimizerCell, currentCell, socCell, priceCell, forecastCell);
            elements.body.appendChild(row);
        });

        elements.differenceCount.textContent = `${differences} of ${decisions.length}`;
        return differences;
    }

    function renderSummary(record, differenceCount) {
        const plan = record.plan || {};
        const provisional = Number(record.inputs?.provisional_price_slots || 0);
        elements.horizon.textContent = `${formatDayAndTime(plan.horizon_start)} → ${formatDayAndTime(plan.horizon_end)}`;
        elements.generated.textContent = `Calculated ${formatRunTime(plan.generated_at)}`;
        elements.soc.textContent = `${Number(plan.starting_soc_percent).toFixed(1)}% → ${Number(plan.ending_soc_percent).toFixed(1)}%`;
        elements.efficiency.textContent = `${Math.round(Number(plan.round_trip_efficiency) * 100)}% round-trip efficiency`;
        elements.cost.textContent = formatMoney(plan.expected_energy_cost_eur);
        elements.objective.textContent = formatMoney(plan.objective_eur);
        elements.differenceCount.textContent = `${differenceCount} of ${(plan.decisions || []).length}`;
        elements.priceStatus.textContent = provisional ? `${provisional} provisional price segment${provisional === 1 ? "" : "s"}` : "All prices official";
        elements.meta.textContent = `Shadow plan from ${formatRunTime(plan.generated_at)}`;
        elements.footnote.textContent = "Forecast P&L is electricity sales minus purchases and excludes terminal battery value. Current dynamic NZ power is estimated from forecast solar and load; actual meter readings will differ. End-of-day SoC is shown because retained battery energy is not cash P&L.";
    }

    function renderDailyPnl(record, schedules) {
        if (!window.OptimizerPnl) throw new Error("The daily P&L calculator is unavailable.");
        const plan = record.plan || {};
        const inputs = record.inputs || {};
        const scheduleObject = Object.fromEntries(schedules.entries());
        const days = window.OptimizerPnl.estimateDailyComparison(
            plan.decisions || [],
            scheduleObject,
            {
                capacityWh: inputs.battery_capacity_wh,
                minSocPercent: inputs.min_soc_percent,
                maxSocPercent: inputs.max_soc_percent,
                startingSocPercent: plan.starting_soc_percent,
                maxChargePowerW: inputs.max_charge_power_w,
                maxDischargePowerW: inputs.max_discharge_power_w,
                roundTripEfficiency: plan.round_trip_efficiency,
            }
        );
        elements.dailyPnl.replaceChildren();

        let totalCurrent = 0;
        let totalOptimized = 0;
        days.forEach((day) => {
            totalCurrent += day.currentPnlEur;
            totalOptimized += day.optimizedPnlEur;
            const card = document.createElement("article");
            card.className = "optimizer-pnl-day";
            const header = document.createElement("header");
            const title = appendText(header, "h3", formatDay(`${day.date}T12:00:00`));
            title.className = "optimizer-pnl-day__title";
            appendText(header, "span", day.isPartialDay ? "Partial day" : "Full day", "optimizer-pnl-day__period");
            card.appendChild(header);

            const metrics = document.createElement("div");
            metrics.className = "optimizer-pnl-day__metrics";
            [
                ["Rules", day.currentPnlEur, `End SoC ${day.currentEndSocPercent.toFixed(1)}%`],
                ["Optimized", day.optimizedPnlEur, `End SoC ${day.optimizedEndSocPercent.toFixed(1)}%`],
                ["Difference", day.differenceEur, "Cash P&L difference"],
            ].forEach(([label, value, detail]) => {
                const metric = document.createElement("div");
                appendText(metric, "span", label);
                appendText(metric, "strong", formatSignedMoney(value), pnlClass(value));
                appendText(metric, "small", detail);
                metrics.appendChild(metric);
            });
            card.appendChild(metrics);
            if (day.uncertainSlots > 0) {
                appendText(card, "p", `${day.uncertainSlots} current schedule segment${day.uncertainSlots === 1 ? "" : "s"} could not be modeled and were treated as idle.`, "optimizer-pnl-day__warning");
            }
            elements.dailyPnl.appendChild(card);
        });

        const totalDifference = totalOptimized - totalCurrent;
        const finalDay = days.at(-1);
        if (finalDay) {
            const rulesSoc = finalDay.currentEndSocPercent;
            const optimizedSoc = finalDay.optimizedEndSocPercent;
            const socDifference = optimizedSoc - rulesSoc;
            elements.finalSoc.textContent = `${rulesSoc.toFixed(1)}% vs ${optimizedSoc.toFixed(1)}%`;
            elements.finalSocDetail.textContent = `Rules vs Optimizer · ${socDifference >= 0 ? "+" : "−"}${Math.abs(socDifference).toFixed(1)} percentage points`;
            elements.forecastDifference.textContent = formatSignedMoney(totalDifference);
            elements.forecastDifference.className = `gsd-price ${pnlClass(totalDifference)}`.trim();
            elements.forecastDifferenceDetail.textContent = `Optimizer vs Rules cash P&L · final SoC ${optimizedSoc.toFixed(1)}%`;
        } else {
            elements.finalSoc.textContent = "—";
            elements.finalSocDetail.textContent = "Rules versus Optimizer";
            elements.forecastDifference.textContent = "—";
            elements.forecastDifference.className = "gsd-price";
            elements.forecastDifferenceDetail.textContent = "Optimizer versus Rules cash P&L";
        }
        const totalCard = document.createElement("article");
        totalCard.className = "optimizer-pnl-day optimizer-pnl-day--total";
        const totalHeader = document.createElement("header");
        const totalTitle = appendText(totalHeader, "h3", "Complete forecast");
        totalTitle.className = "optimizer-pnl-day__title";
        appendText(
            totalHeader,
            "span",
            `${days.length} calendar day${days.length === 1 ? "" : "s"}`,
            "optimizer-pnl-day__period"
        );
        totalCard.appendChild(totalHeader);

        const totalMetrics = document.createElement("div");
        totalMetrics.className = "optimizer-pnl-day__metrics";
        [
            ["Rules", totalCurrent, finalDay ? `Final SoC ${finalDay.currentEndSocPercent.toFixed(1)}%` : "Final SoC unavailable"],
            ["Optimized", totalOptimized, finalDay ? `Final SoC ${finalDay.optimizedEndSocPercent.toFixed(1)}%` : "Final SoC unavailable"],
            ["Difference", totalDifference, "Cash P&L difference"],
        ].forEach(([label, value, detail]) => {
            const metric = document.createElement("div");
            appendText(metric, "span", label);
            appendText(metric, "strong", formatSignedMoney(value), pnlClass(value));
            appendText(metric, "small", detail);
            totalMetrics.appendChild(metric);
        });
        totalCard.appendChild(totalMetrics);
        elements.dailyPnl.appendChild(totalCard);
    }

    function populateSelect() {
        elements.select.replaceChildren();
        state.records.forEach((record, index) => {
            const option = document.createElement("option");
            option.value = String(index);
            option.textContent = `${index === 0 ? "Latest · " : ""}${formatRunTime(record.plan?.generated_at)}`;
            elements.select.appendChild(option);
        });
        elements.select.value = String(state.selectedIndex);
        elements.select.disabled = state.records.length < 2;
    }

    async function renderSelected(signal) {
        const record = state.records[state.selectedIndex];
        if (!record?.plan) throw new Error("The selected optimizer record is incomplete.");
        const schedules = await fetchCurrentSchedules(record, signal);
        const differenceCount = renderRows(record, schedules);
        renderSummary(record, differenceCount);
        renderDailyPnl(record, schedules);
    }

    async function load({ preserveSelection = false } = {}) {
        if (state.controller) state.controller.abort();
        state.controller = new AbortController();
        const previousGeneratedAt = preserveSelection ? state.records[state.selectedIndex]?.plan?.generated_at : null;
        setView("loading");
        try {
            const [payload, modePayload] = await Promise.all([
                fetchJson(config.logUrl, state.controller.signal),
                fetchJson(config.modeUrl, state.controller.signal)
            ]);
            renderMode(modePayload.status);
            if (payload.success === false || !Array.isArray(payload.records) || payload.records.length === 0) {
                throw new Error(payload.error || "No completed optimizer plans are present in the log.");
            }
            state.records = payload.records;
            const preservedIndex = previousGeneratedAt
                ? state.records.findIndex((record) => record.plan?.generated_at === previousGeneratedAt)
                : -1;
            state.selectedIndex = preservedIndex >= 0 ? preservedIndex : 0;
            populateSelect();
            await renderSelected(state.controller.signal);
            setView("ready");
        } catch (error) {
            if (error.name === "AbortError") return;
            setView("error", error.message || "The optimizer comparison could not be loaded.");
        }
    }

    elements.select.addEventListener("change", async () => {
        state.selectedIndex = Number.parseInt(elements.select.value, 10) || 0;
        setView("loading");
        try {
            await renderSelected(state.controller?.signal);
            setView("ready");
        } catch (error) {
            if (error.name !== "AbortError") setView("error", error.message || "The selected comparison could not be loaded.");
        }
    });
    elements.refresh.addEventListener("click", () => load({ preserveSelection: false }));
    elements.retry.addEventListener("click", () => load({ preserveSelection: true }));
    elements.modeRules.addEventListener("click", () => changeMode("rules"));
    elements.modeOptimizer.addEventListener("click", () => changeMode("optimizer"));
    document.addEventListener("visibilitychange", () => {
        if (!document.hidden) load({ preserveSelection: true });
    });
    window.setInterval(() => {
        if (!document.hidden) load({ preserveSelection: true });
    }, Math.max(60000, Number(config.refreshIntervalMs) || 300000));

    load();
})();
