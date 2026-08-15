(function () {
    "use strict";

    const DISPLAY_MAX_AGE_MS = 4 * 60 * 60 * 1000;
    const SVG_HEIGHT = 300;
    const MARGIN = Object.freeze({ top: 42, right: 22, bottom: 58, left: 46 });

    function finiteNumber(value) {
        const number = Number(value);
        return Number.isFinite(number) ? number : null;
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    function dateParts(timestamp) {
        const match = String(timestamp).match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
        if (!match) return null;
        return {
            key: `${match[1]}-${match[2]}-${match[3]}`,
            dateLabel: `${match[3]}-${match[2]}`,
            hour: Number(match[4]),
            minute: Number(match[5]),
            date: new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]), 12)
        };
    }

    function normalizePayload(payload) {
        const times = payload?.hourly?.time;
        const rawValues = payload?.hourly?.shortwave_radiation;
        if (!Array.isArray(times) || !Array.isArray(rawValues) || times.length === 0 || times.length !== rawValues.length) {
            throw new Error("Shortwave radiation data is unavailable.");
        }

        const values = rawValues.map(finiteNumber);
        if (values.some((value) => value === null)) {
            throw new Error("Shortwave radiation data contains an invalid value.");
        }

        const parsedTimes = times.map(dateParts);
        if (parsedTimes.some((value) => value === null)) {
            throw new Error("Shortwave radiation data contains an invalid timestamp.");
        }

        const dailyTotals = new Map();
        parsedTimes.forEach((parts, index) => {
            dailyTotals.set(parts.key, (dailyTotals.get(parts.key) || 0) + values[index]);
        });

        const days = [];
        dailyTotals.forEach((total, key) => {
            const first = parsedTimes.find((parts) => parts.key === key);
            days.push({
                key,
                dateLabel: first.dateLabel,
                weekday: first.date.toLocaleDateString([], { weekday: "short" }),
                total: Math.round(total)
            });
        });

        return {
            payload,
            times: parsedTimes,
            values,
            days,
            hourlyUnit: String(payload?.hourly_units?.shortwave_radiation || "W/m²"),
            dailyUnit: String(payload?.unit || "Wh/m²")
        };
    }

    function niceScaleMaximum(maximum) {
        const safeMaximum = Math.max(1, maximum);
        const magnitude = 10 ** Math.floor(Math.log10(safeMaximum));
        const normalized = safeMaximum / magnitude;
        const nice = normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10;
        return Math.max(50, nice * magnitude);
    }

    function buildChartModel(normalized, viewportWidth) {
        const visibleWidth = Math.max(300, Math.floor(viewportWidth || 300));
        const dayWidth = Math.max(150, Math.min(210, visibleWidth / 3.4));
        const width = Math.max(visibleWidth, Math.round(MARGIN.left + MARGIN.right + normalized.days.length * dayWidth));
        const plotWidth = width - MARGIN.left - MARGIN.right;
        const plotHeight = SVG_HEIGHT - MARGIN.top - MARGIN.bottom;
        const maximum = niceScaleMaximum(Math.max(...normalized.values));
        const dayIndex = new Map(normalized.days.map((day, index) => [day.key, index]));

        const points = normalized.values.map((value, index) => {
            const time = normalized.times[index];
            const hourFraction = (time.hour + time.minute / 60) / 24;
            const x = MARGIN.left + ((dayIndex.get(time.key) + hourFraction) / normalized.days.length) * plotWidth;
            const y = MARGIN.top + plotHeight - (value / maximum) * plotHeight;
            return { x, y };
        });

        const yTicks = Array.from({ length: 6 }, (_, index) => ({
            value: Math.round((maximum / 5) * index),
            y: MARGIN.top + plotHeight - (index / 5) * plotHeight
        }));

        return {
            width,
            height: SVG_HEIGHT,
            plotWidth,
            plotHeight,
            bottom: MARGIN.top + plotHeight,
            points,
            yTicks,
            days: normalized.days,
            hourlyUnit: normalized.hourlyUnit,
            dailyUnit: normalized.dailyUnit
        };
    }

    function renderChart(model) {
        const dayCount = Math.max(1, model.days.length);
        const xForDayHour = (dayIndex, hour) => MARGIN.left + ((dayIndex + hour / 24) / dayCount) * model.plotWidth;
        const linePoints = model.points.map((point) => `${point.x.toFixed(2)},${point.y.toFixed(2)}`).join(" ");
        const areaPoints = [
            `${MARGIN.left},${model.bottom}`,
            ...model.points.map((point) => `${point.x.toFixed(2)},${point.y.toFixed(2)}`),
            `${model.width - MARGIN.right},${model.bottom}`
        ].join(" ");

        const horizontalGrid = model.yTicks.map((tick) => (
            `<line x1="${MARGIN.left}" y1="${tick.y}" x2="${model.width - MARGIN.right}" y2="${tick.y}" class="app-shortwave-chart__grid"></line>` +
            `<text x="${MARGIN.left - 8}" y="${tick.y + 4}" text-anchor="end" class="app-shortwave-chart__axis-label">${tick.value}</text>`
        )).join("");

        const dayRegions = model.days.map((day, dayIndex) => {
            const startX = xForDayHour(dayIndex, 0);
            const centerX = xForDayHour(dayIndex, 12);
            const separator = dayIndex === 0 ? "" : `<line x1="${startX}" y1="${MARGIN.top}" x2="${startX}" y2="${model.bottom}" class="app-shortwave-chart__day-separator"></line>`;
            const guides = [6, 12, 18].map((hour) => {
                const x = xForDayHour(dayIndex, hour);
                return `<line x1="${x}" y1="${MARGIN.top}" x2="${x}" y2="${model.bottom}" class="app-shortwave-chart__hour-guide"></line>`;
            }).join("");
            const ticks = [0, 6, 12, 18].map((hour) => {
                const x = xForDayHour(dayIndex, hour);
                const label = hour === 0 ? day.dateLabel : `${String(hour).padStart(2, "0")}:00`;
                const tone = hour === 0 ? " app-shortwave-chart__x-label--date" : "";
                return `<text x="${x}" y="${model.bottom + 26}" class="app-shortwave-chart__x-label${tone}" transform="rotate(-38 ${x} ${model.bottom + 26})">${escapeHtml(label)}</text>`;
            }).join("");
            const heading = `${day.weekday} ${day.total} ${model.dailyUnit}`;
            return `${separator}${guides}${ticks}<text x="${centerX}" y="${MARGIN.top - 17}" text-anchor="middle" class="app-shortwave-chart__day-label">${escapeHtml(heading)}</text>`;
        }).join("");

        return [
            `<svg viewBox="0 0 ${model.width} ${model.height}" width="${model.width}" height="${model.height}" role="img" aria-labelledby="app-shortwave-chart-title app-shortwave-chart-description">`,
            '<title id="app-shortwave-chart-title">Hourly shortwave radiation forecast</title>',
            '<desc id="app-shortwave-chart-description">Area chart of hourly radiation in watts per square metre with daily energy totals.</desc>',
            horizontalGrid,
            `<line x1="${MARGIN.left}" y1="${MARGIN.top}" x2="${MARGIN.left}" y2="${model.bottom}" class="app-shortwave-chart__axis"></line>`,
            `<line x1="${MARGIN.left}" y1="${model.bottom}" x2="${model.width - MARGIN.right}" y2="${model.bottom}" class="app-shortwave-chart__axis"></line>`,
            `<polygon points="${areaPoints}" class="app-shortwave-chart__area"></polygon>`,
            dayRegions,
            `<polyline points="${linePoints}" class="app-shortwave-chart__line"></polyline>`,
            `<text x="12" y="${MARGIN.top - 18}" class="app-shortwave-chart__unit">${escapeHtml(model.hourlyUnit)}</text>`,
            "</svg>"
        ].join("");
    }

    window.GraphiteShortwaveRadiation = Object.freeze({
        normalizePayload,
        buildChartModel,
        renderChart
    });

    const config = Object.freeze({
        shortwaveRadiationUrl: "../main/api/shortwave_radiation_api.php",
        ...(window.GRAPHITE_APP_CONFIG || {})
    });
    const dialog = document.querySelector('[data-component="shortwave-radiation"]');
    const trigger = document.querySelector('[data-gsd-dialog-target="app-shortwave-radiation-dialog"]');
    if (!(dialog instanceof HTMLDialogElement) || !(trigger instanceof HTMLButtonElement)) return;

    const elements = {
        loading: dialog.querySelector('[data-role="shortwave-loading"]'),
        error: dialog.querySelector('[data-role="shortwave-error"]'),
        errorMessage: dialog.querySelector('[data-role="shortwave-error-message"]'),
        retry: dialog.querySelector('[data-role="shortwave-retry"]'),
        content: dialog.querySelector('[data-role="shortwave-content"]'),
        viewport: dialog.querySelector('[data-role="shortwave-viewport"]'),
        chart: dialog.querySelector('[data-role="shortwave-chart"]'),
        meta: dialog.querySelector('[data-role="shortwave-meta"]'),
        summary: dialog.querySelector('[data-role="shortwave-summary"]'),
        refresh: dialog.querySelector('[data-role="shortwave-refresh"]')
    };

    let latestPayload = null;
    let latestNormalized = null;
    let activeRequest = null;
    let resizeFrame = 0;

    function cachedAtMs(payload) {
        const seconds = finiteNumber(payload?.cachedAt);
        return seconds !== null && seconds > 0 ? seconds * 1000 : null;
    }

    function payloadIsFresh() {
        const timestamp = cachedAtMs(latestPayload);
        return timestamp !== null && Date.now() - timestamp < DISPLAY_MAX_AGE_MS;
    }

    function setBusy(busy) {
        dialog.setAttribute("aria-busy", busy ? "true" : "false");
        elements.refresh.disabled = busy;
        elements.refresh.classList.toggle("is-loading", busy);
        elements.loading.hidden = !busy || latestPayload !== null;
        if (busy && latestPayload === null) {
            elements.error.hidden = true;
            elements.content.hidden = true;
        }
    }

    function showError(error) {
        elements.errorMessage.textContent = error?.message || "The forecast could not be loaded.";
        elements.error.hidden = false;
        if (latestPayload === null) elements.content.hidden = true;
    }

    function updateMeta(payload) {
        const timestamp = cachedAtMs(payload);
        const updated = timestamp === null
            ? "Update time unavailable"
            : `Updated ${new Date(timestamp).toLocaleString([], { dateStyle: "medium", timeStyle: "short" })}`;
        const timezone = payload?.timezone ? ` · ${payload.timezone}` : "";
        elements.meta.textContent = `${updated}${timezone}`;
    }

    function render() {
        if (!latestNormalized) return;
        const model = buildChartModel(latestNormalized, elements.viewport.clientWidth);
        elements.chart.innerHTML = renderChart(model);
        elements.chart.style.width = `${model.width}px`;
        elements.summary.textContent = model.days
            .map((day) => `${day.weekday} ${day.dateLabel}: ${day.total} ${model.dailyUnit}`)
            .join(". ");
        updateMeta(latestPayload);
        elements.error.hidden = true;
        elements.content.hidden = false;
    }

    function load(options = {}) {
        if (activeRequest) return activeRequest;
        if (!options.force && latestPayload !== null && payloadIsFresh()) {
            render();
            return Promise.resolve(latestPayload);
        }

        setBusy(true);
        activeRequest = fetch(config.shortwaveRadiationUrl, {
            method: "GET",
            headers: { Accept: "application/json" }
        })
            .then(async (response) => {
                let payload;
                try {
                    payload = await response.json();
                } catch (error) {
                    throw new Error("The shortwave radiation response is not valid JSON.");
                }
                if (!response.ok || payload?.success !== true) {
                    throw new Error(payload?.error || "Failed to load shortwave radiation.");
                }
                latestNormalized = normalizePayload(payload);
                latestPayload = payload;
                render();
                return payload;
            })
            .catch((error) => {
                showError(error);
                return null;
            })
            .finally(() => {
                activeRequest = null;
                setBusy(false);
            });
        return activeRequest;
    }

    function openDialog() {
        const menu = trigger.closest("[data-gsd-footer-more]");
        const returnTarget = menu?.querySelector('[data-role="gsd-footer-more-toggle"]') || trigger;
        if (menu && window.GraphiteFooterMore) window.GraphiteFooterMore.close(menu);
        window.GraphiteDialog.open(dialog, { trigger: returnTarget });
        load();
    }

    trigger.addEventListener("click", openDialog);
    elements.retry.addEventListener("click", () => load({ force: true }));
    elements.refresh.addEventListener("click", () => load({ force: true }));
    elements.viewport.addEventListener("keydown", (event) => {
        if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") return;
        event.preventDefault();
        const direction = event.key === "ArrowLeft" ? -1 : 1;
        elements.viewport.scrollBy({
            left: direction * Math.max(120, elements.viewport.clientWidth * 0.7),
            behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth"
        });
    });
    window.addEventListener("resize", () => {
        if (!dialog.open || !latestNormalized) return;
        if (resizeFrame) cancelAnimationFrame(resizeFrame);
        resizeFrame = requestAnimationFrame(() => {
            resizeFrame = 0;
            render();
        });
    });
})();
