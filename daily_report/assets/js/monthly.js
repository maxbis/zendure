// Canonical monthly energy report client.
(() => {
    'use strict';

    const boot = window.MONTHLY_REPORT_BOOT || {};
    const form = document.querySelector('[data-role="month-form"]');
    const input = document.querySelector('#report-month');
    const error = document.querySelector('[data-role="error"]');
    const chart = document.querySelector('[data-role="chart"]');
    const table = document.querySelector('[data-role="day-table"]');
    const role = (name) => document.querySelector(`[data-role="${name}"]`);
    const finite = (value) => value !== null && value !== '' && typeof value !== 'boolean' && Number.isFinite(Number(value))
        ? Number(value)
        : null;
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[character]);

    function setText(name, value) {
        const element = role(name);
        if (element) element.textContent = value;
    }

    function formatMoney(value, signed = false) {
        const number = finite(value);
        if (number === null) return '—';
        const prefix = number < 0 ? '−' : (signed && number > 0 ? '+' : '');
        return `${prefix}€${Math.abs(number).toFixed(2)}`;
    }

    function formatEnergy(value) {
        const number = finite(value);
        if (number === null) return '—';
        return Math.abs(number) >= 1000 ? `${(number / 1000).toFixed(2)} kWh` : `${Math.round(number)} Wh`;
    }

    function valueClass(value, cost = false) {
        const number = finite(value);
        if (number === null || number === 0) return '';
        const positive = cost ? number < 0 : number > 0;
        return positive ? 'value-positive' : 'value-negative';
    }

    function renderSummary(report) {
        const totals = report.totals || {};
        const coverage = report.coverage || {};
        const status = coverage.costComplete && coverage.contributionComplete ? (report.isPartialMonth ? 'complete_so_far' : 'complete') : 'partial';
        setText('month-status', status === 'complete' ? 'Complete month' : (status === 'complete_so_far' ? 'Complete so far' : 'Partial data'));
        role('month-status').dataset.status = status;
        const affectedDays = Number(coverage.incompleteDayCount || 0);
        const missingHours = Number(coverage.missingBatteryValuationHours || 0);
        setText('coverage-status', `${coverage.batteryValuationHours || 0}/${coverage.expectedHours || 0} battery hours valued · ${missingHours} missing across ${affectedDays} day${affectedDays === 1 ? '' : 's'}`);
        setText('net-grid-cost', formatMoney(totals.netGridCostEur));
        setText('net-grid-detail', coverage.costComplete ? `${formatMoney(totals.gridImportEur)} import − ${formatMoney(totals.gridExportEur)} export value` : 'At least one day has incomplete grid-cost data');
        setText('battery-contribution', formatMoney(totals.contributionEur, true));
        const contribution = role('battery-contribution');
        const contributionValue = finite(totals.contributionEur);
        contribution.dataset.sign = contributionValue === null || contributionValue === 0 ? 'neutral' : (contributionValue > 0 ? 'positive' : 'negative');
        setText('battery-confidence', coverage.contributionComplete
            ? `${coverage.confidence || 'Unknown'} confidence · complete operational estimate`
            : `Partial · based on ${coverage.batteryValuationHours || 0} of ${coverage.expectedHours || 0} elapsed hours`);
        setText('grid-import-energy', formatEnergy(totals.gridImportWh));
        setText('grid-export-energy', formatEnergy(totals.gridExportWh));
        setText('battery-charged', formatEnergy(totals.chargedWh));
        setText('battery-discharged', formatEnergy(totals.dischargedWh));
        setText('grid-import-cost', formatMoney(totals.gridImportEur));
        setText('grid-export-value', formatMoney(totals.gridExportEur));
        setText('grid-net-cost-detail', formatMoney(totals.netGridCostEur));
        setText('battery-flow-pnl', formatMoney(totals.flowPnlEur, true));
        setText('battery-stored-value', formatMoney(totals.storedValueEur, true));
        setText('battery-contribution-detail', formatMoney(totals.contributionEur, true));
        const incompleteDays = Array.isArray(coverage.incompleteDays) ? coverage.incompleteDays : [];
        const affected = incompleteDays.map((day) => {
            const energy = finite(day.unvaluedDischargeWh);
            return `${day.date}: ${day.missingHours} missing hour${day.missingHours === 1 ? '' : 's'}${energy !== null ? `, ${formatEnergy(energy)} unvalued discharge` : ''}`;
        }).join(' · ');
        setText('monthly-note', `${report.note || 'Monthly values use the available operational estimates.'}${affected ? ` Affected: ${affected}.` : ''}`);
        setText('chart-title', `${report.month}${report.isPartialMonth ? ' · through ' + report.lastIncludedDate : ''}`);
        setText('day-count', `${report.includedDayCount || 0} day${report.includedDayCount === 1 ? '' : 's'}`);
    }

    function renderChart(days) {
        if (!Array.isArray(days) || days.length === 0) {
            chart.innerHTML = '<p class="empty-state">No daily values are available.</p>';
            return;
        }
        const width = 1120;
        const height = 330;
        const left = 54;
        const right = 28;
        const top = 24;
        const bottom = 44;
        const plotWidth = width - left - right;
        const plotHeight = height - top - bottom;
        const baseline = top + plotHeight / 2;
        const values = days.flatMap((day) => [finite(day.netGridCostEur) || 0, finite(day.contributionEur) || 0]);
        const maxAbs = Math.max(0.25, ...values.map((value) => Math.abs(value)));
        const halfHeight = plotHeight / 2 - 10;
        const slot = plotWidth / days.length;
        const barWidth = Math.max(3, Math.min(12, slot * 0.3));
        const guides = [-1, -0.5, 0, 0.5, 1].map((ratio) => {
            const y = baseline - ratio * halfHeight;
            return `<line class="${ratio === 0 ? 'chart-axis' : 'chart-guide'}" x1="${left}" y1="${y}" x2="${width - right}" y2="${y}"></line><text x="${left - 8}" y="${y + 4}" text-anchor="end">${(ratio * maxAbs).toFixed(1)}</text>`;
        }).join('');
        const bars = days.map((day, index) => {
            const center = left + slot * (index + 0.5);
            return [finite(day.netGridCostEur), finite(day.contributionEur)].map((value, series) => {
                if (value === null) return '';
                const barHeight = Math.abs(value) / maxAbs * halfHeight;
                const y = value >= 0 ? baseline - barHeight : baseline;
                const x = center + (series === 0 ? -barWidth - 1 : 1);
                const partial = series === 1 && day.coverageStatus === 'partial';
                const cssClass = series === 0 ? 'chart-cost' : `chart-contribution${partial ? ' chart-partial' : ''}`;
                return `<rect class="${cssClass}" x="${x.toFixed(2)}" y="${y.toFixed(2)}" width="${barWidth.toFixed(2)}" height="${barHeight.toFixed(2)}" rx="2"><title>${escapeHtml(day.date)} · ${series === 0 ? 'Net grid cost' : 'Battery contribution'} ${formatMoney(value, series === 1)}${partial ? ' · partial' : ''}</title></rect>`;
            }).join('');
        }).join('');
        const labelInterval = days.length > 20 ? 5 : 3;
        const labels = days.map((day, index) => index % labelInterval === 0
            ? `<text x="${left + slot * (index + 0.5)}" y="${height - 16}" text-anchor="middle">${escapeHtml(day.date.slice(-2))}</text>`
            : '').join('');
        chart.innerHTML = `<svg class="report-chart" viewBox="0 0 ${width} ${height}" role="img" aria-label="Daily net grid cost and battery contribution for the selected month"><title>Monthly financial overview</title>${guides}${bars}${labels}</svg>`;
    }

    function renderTable(days) {
        if (!days.length) {
            table.innerHTML = '<tr><td colspan="10">No daily rows are available.</td></tr>';
            return;
        }
        table.innerHTML = days.map((day) => `<tr>
            <td><a href="./?date=${encodeURIComponent(day.date)}">${escapeHtml(day.date)}</a></td>
            <td>${escapeHtml(formatEnergy(day.gridImportWh))}</td>
            <td>${escapeHtml(formatEnergy(day.gridExportWh))}</td>
            <td class="${valueClass(day.netGridCostEur, true)}">${escapeHtml(formatMoney(day.netGridCostEur))}</td>
            <td>${escapeHtml(formatEnergy(day.chargedWh))}</td>
            <td>${escapeHtml(formatEnergy(day.dischargedWh))}</td>
            <td class="${valueClass(day.flowPnlEur)}">${escapeHtml(formatMoney(day.flowPnlEur, true))}</td>
            <td class="${valueClass(day.storedValueEur)}">${escapeHtml(formatMoney(day.storedValueEur, true))}</td>
            <td class="${valueClass(day.contributionEur)}">${escapeHtml(formatMoney(day.contributionEur, true))}</td>
            <td>${escapeHtml(`${day.batteryValuationHours || 0}/${day.expectedHours || 0}${day.missingHours ? ' · partial' : (day.confidence ? ` · ${day.confidence}` : '')}`)}</td>
        </tr>`).join('');
    }

    async function load(month) {
        error.hidden = true;
        setText('month-status', 'Loading');
        role('month-status').dataset.status = '';
        try {
            const response = await fetch(`${boot.apiUrl || 'api/monthly.php'}?month=${encodeURIComponent(month)}`, {headers: {'Accept': 'application/json'}, cache: 'no-store'});
            const payload = await response.json();
            if (!response.ok || !payload.success) throw new Error(payload.error || `Report request failed (${response.status}).`);
            renderSummary(payload.report);
            renderChart(payload.report.days || []);
            renderTable(payload.report.days || []);
            const url = new URL(window.location.href);
            url.searchParams.set('month', month);
            window.history.replaceState({}, '', url);
        } catch (reason) {
            error.textContent = reason instanceof Error ? reason.message : 'The report could not be loaded.';
            error.hidden = false;
            setText('month-status', 'Load failed');
            role('month-status').dataset.status = 'unavailable';
        }
    }

    function shiftMonth(month, delta) {
        const [year, monthNumber] = month.split('-').map(Number);
        const date = new Date(Date.UTC(year, monthNumber - 1 + delta, 1));
        return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
    }

    function updateNextState() {
        role('next-month').disabled = input.value >= input.max;
    }

    form.addEventListener('submit', (event) => { event.preventDefault(); updateNextState(); load(input.value); });
    input.addEventListener('change', updateNextState);
    role('previous-month').addEventListener('click', () => { input.value = shiftMonth(input.value, -1); updateNextState(); load(input.value); });
    role('next-month').addEventListener('click', () => { input.value = shiftMonth(input.value, 1); updateNextState(); load(input.value); });
    updateNextState();
    load(boot.month || input.value);
})();
