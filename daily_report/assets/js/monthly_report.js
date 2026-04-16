(function () {
    'use strict';

    const boot = window.MONTHLY_REPORT_BOOT || {};
    const apiUrl = boot.apiUrl || 'api/monthly_report_data.php';
    const rootStyles = window.getComputedStyle(document.documentElement);

    const chartEl = document.querySelector('[data-role="chart"]');
    const chartWrapEl = document.querySelector('[data-role="chart-wrap"]');
    const chartStatusEl = document.querySelector('[data-role="chart-status"]');
    const tableBodyEl = document.querySelector('[data-role="monthly-table-body"]');
    const monthFormEl = document.querySelector('[data-role="month-form"]');
    const monthInputEl = document.querySelector('#report-month');
    const prevMonthEl = document.querySelector('[data-role="prev-month"]');
    const nextMonthEl = document.querySelector('[data-role="next-month"]');

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
    };

    function setText(role, value) {
        const el = document.querySelector(`[data-role="${role}"]`);
        if (el) el.textContent = value;
    }

    function setStatus(message) {
        if (chartStatusEl) chartStatusEl.textContent = message;
    }

    function toFiniteNumber(value) {
        if (typeof value === 'number' && Number.isFinite(value)) return value;
        if (typeof value === 'string' && value.trim() !== '') {
            const parsed = Number(value);
            return Number.isFinite(parsed) ? parsed : null;
        }
        return null;
    }

    function formatWh(value) {
        if (!Number.isFinite(value)) return '--';
        return `${value.toFixed(2)} Wh`;
    }

    function formatKwhFromWh(value) {
        if (!Number.isFinite(value)) return '--';
        return `${(value / 1000).toFixed(2)} kWh`;
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

    function shiftMonth(monthString, delta) {
        const [yearText, monthText] = String(monthString || '').split('-');
        const year = Number(yearText);
        const month = Number(monthText);
        if (!Number.isInteger(year) || !Number.isInteger(month)) return monthString;

        const date = new Date(Date.UTC(year, month - 1, 1));
        date.setUTCMonth(date.getUTCMonth() + delta);
        return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
    }

    function updateQueryMonth(month) {
        const url = new URL(window.location.href);
        url.searchParams.set('month', month);
        window.history.replaceState({}, '', url.toString());
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

    function clamp(value, min, max) {
        return Math.min(Math.max(value, min), max);
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

    function renderSummary(payload) {
        const report = payload.report || {};
        const totals = report.totals || {};
        const battery = report.battery || {};
        const batteryRange = toFiniteNumber(battery.range_pct);
        const batteryStart = toFiniteNumber(battery.start_pct);
        const batteryEnd = toFiniteNumber(battery.end_pct);
        const batteryMin = toFiniteNumber(battery.min_pct);
        const batteryMax = toFiniteNumber(battery.max_pct);
        const netCost = toFiniteNumber(totals.net_cost);
        const spotNetCost = toFiniteNumber(totals.spot_net_cost_eur);
        const savings = toFiniteNumber(totals.savings_eur);
        const chargeCost = toFiniteNumber(totals.charge_cost_eur);
        const spotChargeCost = toFiniteNumber(totals.spot_charge_cost_eur);
        const pnl = toFiniteNumber(totals.pnl_eur);
        const spotPnl = toFiniteNumber(totals.spot_pnl_eur);

        setText('charged-total', formatKwhFromWh(Number(totals.charged_wh)));
        setText('discharged-total', formatKwhFromWh(Number(totals.discharged_wh)));
        setText('battery-delta-total', formatPercent(batteryRange));
        setText(
            'battery-delta-range',
            Number.isFinite(batteryStart) || Number.isFinite(batteryEnd)
                ? `Start ${formatPercentNeutral(batteryStart)} / End ${formatPercentNeutral(batteryEnd)}`
                : '--'
        );
        setText(
            'battery-delta-extrema',
            Number.isFinite(batteryMin) || Number.isFinite(batteryMax)
                ? `Min ${formatPercentNeutral(batteryMin)} / Max ${formatPercentNeutral(batteryMax)}`
                : 'Interpolated month delta'
        );
        setText('grid-from-total', formatKwhFromWh(Number(totals.grid_from_wh)));
        setText('grid-to-total', formatKwhFromWh(Number(totals.grid_to_wh)));
        setText('net-cost-total', formatEur(netCost));
        setText('net-cost-spot-total', Number.isFinite(spotNetCost) ? `Spot ${formatEur(spotNetCost)}` : '--');
        setText('savings-total', formatEur(savings));
        setText('charge-cost-total', formatEur(chargeCost));
        setText('charge-cost-spot-total', Number.isFinite(spotChargeCost) ? `Spot ${formatEur(spotChargeCost)}` : '--');
        setText('pnl-total', formatEur(pnl));
        setText('pnl-spot-total', Number.isFinite(spotPnl) ? `Spot ${formatEur(spotPnl)}` : '--');
        setCostBadge(netCost);

        setText('chart-title', report.month || payload.requestedMonth || 'Selected month');
        setText('month-included-days', `${report.includedDayCount || 0} day(s)`);
        setText('month-cost-coverage', `${report.costCoverageDayCount || 0}/${report.includedDayCount || 0} days`);

        setText('meta-month', report.month || payload.requestedMonth || '--');
        setText('meta-timezone', report.timezone || '--');
        setText('meta-included-days', String(report.includedDayCount ?? '--'));
        setText('meta-saved-days', String(report.savedDayCount ?? '--'));
        setText('meta-generated-days', String(report.generatedDayCount ?? '--'));
        setText('meta-missing-price-days', String(report.missingPriceDayCount ?? '--'));
        setText('meta-cost-coverage', `${report.costCoverageDayCount || 0}/${report.includedDayCount || 0} days`);
        setText('meta-partial-month', formatBool(Boolean(report.isPartialMonth)));
        setText('meta-last-included-date', report.lastIncludedDate || '--');
        setText('meta-generated-at', formatDateTime(payload.savedAt || null));
    }

    function renderTable(days) {
        if (!tableBodyEl) return;
        if (!Array.isArray(days) || days.length === 0) {
            tableBodyEl.innerHTML = '<tr><td colspan="16" class="table-placeholder">No daily rows available.</td></tr>';
            return;
        }

        tableBodyEl.innerHTML = days.map((row) => {
            const netCost = toFiniteNumber(row.net_cost);
            const spotNetCost = toFiniteNumber(row.spot_net_cost_eur);
            const pnl = toFiniteNumber(row.pnl_eur);
            const spotPnl = toFiniteNumber(row.spot_pnl_eur);
            const batteryStart = toFiniteNumber(row.battery_start_pct);
            const batteryEnd = toFiniteNumber(row.battery_end_pct);
            const batteryRange = toFiniteNumber(row.battery_range_pct);
            const netCostClass = Number.isFinite(netCost) ? (netCost >= 0 ? 'is-negative-text' : 'is-positive-text') : '';
            const pnlClass = Number.isFinite(pnl) ? (pnl >= 0 ? 'is-positive-text' : 'is-negative-text') : '';
            return `
                <tr>
                    <td>${escapeHtml(row.date || '--')}</td>
                    <td>${escapeHtml(formatWh(Number(row.charged_wh)))}</td>
                    <td>${escapeHtml(formatWh(Number(row.discharged_wh)))}</td>
                    <td>${escapeHtml(formatPercentNeutral(batteryStart))}</td>
                    <td>${escapeHtml(formatPercentNeutral(batteryEnd))}</td>
                    <td>${escapeHtml(formatPercent(batteryRange))}</td>
                    <td>${escapeHtml(formatWh(Number(row.grid_from_wh)))}</td>
                    <td>${escapeHtml(formatWh(Number(row.grid_to_wh)))}</td>
                    <td class="${netCostClass}">${escapeHtml(formatEur(netCost))}</td>
                    <td class="${netCostClass}">${escapeHtml(formatEur(spotNetCost))}</td>
                    <td>${escapeHtml(formatEur(toFiniteNumber(row.savings_eur)))}</td>
                    <td>${escapeHtml(formatEur(toFiniteNumber(row.charge_cost_eur)))}</td>
                    <td>${escapeHtml(formatEur(toFiniteNumber(row.spot_charge_cost_eur)))}</td>
                    <td class="${pnlClass}">${escapeHtml(formatEur(pnl))}</td>
                    <td class="${pnlClass}">${escapeHtml(formatEur(spotPnl))}</td>
                    <td>${row.is_partial_day ? 'Yes' : 'No'}</td>
                </tr>
            `;
        }).join('');
    }

    function buildChart(days) {
        if (!chartEl || !chartWrapEl) return;
        if (!Array.isArray(days) || days.length === 0) {
            chartWrapEl.hidden = true;
            setStatus('No monthly chart data available.');
            return;
        }

        const width = 1200;
        const height = 420;
        const margin = { top: 20, right: 90, bottom: 70, left: 76 };
        const plotWidth = width - margin.left - margin.right;
        const plotHeight = height - margin.top - margin.bottom;
        const baseline = margin.top + (plotHeight / 2);
        const slotWidth = plotWidth / Math.max(1, days.length);
        const energyValues = days.flatMap((row) => [
            Number(row.charged_wh) || 0,
            Number(row.discharged_wh) || 0,
            Number(row.grid_from_wh) || 0,
            Number(row.grid_to_wh) || 0
        ]);
        const fixedEnergyMax = Math.max(800, ...energyValues);
        let runningNetCost = 0;
        const cumulativeCostValues = days.map((row) => {
            const netCost = toFiniteNumber(row.net_cost);
            if (!Number.isFinite(netCost)) return null;
            runningNetCost += netCost;
            return runningNetCost;
        });
        const cumulativeAbsMax = cumulativeCostValues.reduce((max, value) => (
            Number.isFinite(value) ? Math.max(max, Math.abs(value)) : max
        ), 0);
        const fixedCostMax = Math.max(2, cumulativeAbsMax);

        const energyAxis = [];
        const costAxis = [];
        const sharedGuides = [];
        const costPoints = [];
        const labels = [];
        const bars = [];
        const energyScaleHeight = plotHeight / 2;
        const costZeroY = margin.top + (plotHeight / 2);
        const costScaleHeight = plotHeight / 2;

        [-1, -0.5, 0, 0.5, 1].forEach((ratio) => {
            const value = fixedEnergyMax * ratio;
            const y = baseline - (ratio * energyScaleHeight);
            energyAxis.push(`
                <line x1="${(margin.left - 5).toFixed(2)}" y1="${y.toFixed(2)}" x2="${margin.left.toFixed(2)}" y2="${y.toFixed(2)}" stroke="${palette.textMuted}" stroke-width="1"></line>
                <text x="${(margin.left - 9).toFixed(2)}" y="${(y + 4).toFixed(2)}" fill="${palette.textMuted}" font-size="10" text-anchor="end">${escapeHtml(formatAxisWh(value))}</text>
            `);
        });

        [-1, -0.5, 0, 0.5, 1].forEach((ratio) => {
            const value = fixedCostMax * ratio;
            const y = costZeroY - (ratio * costScaleHeight);
            sharedGuides.push(`<line x1="${margin.left.toFixed(2)}" y1="${y.toFixed(2)}" x2="${(margin.left + plotWidth).toFixed(2)}" y2="${y.toFixed(2)}" stroke="${palette.guide}" stroke-width="1" stroke-dasharray="4 4"></line>`);
            costAxis.push(`
                <line x1="${(margin.left + plotWidth).toFixed(2)}" y1="${y.toFixed(2)}" x2="${(margin.left + plotWidth + 5).toFixed(2)}" y2="${y.toFixed(2)}" stroke="${palette.cost}" stroke-width="1"></line>
                <text x="${(margin.left + plotWidth + 9).toFixed(2)}" y="${(y + 4).toFixed(2)}" fill="${palette.cost}" font-size="10" text-anchor="start">${escapeHtml(formatAxisEur(value))}</text>
            `);
        });

        days.forEach((row, index) => {
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

            labels.push(`<text x="${(xBase + groupWidth / 2).toFixed(2)}" y="${(height - 20).toFixed(2)}" fill="${palette.textMuted}" font-size="11" text-anchor="middle">${escapeHtml(String(row.date || '').slice(-2) || '--')}</text>`);
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
        setStatus('Daily totals and cumulative net cost view.');
    }

    async function loadReport(month) {
        setStatus('Loading month report...');
        if (chartWrapEl) chartWrapEl.hidden = true;
        if (monthInputEl) monthInputEl.value = month;
        updateQueryMonth(month);

        const url = new URL(apiUrl, window.location.href);
        url.searchParams.set('month', month);

        const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
        const payload = await response.json();
        if (!response.ok || !payload.success) {
            throw new Error(payload && payload.error ? payload.error : 'Failed to load monthly report.');
        }

        renderSummary(payload);
        renderTable(payload.report && payload.report.days ? payload.report.days : []);
        buildChart(payload.report && payload.report.days ? payload.report.days : []);
    }

    function handleError(error) {
        setStatus(error && error.message ? error.message : 'Failed to load monthly report.');
        if (tableBodyEl) {
            tableBodyEl.innerHTML = '<tr><td colspan="16" class="table-placeholder">Failed to load month report.</td></tr>';
        }
    }

    if (monthInputEl) {
        monthInputEl.addEventListener('click', function openMonthPicker() {
            if (typeof this.showPicker === 'function') {
                try {
                    this.showPicker();
                } catch {
                    /* ignore: already open or unsupported */
                }
            }
        });
    }

    if (monthFormEl) {
        monthFormEl.addEventListener('submit', (event) => {
            event.preventDefault();
            const month = monthInputEl && monthInputEl.value ? monthInputEl.value : boot.requestedMonth;
            loadReport(month).catch(handleError);
        });
    }

    if (prevMonthEl) {
        prevMonthEl.addEventListener('click', () => {
            const current = monthInputEl && monthInputEl.value ? monthInputEl.value : boot.requestedMonth;
            loadReport(shiftMonth(current, -1)).catch(handleError);
        });
    }

    if (nextMonthEl) {
        nextMonthEl.addEventListener('click', () => {
            const current = monthInputEl && monthInputEl.value ? monthInputEl.value : boot.requestedMonth;
            loadReport(shiftMonth(current, 1)).catch(handleError);
        });
    }

    loadReport(boot.requestedMonth || new Date().toISOString().slice(0, 7)).catch(handleError);
})();
