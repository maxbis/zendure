(function () {
    'use strict';

    const boot = window.DAILY_REPORT_BOOT || {};
    const apiUrl = boot.apiUrl || 'api/report_data.php';
    const rootStyles = window.getComputedStyle(document.documentElement);

    const chartEl = document.querySelector('[data-role="chart"]');
    const chartWrapEl = document.querySelector('[data-role="chart-wrap"]');
    const chartStatusEl = document.querySelector('[data-role="chart-status"]');
    const tableBodyEl = document.querySelector('[data-role="hourly-table-body"]');
    const dateFormEl = document.querySelector('[data-role="date-form"]');
    const dateInputEl = document.querySelector('#report-date');
    const prevDayEl = document.querySelector('[data-role="prev-day"]');
    const nextDayEl = document.querySelector('[data-role="next-day"]');
    const regenerateButtonEl = document.querySelector('[data-role="report-regenerate"]');

    function cssColor(name, fallback) {
        const value = rootStyles.getPropertyValue(name).trim();
        return value || fallback;
    }

    const palette = {
        charge: cssColor('--accent-charge', '#9ce365'),
        discharge: cssColor('--accent-discharge', '#e97560'),
        gridFrom: cssColor('--accent-grid-from', '#8fb8c9'),
        gridTo: cssColor('--accent-grid-to', '#ffd166'),
        cost: cssColor('--accent-cost', '#7e89ff'),
        guide: cssColor('--chart-guide', 'rgba(127, 147, 139, 0.5)'),
        textSoft: cssColor('--text-soft', '#a7bbb3'),
        textMuted: cssColor('--text-muted', '#7f938b'),
        stroke: 'rgba(255,255,255,0.1)'
    };

    function setText(role, value) {
        const el = document.querySelector(`[data-role="${role}"]`);
        if (el) el.textContent = value;
    }

    function setStatus(message) {
        if (chartStatusEl) chartStatusEl.textContent = message;
    }

    function setRegenerateButtonState(canRegenerate, isBusy) {
        if (!regenerateButtonEl) return;

        regenerateButtonEl.hidden = !canRegenerate;
        regenerateButtonEl.disabled = Boolean(isBusy);
        regenerateButtonEl.textContent = isBusy ? 'Regenerating...' : 'Regenerate';
    }

    function formatWh(value) {
        if (!Number.isFinite(value)) return '--';
        return `${value.toFixed(2)} Wh`;
    }

    function formatPercent(value) {
        if (!Number.isFinite(value)) return '--';
        const prefix = value > 0 ? '+' : '';
        return `${prefix}${value.toFixed(2)}%`;
    }

    function formatPercentNeutral(value) {
        const formatted = formatPercent(value);
        return formatted.startsWith('+') ? formatted.slice(1) : formatted;
    }

    function formatEur(value) {
        if (!Number.isFinite(value)) return '--';
        const prefix = value > 0 ? '+' : '';
        return `${prefix}EUR ${value.toFixed(4)}`;
    }

    function formatPrice(value) {
        if (!Number.isFinite(value)) return '--';
        return `EUR ${value.toFixed(4)}/kWh`;
    }

    function toFiniteNumber(value) {
        if (typeof value === 'number' && Number.isFinite(value)) return value;
        if (typeof value === 'string' && value.trim() !== '') {
            const parsed = Number(value);
            return Number.isFinite(parsed) ? parsed : null;
        }
        return null;
    }

    function deriveSpotPrice(value) {
        if (typeof convertConsumerToSpotPrice !== 'function') return null;
        return convertConsumerToSpotPrice(value);
    }

    function computePriceVariationStats(hours) {
        if (!Array.isArray(hours) || hours.length === 0) {
            return { min: null, max: null, range: null, cvPct: null, hasPrices: false };
        }

        const prices = hours
            .map((row) => toFiniteNumber(row && row.price_eur_per_kwh))
            .filter((value) => Number.isFinite(value));

        if (prices.length === 0) {
            return { min: null, max: null, range: null, cvPct: null, hasPrices: false };
        }

        const min = Math.min(...prices);
        const max = Math.max(...prices);
        const range = max - min;
        const mean = prices.reduce((total, value) => total + value, 0) / prices.length;
        const variance = prices.reduce((total, value) => total + ((value - mean) ** 2), 0) / prices.length;
        const stddev = Math.sqrt(variance);
        const cvPct = mean > 0 ? (stddev / mean) * 100 : null;

        return { min, max, range, cvPct, hasPrices: true };
    }

    function computeSpotChargeCost(hours) {
        if (!Array.isArray(hours) || hours.length === 0) return null;

        let total = 0;
        let hasAny = false;

        hours.forEach((row) => {
            const chargedWh = toFiniteNumber(row && row.charged_wh);
            const consumerPrice = toFiniteNumber(row && row.price_eur_per_kwh);
            const spotPrice = deriveSpotPrice(consumerPrice);
            if (!Number.isFinite(chargedWh) || !Number.isFinite(spotPrice)) return;

            total += (chargedWh / 1000) * spotPrice;
            hasAny = true;
        });

        return hasAny ? total : null;
    }

    function computeSpotNetCost(hours) {
        if (!Array.isArray(hours) || hours.length === 0) return null;

        let total = 0;
        let hasAny = false;

        hours.forEach((row) => {
            const gridFromWh = toFiniteNumber(row && row.grid_from_wh);
            const gridToWh = toFiniteNumber(row && row.grid_to_wh);
            const consumerPrice = toFiniteNumber(row && row.price_eur_per_kwh);
            const spotPrice = deriveSpotPrice(consumerPrice);

            const gridFromCost = Number.isFinite(gridFromWh) && Number.isFinite(consumerPrice)
                ? (gridFromWh / 1000) * consumerPrice
                : null;
            const gridToCostSpot = Number.isFinite(gridToWh) && Number.isFinite(spotPrice)
                ? -1 * ((gridToWh / 1000) * spotPrice)
                : null;

            if (!Number.isFinite(gridFromCost) && !Number.isFinite(gridToCostSpot)) return;

            total += (gridFromCost || 0) + (gridToCostSpot || 0);
            hasAny = true;
        });

        return hasAny ? total : null;
    }

    function computeBatteryStats(hours) {
        if (!Array.isArray(hours) || hours.length === 0) {
            return { start: null, end: null, min: null, max: null };
        }

        let start = null;
        let end = null;
        let min = null;
        let max = null;

        hours.forEach((row) => {
            const startValue = toFiniteNumber(row && row.battery_pct_start);
            const endValue = toFiniteNumber(row && row.battery_pct_end);

            if (start === null) {
                const fallbackStart = endValue;
                start = Number.isFinite(startValue) ? startValue : fallbackStart;
            }

            [startValue, endValue].forEach((value) => {
                if (!Number.isFinite(value)) return;
                min = min === null ? value : Math.min(min, value);
                max = max === null ? value : Math.max(max, value);
            });
        });

        for (let index = hours.length - 1; index >= 0; index -= 1) {
            const row = hours[index];
            const endValue = toFiniteNumber(row && row.battery_pct_end);
            const fallbackEnd = toFiniteNumber(row && row.battery_pct_start);
            if (Number.isFinite(endValue)) {
                end = endValue;
                break;
            }
            if (Number.isFinite(fallbackEnd)) {
                end = fallbackEnd;
                break;
            }
        }

        return { start, end, min, max };
    }

    function formatBool(value) {
        return value ? 'Yes' : 'No';
    }

    function formatDateTime(value) {
        if (!value) return '--';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return String(value);
        return date.toLocaleString('en-GB', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function updateQueryDate(date) {
        const url = new URL(window.location.href);
        url.searchParams.set('date', date);
        window.history.replaceState({}, '', url.toString());
    }

    function shiftDate(dateString, days) {
        const date = new Date(`${dateString}T00:00:00`);
        if (Number.isNaN(date.getTime())) return dateString;
        date.setDate(date.getDate() + days);
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function setCostBadge(value) {
        const el = document.querySelector('[data-role="cost-badge"]');
        if (!el) return;
        el.classList.remove('is-positive', 'is-negative');
        if (!Number.isFinite(value)) {
            el.textContent = 'No cost data';
            return;
        }
        if (value > 0) {
            el.classList.add('is-negative');
            el.textContent = `Net import ${formatEur(value)}`;
            return;
        }
        if (value < 0) {
            el.classList.add('is-positive');
            el.textContent = `Net export ${formatEur(value)}`;
            return;
        }
        el.textContent = 'Balanced EUR 0.0000';
    }

    function applyPayload(payload) {
        renderSummary(payload);
        renderTable(payload.report && payload.report.hours ? payload.report.hours : []);
        buildChart(payload.report && payload.report.hours ? payload.report.hours : []);
        setRegenerateButtonState(Boolean(payload && payload.canRegenerate), false);
    }

    function renderSummary(payload) {
        const report = payload.report || {};
        const totals = report.totals || {};
        const batteryStats = computeBatteryStats(report.hours);
        const priceVariation = computePriceVariationStats(report.hours);
        const batteryDeltaRange = Number.isFinite(batteryStats.min) && Number.isFinite(batteryStats.max)
            ? Math.abs(batteryStats.max - batteryStats.min)
            : null;
        const netCost = Number(totals.net_cost);
        const spotNetCost = computeSpotNetCost(report.hours);
        const savings = Number(totals.savings_eur);
        const chargeCost = Number(totals.charge_cost_eur);
        const spotChargeCost = computeSpotChargeCost(report.hours);
        const pnl = Number.isFinite(netCost) && Number.isFinite(savings) && Number.isFinite(chargeCost)
            ? (chargeCost - savings + netCost) * -1
            : null;
        const spotPnl = Number.isFinite(spotNetCost) && Number.isFinite(savings) && Number.isFinite(spotChargeCost)
            ? (spotChargeCost - savings + spotNetCost) * -1
            : null;

        setText('charged-total', formatWh(Number(totals.charged_wh)));
        setText('discharged-total', formatWh(Number(totals.discharged_wh)));
        setText(
            'battery-delta-total',
            Number.isFinite(batteryDeltaRange)
                ? formatPercent(batteryDeltaRange)
                : formatPercent(Number(totals.battery_pct_delta_total))
        );
        setText(
            'battery-delta-range',
            Number.isFinite(batteryStats.start) || Number.isFinite(batteryStats.end)
                ? `Start ${formatPercentNeutral(batteryStats.start)} / End ${formatPercentNeutral(batteryStats.end)}`
                : '--'
        );
        setText(
            'battery-delta-extrema',
            Number.isFinite(batteryStats.min) || Number.isFinite(batteryStats.max)
                ? `Min ${formatPercentNeutral(batteryStats.min)} / Max ${formatPercentNeutral(batteryStats.max)}`
                : 'Interpolated day delta'
        );
        setText('grid-from-total', formatWh(Number(totals.grid_from_wh)));
        setText('grid-to-total', formatWh(Number(totals.grid_to_wh)));
        setText('price-variation-total', priceVariation.hasPrices ? formatPrice(priceVariation.range) : '--');
        setText(
            'price-variation-range',
            priceVariation.hasPrices
                ? `Min ${formatPrice(priceVariation.min)} / Max ${formatPrice(priceVariation.max)}`
                : '--'
        );
        setText(
            'price-variation-indicator',
            priceVariation.hasPrices
                ? `CV ${formatPercentNeutral(priceVariation.cvPct)}`
                : 'No hourly prices'
        );
        setText('net-cost-total', formatEur(netCost));
        setText('net-cost-spot-total', Number.isFinite(spotNetCost) ? `Spot ${formatEur(spotNetCost)}` : '--');
        setText('savings-total', formatEur(savings));
        setText('charge-cost-total', formatEur(chargeCost));
        setText('charge-cost-spot-total', Number.isFinite(spotChargeCost) ? `Spot ${formatEur(spotChargeCost)}` : '--');
        setText('pnl-total', formatEur(pnl));
        setText('pnl-spot-total', Number.isFinite(spotPnl) ? `Spot ${formatEur(spotPnl)}` : '--');
        setCostBadge(netCost);

        setText('report-source', payload.source || '--');
        setText('chart-title', report.date || payload.requestedDate || 'Selected day');

        setText('meta-date', payload.requestedDate || '--');
        setText('meta-timezone', report.timezone || '--');
        setText('meta-partial', formatBool(Boolean(report.is_partial_day)));
        setText('meta-price-file', report.price_file_found ? (report.price_file_path || 'Available') : 'Missing');
        setText('meta-price-hours', Number.isFinite(Number(report.price_hours_available)) ? String(report.price_hours_available) : '--');
        setText('meta-generated-at', formatDateTime(report.generated_at || report.generatedAt || payload.savedAt || null));
        setText('meta-saved-at', formatDateTime(payload.savedAt || null));
    }

    function renderTable(hours) {
        if (!tableBodyEl) return;
        if (!Array.isArray(hours) || hours.length === 0) {
            tableBodyEl.innerHTML = '<tr><td colspan="14" class="table-placeholder">No hourly rows available.</td></tr>';
            return;
        }
        tableBodyEl.innerHTML = hours.map((row) => {
            const netCost = Number(row.net_cost);
            const costClass = Number.isFinite(netCost) ? (netCost >= 0 ? 'is-negative-text' : 'is-positive-text') : '';
            const consumerPrice = toFiniteNumber(row.price_eur_per_kwh);
            const spotPrice = deriveSpotPrice(consumerPrice);
            return `
                <tr>
                    <td>${escapeHtml(row.hour || '--')}</td>
                    <td>${escapeHtml(formatWh(Number(row.charged_wh)))}</td>
                    <td>${escapeHtml(formatWh(Number(row.discharged_wh)))}</td>
                    <td>${escapeHtml(formatPercent(Number(row.battery_pct_start)).replace('+', ''))}</td>
                    <td>${escapeHtml(formatPercent(Number(row.battery_pct_end)).replace('+', ''))}</td>
                    <td>${escapeHtml(formatPercent(Number(row.battery_pct_delta)))}</td>
                    <td>${escapeHtml(formatWh(Number(row.grid_from_wh)))}</td>
                    <td>${escapeHtml(formatWh(Number(row.grid_to_wh)))}</td>
                    <td>${escapeHtml(formatPrice(consumerPrice))}</td>
                    <td>${escapeHtml(formatPrice(spotPrice))}</td>
                    <td>${escapeHtml(formatEur(Number(row.grid_from_cost)))}</td>
                    <td>${escapeHtml(formatEur(Number(row.grid_to_cost)))}</td>
                    <td class="${costClass}">${escapeHtml(formatEur(netCost))}</td>
                    <td>${row.is_partial_hour ? 'Yes' : 'No'}</td>
                </tr>
            `;
        }).join('');
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function rect(x, y, width, height, fill) {
        return `<rect x="${x.toFixed(2)}" y="${y.toFixed(2)}" width="${width.toFixed(2)}" height="${Math.max(0, height).toFixed(2)}" fill="${fill}" rx="3"></rect>`;
    }

    function formatAxisWh(value) {
        if (!Number.isFinite(value)) return '--';
        return `${Math.round(value)} Wh`;
    }

    function formatAxisEur(value) {
        if (!Number.isFinite(value)) return '--';
        const prefix = value > 0 ? '+' : '';
        return `${prefix}${value.toFixed(2)} EUR`;
    }

    function clamp(value, min, max) {
        return Math.min(Math.max(value, min), max);
    }

    function buildChart(hours) {
        if (!chartEl || !chartWrapEl) return;
        if (!Array.isArray(hours) || hours.length === 0) {
            chartWrapEl.hidden = true;
            setStatus('No hourly chart data available.');
            return;
        }

        const width = 1200;
        const height = 420;
        const margin = { top: 20, right: 90, bottom: 70, left: 76 };
        const plotWidth = width - margin.left - margin.right;
        const plotHeight = height - margin.top - margin.bottom;
        const baseline = margin.top + (plotHeight / 2);
        const slotWidth = plotWidth / Math.max(1, hours.length);
        const fixedEnergyMax = 800;
        let runningNetCost = 0;
        const cumulativeCostValues = hours.map((row) => {
            const netCost = Number(row.net_cost);
            if (!Number.isFinite(netCost)) return null;
            runningNetCost += netCost;
            return runningNetCost;
        });
        const fixedCostMax = 2;

        const energyAxis = [];
        const costAxis = [];
        const sharedGuides = [];
        const costPoints = [];
        const labels = [];
        const bars = [];
        const energyScaleHeight = plotHeight / 2;
        const costZeroY = margin.top + (plotHeight / 2);
        const costScaleHeight = plotHeight / 2;

        [-1, -0.75, -0.5, -0.25, 0, 0.25, 0.5, 0.75, 1].forEach((ratio) => {
            const value = fixedEnergyMax * ratio;
            const y = baseline - (ratio * energyScaleHeight);
            energyAxis.push(`
                <line x1="${(margin.left - 5).toFixed(2)}" y1="${y.toFixed(2)}" x2="${margin.left.toFixed(2)}" y2="${y.toFixed(2)}" stroke="${palette.textMuted}" stroke-width="1"></line>
                <text x="${(margin.left - 9).toFixed(2)}" y="${(y + 4).toFixed(2)}" fill="${palette.textMuted}" font-size="10" text-anchor="end">${escapeHtml(formatAxisWh(value))}</text>
            `);
        });

        [-2, -1.5, -1, -0.5, 0, 0.5, 1, 1.5, 2].forEach((value) => {
            const ratio = value / fixedCostMax;
            const y = costZeroY - (ratio * costScaleHeight);
            sharedGuides.push(`<line x1="${margin.left.toFixed(2)}" y1="${y.toFixed(2)}" x2="${(margin.left + plotWidth).toFixed(2)}" y2="${y.toFixed(2)}" stroke="${palette.guide}" stroke-width="1" stroke-dasharray="4 4"></line>`);
            costAxis.push(`
                <line x1="${(margin.left + plotWidth).toFixed(2)}" y1="${y.toFixed(2)}" x2="${(margin.left + plotWidth + 5).toFixed(2)}" y2="${y.toFixed(2)}" stroke="${palette.cost}" stroke-width="1"></line>
                <text x="${(margin.left + plotWidth + 9).toFixed(2)}" y="${(y + 4).toFixed(2)}" fill="${palette.cost}" font-size="10" text-anchor="start">${escapeHtml(formatAxisEur(value))}</text>
            `);
        });

        hours.forEach((row, index) => {
            const xBase = margin.left + (index * slotWidth);
            const groupWidth = Math.max(8, slotWidth - 6);
            const barWidth = Math.max(2, groupWidth / 4 - 2);
            const charge = Number(row.charged_wh) || 0;
            const discharge = Number(row.discharged_wh) || 0;
            const gridFrom = Number(row.grid_from_wh) || 0;
            const gridTo = Number(row.grid_to_wh) || 0;
            const cumulativeNetCost = cumulativeCostValues[index];

            const chargeHeight = (clamp(charge, 0, fixedEnergyMax) / fixedEnergyMax) * energyScaleHeight;
            const dischargeHeight = (clamp(discharge, 0, fixedEnergyMax) / fixedEnergyMax) * energyScaleHeight;
            const fromHeight = (clamp(gridFrom, 0, fixedEnergyMax) / fixedEnergyMax) * energyScaleHeight;
            const toHeight = (clamp(gridTo, 0, fixedEnergyMax) / fixedEnergyMax) * energyScaleHeight;

            bars.push(rect(xBase + 0, baseline - chargeHeight, barWidth, chargeHeight, palette.charge));
            bars.push(rect(xBase + barWidth + 2, baseline, barWidth, dischargeHeight, palette.discharge));
            bars.push(rect(xBase + ((barWidth + 2) * 2), baseline - fromHeight, barWidth, fromHeight, palette.gridFrom));
            bars.push(rect(xBase + ((barWidth + 2) * 3), baseline, barWidth, toHeight, palette.gridTo));

            if (Number.isFinite(cumulativeNetCost)) {
                const costY = costZeroY - (clamp(cumulativeNetCost, -fixedCostMax, fixedCostMax) / fixedCostMax) * costScaleHeight;
                costPoints.push(`${(xBase + groupWidth / 2).toFixed(2)},${costY.toFixed(2)}`);
            }

            labels.push(`<text x="${(xBase + groupWidth / 2).toFixed(2)}" y="${(height - 20).toFixed(2)}" fill="${palette.textMuted}" font-size="11" text-anchor="middle">${escapeHtml(row.hour)}</text>`);
        });

        chartEl.innerHTML = `
            <rect x="${margin.left}" y="${margin.top}" width="${plotWidth}" height="${plotHeight}" fill="rgba(255,255,255,0.02)" rx="18"></rect>
            <line x1="${margin.left.toFixed(2)}" y1="${margin.top.toFixed(2)}" x2="${margin.left.toFixed(2)}" y2="${(margin.top + plotHeight).toFixed(2)}" stroke="${palette.textMuted}" stroke-width="1"></line>
            <line x1="${(margin.left + plotWidth).toFixed(2)}" y1="${margin.top.toFixed(2)}" x2="${(margin.left + plotWidth).toFixed(2)}" y2="${(margin.top + plotHeight).toFixed(2)}" stroke="${palette.cost}" stroke-width="1"></line>
            ${sharedGuides.join('')}
            ${energyAxis.join('')}
            ${costAxis.join('')}
            <line x1="${margin.left}" y1="${baseline.toFixed(2)}" x2="${(margin.left + plotWidth).toFixed(2)}" y2="${baseline.toFixed(2)}" stroke="${palette.textMuted}" stroke-width="1.2"></line>
            ${bars.join('')}
            ${costPoints.length > 1 ? `<polyline points="${costPoints.join(' ')}" fill="none" stroke="${palette.cost}" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"></polyline>` : ''}
            ${labels.join('')}
            <text x="${margin.left}" y="${(margin.top - 6).toFixed(2)}" fill="${palette.textSoft}" font-size="12">Above baseline: charge/import | below baseline: discharge/export</text>
            <text x="${margin.left}" y="${(height - 44).toFixed(2)}" fill="${palette.textMuted}" font-size="11" text-anchor="start">Energy scale</text>
            <text x="${(margin.left + plotWidth).toFixed(2)}" y="${(height - 44).toFixed(2)}" fill="${palette.cost}" font-size="11" text-anchor="end">Cost scale</text>
        `;
        chartWrapEl.hidden = false;
        setStatus('Hourly energy and cumulative net cost view.');
    }

    async function loadReport(date) {
        setStatus('Loading report...');
        if (chartWrapEl) chartWrapEl.hidden = true;
        if (dateInputEl) dateInputEl.value = date;
        updateQueryDate(date);
        setRegenerateButtonState(false, false);

        const url = new URL(apiUrl, window.location.href);
        url.searchParams.set('date', date);

        const response = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
        const payload = await response.json();
        if (!response.ok || !payload.success) {
            throw new Error(payload && payload.error ? payload.error : 'Failed to load daily report.');
        }

        applyPayload(payload);
    }

    async function regenerateReport(date) {
        setStatus('Regenerating report...');
        setRegenerateButtonState(true, true);

        const response = await fetch(new URL(apiUrl, window.location.href).toString(), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: new URLSearchParams({
                date,
                action: 'regenerate'
            }).toString()
        });
        const payload = await response.json();
        if (!response.ok || !payload.success) {
            setRegenerateButtonState(true, false);
            throw new Error(payload && payload.error ? payload.error : 'Failed to regenerate daily report.');
        }

        if (dateInputEl) dateInputEl.value = payload.requestedDate || date;
        updateQueryDate(payload.requestedDate || date);
        applyPayload(payload);
    }

    function handleError(error) {
        setStatus(error && error.message ? error.message : 'Failed to load daily report.');
        setRegenerateButtonState(Boolean(regenerateButtonEl && !regenerateButtonEl.hidden), false);
        if (tableBodyEl) {
            tableBodyEl.innerHTML = '<tr><td colspan="14" class="table-placeholder">Failed to load report.</td></tr>';
        }
    }

    if (dateInputEl) {
        dateInputEl.addEventListener('click', function openDatePicker() {
            if (typeof this.showPicker === 'function') {
                try {
                    this.showPicker();
                } catch {
                    /* ignore: already open or unsupported */
                }
            }
        });
    }

    if (dateFormEl) {
        dateFormEl.addEventListener('submit', (event) => {
            event.preventDefault();
            const date = dateInputEl && dateInputEl.value ? dateInputEl.value : boot.requestedDate;
            loadReport(date).catch(handleError);
        });
    }

    if (prevDayEl) {
        prevDayEl.addEventListener('click', () => {
            const current = dateInputEl && dateInputEl.value ? dateInputEl.value : boot.requestedDate;
            loadReport(shiftDate(current, -1)).catch(handleError);
        });
    }

    if (nextDayEl) {
        nextDayEl.addEventListener('click', () => {
            const current = dateInputEl && dateInputEl.value ? dateInputEl.value : boot.requestedDate;
            loadReport(shiftDate(current, 1)).catch(handleError);
        });
    }

    if (regenerateButtonEl) {
        regenerateButtonEl.addEventListener('click', () => {
            const current = dateInputEl && dateInputEl.value ? dateInputEl.value : boot.requestedDate;
            regenerateReport(current).catch(handleError);
        });
    }

    loadReport(boot.requestedDate || new Date().toISOString().slice(0, 10)).catch(handleError);
})();
