// Canonical daily energy report client.
(() => {
    'use strict';

    const boot = window.DAILY_REPORT_BOOT || {};
    const form = document.querySelector('[data-role="date-form"]');
    const input = document.querySelector('#report-date');
    const error = document.querySelector('[data-role="error"]');
    const chart = document.querySelector('[data-role="chart"]');
    const table = document.querySelector('[data-role="hour-table"]');

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

    function formatPrice(value) {
        const number = finite(value);
        return number === null ? '—' : `€${number.toFixed(3)}`;
    }

    function valueClass(value) {
        const number = finite(value);
        if (number === null || number === 0) return '';
        return number > 0 ? 'value-positive' : 'value-negative';
    }

    function setSignedMetric(name, value) {
        const element = role(name);
        if (!element) return;
        const number = finite(value);
        element.textContent = formatMoney(number, true);
        element.dataset.sign = number === null || number === 0 ? 'neutral' : (number > 0 ? 'positive' : 'negative');
    }

    function basisLabel(value) {
        if (value === 'schedule_self_consumption') return 'consumer-price valuation for self-consumption schedules';
        if (value === 'schedule_fixed_discharge') return 'consumer and spot-price valuation for scheduled fixed discharge';
        if (value === 'mixed') return 'consumer and spot-price valuation from mixed schedule modes';
        return 'the applicable optimizer schedule';
    }

    function statusLabel(status) {
        return ({
            complete: 'Complete day',
            complete_so_far: 'Complete so far',
            partial: 'Partial valuation',
            unavailable: 'Unavailable'
        })[status] || 'Unknown status';
    }

    function renderSummary(report) {
        const energy = report.energy || {};
        const grid = report.gridCost || {};
        const battery = report.batteryEconomics || {};
        const coverage = report.coverage || {};

        setText('day-status', statusLabel(coverage.status));
        role('day-status').dataset.status = coverage.status || 'unavailable';
        setText('coverage-status', `${coverage.availableHours || 0}/${coverage.expectedHours || 0} hours · ${coverage.priceHours || 0} priced · ${coverage.batteryValuationHours || 0} battery-valued`);
        setText('saved-status', report.savedAt ? `Updated ${new Date(report.savedAt).toLocaleString('en-GB')}` : 'Update time unavailable');
        setText('net-grid-cost', formatMoney(grid.netEur));
        setText('net-grid-detail', grid.complete
            ? `${formatMoney(grid.importEur)} import − ${formatMoney(grid.exportEur)} export value`
            : 'Complete consumer and spot prices are required');
        setSignedMetric('battery-contribution', battery.contributionEur);
        setText('battery-confidence', battery.complete
            ? `${String(battery.confidence || 'unknown').replace(/^./, (letter) => letter.toUpperCase())} confidence · method v${battery.methodVersion}`
            : (battery.partial
                ? `Partial · ${coverage.batteryValuationHours || 0}/${coverage.expectedHours || 0} hours valued · ${coverage.missingBatteryValuationHours || 0} missing`
                : 'No battery contribution components are available'));

        setText('grid-import-energy', formatEnergy(energy.gridImportWh));
        setText('grid-export-energy', formatEnergy(energy.gridExportWh));
        setText('battery-charged', formatEnergy(energy.chargedWh));
        setText('battery-discharged', formatEnergy(energy.dischargedWh));
        setText('grid-import-cost', formatMoney(grid.importEur));
        setText('grid-export-value', formatMoney(grid.exportEur));
        setText('grid-net-cost-detail', formatMoney(grid.netEur));
        setText('battery-charge-cost', formatMoney(battery.chargeCostEur === null ? null : -battery.chargeCostEur, true));
        setText('battery-home-value', formatMoney(battery.homeValueEur, true));
        setText('battery-export-value', formatMoney(battery.exportValueEur, true));
        setText('battery-flow-pnl', formatMoney(battery.flowPnlEur, true));
        setText('battery-stored-value', formatMoney(battery.storedValueEur, true));
        setText('battery-contribution-detail', formatMoney(battery.contributionEur, true));
        setText('battery-basis', battery.complete
            ? `Estimated using ${basisLabel(battery.estimationBasis)}. Stored value uses ${formatPrice(battery.averageConsumerEurPerKwh)}/kWh and battery movement from ${finite(battery.openingPct)?.toFixed(0) ?? '—'}% to ${finite(battery.closingPct)?.toFixed(0) ?? '—'}%.`
            : (battery.partial
                ? `Partial accounted value using ${basisLabel(battery.estimationBasis)}. Missing ${coverage.missingBatteryValuationHours || 0} hour${coverage.missingBatteryValuationHours === 1 ? '' : 's'} with ${formatEnergy(coverage.unvaluedDischargeWh)} unvalued discharge. Missing hours are not treated as zero.`
                : `Unavailable: ${Array.isArray(battery.reasons) && battery.reasons.length ? battery.reasons.join(', ') : 'missing flow or stored-energy inputs'}.`));
        setText('chart-title', `${report.date}${report.isPartialDay ? ' · so far' : ''}`);
    }

    function renderChart(hours) {
        if (!Array.isArray(hours) || hours.length === 0) {
            chart.innerHTML = '<p class="empty-state">No hourly data is available.</p>';
            return;
        }
        const width = 1120;
        const height = 330;
        const left = 54;
        const right = 42;
        const top = 24;
        const bottom = 44;
        const plotWidth = width - left - right;
        const plotHeight = height - top - bottom;
        const baseline = top + plotHeight / 2;
        const values = hours.flatMap((hour) => [
            (finite(hour.gridImportWh) || 0) - (finite(hour.gridExportWh) || 0),
            (finite(hour.chargedWh) || 0) - (finite(hour.dischargedWh) || 0)
        ]);
        const maxAbs = Math.max(100, ...values.map((value) => Math.abs(value)));
        const halfHeight = plotHeight / 2 - 10;
        const slot = plotWidth / hours.length;
        const barWidth = Math.max(3, Math.min(14, slot * 0.28));
        const guides = [-1, -0.5, 0, 0.5, 1].map((ratio) => {
            const y = baseline - ratio * halfHeight;
            const label = Math.round(ratio * maxAbs);
            return `<line class="${ratio === 0 ? 'chart-axis' : 'chart-guide'}" x1="${left}" y1="${y}" x2="${width - right}" y2="${y}"><title>${label} Wh</title></line><text x="${left - 8}" y="${y + 4}" text-anchor="end">${label}</text>`;
        }).join('');
        const bars = hours.map((hour, index) => {
            const center = left + slot * (index + 0.5);
            const gridNet = (finite(hour.gridImportWh) || 0) - (finite(hour.gridExportWh) || 0);
            const batteryNet = (finite(hour.chargedWh) || 0) - (finite(hour.dischargedWh) || 0);
            return [gridNet, batteryNet].map((value, series) => {
                const barHeight = Math.abs(value) / maxAbs * halfHeight;
                const y = value >= 0 ? baseline - barHeight : baseline;
                const x = center + (series === 0 ? -barWidth - 1 : 1);
                const baseClass = series === 0 ? 'chart-grid-net' : 'chart-battery-net';
                return `<rect class="${baseClass}${value < 0 ? ' chart-negative' : ''}" x="${x.toFixed(2)}" y="${y.toFixed(2)}" width="${barWidth.toFixed(2)}" height="${barHeight.toFixed(2)}" rx="2"><title>${hour.hour}:00 · ${series === 0 ? 'Grid net' : 'Battery net'} ${Math.round(value)} Wh</title></rect>`;
            }).join('');
        }).join('');
        const levelPoints = hours.map((hour, index) => {
            const level = finite(hour.batteryEndPct);
            if (level === null) return null;
            const x = left + slot * (index + 0.5);
            const y = top + (100 - level) / 100 * plotHeight;
            return `${x.toFixed(2)},${y.toFixed(2)}`;
        }).filter(Boolean).join(' ');
        const labels = hours.map((hour, index) => index % 3 === 0
            ? `<text x="${left + slot * (index + 0.5)}" y="${height - 16}" text-anchor="middle">${hour.hour}:00</text>`
            : '').join('');
        chart.innerHTML = `<svg class="report-chart" viewBox="0 0 ${width} ${height}" role="img" aria-label="Hourly net grid and battery energy with battery level"><title>Hourly energy flow for ${escapeHtml(input.value)}</title>${guides}${bars}${levelPoints ? `<polyline class="chart-level" points="${levelPoints}"><title>Battery level</title></polyline>` : ''}${labels}</svg>`;
    }

    function renderTable(hours) {
        setText('hour-count', `${hours.length} hour${hours.length === 1 ? '' : 's'}`);
        if (!hours.length) {
            table.innerHTML = '<tr><td colspan="9">No hourly data is available.</td></tr>';
            return;
        }
        table.innerHTML = hours.map((hour) => {
            const batteryNet = (finite(hour.chargedWh) || 0) - (finite(hour.dischargedWh) || 0);
            const gridNet = (finite(hour.gridImportWh) || 0) - (finite(hour.gridExportWh) || 0);
            return `<tr>
                <td>${escapeHtml(hour.hour)}:00</td>
                <td>${escapeHtml(formatEnergy(batteryNet))}</td>
                <td>${finite(hour.batteryEndPct)?.toFixed(0) ?? '—'}%</td>
                <td>${escapeHtml(formatEnergy(gridNet))}</td>
                <td>${escapeHtml(formatPrice(hour.consumerEurPerKwh))}</td>
                <td>${escapeHtml(formatPrice(hour.spotEurPerKwh))}</td>
                <td class="${valueClass(-finite(hour.netGridCostEur))}">${escapeHtml(formatMoney(hour.netGridCostEur))}</td>
                <td class="${valueClass(hour.batteryFlowPnlEur)}">${escapeHtml(formatMoney(hour.batteryFlowPnlEur, true))}</td>
                <td>${escapeHtml(hour.confidence || hour.status || '—')}</td>
            </tr>`;
        }).join('');
    }

    async function load(date) {
        error.hidden = true;
        setText('day-status', 'Loading');
        role('day-status').dataset.status = '';
        try {
            const response = await fetch(`${boot.apiUrl || 'api/daily.php'}?date=${encodeURIComponent(date)}`, {headers: {'Accept': 'application/json'}, cache: 'no-store'});
            const payload = await response.json();
            if (!response.ok || !payload.success) throw new Error(payload.error || `Report request failed (${response.status}).`);
            renderSummary(payload.report);
            renderChart(payload.report.hours || []);
            renderTable(payload.report.hours || []);
            const url = new URL(window.location.href);
            url.searchParams.set('date', date);
            window.history.replaceState({}, '', url);
        } catch (reason) {
            error.textContent = reason instanceof Error ? reason.message : 'The report could not be loaded.';
            error.hidden = false;
            setText('day-status', 'Load failed');
            role('day-status').dataset.status = 'unavailable';
        }
    }

    function shiftDate(date, days) {
        const result = new Date(`${date}T12:00:00Z`);
        result.setUTCDate(result.getUTCDate() + days);
        return result.toISOString().slice(0, 10);
    }

    function updateNextState() {
        role('next-date').disabled = input.value >= input.max;
    }

    form.addEventListener('submit', (event) => { event.preventDefault(); updateNextState(); load(input.value); });
    input.addEventListener('change', updateNextState);
    role('previous-date').addEventListener('click', () => { input.value = shiftDate(input.value, -1); updateNextState(); load(input.value); });
    role('next-date').addEventListener('click', () => { input.value = shiftDate(input.value, 1); updateNextState(); load(input.value); });
    updateNextState();
    load(boot.date || input.value);
})();
