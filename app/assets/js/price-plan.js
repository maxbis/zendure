(function () {
    "use strict";

    const component = document.querySelector('[data-component="price-plan"]');
    if (!component) return;

    const config = window.GRAPHITE_APP_CONFIG || {};
    const isSimulation = config.mode === "simulation";
    const elements = {
        date: component.querySelector('[data-role="price-plan-date"]'),
        loading: component.querySelector('[data-role="price-loading"]'),
        error: component.querySelector('[data-role="price-error"]'),
        errorMessage: component.querySelector('[data-role="price-error-message"]'),
        retry: component.querySelector('[data-role="price-retry"]'),
        content: component.querySelector('[data-role="price-content"]'),
        refresh: component.querySelector('[data-role="price-refresh"]'),
        tomorrowAvailability: component.querySelector('[data-role="tomorrow-availability"]'),
        tomorrowStatus: component.querySelector('[data-role="tomorrow-status"]'),
        tomorrowStatusLabel: component.querySelector('[data-role="tomorrow-status-label"]'),
        currentLabel: component.querySelector('[data-role="price-current-label"]'),
        currentKpi: component.querySelector('[data-role="price-current-kpi"]'),
        current: component.querySelector('[data-role="price-current"]'),
        lowKpi: component.querySelector('[data-role="price-low-kpi"]'),
        low: component.querySelector('[data-role="price-low"]'),
        averageKpi: component.querySelector('[data-role="price-average-kpi"]'),
        average: component.querySelector('[data-role="price-average"]'),
        highKpi: component.querySelector('[data-role="price-high-kpi"]'),
        high: component.querySelector('[data-role="price-high"]'),
        scrollShell: component.querySelector('[data-role="price-scroll-shell"]'),
        scroll: component.querySelector('[data-role="price-scroll"]'),
        scrollPrev: component.querySelector('[data-role="price-scroll-prev"]'),
        scrollNext: component.querySelector('[data-role="price-scroll-next"]'),
        timeline: component.querySelector('[data-role="price-timeline"]')
    };
    const editDialog = document.getElementById("app-schedule-edit-dialog");
    const editElements = editDialog ? {
        form: editDialog.querySelector('[data-role="schedule-edit-form"]'),
        title: editDialog.querySelector('[data-role="schedule-edit-title"]'),
        priceSummary: editDialog.querySelector('[data-role="schedule-edit-price-summary"]'),
        modeInputs: Array.from(editDialog.querySelectorAll('input[name="schedule-mode"]')),
        fixedField: editDialog.querySelector('[data-role="schedule-fixed-field"]'),
        watts: editDialog.querySelector('input[name="watts"]'),
        fixedRange: editDialog.querySelector('[data-role="schedule-fixed-range"]'),
        fixedDisplay: editDialog.querySelector('[data-role="schedule-fixed-display"]'),
        fixedSlider: editDialog.querySelector('[data-role="schedule-fixed-slider"]'),
        fixedSelection: editDialog.querySelector('[data-role="schedule-fixed-selection"]'),
        fixedSummary: editDialog.querySelector('[data-role="schedule-fixed-summary"]'),
        limitEditor: editDialog.querySelector('[data-role="schedule-limit-editor"]'),
        limitsDisabled: editDialog.querySelector('[data-role="schedule-limits-disabled"]'),
        limitsEnabled: editDialog.querySelector('[data-role="schedule-limits-enabled"]'),
        limitFields: editDialog.querySelector('[data-role="schedule-limit-fields"]'),
        minimum: editDialog.querySelector('input[name="minimum-power"]'),
        maximum: editDialog.querySelector('input[name="maximum-power"]'),
        minimumRange: editDialog.querySelector('[data-role="schedule-limit-min-range"]'),
        maximumRange: editDialog.querySelector('[data-role="schedule-limit-max-range"]'),
        minimumDisplay: editDialog.querySelector('[data-role="schedule-limit-min-display"]'),
        maximumDisplay: editDialog.querySelector('[data-role="schedule-limit-max-display"]'),
        limitSlider: editDialog.querySelector('[data-role="schedule-limit-slider"]'),
        limitSelection: editDialog.querySelector('[data-role="schedule-limit-selection"]'),
        limitSummary: editDialog.querySelector('[data-role="schedule-limit-summary"]'),
        error: editDialog.querySelector('[data-role="schedule-edit-error"]'),
        save: editDialog.querySelector('[data-role="schedule-edit-save"]')
    } : null;
    const priceTooltip = ensurePriceTooltip();
    let activeTooltipTrigger = null;
    let pinnedTooltipTrigger = null;
    let selectedHourKey = null;
    let isProgrammaticTimelineScroll = false;
    let programmaticTimelineScrollTimer = null;
    let activeScheduleTooltip = null;
    let scheduleRefreshInFlight = false;
    const summaryTooltipDetails = new Map();

    const state = {
        prices: { today: null, tomorrow: null },
        dates: { today: null, tomorrow: null },
        schedules: { today: [], tomorrow: [] },
        entries: { today: [], tomorrow: [] },
        ruleColors: {},
        batteryForecast: {},
        forecastAsOf: null,
        forecastUnavailableReason: null,
        controller: null
    };

    function requiredSharedNumber(key) {
        const value = Number(config[key]);
        if (!Number.isFinite(value)) throw new Error(`Missing required shared setting: ${key}.`);
        return value;
    }

    function sharedPowerBounds() {
        const minimum = requiredSharedNumber("powerMinW");
        const maximum = requiredSharedNumber("powerMaxW");
        if (minimum >= maximum) throw new Error("The shared schedule power range is invalid.");
        return { minimum, maximum };
    }

    function sharedPowerStepW() {
        const step = requiredSharedNumber("powerStepW");
        if (!Number.isInteger(step) || step <= 0) throw new Error("The shared schedule power step is invalid.");
        return step;
    }

    const DAY_PARTS = [
        { key: "night", label: "Night", start: 0, end: 6 },
        { key: "morning", label: "Morning", start: 6, end: 12 },
        { key: "afternoon", label: "Afternoon", start: 12, end: 18 },
        { key: "evening", label: "Evening", start: 18, end: 24 }
    ];

    function pad(value) {
        return String(value).padStart(2, "0");
    }

    function hourSelectionKey(detail) {
        if (!detail?.date || !Number.isInteger(detail.hour)) return null;
        return `${detail.date}${pad(detail.hour)}00`;
    }

    function timelineScrollMetrics() {
        const maxScrollLeft = Math.max(0, elements.scroll.scrollWidth - elements.scroll.clientWidth);
        const scrollLeft = elements.scroll.scrollLeft;
        return {
            maxScrollLeft,
            scrollLeft,
            canScrollStart: scrollLeft > 1,
            canScrollEnd: scrollLeft < maxScrollLeft - 1
        };
    }

    function updateTimelineScrollButtons() {
        if (!elements.scrollPrev || !elements.scrollNext) return;
        const { maxScrollLeft, canScrollStart, canScrollEnd } = timelineScrollMetrics();
        const hasOverflow = maxScrollLeft > 0;
        elements.scrollPrev.hidden = !hasOverflow;
        elements.scrollNext.hidden = !hasOverflow;
        elements.scrollPrev.disabled = !canScrollStart;
        elements.scrollNext.disabled = !canScrollEnd;
        if (!hasOverflow && elements.scrollShell?.dataset.hoverEdge) {
            delete elements.scrollShell.dataset.hoverEdge;
        }
    }

    function scrollTimelineByPage(direction) {
        const { maxScrollLeft, scrollLeft } = timelineScrollMetrics();
        if (maxScrollLeft <= 0) return;
        const distance = Math.max(180, Math.round(elements.scroll.clientWidth * 0.72));
        const left = Math.min(maxScrollLeft, Math.max(0, scrollLeft + (direction * distance)));
        const useSmoothScroll = !window.matchMedia("(prefers-reduced-motion: reduce)").matches;

        if (programmaticTimelineScrollTimer !== null) {
            window.clearTimeout(programmaticTimelineScrollTimer);
        }
        isProgrammaticTimelineScroll = true;
        elements.scroll.scrollTo({ left, behavior: useSmoothScroll ? "smooth" : "auto" });
        programmaticTimelineScrollTimer = window.setTimeout(() => {
            isProgrammaticTimelineScroll = false;
            programmaticTimelineScrollTimer = null;
            updateTimelineScrollButtons();
        }, useSmoothScroll ? 500 : 0);
        updateTimelineScrollButtons();
    }

    function syncTimelineHoverEdge(clientX) {
        if (!elements.scrollShell) return;
        const { maxScrollLeft } = timelineScrollMetrics();
        if (maxScrollLeft <= 0) {
            delete elements.scrollShell.dataset.hoverEdge;
            return;
        }
        const rect = elements.scrollShell.getBoundingClientRect();
        const edgeWidth = Math.min(72, Math.max(40, rect.width * 0.12));
        const offsetX = clientX - rect.left;
        if (offsetX <= edgeWidth) {
            elements.scrollShell.dataset.hoverEdge = "start";
            return;
        }
        if (offsetX >= rect.width - edgeWidth) {
            elements.scrollShell.dataset.hoverEdge = "end";
            return;
        }
        delete elements.scrollShell.dataset.hoverEdge;
    }

    function centerTimelineHour(hourColumn, { smooth = false } = {}) {
        if (!hourColumn) return;
        window.requestAnimationFrame(() => {
            window.requestAnimationFrame(() => {
                const centeredLeft = hourColumn.offsetLeft - ((elements.scroll.clientWidth - hourColumn.offsetWidth) / 2);
                const maximumLeft = Math.max(0, elements.scroll.scrollWidth - elements.scroll.clientWidth);
                const left = Math.min(maximumLeft, Math.max(0, centeredLeft));
                const useSmoothScroll = smooth && !window.matchMedia("(prefers-reduced-motion: reduce)").matches;

                if (programmaticTimelineScrollTimer !== null) {
                    window.clearTimeout(programmaticTimelineScrollTimer);
                }
                isProgrammaticTimelineScroll = true;
                elements.scroll.scrollTo({ left, behavior: useSmoothScroll ? "smooth" : "auto" });
                programmaticTimelineScrollTimer = window.setTimeout(() => {
                    isProgrammaticTimelineScroll = false;
                    programmaticTimelineScrollTimer = null;
                    updateTimelineScrollButtons();
                }, useSmoothScroll ? 500 : 0);
                updateTimelineScrollButtons();
            });
        });
    }

    function setSelectedHour(key, { scroll = false, smooth = false } = {}) {
        const hourColumns = elements.timeline.querySelectorAll(".app-price-hour");
        let selectedColumn = null;
        hourColumns.forEach((hourColumn) => {
            const selected = Boolean(key) && hourColumn.dataset.selectionKey === key;
            hourColumn.dataset.selected = String(selected);
            hourColumn.querySelector(".app-price-hour__edit")?.setAttribute("aria-pressed", String(selected));
            if (selected) selectedColumn = hourColumn;
        });

        selectedHourKey = selectedColumn ? key : null;
        if (scroll && selectedColumn) centerTimelineHour(selectedColumn, { smooth });
        return selectedColumn;
    }

    function dayPartForHour(hour) {
        return DAY_PARTS.find((dayPart) => hour >= dayPart.start && hour < dayPart.end) || DAY_PARTS[0];
    }

    function appendSolarMarkers(fragment, date, dayOffset = 0, totalHours = 24) {
        const events = config.solarEvents?.[date];
        if (!events) return;

        ["sunrise", "sunset"].forEach((eventName) => {
            const event = events[eventName];
            const minuteOfDay = Number(event?.minuteOfDay);
            if (!Number.isFinite(minuteOfDay) || minuteOfDay < 0 || minuteOfDay >= 1440 || !event?.time) return;

            const marker = document.createElement("span");
            const readableName = eventName === "sunrise" ? "Sunrise" : "Sunset";
            const locationName = config.solarLocation?.name || "configured location";
            marker.className = "app-price-solar-marker";
            marker.dataset.event = eventName;
            marker.style.setProperty("--app-solar-position", `${((dayOffset * 24 + minuteOfDay / 60) / totalHours) * 100}%`);
            marker.setAttribute("role", "img");
            marker.setAttribute("aria-label", `${readableName} in ${locationName} at ${event.time}`);
            marker.title = `${readableName} in ${locationName} · ${event.time}`;

            const badge = document.createElement("span");
            badge.className = "app-price-solar-marker__badge";
            badge.setAttribute("aria-hidden", "true");
            const icon = document.createElementNS("http://www.w3.org/2000/svg", "svg");
            const use = document.createElementNS("http://www.w3.org/2000/svg", "use");
            icon.classList.add("gsd-icon");
            use.setAttribute("href", "../themes/graphite-signal-dark/assets/icons/sprite.svg#sun");
            icon.appendChild(use);
            const time = document.createElement("span");
            time.textContent = event.time;
            badge.append(icon, time);
            marker.appendChild(badge);
            fragment.appendChild(marker);
        });
    }

    function dateKey(date) {
        return `${date.getFullYear()}${pad(date.getMonth() + 1)}${pad(date.getDate())}`;
    }

    function localDates() {
        if (isSimulation && /^\d{8}$/.test(String(config.scenarioDate || ""))) {
            const selected = dateFromKey(config.scenarioDate);
            const following = new Date(selected);
            following.setDate(following.getDate() + 1);
            return { today: config.scenarioDate, tomorrow: dateKey(following) };
        }
        const today = new Date();
        const tomorrow = new Date(today);
        tomorrow.setDate(tomorrow.getDate() + 1);
        return { today: dateKey(today), tomorrow: dateKey(tomorrow) };
    }

    function referenceNow() {
        if (!isSimulation) return new Date();
        const scenarioStart = dateFromKey(config.scenarioDate);
        if (scenarioStart) {
            scenarioStart.setHours(0, 0, 0, 0);
            return scenarioStart;
        }
        const configured = new Date(config.referenceTime || "");
        if (Number.isFinite(configured.getTime())) return configured;
        return new Date();
    }

    function dateFromKey(key) {
        if (!/^\d{8}$/.test(String(key || ""))) return null;
        return new Date(Number(key.slice(0, 4)), Number(key.slice(4, 6)) - 1, Number(key.slice(6, 8)));
    }

    function formatDate(key) {
        const date = dateFromKey(key);
        if (!date) return "Date unavailable";
        return new Intl.DateTimeFormat(undefined, {
            weekday: "long",
            day: "numeric",
            month: "long"
        }).format(date);
    }

    function formatPrice(value) {
        return Number.isFinite(value) ? `€${value.toFixed(3)}` : "—";
    }

    function formatPriceCents(value) {
        return Number.isFinite(value) ? `${Math.round(value * 100)} ct` : "—";
    }

    function setDimmedToken(element, formattedValue, token) {
        const tokenIndex = formattedValue.indexOf(token);
        if (tokenIndex < 0) {
            element.textContent = formattedValue;
            return;
        }

        const affix = document.createElement("span");
        affix.className = "app-value-affix";
        affix.textContent = token;
        element.replaceChildren(
            document.createTextNode(formattedValue.slice(0, tokenIndex)),
            affix,
            document.createTextNode(formattedValue.slice(tokenIndex + token.length))
        );
    }

    function formatWatts(value) {
        if (!Number.isFinite(value)) return "—";
        const rounded = Math.round(value);
        const sign = rounded > 0 ? "+" : rounded < 0 ? "−" : "±";
        return `${sign}${Math.abs(rounded).toLocaleString()} W`;
    }

    function powerLimits(slot) {
        return {
            minimum: numericValue(slot?.min_power),
            maximum: numericValue(slot?.max_power)
        };
    }

    function hasPowerLimits(slot) {
        const limits = powerLimits(slot);
        return limits.minimum !== null || limits.maximum !== null;
    }

    function formatPowerLimits(slot, emptyText = "No explicit limits") {
        const { minimum, maximum } = powerLimits(slot);
        if (minimum === null && maximum === null) return emptyText;
        if (minimum !== null && maximum !== null) return `${formatWatts(minimum).replace("±", "")} to ${formatWatts(maximum).replace("±", "")}`;
        if (minimum !== null) return `At least ${formatWatts(minimum).replace("±", "")}`;
        return `At most ${formatWatts(maximum).replace("±", "")}`;
    }

    function normalizeUrls(value) {
        const list = Array.isArray(value) ? value : [value];
        return list
            .filter((url) => typeof url === "string" && url.trim())
            .map((url) => new URL(url, document.baseURI).href);
    }

    async function fetchJson(url, signal) {
        const response = await fetch(url, {
            signal,
            headers: { Accept: "application/json" },
            cache: "no-store"
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const contentType = response.headers.get("content-type") || "";
        if (!contentType.includes("application/json")) throw new Error("Server returned a non-JSON response");
        return response.json();
    }

    async function fetchPrices(signal) {
        const urls = normalizeUrls(config.priceUrls);
        if (!urls.length) throw new Error("No price source is configured");

        let lastError = null;
        for (const url of urls) {
            try {
                const payload = await fetchJson(url, signal);
                if (!payload || typeof payload !== "object" || !("today" in payload)) {
                    throw new Error("Invalid price response");
                }
                return payload;
            } catch (error) {
                if (error.name === "AbortError") throw error;
                lastError = error;
            }
        }
        throw lastError || new Error("All price sources failed");
    }

    async function fetchSchedule(date, signal) {
        if (!config.scheduleUrl) throw new Error("No schedule source is configured");
        const url = new URL(config.scheduleUrl, document.baseURI);
        url.searchParams.set("date", date);
        if (!url.searchParams.has("resolved") && !url.searchParams.has("format")) {
            url.searchParams.set("resolved", "1");
        }
        const payload = await fetchJson(url.href, signal);
        if (
            !payload
            || payload.success === false
            || !Array.isArray(payload.resolved)
            || !payload.forecast
            || typeof payload.forecast !== "object"
            || Array.isArray(payload.forecast)
        ) {
            throw new Error(payload?.error || "Invalid schedule response");
        }
        return payload;
    }

    async function fetchBacktest(signal) {
        if (!config.backtestUrl) throw new Error("No historical simulation source is configured");
        const url = new URL(config.backtestUrl, document.baseURI);
        url.searchParams.set("date", String(config.scenarioDate || ""));
        url.searchParams.set("soc", String(config.startingBatteryPercent ?? ""));
        const response = await fetch(url.href, {
            signal,
            headers: { Accept: "application/json" },
            cache: "no-store"
        });
        const contentType = response.headers.get("content-type") || "";
        const payload = contentType.includes("application/json") ? await response.json() : null;
        if (
            !response.ok
            || !payload?.success
            || !payload?.prices
            || !payload?.schedules
            || !payload?.forecast
            || typeof payload.forecast !== "object"
            || Array.isArray(payload.forecast)
        ) {
            throw new Error(payload?.error || "Invalid historical simulation response");
        }
        return payload;
    }

    async function refreshAutomationSchedule() {
        if (!config.scheduleRefreshUrl) throw new Error("No automation refresh endpoint is configured.");

        const response = await fetch(new URL(config.scheduleRefreshUrl, document.baseURI).href, {
            method: "POST",
            headers: { Accept: "application/json" },
            credentials: "same-origin",
            cache: "no-store"
        });
        const contentType = response.headers.get("content-type") || "";
        const result = contentType.includes("application/json") ? await response.json() : null;
        if (!response.ok || result?.ok !== true) {
            throw new Error(result?.error || `Automation refresh failed with HTTP ${response.status}.`);
        }
        return result;
    }

    function normalizeRuleColor(value) {
        const color = String(value || "").trim();
        return /^#[0-9a-f]{6}$/i.test(color) ? color.toUpperCase() : "";
    }

    async function fetchRuleColors(signal) {
        if (!config.rulesUrl) return {};
        const payload = await fetchJson(new URL(config.rulesUrl, document.baseURI).href, signal);
        if (!payload?.success || !Array.isArray(payload.rules)) throw new Error(payload?.error || "Invalid rules response");
        return payload.rules.reduce((colors, rule, index) => {
            const color = normalizeRuleColor(rule?.color);
            if (color) colors[String(index + 1)] = color;
            return colors;
        }, {});
    }

    function numericValue(value) {
        if (typeof value === "number" && Number.isFinite(value)) return value;
        if (typeof value === "string" && value.trim() && Number.isFinite(Number(value))) return Number(value);
        return null;
    }

    function actionFor(slot) {
        const raw = slot?.value;
        const numeric = numericValue(raw);
        if (numeric !== null) {
            if (numeric > 0) return { type: "charge", label: "Charge", short: "CHG", powerW: numeric, signature: `charge:${numeric}` };
            if (numeric < 0) return { type: "discharge", label: "Discharge", short: "DIS", powerW: numeric, signature: `discharge:${numeric}` };
            return { type: "standby", label: "Standby", short: "OFF", powerW: 0, signature: "standby" };
        }

        const value = String(raw ?? "auto").trim().toLowerCase();
        if (["netzero", "netzero+", "netzero-plus", "netzero-", "netzero-minus"].includes(value)) {
            const minimum = numericValue(slot?.min_power);
            const maximum = numericValue(slot?.max_power);
            const isPlus = value === "netzero+"
                || value === "netzero-plus"
                || (value === "netzero" && minimum !== null && minimum >= 0 && (maximum === null || maximum > 0));
            const isMinus = value === "netzero-"
                || value === "netzero-minus"
                || (value === "netzero" && maximum !== null && maximum <= 0 && (minimum === null || minimum < 0));
            const direction = isPlus ? "plus" : isMinus ? "minus" : "bidirectional";
            const label = direction === "plus" ? "Net zero+" : direction === "minus" ? "Net zero−" : "Net zero ±";
            const short = direction === "plus" ? "NZ+" : direction === "minus" ? "NZ−" : "NZ±";
            return {
                type: "netzero",
                direction,
                label,
                short,
                powerW: null,
                signature: `netzero:${direction}:${minimum ?? ""}:${maximum ?? ""}`
            };
        }
        return { type: "auto", label: "Auto", short: "AUTO", powerW: null, signature: "auto" };
    }

    function scheduleMap(slots) {
        const direct = new Map();
        (Array.isArray(slots) ? slots : []).forEach((slot) => {
            const hour = Number.parseInt(String(slot?.time || "").slice(0, 2), 10);
            if (Number.isInteger(hour) && hour >= 0 && hour <= 23) direct.set(hour, slot);
        });

        const result = [];
        let active = null;
        for (let hour = 0; hour < 24; hour += 1) {
            if (direct.has(hour)) active = direct.get(hour);
            result.push(active || { time: `${pad(hour)}00`, value: "auto" });
        }
        return result;
    }

    function applyServerForecast(payload, date, { reset = false } = {}) {
        if (reset) state.batteryForecast = {};
        Object.keys(state.batteryForecast).forEach((key) => {
            if (String(key).startsWith(String(date))) delete state.batteryForecast[key];
        });
        Object.assign(state.batteryForecast, payload.forecast);
        state.forecastAsOf = payload.forecastAsOf || state.forecastAsOf;
        state.forecastUnavailableReason = payload.forecastUnavailableReason || null;
    }

    function forecastKey(date, hour) {
        return `${date}${pad(hour)}00`;
    }

    function hourEndsInFuture(date, hour) {
        const start = dateFromKey(date);
        if (!start) return false;
        start.setHours(hour + 1, 0, 0, 0);
        return start > referenceNow();
    }

    function priceValues(dayPrices) {
        return Array.from({ length: 24 }, (_, hour) => {
            const raw = dayPrices && typeof dayPrices === "object" ? dayPrices[pad(hour)] : null;
            return numericValue(raw);
        });
    }

    function mix(start, end, progress) {
        return Math.round(start + (end - start) * progress);
    }

    function colorBetween(lower, upper, progress) {
        return `rgb(${mix(lower[0], upper[0], progress)}, ${mix(lower[1], upper[1], progress)}, ${mix(lower[2], upper[2], progress)})`;
    }

    function priceColor(position) {
        const stops = [
            { at: 0, color: [121, 212, 132] },
            { at: 0.42, color: [197, 202, 98] },
            { at: 0.7, color: [242, 168, 74] },
            { at: 1, color: [255, 98, 95] }
        ];
        for (let index = 1; index < stops.length; index += 1) {
            const lower = stops[index - 1];
            const upper = stops[index];
            if (position <= upper.at) {
                return colorBetween(lower.color, upper.color, (position - lower.at) / (upper.at - lower.at));
            }
        }
        return colorBetween(stops.at(-1).color, stops.at(-1).color, 0);
    }

    function sourceFor(slot) {
        if (slot?.rule_name) return `Rule: ${slot.rule_name}`;
        if (Number.isFinite(Number(slot?.rule_index)) && Number(slot.rule_index) > 0) return `Rule #${Number(slot.rule_index)}`;
        if (slot?.rule_id) return "Rule";
        if (Array.isArray(slot?.runtime_conditions) && slot.runtime_conditions.length) return "Conditional rule";
        const key = String(slot?.key || "");
        if (key.includes("*")) return "Recurring schedule";
        if (key) return "Manual schedule";
        return "Automatic resolution";
    }

    function isRuleResult(slot) {
        return Boolean(
            slot?.rule_name ||
            slot?.rule_id ||
            (Number.isFinite(Number(slot?.rule_index)) && Number(slot.rule_index) > 0) ||
            (Array.isArray(slot?.runtime_conditions) && slot.runtime_conditions.length)
        );
    }

    function formatRuntimeConditions(slot) {
        const conditions = Array.isArray(slot?.runtime_conditions) ? slot.runtime_conditions : [];
        const operatorLabels = {
            ">": "is above",
            ">=": "is at least",
            "<": "is below",
            "<=": "is at most",
            "==": "equals",
            "!=": "does not equal"
        };
        const formatted = conditions.flatMap((condition) => {
            if (!condition || typeof condition !== "object") return [];
            const field = String(condition.field || "");
            const isBatteryLevel = ["electricity_level", "electric_level", "electricLevel"].includes(field);
            const label = isBatteryLevel ? "battery level" : field.replaceAll("_", " ");
            const operator = operatorLabels[condition.op] || String(condition.op || "=");
            const rawValue = condition.value;
            const value = Array.isArray(rawValue) ? rawValue.join(", ") : String(rawValue ?? "");
            if (!label || !value) return [];
            return [`${label} ${operator} ${value}${isBatteryLevel ? "%" : ""}`];
        });
        if (!formatted.length) return "";
        return formatted.join(" AND ");
    }

    function runtimeFallbackAction(slot) {
        const fallback = slot?.fallback_value;
        const numeric = numericValue(fallback);
        if (numeric !== null) return actionFor({ value: numeric });
        const normalized = String(fallback ?? "").trim().toLowerCase();
        const validModes = ["netzero", "netzero+", "netzero-plus", "netzero-", "netzero-minus"];
        return actionFor({ value: validModes.includes(normalized) ? normalized : 0 });
    }

    function ensurePriceTooltip() {
        const existing = document.getElementById("app-price-action-tooltip");
        if (existing) return existing;
        const tooltip = document.createElement("div");
        tooltip.id = "app-price-action-tooltip";
        tooltip.className = "app-schedule-tooltip";
        tooltip.setAttribute("role", "dialog");
        tooltip.setAttribute("aria-modal", "false");
        tooltip.hidden = true;
        document.body.appendChild(tooltip);
        return tooltip;
    }

    function eventInsidePriceTooltip(target) {
        return target instanceof Node && priceTooltip.contains(target);
    }

    function createTooltipCloseButton() {
        const button = document.createElement("button");
        button.className = "gsd-icon-btn app-schedule-tooltip__close";
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
            const trigger = activeTooltipTrigger;
            hidePriceTooltip(trigger);
            trigger?.focus?.({ preventScroll: true });
        });
        return button;
    }

    function createTooltipRule(slot, ruleColor) {
        const source = sourceFor(slot);
        const hasRuleResult = isRuleResult(slot);
        const isPlannedTarget = ["empty_at_solar_charge", "full_at_netzero_minus"].includes(slot?.planning?.mode);
        if (!(hasRuleResult || isPlannedTarget)) {
            return null;
        }
        const rule = document.createElement("span");
        rule.className = "app-schedule-tooltip__rule";
        if (ruleColor) rule.style.setProperty("--app-rule-color", ruleColor);
        const dot = document.createElement("i");
        dot.setAttribute("aria-hidden", "true");
        rule.append(
            dot,
            document.createTextNode(hasRuleResult ? source : "Planned target")
        );
        return rule;
    }

    function hidePriceTooltip(trigger = null) {
        if (trigger && trigger !== activeTooltipTrigger) return;
        if (activeTooltipTrigger) {
            activeTooltipTrigger.removeAttribute("aria-describedby");
            if (activeTooltipTrigger.matches(".app-price-kpi, .app-price-hour__action")) {
                activeTooltipTrigger.setAttribute("aria-expanded", "false");
            }
        }
        if (!trigger || pinnedTooltipTrigger === trigger || pinnedTooltipTrigger === activeTooltipTrigger) {
            pinnedTooltipTrigger = null;
        }
        activeTooltipTrigger = null;
        activeScheduleTooltip = null;
        priceTooltip.hidden = true;
        priceTooltip.removeAttribute("aria-labelledby");
        priceTooltip.style.removeProperty("left");
        priceTooltip.style.removeProperty("top");
        priceTooltip.style.removeProperty("visibility");
    }

    function positionPriceTooltip(anchor, { preferAbove = false } = {}) {
        const gap = 8;
        const viewportPadding = 12;
        const anchorRect = anchor.getBoundingClientRect();
        const tooltipRect = priceTooltip.getBoundingClientRect();
        let left = anchorRect.left + ((anchorRect.width - tooltipRect.width) / 2);
        left = Math.max(viewportPadding, Math.min(left, window.innerWidth - tooltipRect.width - viewportPadding));
        const aboveTop = anchorRect.top - tooltipRect.height - gap;
        const belowTop = anchorRect.bottom + gap;
        const fitsAbove = aboveTop >= viewportPadding;
        const fitsBelow = belowTop + tooltipRect.height <= window.innerHeight - viewportPadding;
        let top = preferAbove && fitsAbove
            ? aboveTop
            : fitsBelow
                ? belowTop
                : fitsAbove
                    ? aboveTop
                    : belowTop;
        top = Math.max(viewportPadding, top);
        priceTooltip.style.left = `${Math.round(left)}px`;
        priceTooltip.style.top = `${Math.round(top)}px`;
        priceTooltip.style.visibility = "visible";
    }

    function tooltipActionRow(slot, action, ruleColor = "") {
        const row = document.createElement("div");
        row.className = "app-schedule-tooltip__action";

        const badge = document.createElement("span");
        badge.className = "app-schedule-tooltip__badge";
        badge.dataset.tone = actionTone(action);
        if (ruleColor) {
            badge.dataset.runtimeRule = "true";
            badge.style.setProperty("--app-rule-color", ruleColor);
        }
        setActionBadgeContent(badge, slot, action);

        const copy = document.createElement("span");
        const label = document.createElement("strong");
        label.textContent = action.label;
        const description = document.createElement("span");
        const descriptionParts = [descriptionFor(action)];
        if (Number.isFinite(action.powerW) && action.powerW !== 0) descriptionParts.push(formatWatts(action.powerW));
        description.textContent = descriptionParts.join(" · ");
        copy.append(label, description);
        row.append(badge, copy);
        return row;
    }

    function tooltipSectionLabel(text) {
        const label = document.createElement("div");
        label.className = "app-schedule-tooltip__section-label";
        label.textContent = text;
        return label;
    }

    function tooltipLimits(slot, matched = false) {
        const limits = document.createElement("div");
        limits.className = "app-schedule-tooltip__limits";
        limits.textContent = `${matched ? "Matched-action limits" : "Limits"}: ${formatPowerLimits(slot)}`;
        return limits;
    }

    function forecastSourceCopy(forecast) {
        const fallback = forecast.usedFallback ? "Runtime fallback · " : "";
        if (forecast.source === "scheduled_power") return `${fallback}scheduled power`;
        if (forecast.source === "household_profile") return `${fallback}household estimate`;
        if (forecast.source === "bidirectional_neutral") return `${fallback}NZ± assumed neutral`;
        if (forecast.source === "runtime_condition_conservative") return "runtime condition · least guaranteed discharge";
        if (forecast.source === "solar_forecast_unavailable") return `${fallback}no solar forecast`;
        return `${fallback}automatic action unknown`;
    }

    function tooltipBatteryForecast(date, hour) {
        if (!hourEndsInFuture(date, hour)) return null;

        const section = document.createElement("div");
        section.className = "app-schedule-tooltip__forecast";
        const title = tooltipSectionLabel("Estimated real battery level");
        const forecast = state.batteryForecast[forecastKey(date, hour)];
        section.appendChild(title);

        if (!forecast) {
            const unavailable = document.createElement("p");
            unavailable.className = "app-schedule-tooltip__forecast-unavailable";
            unavailable.textContent = state.forecastUnavailableReason || "The server forecast is unavailable for this hour.";
            section.appendChild(unavailable);
            return section;
        }

        const values = document.createElement("div");
        values.className = "app-schedule-tooltip__forecast-values";
        const start = document.createElement("p");
        const end = document.createElement("p");
        const startLabel = document.createElement("span");
        const endLabel = document.createElement("span");
        const startValue = document.createElement("strong");
        const endValue = document.createElement("strong");
        startLabel.textContent = forecast.currentHour ? "Now" : "Start";
        endLabel.textContent = "End";
        startValue.textContent = `${forecast.startPercent.toFixed(1)}%`;
        endValue.textContent = `${forecast.endPercent.toFixed(1)}%`;
        start.append(startLabel, startValue);
        end.append(endLabel, endValue);
        values.append(start, end);

        const detail = document.createElement("p");
        detail.className = "app-schedule-tooltip__forecast-detail";
        const deltaPrefix = forecast.deltaPercent > 0 ? "+" : forecast.deltaPercent < 0 ? "−" : "";
        const durationMinutes = Math.round(forecast.durationHours * 60);
        const primaryMinutes = Math.round((forecast.primaryDurationHours || 0) * 60);
        const powerDescription = forecast.transitionedToFallback && primaryMinutes > 0
            ? `${formatWatts(forecast.primaryPowerW)} → ${formatWatts(forecast.fallbackPowerW)}`
            : formatWatts(forecast.transitionedToFallback ? forecast.fallbackPowerW : forecast.estimatedPowerW);
        detail.textContent = `${deltaPrefix}${Math.abs(forecast.deltaPercent).toFixed(1)} pp · ${powerDescription} · ${forecastSourceCopy(forecast)}${forecast.currentHour ? ` · ${durationMinutes} min remaining` : ""}`;
        section.append(values, detail);
        return section;
    }

    function tooltipTargetPlanning(slot) {
        const planning = slot?.planning;
        if (!planning || !["empty_at_solar_charge", "full_at_netzero_minus"].includes(planning.mode)) return null;

        const isChargeTarget = planning.mode === "full_at_netzero_minus";

        const section = document.createElement("div");
        section.className = "app-schedule-tooltip__forecast app-schedule-tooltip__target-plan";
        section.appendChild(tooltipSectionLabel("Battery target"));

        const values = document.createElement("div");
        values.className = "app-schedule-tooltip__forecast-values";
        const target = document.createElement("p");
        const result = document.createElement("p");
        const targetLabel = document.createElement("span");
        const resultLabel = document.createElement("span");
        const targetValue = document.createElement("strong");
        const resultValue = document.createElement("strong");
        const predictedChargePercent = Number(planning.predicted_anchor_soc_percent);
        const hasPredictedChargePercent = Number.isFinite(predictedChargePercent);
        targetLabel.textContent = isChargeTarget ? "Goal at NZ−" : "Goal at solar";
        resultLabel.textContent = isChargeTarget
            ? (hasPredictedChargePercent ? "Predicted at NZ−" : "Planner input")
            : "Predicted";
        targetValue.textContent = Number.isFinite(Number(planning.target_soc_percent))
            ? `${Number(planning.target_soc_percent).toFixed(1)}%`
            : "Unavailable";
        const resultPercent = isChargeTarget
            ? (hasPredictedChargePercent ? predictedChargePercent : planning.current_soc_percent)
            : planning.predicted_anchor_soc_percent;
        resultValue.textContent = Number.isFinite(Number(resultPercent))
            ? `${Number(resultPercent).toFixed(1)}%`
            : "Unavailable";
        target.append(targetLabel, targetValue);
        result.append(resultLabel, resultValue);
        values.append(target, result);

        const detail = document.createElement("p");
        detail.className = "app-schedule-tooltip__forecast-detail";
        const hasAnchor = Boolean(planning.anchor_date && planning.anchor_time);
        const anchor = hasAnchor
            ? `${isChargeTarget ? "NZ−" : "Solar"} ${formatDate(planning.anchor_date)} ${String(planning.anchor_time).slice(0, 2)}:${String(planning.anchor_time).slice(2, 4)}`
            : `${isChargeTarget ? "NZ−" : "Solar-charge"} anchor not found`;
        const statuses = {
            achievable: "Target achievable",
            best_effort: "Best effort",
            already_satisfied: isChargeTarget ? "Target already forecast" : "No extra discharge needed",
            unavailable: "Calculation unavailable",
            past: "Rule hour passed"
        };
        const parts = [statuses[planning.status] || String(planning.status || "Planned"), anchor];
        if (isChargeTarget && Number.isFinite(Number(planning.calculated_min_power_w))) {
            parts.push(`minimum ${formatWatts(Number(planning.calculated_min_power_w))}`);
        } else if (Number.isFinite(Number(planning.calculated_power_w))) {
            parts.push(`calculated ${formatWatts(Number(planning.calculated_power_w))}`);
        }
        if (isChargeTarget && Number.isFinite(Number(planning.remaining_eligible_hours))) {
            parts.push(`${Number(planning.remaining_eligible_hours).toFixed(2)} h remaining`);
        }
        detail.textContent = parts.join(" · ");
        section.appendChild(values);
        if (isChargeTarget) {
            const forecastFacts = [];
            if (Number.isFinite(Number(planning.current_soc_percent))) {
                forecastFacts.push(`planner input ${Number(planning.current_soc_percent).toFixed(1)}%`);
            }
            if (Number.isFinite(Number(planning.predicted_start_soc_percent))) {
                forecastFacts.push(`predicted first-slot start ${Number(planning.predicted_start_soc_percent).toFixed(1)}%`);
            }
            if (Number.isFinite(Number(planning.baseline_anchor_soc_percent))) {
                forecastFacts.push(`without minimum ${Number(planning.baseline_anchor_soc_percent).toFixed(1)}% at NZ−`);
            }
            if (forecastFacts.length) {
                const forecastContext = document.createElement("p");
                forecastContext.className = "app-schedule-tooltip__forecast-detail";
                forecastContext.textContent = forecastFacts.join(" · ");
                section.appendChild(forecastContext);
            }
        }
        const reasonText = planning.reason || (planning.status === "unavailable"
            ? "The planner did not return a detailed reason."
            : "");
        if (reasonText) {
            const reason = document.createElement("p");
            reason.className = "app-schedule-tooltip__forecast-unavailable app-schedule-tooltip__forecast-reason";
            reason.textContent = `Reason: ${reasonText}`;
            section.appendChild(reason);
        }
        section.appendChild(detail);
        return section;
    }

    function showPriceTooltip({ date, hour, slot, action, limited }, trigger, anchor) {
        hidePriceTooltip();
        activeTooltipTrigger = trigger;
        activeScheduleTooltip = { detail: { date, hour, slot, action, limited }, trigger, anchor };
        trigger.setAttribute("aria-describedby", priceTooltip.id);
        if (trigger.matches(".app-price-hour__action")) trigger.setAttribute("aria-expanded", "true");

        const nextHour = (hour + 1) % 24;
        const header = document.createElement("div");
        header.className = "app-schedule-tooltip__header";
        const heading = document.createElement("div");
        heading.className = "app-schedule-tooltip__heading";
        const title = document.createElement("div");
        title.className = "app-schedule-tooltip__title";
        const time = document.createElement("strong");
        time.id = "app-price-action-tooltip-title";
        time.textContent = `${pad(hour)}:00–${pad(nextHour)}:00`;
        const dayPrices = date === state.dates.tomorrow ? state.prices.tomorrow : state.prices.today;
        const hourPrice = numericValue(dayPrices && typeof dayPrices === "object" ? dayPrices[pad(hour)] : null);
        const priceMeta = document.createElement("span");
        priceMeta.className = "app-schedule-tooltip__price";
        priceMeta.textContent = `Price (${formatPriceCents(hourPrice)} / ${formatPriceCents(spotPrice(hourPrice))})`;
        title.append(time, priceMeta);
        heading.append(title, createTooltipCloseButton());
        header.appendChild(heading);

        const runtimeConditions = formatRuntimeConditions(slot);
        const ruleColor = normalizeRuleColor(state.ruleColors[String(slot?.rule_index ?? "")]);
        const rule = createTooltipRule(slot, ruleColor);
        if (rule) header.appendChild(rule);
        priceTooltip.setAttribute("aria-labelledby", time.id);

        const content = document.createElement("div");
        content.className = "app-schedule-tooltip__content";
        if (runtimeConditions) {
            content.appendChild(tooltipSectionLabel("When"));
            const condition = document.createElement("p");
            condition.className = "app-schedule-tooltip__condition";
            condition.textContent = runtimeConditions.charAt(0).toUpperCase() + runtimeConditions.slice(1);
            content.append(condition, tooltipActionRow(slot, action, ruleColor));
            if (limited) content.appendChild(tooltipLimits(slot, true));

            const otherwise = tooltipSectionLabel("Otherwise");
            otherwise.classList.add("app-schedule-tooltip__section-label--otherwise");
            const fallbackValue = Object.prototype.hasOwnProperty.call(slot, "fallback_value") ? slot.fallback_value : 0;
            const fallbackSlot = { value: fallbackValue };
            content.append(otherwise, tooltipActionRow(fallbackSlot, runtimeFallbackAction(slot)));
        } else {
            content.appendChild(tooltipActionRow(slot, action));
            if (limited) content.appendChild(tooltipLimits(slot));
        }

        const targetPlanning = tooltipTargetPlanning(slot);
        if (targetPlanning) content.appendChild(targetPlanning);
        const batteryForecast = tooltipBatteryForecast(date, hour);
        if (batteryForecast) content.appendChild(batteryForecast);

        priceTooltip.replaceChildren(header, content);
        priceTooltip.hidden = false;
        priceTooltip.style.visibility = "hidden";
        positionPriceTooltip(anchor);
    }

    function showSummaryPriceTooltip(detail, trigger) {
        if (!detail) return;
        hidePriceTooltip();
        activeTooltipTrigger = trigger;
        trigger.setAttribute("aria-describedby", priceTooltip.id);
        trigger.setAttribute("aria-expanded", "true");

        const header = document.createElement("div");
        header.className = "app-schedule-tooltip__header";
        const heading = document.createElement("div");
        heading.className = "app-schedule-tooltip__heading";
        const period = document.createElement("strong");
        period.id = "app-price-action-tooltip-title";
        period.textContent = detail.kind === "average"
            ? detail.tomorrowAverage
                ? "Daily average prices"
                : `Today average · ${detail.hourCount} hourly price${detail.hourCount === 1 ? "" : "s"}`
            : `${formatDate(detail.date)} · ${pad(detail.hour)}:00–${pad((detail.hour + 1) % 24)}:00`;
        heading.append(period, createTooltipCloseButton());
        header.appendChild(heading);
        priceTooltip.setAttribute("aria-labelledby", period.id);

        const prices = document.createElement("div");
        prices.className = "app-price-summary-tooltip__prices";
        if (detail.kind === "average" && detail.tomorrowAverage) {
            [
                ["Today · consumer", detail.price],
                ["Today · spot", detail.spotPrice],
                ["Tomorrow · consumer", detail.tomorrowAverage.price],
                ["Tomorrow · spot", detail.tomorrowAverage.spotPrice]
            ].forEach(([label, value]) => {
                const row = document.createElement("p");
                const name = document.createElement("span");
                const amount = document.createElement("strong");
                name.textContent = label;
                amount.textContent = formatPrice(value);
                row.append(name, amount);
                prices.appendChild(row);
            });
            priceTooltip.replaceChildren(header, prices);
            priceTooltip.hidden = false;
            priceTooltip.style.visibility = "hidden";
            positionPriceTooltip(trigger, { preferAbove: true });
            return;
        }

        const detailSpotPrice = Number.isFinite(detail.spotPrice)
            ? detail.spotPrice
            : spotPrice(detail.price);
        [["Consumer price", detail.price], ["Spot price", detailSpotPrice]].forEach(([label, value]) => {
            const row = document.createElement("p");
            const name = document.createElement("span");
            const amount = document.createElement("strong");
            name.textContent = label;
            amount.textContent = formatPrice(value);
            row.append(name, amount);
            prices.appendChild(row);
        });

        priceTooltip.replaceChildren(header, prices);
        priceTooltip.hidden = false;
        priceTooltip.style.visibility = "hidden";
        positionPriceTooltip(trigger, { preferAbove: true });
    }

    function setSummaryTooltip(card, label, detail) {
        if (!card) return;
        summaryTooltipDetails.set(card, detail);
        card.disabled = !detail;
        card.setAttribute("aria-expanded", "false");
        if (detail?.kind === "average" && detail.tomorrowAverage) {
            card.setAttribute(
                "aria-label",
                `${label}. Today consumer price ${formatPrice(detail.price)}, spot price ${formatPrice(detail.spotPrice)}. Tomorrow consumer price ${formatPrice(detail.tomorrowAverage.price)}, spot price ${formatPrice(detail.tomorrowAverage.spotPrice)}. Show price details.`
            );
            return;
        }
        const dateAndTime = detail?.kind === "average"
            ? detail.startDate === detail.endDate
                ? `${detail.hourCount} available hourly price${detail.hourCount === 1 ? "" : "s"} for ${formatDate(detail.startDate)}`
                : `${detail.hourCount} available hourly price${detail.hourCount === 1 ? "" : "s"} from ${formatDate(detail.startDate)} through ${formatDate(detail.endDate)}`
            : detail
                ? `${formatDate(detail.date)}, ${pad(detail.hour)}:00 to ${pad((detail.hour + 1) % 24)}:00`
                : "time unavailable";
        const consumer = detail ? formatPrice(detail.price) : "unavailable";
        const spot = detail
            ? formatPrice(Number.isFinite(detail.spotPrice) ? detail.spotPrice : spotPrice(detail.price))
            : "unavailable";
        card.setAttribute("aria-label", `${label}. ${dateAndTime}. Consumer price ${consumer}. Spot price ${spot}. Show price details.`);
    }

    function bindSummaryTooltip(card) {
        if (!card) return;
        card.addEventListener("click", () => {
            if (activeTooltipTrigger === card && !priceTooltip.hidden) {
                hidePriceTooltip(card);
                return;
            }
            const detail = summaryTooltipDetails.get(card);
            if (detail?.kind !== "average") {
                setSelectedHour(hourSelectionKey(detail), { scroll: true, smooth: true });
            }
            showSummaryPriceTooltip(detail, card);
        });
    }

    function actionTone(action) {
        if (action.type === "charge") return "positive";
        if (action.type === "discharge") return "negative";
        if (action.type === "netzero") {
            if (action.direction === "plus") return "positive";
            if (action.direction === "minus") return "negative";
            return "mixed";
        }
        return "neutral";
    }

    function formatBadgePower(value) {
        const magnitude = Math.abs(Number(value));
        if (!Number.isFinite(magnitude)) return "";
        if (magnitude < 1000) return String(magnitude);
        const step = sharedPowerStepW();
        const rounded = Math.round(magnitude / step) * step;
        const thousands = Math.floor(rounded / 1000);
        const hundreds = (rounded % 1000) / 100;
        return `${thousands}K${hundreds || ""}`;
    }

    function closestPowerLimit(slot) {
        const { minimum, maximum } = powerLimits(slot);
        return [minimum, maximum]
            .filter((value) => value !== null)
            .reduce((closest, value) => (
                closest === null || Math.abs(value) < Math.abs(closest) ? value : closest
            ), null);
    }

    function setActionBadgeContent(element, slot, action) {
        const fixedPower = numericValue(slot?.value);
        if (fixedPower !== null) {
            element.textContent = formatBadgePower(fixedPower);
            return;
        }

        const limit = closestPowerLimit(slot);
        if (limit !== null) {
            element.dataset.limitValue = "true";
            element.textContent = formatBadgePower(limit);
            return;
        }

        let icon = "refresh";
        if (action.type === "netzero") {
            icon = action.direction === "plus" ? "sun" : action.direction === "minus" ? "bolt" : "bidirectional";
        }
        const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        const use = document.createElementNS("http://www.w3.org/2000/svg", "use");
        svg.classList.add("gsd-icon");
        svg.setAttribute("aria-hidden", "true");
        use.setAttribute("href", `../themes/graphite-signal-dark/assets/icons/sprite.svg#${icon}`);
        svg.appendChild(use);
        element.replaceChildren(svg);
    }

    function spotPrice(consumerPrice) {
        if (!Number.isFinite(consumerPrice)) return null;
        const conversion = config.priceConversion;
        const vat = conversion?.vatMultiplier;
        const markup = conversion?.supplierMarkupEurPerKwh;
        const tax = conversion?.energyTaxEurPerKwh;
        if (
            !Number.isFinite(vat)
            || !Number.isFinite(markup)
            || !Number.isFinite(tax)
            || vat <= 0
            || markup < 0
            || tax < 0
        ) {
            throw new Error("The shared price-conversion settings are missing or invalid.");
        }
        return (consumerPrice / vat) - markup - tax;
    }

    function selectedMode() {
        return editElements?.modeInputs.find((input) => input.checked)?.value || "netzero";
    }

    function exactEntryFor(key, day) {
        return (state.entries[day] || []).find((item) => item?.key === key) || null;
    }

    function setEditError(message = "") {
        if (!editElements) return;
        editElements.error.textContent = message;
        editElements.error.hidden = !message;
    }

    function limitBoundsForMode(mode = selectedMode()) {
        const bounds = sharedPowerBounds();
        const lower = Math.min(bounds.minimum, -1);
        const upper = Math.max(bounds.maximum, 1);
        if (mode === "netzero+") return { minimum: 0, maximum: upper };
        if (mode === "netzero-") return { minimum: lower, maximum: 0 };
        return { minimum: lower, maximum: upper };
    }

    function clampLimit(value, bounds, fallback) {
        const numeric = numericValue(value);
        if (numeric === null) return fallback;
        const step = sharedPowerStepW();
        const snapped = Math.round(numeric / step) * step;
        return Math.min(bounds.maximum, Math.max(bounds.minimum, snapped));
    }

    function formatLimitValue(value) {
        const rounded = Math.round(value);
        const sign = rounded > 0 ? "+" : rounded < 0 ? "−" : "";
        return `${sign}${Math.abs(rounded).toLocaleString()} W`;
    }

    function setLimitTone(element, value) {
        if (!element) return;
        element.dataset.tone = value < 0 ? "negative" : value > 0 ? "positive" : "neutral";
    }

    function updateFixedPowerControls({ fromSlider = false } = {}) {
        if (!editElements) return;
        const bounds = limitBoundsForMode("netzero");
        if (fromSlider) editElements.watts.value = String(editElements.fixedRange.value);

        const rawValue = numericValue(editElements.watts.value);
        const displayValue = rawValue ?? 0;
        const sliderValue = clampLimit(displayValue, bounds, 0);
        editElements.fixedRange.min = String(bounds.minimum);
        editElements.fixedRange.max = String(bounds.maximum);
        editElements.fixedRange.step = String(sharedPowerStepW());
        editElements.fixedRange.value = String(sliderValue);
        editElements.fixedDisplay.textContent = formatLimitValue(displayValue);
        setLimitTone(editElements.watts, displayValue);
        setLimitTone(editElements.fixedDisplay, displayValue);
        setLimitTone(editElements.fixedRange, sliderValue);
        setLimitTone(editElements.fixedSelection, sliderValue);
        setLimitTone(editElements.fixedSummary, displayValue);

        const span = Math.max(1, bounds.maximum - bounds.minimum);
        const center = ((0 - bounds.minimum) / span) * 100;
        const valuePosition = ((sliderValue - bounds.minimum) / span) * 100;
        editElements.fixedSlider.style.setProperty("--app-fixed-center", `${center}%`);
        editElements.fixedSelection.style.left = `${Math.min(center, valuePosition)}%`;
        editElements.fixedSelection.style.width = `${Math.abs(valuePosition - center)}%`;

        editElements.fixedSummary.textContent = displayValue < 0
            ? `Fixed discharge: ${formatLimitValue(displayValue)}`
            : displayValue > 0
                ? `Fixed charge: ${formatLimitValue(displayValue)}`
                : "Idle: 0 W";
    }

    function updateLimitControls({ activeThumb = null, reset = false } = {}) {
        if (!editElements) return;
        if (!editElements.limitsEnabled.checked || editElements.limitEditor.hidden) {
            editElements.limitSummary.textContent = "No explicit power limits will be saved.";
            return;
        }

        const mode = selectedMode();
        const bounds = limitBoundsForMode(mode);
        editElements.limitSlider.dataset.mode = mode;
        const minimumSource = activeThumb === "minimum" ? editElements.minimumRange.value : editElements.minimum.value;
        const maximumSource = activeThumb === "maximum" ? editElements.maximumRange.value : editElements.maximum.value;
        let minimum = reset ? bounds.minimum : clampLimit(minimumSource, bounds, bounds.minimum);
        let maximum = reset ? bounds.maximum : clampLimit(maximumSource, bounds, bounds.maximum);

        if (minimum > maximum) {
            if (activeThumb === "minimum") maximum = minimum;
            else minimum = maximum;
        }

        [editElements.minimumRange, editElements.maximumRange].forEach((input) => {
            input.min = String(bounds.minimum);
            input.max = String(bounds.maximum);
            input.step = String(sharedPowerStepW());
        });
        editElements.minimumRange.value = String(minimum);
        editElements.maximumRange.value = String(maximum);
        editElements.minimum.value = String(minimum);
        editElements.maximum.value = String(maximum);
        editElements.minimumDisplay.textContent = formatLimitValue(minimum);
        editElements.maximumDisplay.textContent = formatLimitValue(maximum);
        setLimitTone(editElements.minimumDisplay, minimum);
        setLimitTone(editElements.maximumDisplay, maximum);
        setLimitTone(editElements.minimumRange, minimum);
        setLimitTone(editElements.maximumRange, maximum);

        const span = Math.max(1, bounds.maximum - bounds.minimum);
        const start = ((minimum - bounds.minimum) / span) * 100;
        const end = ((maximum - bounds.minimum) / span) * 100;
        editElements.limitSelection.style.left = `${start}%`;
        editElements.limitSelection.style.width = `${Math.max(0, end - start)}%`;

        const rangeLabel = mode === "netzero+" ? "Charge-only range" : mode === "netzero-" ? "Discharge-only range" : "Bidirectional range";
        editElements.limitSummary.textContent = `${rangeLabel}: ${formatLimitValue(minimum)} to ${formatLimitValue(maximum)}`;
    }

    function updateEditFields({ resetLimits = false } = {}) {
        if (!editElements) return;
        const mode = selectedMode();
        const dynamic = ["netzero-", "netzero", "netzero+"].includes(mode);
        editElements.fixedField.hidden = mode !== "fixed";
        editElements.limitEditor.hidden = !dynamic;
        editElements.limitFields.hidden = !dynamic || !editElements.limitsEnabled.checked;
        updateLimitControls({ reset: resetLimits });
        updateFixedPowerControls();
        setEditError();
    }

    function openEditDialog(detail, trigger) {
        if (!editDialog || !editElements || !detail) return;
        const date = detail.date || state.dates[detail.day] || localDates()[detail.day];
        const key = `${date}${pad(detail.hour)}00`;
        const exact = exactEntryFor(key, detail.day);
        const entry = exact?.entry || detail.slot || { value: "netzero" };
        const action = actionFor(entry);
        const rawNumeric = numericValue(entry.value);
        const mode = rawNumeric !== null
            ? "fixed"
            : action.type === "netzero"
                ? action.direction === "plus" ? "netzero+" : action.direction === "minus" ? "netzero-" : "netzero"
                : String(entry.value || "auto").toLowerCase() === "auto" ? "auto" : "netzero";
        const limits = powerLimits(entry);

        editDialog.dataset.scheduleKey = key;
        editDialog.dataset.originalKey = exact?.key || "";
        editElements.title.replaceChildren(
            document.createTextNode(`Edit ${formatDate(date)}`),
            Object.assign(document.createElement("span"), {
                className: "app-schedule-edit-dialog__desktop-separator",
                textContent: " · "
            }),
            Object.assign(document.createElement("br"), {
                className: "app-schedule-edit-dialog__mobile-break"
            }),
            document.createTextNode(`${pad(detail.hour)}:00–${pad((detail.hour + 1) % 24)}:00`)
        );
        editElements.priceSummary.textContent = `Price (${formatPriceCents(detail.price)} / ${formatPriceCents(spotPrice(detail.price))})`;
        editElements.modeInputs.forEach((input) => { input.checked = input.value === mode; });
        editElements.watts.value = rawNumeric !== null ? String(rawNumeric) : "0";
        editElements.minimum.value = limits.minimum ?? "";
        editElements.maximum.value = limits.maximum ?? "";
        editElements.limitsEnabled.checked = limits.minimum !== null || limits.maximum !== null;
        editElements.limitsDisabled.checked = !editElements.limitsEnabled.checked;
        setEditError();
        updateEditFields();

        window.GraphiteDialog?.open(editDialog, { trigger });
    }

    function renderSummary(days) {
        const entries = days.flatMap((day) => day.values.map((price, hour) => ({
            date: day.date,
            day: day.key,
            hour,
            price
        })));
        const hour = referenceNow().getHours();
        const available = entries.filter((entry) => Number.isFinite(entry.price)
            && (entry.day === "tomorrow" || (entry.day === "today" && entry.hour >= hour)));
        const current = entries.find((entry) => entry.day === "today" && entry.hour === hour) || null;
        const low = available.reduce((lowest, entry) => !lowest || entry.price < lowest.price ? entry : lowest, null);
        const high = available.reduce((highest, entry) => !highest || entry.price > highest.price ? entry : highest, null);
        const dailyAverage = (day) => {
            if (!day) return null;
            const prices = day.values.filter(Number.isFinite);
            return prices.length ? {
                kind: "average",
                date: day.date,
                price: prices.reduce((sum, price) => sum + price, 0) / prices.length,
                spotPrice: prices.reduce((sum, price) => sum + spotPrice(price), 0) / prices.length,
                hourCount: prices.length,
                startDate: day.date,
                endDate: day.date
            } : null;
        };
        const todayAverage = dailyAverage(days.find((day) => day.key === "today"));
        const tomorrowAverage = dailyAverage(days.find((day) => day.key === "tomorrow"));
        const averageDetail = todayAverage ? { ...todayAverage, tomorrowAverage } : null;
        elements.currentLabel.textContent = isSimulation ? "Simulation start" : "Current";
        setDimmedToken(elements.current, formatPrice(current?.price), "€");
        setDimmedToken(elements.low, formatPrice(low?.price), "€");
        setDimmedToken(elements.average, formatPrice(todayAverage?.price), "€");
        setDimmedToken(elements.high, formatPrice(high?.price), "€");
        setSummaryTooltip(elements.currentKpi, "Current price", current);
        setSummaryTooltip(elements.lowKpi, "From now low", low);
        setSummaryTooltip(elements.averageKpi, "Daily averages", averageDetail);
        setSummaryTooltip(elements.highKpi, "From now high", high);
    }

    function publishCurrentPrice() {
        if (isSimulation) return;
        const currentHour = referenceNow().getHours();
        const currentPrice = priceValues(state.prices.today)[currentHour];
        document.dispatchEvent(new CustomEvent("graphite:current-price", {
            detail: { eurPerKwh: Number.isFinite(currentPrice) ? currentPrice : null }
        }));
    }

    function renderTimeline(days) {
        const values = days.flatMap((day) => day.values);
        const available = values.filter(Number.isFinite);
        const minimum = available.length ? Math.min(...available) : 0;
        const maximum = available.length ? Math.max(...available) : 1;
        const span = Math.max(0.0001, maximum - minimum);
        const currentHour = referenceNow().getHours();
        const fragment = document.createDocumentFragment();

        days.forEach((day, dayIndex) => {
            const dayHeading = document.createElement("span");
            const dayHeadingLabel = document.createElement("span");
            dayHeading.className = "app-price-day-heading";
            dayHeading.dataset.day = day.key;
            dayHeading.style.setProperty("--app-day-start", String(dayIndex * 24 + 1));
            dayHeading.style.setProperty("--app-day-end", String((dayIndex + 1) * 24 + 1));
            dayHeadingLabel.className = "app-price-day-heading__label";
            dayHeadingLabel.textContent = `${day.label} · ${formatDate(day.date)}`;
            dayHeading.appendChild(dayHeadingLabel);
            fragment.appendChild(dayHeading);

            DAY_PARTS.forEach((dayPart) => {
                const band = document.createElement("span");
                band.className = "app-price-daypart";
                band.dataset.daypart = dayPart.key;
                band.dataset.day = day.key;
                band.style.setProperty("--app-daypart-start", String(dayIndex * 24 + dayPart.start + 1));
                band.style.setProperty("--app-daypart-end", String(dayIndex * 24 + dayPart.end + 1));
                const label = document.createElement("span");
                label.className = "app-price-daypart__label";
                label.textContent = dayPart.label;
                band.appendChild(label);
                band.setAttribute("aria-hidden", "true");
                fragment.appendChild(band);
            });
            appendSolarMarkers(fragment, day.date, dayIndex, 48);

            for (let hour = 0; hour < 24; hour += 1) {
                const price = day.values[hour];
                const slot = day.slots[hour];
                const action = actionFor(slot);
                const limited = hasPowerLimits(slot);
                const position = Number.isFinite(price) ? (price - minimum) / span : 0;
                const hourColumn = document.createElement("div");
                const editButton = document.createElement("button");
                const isCurrent = day.key === "today" && hour === currentHour;
                const dayPart = dayPartForHour(hour);
                const selectionKey = hourSelectionKey({ date: day.date, hour });
                hourColumn.className = "app-price-hour";
                hourColumn.dataset.current = isCurrent ? "true" : "false";
                hourColumn.dataset.selectionKey = selectionKey;
                hourColumn.dataset.selected = String(selectionKey === selectedHourKey);
                hourColumn.dataset.daypart = dayPart.key;
                hourColumn.dataset.daypartStart = hour === dayPart.start && hour !== 0 ? "true" : "false";
                hourColumn.dataset.dayStart = hour === 0 ? "true" : "false";
                hourColumn.dataset.day = day.key;
                hourColumn.style.gridColumn = String(dayIndex * 24 + hour + 1);
                editButton.type = "button";
                editButton.className = "app-price-hour__edit";
                editButton.setAttribute("aria-pressed", String(selectionKey === selectedHourKey));
            editButton.setAttribute(
                "aria-label",
                isSimulation
                    ? `Historical price for ${day.label}, ${pad(hour)}:00, ${dayPart.label}, ${Number.isFinite(price) ? formatPrice(price) : "price unavailable"}`
                    : `Create or edit hourly override for ${day.label}, ${pad(hour)}:00, ${dayPart.label}, ${Number.isFinite(price) ? formatPrice(price) : "price unavailable"}`
            );
            if (isSimulation) {
                editButton.setAttribute("aria-disabled", "true");
                editButton.tabIndex = -1;
                editButton.dataset.readonly = "true";
            }

            const barZone = document.createElement("span");
            barZone.className = "app-price-hour__bar-zone";
            const bar = document.createElement("span");
            bar.className = "app-price-hour__bar";
            bar.style.setProperty("--app-price-height", Number.isFinite(price) ? `${18 + position * 82}%` : "5%");
            bar.style.setProperty("--app-price-color", Number.isFinite(price) ? priceColor(position) : "var(--gsd-border-strong)");
            barZone.appendChild(bar);

            const priceLabel = document.createElement("span");
            priceLabel.className = "app-price-hour__price";
            priceLabel.textContent = Number.isFinite(price) ? formatPriceCents(price) : "—";
            const time = document.createElement("span");
            time.className = "app-price-hour__time";
            time.textContent = `${pad(hour)}:00`;
            const actionElement = document.createElement("button");
            actionElement.type = "button";
            actionElement.className = "app-price-hour__action";
            actionElement.dataset.action = action.type;
            actionElement.dataset.tone = actionTone(action);
            actionElement.dataset.limited = limited ? "true" : "false";
            const hasRuntimeRule = Array.isArray(slot?.runtime_conditions) && slot.runtime_conditions.length > 0;
            const ruleColor = normalizeRuleColor(state.ruleColors[String(slot?.rule_index ?? "")]);
            if (isRuleResult(slot)) {
                actionElement.dataset.ruleResult = "true";
                if (ruleColor) actionElement.style.setProperty("--app-rule-color", ruleColor);
            }
            if (hasRuntimeRule) {
                actionElement.dataset.runtimeRule = "true";
                if (Object.prototype.hasOwnProperty.call(slot, "fallback_value") && slot.fallback_value !== null) {
                    actionElement.dataset.fallbackTone = actionTone(runtimeFallbackAction(slot));
                }
            }
            if (action.type === "netzero") actionElement.dataset.netzeroDirection = action.direction;
            setActionBadgeContent(actionElement, slot, action);
            const tooltipDetail = { date: day.date, hour, slot, action, limited };
            actionElement.setAttribute("aria-expanded", "false");
            actionElement.setAttribute(
                "aria-label",
                `${day.label}, ${pad(hour)}:00, scheduled action ${action.label}${limited ? `, limited to ${formatPowerLimits(slot)}` : ""}, source ${sourceFor(slot)}. Show schedule details.`
            );
            actionElement.addEventListener("mouseenter", () => {
                if (!pinnedTooltipTrigger) showPriceTooltip(tooltipDetail, actionElement, actionElement);
            });
            actionElement.addEventListener("mouseleave", () => {
                window.setTimeout(() => {
                    if (pinnedTooltipTrigger === actionElement) return;
                    if (document.activeElement === actionElement || eventInsidePriceTooltip(document.activeElement)) return;
                    if (priceTooltip.matches(":hover")) return;
                    hidePriceTooltip(actionElement);
                }, 80);
            });
            actionElement.addEventListener("focus", () => showPriceTooltip(tooltipDetail, actionElement, actionElement));
            actionElement.addEventListener("blur", () => {
                window.setTimeout(() => {
                    if (pinnedTooltipTrigger === actionElement) return;
                    if (eventInsidePriceTooltip(document.activeElement)) return;
                    hidePriceTooltip(actionElement);
                }, 0);
            });
            actionElement.addEventListener("click", () => {
                setSelectedHour(selectionKey);
                if (pinnedTooltipTrigger === actionElement && activeTooltipTrigger === actionElement && !priceTooltip.hidden) {
                    hidePriceTooltip(actionElement);
                    return;
                }
                pinnedTooltipTrigger = actionElement;
                showPriceTooltip(tooltipDetail, actionElement, actionElement);
                pinnedTooltipTrigger = actionElement;
            });

            editButton.append(barZone, priceLabel, time);
            if (!isSimulation) {
                editButton.addEventListener("click", () => {
                    setSelectedHour(selectionKey);
                    hidePriceTooltip();
                    openEditDialog({ hour, price, slot, day: day.key, date: day.date }, editButton);
                });
            }
            hourColumn.append(editButton, actionElement);
            fragment.appendChild(hourColumn);
            }
        });

        elements.timeline.replaceChildren(fragment);
        if (selectedHourKey && !setSelectedHour(selectedHourKey)) {
            selectedHourKey = null;
        }
    }

    function scrollTimelineToActiveHour() {
        const selected = selectedHourKey
            ? elements.timeline.querySelector('[data-selected="true"]')
            : null;
        const target = selected || elements.timeline.querySelector('[data-current="true"]');
        if (target) {
            centerTimelineHour(target);
            return;
        }
        elements.scroll.scrollLeft = 0;
        updateTimelineScrollButtons();
    }

    function descriptionFor(action) {
        if (action.type === "charge") return "Store energy in the battery";
        if (action.type === "discharge") return "Use battery energy";
        if (action.type === "netzero") {
            if (action.direction === "plus") return "Charge-only grid balancing";
            if (action.direction === "minus") return "Discharge-only grid balancing";
            return "Bidirectional grid balancing";
        }
        if (action.type === "standby") return "No battery power scheduled";
        return "Controller chooses the action";
    }

    function render({ preserveScroll = false } = {}) {
        const previousScrollLeft = elements.scroll.scrollLeft;
        hidePriceTooltip();
        const dates = localDates();
        const todayDate = state.dates.today || dates.today;
        const tomorrowDate = state.dates.tomorrow || dates.tomorrow;
        const todayValues = priceValues(state.prices.today);
        const tomorrowValues = priceValues(state.prices.tomorrow);
        const tomorrowHasPrices = priceValues(state.prices.tomorrow).some(Number.isFinite);
        elements.date.textContent = isSimulation
            ? `${formatDate(todayDate)} through ${formatDate(tomorrowDate)} · historical simulation`
            : "Today through tomorrow · swipe or scroll for all 48 hours";
        const days = [
            { key: "today", label: isSimulation ? "Selected day" : "Today", date: todayDate, values: todayValues, slots: scheduleMap(state.schedules.today) },
            { key: "tomorrow", label: isSimulation ? "Following day" : "Tomorrow", date: tomorrowDate, values: tomorrowValues, slots: scheduleMap(state.schedules.tomorrow) }
        ];
        renderSummary(days);
        renderTimeline(days);
        if (elements.tomorrowAvailability) {
            elements.tomorrowAvailability.dataset.availability = tomorrowHasPrices ? "available" : "unavailable";
        }
        if (elements.tomorrowStatusLabel) {
            elements.tomorrowStatusLabel.textContent = isSimulation
                ? (tomorrowHasPrices ? "Historical data ready" : "Historical data missing")
                : (tomorrowHasPrices ? "Tomorrow ready" : "Tomorrow pending");
        }
        elements.tomorrowStatus?.setAttribute(
            "aria-label",
            isSimulation
                ? (tomorrowHasPrices ? "Historical prices are available" : "Historical prices are unavailable")
                : (tomorrowHasPrices ? "Tomorrow's prices are available" : "Tomorrow's prices are not available yet")
        );
        if (preserveScroll) {
            elements.scroll.scrollLeft = previousScrollLeft;
            updateTimelineScrollButtons();
        } else {
            scrollTimelineToActiveHour();
        }
        window.requestAnimationFrame(updateTimelineScrollButtons);
    }

    function setView(view, message = "") {
        component.dataset.state = view;
        component.setAttribute("aria-busy", view === "loading" ? "true" : "false");
        elements.loading.hidden = view !== "loading";
        elements.error.hidden = view !== "error";
        elements.content.hidden = view !== "ready";
        elements.refresh.setAttribute("aria-busy", view === "loading" ? "true" : "false");
        elements.refresh.disabled = view === "loading";
        if (message) elements.errorMessage.textContent = message;
    }

    function optionalInteger(input, label) {
        const raw = input.value.trim();
        if (!raw) return null;
        if (!/^-?\d+$/.test(raw)) throw new Error(`${label} must be a whole number.`);
        return Number.parseInt(raw, 10);
    }

    function scheduleEditPayload() {
        if (!editDialog || !editElements) throw new Error("The schedule editor is unavailable.");
        const mode = selectedMode();
        const key = editDialog.dataset.scheduleKey;
        if (!/^\d{12}$/.test(key || "")) throw new Error("The selected schedule hour is invalid.");

        let value = mode;
        const entry = {};
        if (mode === "fixed") {
            value = optionalInteger(editElements.watts, "Fixed power");
            if (value === null) throw new Error("Enter a fixed power value.");
            const bounds = sharedPowerBounds();
            const lower = Math.min(bounds.minimum, -1);
            const upper = Math.max(bounds.maximum, 1);
            if (value < lower || value > upper) throw new Error(`Fixed power must be between ${lower} W and ${upper} W.`);
        }
        entry.value = value;

        if (["netzero-", "netzero", "netzero+"].includes(mode) && editElements.limitsEnabled.checked) {
            const minimum = optionalInteger(editElements.minimum, "Minimum power");
            const maximum = optionalInteger(editElements.maximum, "Maximum power");
            if (minimum === null && maximum === null) throw new Error("Enter at least one power limit or turn limits off.");
            if (minimum !== null && maximum !== null && minimum > maximum) {
                throw new Error("Minimum power cannot be greater than maximum power.");
            }
            if (mode === "netzero+" && [minimum, maximum].some((bound) => bound !== null && bound < 0)) {
                throw new Error("Net zero+ limits cannot be negative.");
            }
            if (mode === "netzero-" && [minimum, maximum].some((bound) => bound !== null && bound > 0)) {
                throw new Error("Net zero− limits cannot be positive.");
            }
            if (minimum !== null) entry.min_power = minimum;
            if (maximum !== null) entry.max_power = maximum;
        }

        const payload = { key, entry };
        if (editDialog.dataset.originalKey) payload.originalKey = editDialog.dataset.originalKey;
        return payload;
    }

    async function saveScheduleEdit(event) {
        event.preventDefault();
        if (!editElements || !config.scheduleUrl) return;
        setEditError();

        let payload;
        try {
            payload = scheduleEditPayload();
            editElements.save.disabled = true;
            editElements.save.setAttribute("aria-busy", "true");
            const response = await fetch(new URL(config.scheduleUrl, document.baseURI).href, {
                method: "PUT",
                headers: { "Content-Type": "application/json", Accept: "application/json" },
                body: JSON.stringify(payload)
            });
            const contentType = response.headers.get("content-type") || "";
            const result = contentType.includes("application/json") ? await response.json() : null;
            if (!response.ok || !result?.success) {
                throw new Error(result?.error || `Schedule save failed with HTTP ${response.status}.`);
            }

            let refreshError = null;
            try {
                await refreshAutomationSchedule();
            } catch (error) {
                refreshError = error;
                console.error("Schedule saved, but the automation refresh failed:", error);
            }

            await load();
            window.GraphiteDialog?.close(editDialog, "saved");
            if (refreshError) {
                window.GraphiteFlash?.warning(
                    "Schedule saved, but automation could not be refreshed immediately. It will be picked up during the next scheduled refresh."
                );
            } else {
                window.GraphiteFlash?.success(`Schedule updated for ${payload.key.slice(8, 10)}:00 and automation refreshed.`);
            }
        } catch (error) {
            setEditError(error.message || "The schedule could not be saved.");
        } finally {
            editElements.save.disabled = false;
            editElements.save.removeAttribute("aria-busy");
        }
    }

    function beginLoad() {
        // Keep the tall chart visible on refresh so the card does not collapse
        // to the short loading panel and jump the page.
        if (component.dataset.state === "ready") {
            component.setAttribute("aria-busy", "true");
            elements.refresh.setAttribute("aria-busy", "true");
            elements.refresh.disabled = true;
            return;
        }
        setView("loading");
    }

    async function load() {
        if (state.controller) state.controller.abort();
        state.controller = new AbortController();
        beginLoad();
        const dates = localDates();

        try {
            if (isSimulation) {
                const [scenarioResult, rulesResult] = await Promise.allSettled([
                    fetchBacktest(state.controller.signal),
                    fetchRuleColors(state.controller.signal)
                ]);
                if (scenarioResult.status !== "fulfilled") throw scenarioResult.reason;
                const scenario = scenarioResult.value;
                config.referenceTime = scenario.referenceTime;
                config.solarEvents = scenario.solarEvents || {};
                state.prices.today = scenario.prices.today || null;
                state.prices.tomorrow = scenario.prices.tomorrow || null;
                state.dates.today = scenario.dates?.today || dates.today;
                state.dates.tomorrow = scenario.dates?.tomorrow || dates.tomorrow;
                state.schedules.today = scenario.schedules.today || [];
                state.schedules.tomorrow = scenario.schedules.tomorrow || [];
                state.entries.today = scenario.entries?.today || [];
                state.entries.tomorrow = scenario.entries?.tomorrow || [];
                state.ruleColors = rulesResult.status === "fulfilled" ? rulesResult.value : {};
                state.batteryForecast = scenario.forecast;
                state.forecastAsOf = scenario.forecastAsOf || null;
                state.forecastUnavailableReason = scenario.forecastUnavailableReason || null;
                render();
                setView("ready");
                return;
            }
            const results = await Promise.allSettled([
                fetchPrices(state.controller.signal),
                fetchSchedule(dates.today, state.controller.signal),
                fetchSchedule(dates.tomorrow, state.controller.signal),
                fetchRuleColors(state.controller.signal)
            ]);
            if (results.slice(0, 3).every((result) => result.status === "rejected")) {
                throw results[0].reason || new Error("All price and schedule sources failed");
            }

            const pricePayload = results[0].status === "fulfilled" ? results[0].value : {};
            state.prices.today = pricePayload.today || null;
            state.prices.tomorrow = pricePayload.tomorrow || null;
            state.dates.today = pricePayload.dates?.today || dates.today;
            state.dates.tomorrow = pricePayload.dates?.tomorrow || dates.tomorrow;
            state.schedules.today = results[1].status === "fulfilled" ? results[1].value.resolved : [];
            state.schedules.tomorrow = results[2].status === "fulfilled" ? results[2].value.resolved : [];
            state.entries.today = results[1].status === "fulfilled" ? results[1].value.entries || [] : [];
            state.entries.tomorrow = results[2].status === "fulfilled" ? results[2].value.entries || [] : [];
            state.ruleColors = results[3].status === "fulfilled" ? results[3].value : {};
            state.batteryForecast = {};
            if (results[1].status === "fulfilled") applyServerForecast(results[1].value, dates.today);
            if (results[2].status === "fulfilled") applyServerForecast(results[2].value, dates.tomorrow);
            publishCurrentPrice();
            render();
            setView("ready");
        } catch (error) {
            if (error.name === "AbortError") return;
            setView("error", error.message || "Price and schedule data could not be loaded.");
        }
    }

    async function refreshSchedules() {
        if (scheduleRefreshInFlight || document.hidden) return;
        scheduleRefreshInFlight = true;
        const dates = localDates();
        const controller = new AbortController();
        try {
            const results = await Promise.allSettled([
                fetchSchedule(dates.today, controller.signal),
                fetchSchedule(dates.tomorrow, controller.signal)
            ]);
            if (results.every((result) => result.status === "rejected")) return;
            if (results[0].status === "fulfilled") {
                state.schedules.today = results[0].value.resolved;
                state.entries.today = results[0].value.entries || [];
                applyServerForecast(results[0].value, dates.today);
            }
            if (results[1].status === "fulfilled") {
                state.schedules.tomorrow = results[1].value.resolved;
                state.entries.tomorrow = results[1].value.entries || [];
                applyServerForecast(results[1].value, dates.tomorrow);
            }
            render({ preserveScroll: true });
        } finally {
            scheduleRefreshInFlight = false;
        }
    }

    elements.refresh.addEventListener("click", load);
    elements.retry.addEventListener("click", load);
    elements.scrollPrev?.addEventListener("click", () => scrollTimelineByPage(-1));
    elements.scrollNext?.addEventListener("click", () => scrollTimelineByPage(1));
    elements.scroll.addEventListener("scroll", updateTimelineScrollButtons, { passive: true });
    elements.scrollShell?.addEventListener("pointermove", (event) => {
        if (event.pointerType && event.pointerType !== "mouse") return;
        syncTimelineHoverEdge(event.clientX);
    });
    elements.scrollShell?.addEventListener("pointerleave", () => {
        if (elements.scrollShell) delete elements.scrollShell.dataset.hoverEdge;
    });
    [elements.currentKpi, elements.lowKpi, elements.averageKpi, elements.highKpi].forEach(bindSummaryTooltip);
    editElements?.form?.addEventListener("submit", saveScheduleEdit);
    editElements?.modeInputs.forEach((input) => input.addEventListener("change", () => updateEditFields({ resetLimits: true })));
    editElements?.limitsEnabled?.addEventListener("change", () => updateEditFields({ resetLimits: editElements.limitsEnabled.checked }));
    editElements?.limitsDisabled?.addEventListener("change", () => updateEditFields());
    editElements?.minimumRange?.addEventListener("input", () => updateLimitControls({ activeThumb: "minimum" }));
    editElements?.maximumRange?.addEventListener("input", () => updateLimitControls({ activeThumb: "maximum" }));
    editElements?.watts?.addEventListener("input", () => updateFixedPowerControls());
    editElements?.fixedRange?.addEventListener("input", () => updateFixedPowerControls({ fromSlider: true }));
    if (!isSimulation) {
        document.addEventListener("visibilitychange", () => {
            if (!document.hidden) load();
        });
    }
    priceTooltip.addEventListener("mouseleave", () => {
        if (pinnedTooltipTrigger || !activeTooltipTrigger) return;
        if (document.activeElement === activeTooltipTrigger || eventInsidePriceTooltip(document.activeElement)) return;
        hidePriceTooltip(activeTooltipTrigger);
    });
    window.addEventListener("resize", () => {
        hidePriceTooltip();
        updateTimelineScrollButtons();
    });
    window.addEventListener("scroll", (event) => {
        if (isProgrammaticTimelineScroll && event.target === elements.scroll) return;
        if (eventInsidePriceTooltip(event.target)) return;
        hidePriceTooltip();
    }, true);
    document.addEventListener("touchmove", (event) => {
        if (eventInsidePriceTooltip(event.target)) return;
        hidePriceTooltip();
    }, {
        capture: true,
        passive: true
    });
    window.visualViewport?.addEventListener("scroll", () => {
        if (priceTooltip.hidden) return;
        hidePriceTooltip();
    }, { passive: true });
    elements.timeline.addEventListener("click", (event) => {
        if (event.target instanceof Element && !event.target.closest(".app-price-hour")) {
            setSelectedHour(null);
        }
    });
    document.addEventListener("pointerdown", (event) => {
        if (!activeTooltipTrigger?.matches(".app-price-kpi, .app-price-hour__action")) return;
        if (activeTooltipTrigger.contains(event.target) || eventInsidePriceTooltip(event.target)) return;
        hidePriceTooltip(activeTooltipTrigger);
    });
    document.addEventListener("keydown", (event) => {
        if (event.key !== "Escape") return;
        if (activeTooltipTrigger?.matches(".app-price-kpi, .app-price-hour__action")) {
            const trigger = activeTooltipTrigger;
            hidePriceTooltip(trigger);
            trigger.focus({ preventScroll: true });
            return;
        }
        if (event.target instanceof Element && event.target.closest("#app-schedule-edit-dialog")) return;
        setSelectedHour(null);
    });

    if (!isSimulation) {
        const scheduleDisplayRefreshMs = Math.max(60000, Number(config.scheduleDisplayRefreshMs) || 300000);
        window.setInterval(refreshSchedules, scheduleDisplayRefreshMs);
    }
    load();
})();
