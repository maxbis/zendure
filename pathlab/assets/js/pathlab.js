(function () {
    'use strict';

    const constants = window.PATHLAB_CONSTANTS || {};
    const boot = window.PATHLAB_BOOT || {};
    const apiUrl = constants.apiUrl || 'api/path_data.php';
    const calculationLookbackDays = normalizePositiveInteger(constants.calculationLookbackDays, 7);
    const graphDays = normalizePositiveInteger(constants.graphDays, 2);
    const chartConfig = constants.chart || {};
    const chartMargin = chartConfig.margin || {};

    const statusEl = document.querySelector('[data-role="chart-status"]');
    const chartWrapEl = document.querySelector('[data-role="chart-wrap"]');
    const chartEl = document.querySelector('[data-role="chart"]');
    const rootStyles = window.getComputedStyle(document.documentElement);

    function cssColor(variableName, fallback) {
        const value = rootStyles.getPropertyValue(variableName).trim();
        return value || fallback;
    }

    const palette = {
        path: cssColor('--accent-path', (constants.palette && constants.palette.path) || '#9ce365'),
        actual: cssColor('--accent-actual', (constants.palette && constants.palette.actual) || '#ffd166'),
        solar: cssColor('--accent-solar', (constants.palette && constants.palette.solar) || 'rgba(253, 214, 88, 0.26)'),
        usage: cssColor('--accent-usage', (constants.palette && constants.palette.usage) || 'rgba(233, 117, 96, 0.22)')
    };

    function setText(role, value) {
        const el = document.querySelector(`[data-role="${role}"]`);
        if (el) {
            el.textContent = value;
        }
    }

    function setBadge(status, delta) {
        const el = document.querySelector('[data-role="status-badge"]');
        if (!el) return;

        el.classList.remove('is-ahead', 'is-behind');
        if (status === 'ahead') {
            el.classList.add('is-ahead');
            el.textContent = `Ahead ${formatSignedPercent(delta)}`;
            return;
        }
        if (status === 'behind') {
            el.classList.add('is-behind');
            el.textContent = `Behind ${formatSignedPercent(delta)}`;
            return;
        }
        el.textContent = `On path ${formatSignedPercent(delta)}`;
    }

    function setStatus(message) {
        if (statusEl) {
            statusEl.textContent = message;
        }
    }

    function formatPercent(value) {
        if (!Number.isFinite(value)) return '--';
        return `${value.toFixed(1)}%`;
    }

    function formatSignedPercent(value) {
        if (!Number.isFinite(value)) return '--';
        const prefix = value > 0 ? '+' : '';
        return `${prefix}${value.toFixed(1)}%`;
    }

    function formatTimeLabel(timestamp) {
        const date = new Date(timestamp);
        if (Number.isNaN(date.getTime())) return '';
        return date.toLocaleString('en-GB', { weekday: 'short', hour: '2-digit', minute: '2-digit' });
    }

    function normalizePositiveInteger(value, fallback) {
        const parsed = Number.parseInt(value, 10);
        if (!Number.isFinite(parsed) || parsed <= 0) {
            return fallback;
        }
        return parsed;
    }

    function buildApiUrl() {
        const url = new URL(apiUrl, window.location.href);
        url.searchParams.set('lookback_days', String(calculationLookbackDays));
        url.searchParams.set('graph_days', String(graphDays));
        return url.toString();
    }

    function scaleY(value, minSoc, maxSoc, top, plotHeight) {
        const clamped = Math.max(minSoc, Math.min(maxSoc, value));
        const range = Math.max(1, maxSoc - minSoc);
        const normalized = (clamped - minSoc) / range;
        return top + plotHeight - (normalized * plotHeight);
    }

    function escapeXml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function buildExpectedPoints(slots, minSoc, maxSoc, margin, plotWidth, plotHeight) {
        const points = [];
        slots.forEach((slot, index) => {
            const x = margin.left + (index / Math.max(1, slots.length - 1)) * plotWidth;
            const y = scaleY(Number(slot.start_soc), minSoc, maxSoc, margin.top, plotHeight);
            points.push({ x, y });
            if (index === slots.length - 1) {
                const endX = margin.left + plotWidth;
                const endY = scaleY(Number(slot.end_soc), minSoc, maxSoc, margin.top, plotHeight);
                points.push({ x: endX, y: endY });
            }
        });
        return points;
    }

    function buildActualSegments(actualSlots, timestampToX, minSoc, maxSoc, margin, plotHeight) {
        const segments = [];
        let currentSegment = [];
        let previousTimestamp = null;

        actualSlots.forEach((slot) => {
            const timestamp = String(slot.timestamp || '');
            const soc = Number(slot.soc);
            if (!timestamp || !Number.isFinite(soc) || !Object.prototype.hasOwnProperty.call(timestampToX, timestamp)) {
                return;
            }

            const timestampMs = Date.parse(timestamp);
            const point = {
                x: timestampToX[timestamp],
                y: scaleY(soc, minSoc, maxSoc, margin.top, plotHeight),
                timestampMs
            };

            const shouldBreak = previousTimestamp !== null && Number.isFinite(timestampMs)
                && Number.isFinite(previousTimestamp)
                && (timestampMs - previousTimestamp) > 5400000;

            if (shouldBreak && currentSegment.length > 1) {
                segments.push(currentSegment);
                currentSegment = [];
            } else if (shouldBreak) {
                currentSegment = [];
            }

            currentSegment.push(point);
            previousTimestamp = timestampMs;
        });

        if (currentSegment.length > 1) {
            segments.push(currentSegment);
        }

        return segments;
    }

    function pointsToPolyline(points) {
        return points.map((point) => `${point.x.toFixed(2)},${point.y.toFixed(2)}`).join(' ');
    }

    function buildChart(payload) {
        const slots = Array.isArray(payload.slots) ? payload.slots : [];
        if (slots.length === 0 || !chartEl) {
            setStatus('No path data available.');
            return;
        }

        const actualSlots = Array.isArray(payload.actualPath && payload.actualPath.slots)
            ? payload.actualPath.slots
            : [];
        const summary = payload.summary || {};
        const config = payload.config || {};
        const minSoc = Number.isFinite(config.minChargeLevel) ? config.minChargeLevel : 0;
        const maxSoc = Number.isFinite(config.maxChargeLevel) ? config.maxChargeLevel : 100;
        const generatedAt = payload.generatedAt ? new Date(payload.generatedAt) : new Date();
        const nowMs = generatedAt.getTime();

        const width = Number(chartConfig.width) || 1200;
        const height = Number(chartConfig.height) || 420;
        const margin = {
            top: Number(chartMargin.top) || 20,
            right: Number(chartMargin.right) || 18,
            bottom: Number(chartMargin.bottom) || 70,
            left: Number(chartMargin.left) || 52
        };
        const plotWidth = width - margin.left - margin.right;
        const plotHeight = height - margin.top - margin.bottom;

        const points = buildExpectedPoints(slots, minSoc, maxSoc, margin, plotWidth, plotHeight);
        const pathLine = pointsToPolyline(points);

        const timestampToX = {};
        slots.forEach((slot, index) => {
            timestampToX[String(slot.timestamp)] = margin.left + (index / Math.max(1, slots.length - 1)) * plotWidth;
        });

        const actualSegments = buildActualSegments(actualSlots, timestampToX, minSoc, maxSoc, margin, plotHeight);
        const actualLines = actualSegments.map((segment) => pointsToPolyline(segment));

        const nowIndex = slots.findIndex((slot) => {
            const start = Date.parse(slot.timestamp);
            const end = start + 3600000;
            return nowMs >= start && nowMs < end;
        });

        let nowX = margin.left;
        if (nowIndex >= 0) {
            const slotStart = Date.parse(slots[nowIndex].timestamp);
            const fraction = Math.max(0, Math.min(1, (nowMs - slotStart) / 3600000));
            const step = plotWidth / Math.max(1, slots.length - 1);
            nowX = margin.left + (nowIndex * step) + (fraction * step);
        }

        const actualY = scaleY(Number(summary.currentSoc), minSoc, maxSoc, margin.top, plotHeight);
        const expectedY = scaleY(Number(summary.expectedSocNow), minSoc, maxSoc, margin.top, plotHeight);

        const yTicks = [];
        for (let tick = 0; tick <= 5; tick += 1) {
            const value = minSoc + ((maxSoc - minSoc) * (tick / 5));
            const y = scaleY(value, minSoc, maxSoc, margin.top, plotHeight);
            yTicks.push({ value, y });
        }

        const xTicks = [];
        slots.forEach((slot, index) => {
            const date = new Date(slot.timestamp);
            const hour = date.getHours();
            if (hour === 0 || hour === 12) {
                xTicks.push({
                    x: margin.left + (index / Math.max(1, slots.length - 1)) * plotWidth,
                    label: hour === 0
                        ? date.toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit' })
                        : '12:00'
                });
            }
        });

        const underlayWidth = plotWidth / Math.max(1, slots.length);
        const solarBars = slots.map((slot, index) => {
            const value = Number(slot.solar_score) || 0;
            const x = margin.left + index * underlayWidth;
            const barHeight = Math.max(0, value * plotHeight * 0.35);
            return `<rect x="${x.toFixed(2)}" y="${(margin.top + plotHeight - barHeight).toFixed(2)}" width="${Math.max(2, underlayWidth - 1).toFixed(2)}" height="${barHeight.toFixed(2)}" fill="${palette.solar}"></rect>`;
        }).join('');

        const usageBars = slots.map((slot, index) => {
            const value = Number(slot.usage_discharge_pct) || 0;
            const normalized = Math.min(1, value / 8);
            const x = margin.left + index * underlayWidth;
            const barHeight = Math.max(0, normalized * plotHeight * 0.22);
            return `<rect x="${x.toFixed(2)}" y="${(margin.top + plotHeight - barHeight).toFixed(2)}" width="${Math.max(2, underlayWidth - 1).toFixed(2)}" height="${barHeight.toFixed(2)}" fill="${palette.usage}"></rect>`;
        }).join('');

        chartEl.innerHTML = `
            <defs>
                <linearGradient id="pathlabPathGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                    <stop offset="0%" stop-color="#83dd73"></stop>
                    <stop offset="100%" stop-color="#d8ff8b"></stop>
                </linearGradient>
            </defs>
            <rect x="0" y="0" width="${width}" height="${height}" fill="transparent"></rect>
            ${yTicks.map((tick) => `
                <line x1="${margin.left}" y1="${tick.y.toFixed(2)}" x2="${width - margin.right}" y2="${tick.y.toFixed(2)}" stroke="rgba(255,255,255,0.08)" stroke-width="1"></line>
                <text x="${margin.left - 10}" y="${(tick.y + 4).toFixed(2)}" text-anchor="end" fill="rgba(232,240,230,0.72)" font-size="12">${tick.value.toFixed(0)}%</text>
            `).join('')}
            ${solarBars}
            ${usageBars}
            <line x1="${margin.left}" y1="${margin.top + plotHeight}" x2="${width - margin.right}" y2="${margin.top + plotHeight}" stroke="rgba(255,255,255,0.12)" stroke-width="1"></line>
            <polyline points="${pathLine}" fill="none" stroke="url(#pathlabPathGradient)" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"></polyline>
            ${actualLines.map((line) => `<polyline points="${line}" fill="none" stroke="${palette.actual}" stroke-width="1" stroke-dasharray="7 5" stroke-linecap="round" stroke-linejoin="round"></polyline>`).join('')}
            <line x1="${nowX.toFixed(2)}" y1="${margin.top}" x2="${nowX.toFixed(2)}" y2="${(margin.top + plotHeight).toFixed(2)}" stroke="rgba(255,209,102,0.82)" stroke-width="2" stroke-dasharray="7 6"></line>
            <circle cx="${nowX.toFixed(2)}" cy="${actualY.toFixed(2)}" r="7" fill="${palette.actual}" stroke="#08131a" stroke-width="3"></circle>
            <circle cx="${nowX.toFixed(2)}" cy="${expectedY.toFixed(2)}" r="5" fill="${palette.path}" stroke="#08131a" stroke-width="2"></circle>
            <text x="${Math.min(width - margin.right - 40, nowX + 8).toFixed(2)}" y="${(margin.top + 14).toFixed(2)}" fill="rgba(255,209,102,0.9)" font-size="12" font-weight="700">Now</text>
            ${xTicks.map((tick) => `
                <line x1="${tick.x.toFixed(2)}" y1="${(margin.top + plotHeight).toFixed(2)}" x2="${tick.x.toFixed(2)}" y2="${(margin.top + plotHeight + 8).toFixed(2)}" stroke="rgba(255,255,255,0.14)" stroke-width="1"></line>
                <text x="${tick.x.toFixed(2)}" y="${(height - 18).toFixed(2)}" text-anchor="middle" fill="rgba(232,240,230,0.72)" font-size="12">${escapeXml(tick.label)}</text>
            `).join('')}
        `;

        if (chartWrapEl) {
            chartWrapEl.hidden = false;
        }

        const warnings = Array.isArray(payload.warnings) ? payload.warnings : [];
        const message = warnings.length > 0
            ? `Partial data: ${warnings.join(' ')}`
            : `Path generated ${formatTimeLabel(payload.generatedAt)}.`;
        setStatus(message);
    }

    function render(payload) {
        const summary = payload.summary || {};
        const config = payload.config || {};

        setText('current-soc', formatPercent(Number(summary.currentSoc)));
        setText('expected-soc', formatPercent(Number(summary.expectedSocNow)));
        setText('delta-soc', formatSignedPercent(Number(summary.deltaSocNow)));
        const targetLookbackDays = Number.isFinite(Number(config.targetLookbackDays)) ? Number(config.targetLookbackDays) : 0;
        const validLookbackDaysUsed = Number.isFinite(Number(config.validLookbackDaysUsed))
            ? Number(config.validLookbackDaysUsed)
            : (Number.isFinite(Number(config.effectiveLookbackDays)) ? Number(config.effectiveLookbackDays) : 0);
        setText('lookback-days', `${targetLookbackDays} day(s)`);
        setText('valid-lookback-days', `Valid days used: ${validLookbackDaysUsed}`);
        setText('effective-lookback', `Using ${validLookbackDaysUsed} valid day(s) from ${targetLookbackDays}`);
        setText('solar-peak', Number.isFinite(Number(summary.solarPeak)) ? `${Number(summary.solarPeak).toFixed(0)} W/m2` : '--');
        setText('anchor-soc', formatPercent(Number(summary.anchorSoc)));

        const generatedAt = payload.generatedAt ? new Date(payload.generatedAt) : null;
        if (generatedAt && !Number.isNaN(generatedAt.getTime())) {
            setText('current-time', `Live at ${generatedAt.toLocaleString('en-GB', { weekday: 'short', hour: '2-digit', minute: '2-digit' })}`);
        }

        setBadge(String(summary.status || 'on-path'), Number(summary.deltaSocNow));
        buildChart(payload);
    }

    async function init() {
        try {
            const response = await fetch(buildApiUrl(), { method: 'GET', cache: 'no-store' });
            const payload = await response.json();
            if (!response.ok || !payload || payload.success !== true) {
                throw new Error(payload && payload.error ? payload.error : 'Failed to load PathLab data.');
            }
            render(payload);
        } catch (error) {
            setStatus(error && error.message ? error.message : 'Failed to load PathLab data.');
        }
    }

    init();
})();
