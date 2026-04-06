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

    function formatWh(value) {
        if (!Number.isFinite(value)) return '--';
        return `${value.toFixed(2)} Wh`;
    }

    function formatPercent(value) {
        if (!Number.isFinite(value)) return '--';
        const prefix = value > 0 ? '+' : '';
        return `${prefix}${value.toFixed(2)}%`;
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
        return date.toISOString().slice(0, 10);
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

    function renderSummary(payload) {
        const report = payload.report || {};
        const totals = report.totals || {};
        setText('charged-total', formatWh(Number(totals.charged_wh)));
        setText('discharged-total', formatWh(Number(totals.discharged_wh)));
        setText('battery-delta-total', formatPercent(Number(totals.battery_pct_delta_total)));
        setText('grid-from-total', formatWh(Number(totals.grid_from_wh)));
        setText('grid-to-total', formatWh(Number(totals.grid_to_wh)));
        setText('net-cost-total', formatEur(Number(totals.net_cost)));
        setCostBadge(Number(totals.net_cost));

        setText('saved-path', payload.savedPath || '--');
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
            tableBodyEl.innerHTML = '<tr><td colspan="13" class="table-placeholder">No hourly rows available.</td></tr>';
            return;
        }
        tableBodyEl.innerHTML = hours.map((row) => {
            const netCost = Number(row.net_cost);
            const costClass = Number.isFinite(netCost) ? (netCost >= 0 ? 'is-negative-text' : 'is-positive-text') : '';
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
                    <td>${escapeHtml(formatPrice(Number(row.price_eur_per_kwh)))}</td>
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

    function buildChart(hours) {
        if (!chartEl || !chartWrapEl) return;
        if (!Array.isArray(hours) || hours.length === 0) {
            chartWrapEl.hidden = true;
            setStatus('No hourly chart data available.');
            return;
        }

        const width = 1200;
        const height = 420;
        const margin = { top: 20, right: 24, bottom: 70, left: 52 };
        const plotWidth = width - margin.left - margin.right;
        const plotHeight = height - margin.top - margin.bottom;
        const baseline = margin.top + (plotHeight * 0.58);
        const slotWidth = plotWidth / Math.max(1, hours.length);

        const energyMax = Math.max(1, ...hours.flatMap((row) => [
            Number(row.charged_wh) || 0,
            Number(row.discharged_wh) || 0,
            Number(row.grid_from_wh) || 0,
            Number(row.grid_to_wh) || 0
        ]));
        const costValues = hours.map((row) => Number(row.net_cost)).filter((value) => Number.isFinite(value));
        const costMax = Math.max(0.001, ...costValues.map((value) => Math.abs(value)), 0.001);

        const lines = [];
        const costPoints = [];
        const labels = [];
        const bars = [];

        for (let i = 0; i < 5; i += 1) {
            const y = margin.top + (plotHeight * (i / 4));
            lines.push(`<line x1="${margin.left}" y1="${y.toFixed(2)}" x2="${(margin.left + plotWidth).toFixed(2)}" y2="${y.toFixed(2)}" stroke="${palette.stroke}" stroke-width="1"></line>`);
        }

        hours.forEach((row, index) => {
            const xBase = margin.left + (index * slotWidth);
            const groupWidth = Math.max(8, slotWidth - 6);
            const barWidth = Math.max(2, groupWidth / 4 - 2);
            const charge = Number(row.charged_wh) || 0;
            const discharge = Number(row.discharged_wh) || 0;
            const gridFrom = Number(row.grid_from_wh) || 0;
            const gridTo = Number(row.grid_to_wh) || 0;
            const netCost = Number(row.net_cost);

            const chargeHeight = (charge / energyMax) * (plotHeight * 0.42);
            const dischargeHeight = (discharge / energyMax) * (plotHeight * 0.42);
            const fromHeight = (gridFrom / energyMax) * (plotHeight * 0.42);
            const toHeight = (gridTo / energyMax) * (plotHeight * 0.42);

            bars.push(rect(xBase + 0, baseline - chargeHeight, barWidth, chargeHeight, palette.charge));
            bars.push(rect(xBase + barWidth + 2, baseline, barWidth, dischargeHeight, palette.discharge));
            bars.push(rect(xBase + ((barWidth + 2) * 2), baseline - fromHeight, barWidth, fromHeight, palette.gridFrom));
            bars.push(rect(xBase + ((barWidth + 2) * 3), baseline, barWidth, toHeight, palette.gridTo));

            if (Number.isFinite(netCost)) {
                const costY = margin.top + (plotHeight * 0.18) - ((netCost / costMax) * (plotHeight * 0.12));
                costPoints.push(`${(xBase + groupWidth / 2).toFixed(2)},${costY.toFixed(2)}`);
            }

            labels.push(`<text x="${(xBase + groupWidth / 2).toFixed(2)}" y="${(height - 20).toFixed(2)}" fill="${palette.textMuted}" font-size="11" text-anchor="middle">${escapeHtml(row.hour)}</text>`);
        });

        chartEl.innerHTML = `
            <rect x="${margin.left}" y="${margin.top}" width="${plotWidth}" height="${plotHeight}" fill="rgba(255,255,255,0.02)" rx="18"></rect>
            ${lines.join('')}
            <line x1="${margin.left}" y1="${baseline.toFixed(2)}" x2="${(margin.left + plotWidth).toFixed(2)}" y2="${baseline.toFixed(2)}" stroke="${palette.textMuted}" stroke-width="1.2"></line>
            ${bars.join('')}
            ${costPoints.length > 1 ? `<polyline points="${costPoints.join(' ')}" fill="none" stroke="${palette.cost}" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"></polyline>` : ''}
            ${labels.join('')}
            <text x="${margin.left}" y="${(margin.top - 6).toFixed(2)}" fill="${palette.textSoft}" font-size="12">Above baseline: charge/import | below baseline: discharge/export</text>
        `;
        chartWrapEl.hidden = false;
        setStatus('Hourly energy and net cost view.');
    }

    async function loadReport(date) {
        setStatus('Loading report...');
        if (chartWrapEl) chartWrapEl.hidden = true;
        if (dateInputEl) dateInputEl.value = date;
        updateQueryDate(date);

        const url = new URL(apiUrl, window.location.href);
        url.searchParams.set('date', date);

        const response = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
        const payload = await response.json();
        if (!response.ok || !payload.success) {
            throw new Error(payload && payload.error ? payload.error : 'Failed to load daily report.');
        }

        renderSummary(payload);
        renderTable(payload.report && payload.report.hours ? payload.report.hours : []);
        buildChart(payload.report && payload.report.hours ? payload.report.hours : []);
    }

    function handleError(error) {
        setStatus(error && error.message ? error.message : 'Failed to load daily report.');
        if (tableBodyEl) {
            tableBodyEl.innerHTML = '<tr><td colspan="13" class="table-placeholder">Failed to load report.</td></tr>';
        }
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

    loadReport(boot.requestedDate || new Date().toISOString().slice(0, 10)).catch(handleError);
})();
