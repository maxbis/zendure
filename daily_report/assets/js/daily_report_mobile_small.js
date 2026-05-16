(function () {
    'use strict';

    const boot = window.DAILY_REPORT_MOBILE_SMALL_BOOT || {};
    const apiUrl = boot.apiUrl || 'api/report_data.php';
    const rootStyles = window.getComputedStyle(document.documentElement);

    const chartEl = document.querySelector('[data-role="chart"]');
    const chartScrollEl = document.querySelector('[data-role="chart-scroll"]');
    const chartTitleEl = document.querySelector('[data-role="chart-title"]');
    const todayJumpEl = document.querySelector('[data-role="today-jump"]');
    const prevDayEl = document.querySelector('[data-role="prev-day"]');
    const nextDayEl = document.querySelector('[data-role="next-day"]');

    let currentDate = boot.requestedDate || boot.todayDate || new Date().toISOString().slice(0, 10);
    const todayDate = boot.todayDate || new Date().toISOString().slice(0, 10);

    function cssColor(name, fallback) {
        const value = rootStyles.getPropertyValue(name).trim();
        return value || fallback;
    }

    const palette = {
        charge: cssColor('--chart-series-charged', '#9ce365'),
        discharge: cssColor('--chart-series-discharged', '#e97560'),
        gridFrom: cssColor('--chart-series-grid-from', '#8fb8c9'),
        gridTo: cssColor('--chart-series-grid-to', '#b8def4'),
        cost: cssColor('--chart-series-net-cost', '#7e89ff'),
        pnlLine: cssColor('--chart-series-pnl', '#8fd8ff'),
        batteryLevel: cssColor('--chart-series-battery-level', '#f1d34b'),
        guide: cssColor('--chart-guide', 'rgba(127, 147, 139, 0.42)'),
        textSoft: cssColor('--text-soft', '#b7c8c1'),
        textMuted: cssColor('--text-muted', '#7f938b')
    };

    function setChartTitle(value) {
        if (chartTitleEl) chartTitleEl.textContent = value;
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
        return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    }

    function syncNavButtons(date) {
        if (prevDayEl) prevDayEl.disabled = false;
        if (nextDayEl) nextDayEl.disabled = date >= todayDate;
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

    function formatEur(value) {
        if (!Number.isFinite(value)) return '--';
        const prefix = value > 0 ? '+' : '';
        return `${prefix}EUR ${value.toFixed(4)}`;
    }

    function setText(role, value) {
        const el = document.querySelector(`[data-role="${role}"]`);
        if (el) el.textContent = value;
    }

    function deriveSpotPrice(value) {
        if (typeof convertConsumerToSpotPrice !== 'function') return null;
        return convertConsumerToSpotPrice(value);
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

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function rectHorizontal(x, y, width, height, fill, roundSide, titleText = null) {
        const safeWidth = Math.max(0, width);
        const title = titleText ? `<title>${escapeHtml(titleText)}</title>` : '';
        const radius = Math.min(3, Math.max(0, safeWidth / 2), Math.max(0, height / 2));

        if (radius <= 0) {
            return `<rect x="${x.toFixed(2)}" y="${y.toFixed(2)}" width="${safeWidth.toFixed(2)}" height="${height.toFixed(2)}" fill="${fill}">${title}</rect>`;
        }

        const right = x + safeWidth;
        const bottom = y + height;
        let path;

        if (roundSide === 'left') {
            path = [
                `M ${(x + radius).toFixed(2)} ${y.toFixed(2)}`,
                `H ${right.toFixed(2)}`,
                `V ${bottom.toFixed(2)}`,
                `H ${(x + radius).toFixed(2)}`,
                `Q ${x.toFixed(2)} ${bottom.toFixed(2)} ${x.toFixed(2)} ${(bottom - radius).toFixed(2)}`,
                `V ${(y + radius).toFixed(2)}`,
                `Q ${x.toFixed(2)} ${y.toFixed(2)} ${(x + radius).toFixed(2)} ${y.toFixed(2)}`,
                'Z'
            ].join(' ');
        } else {
            path = [
                `M ${x.toFixed(2)} ${y.toFixed(2)}`,
                `H ${(right - radius).toFixed(2)}`,
                `Q ${right.toFixed(2)} ${y.toFixed(2)} ${right.toFixed(2)} ${(y + radius).toFixed(2)}`,
                `V ${(bottom - radius).toFixed(2)}`,
                `Q ${right.toFixed(2)} ${bottom.toFixed(2)} ${(right - radius).toFixed(2)} ${bottom.toFixed(2)}`,
                `H ${x.toFixed(2)}`,
                'Z'
            ].join(' ');
        }

        return `<path d="${path}" fill="${fill}">${title}</path>`;
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

    function buildCumulativeValues(hours, valueKey) {
        let runningValue = 0;
        return hours.map((row) => {
            const value = toFiniteNumber(row && row[valueKey]);
            if (!Number.isFinite(value)) return null;
            runningValue += value;
            return runningValue;
        });
    }

    function computeHourlyPnl(row) {
        const savings = toFiniteNumber(row && row.savings_eur);
        const chargeCost = toFiniteNumber(row && row.charge_cost_eur);
        const netCost = toFiniteNumber(row && row.net_cost);
        if (!Number.isFinite(savings) || !Number.isFinite(chargeCost) || !Number.isFinite(netCost)) {
            return null;
        }
        return savings - chargeCost - netCost;
    }

    function buildCumulativePnlValues(hours) {
        let runningValue = 0;
        return hours.map((row) => {
            const value = computeHourlyPnl(row);
            if (!Number.isFinite(value)) return null;
            runningValue += value;
            return runningValue;
        });
    }

    function buildEnergyChart(hours) {
        if (!chartEl || !chartScrollEl) return;
        if (!Array.isArray(hours) || hours.length === 0) {
            chartScrollEl.hidden = true;
            return;
        }

        const width = 390;
        const height = 860;
        const margin = { top: 72, right: 18, bottom: 56, left: 48 };
        const plotWidth = width - margin.left - margin.right;
        const plotHeight = height - margin.top - margin.bottom;
        const baselineX = margin.left + (plotWidth / 2);
        const slotHeight = plotHeight / Math.max(1, hours.length);
        const fixedEnergyMax = 800;
        const fixedCostMax = 2;
        const cumulativeCostValues = buildCumulativeValues(hours, 'net_cost');
        const cumulativePnlValues = buildCumulativePnlValues(hours);
        const energyHalfWidth = plotWidth / 2;
        const costHalfWidth = plotWidth / 2;

        chartEl.setAttribute('viewBox', `0 0 ${width} ${height}`);
        chartEl.style.width = '100%';

        const energyAxis = [];
        const costAxis = [];
        const batteryAxis = [];
        const guides = [];
        const costPoints = [];
        const pnlPoints = [];
        const batteryPoints = [];
        const labels = [];
        const bars = [];
        const energyGuideValues = [-800, -400, 0, 400, 800];
        const costGuideValues = [-2, -1, 0, 1, 2];

        energyGuideValues.forEach((value) => {
            const x = baselineX + ((value / fixedEnergyMax) * energyHalfWidth);
            energyAxis.push(`
                <line x1="${x.toFixed(2)}" y1="${(height - margin.bottom).toFixed(2)}" x2="${x.toFixed(2)}" y2="${(height - margin.bottom + 4).toFixed(2)}" stroke="${palette.textMuted}" stroke-width="1"></line>
                <text x="${x.toFixed(2)}" y="${(height - margin.bottom + 18).toFixed(2)}" fill="${palette.textMuted}" font-size="10" text-anchor="middle">${escapeHtml(formatAxisWh(value))}</text>
            `);
        });

        costGuideValues.forEach((value) => {
            const x = baselineX + ((value / fixedCostMax) * costHalfWidth);
            guides.push(`<line x1="${x.toFixed(2)}" y1="${margin.top.toFixed(2)}" x2="${x.toFixed(2)}" y2="${(margin.top + plotHeight).toFixed(2)}" stroke="${palette.guide}" stroke-width="1" stroke-dasharray="4 4"></line>`);
            costAxis.push(`
                <line x1="${x.toFixed(2)}" y1="${(margin.top - 4).toFixed(2)}" x2="${x.toFixed(2)}" y2="${margin.top.toFixed(2)}" stroke="${palette.cost}" stroke-width="1"></line>
                <text x="${x.toFixed(2)}" y="${(margin.top - 12).toFixed(2)}" fill="${palette.cost}" font-size="10" text-anchor="middle">${escapeHtml(formatAxisEur(value))}</text>
            `);
        });

        [0, 50, 100].forEach((value) => {
            const x = margin.left + ((value / 100) * plotWidth);
            batteryAxis.push(`<text x="${x.toFixed(2)}" y="${(margin.top - 28).toFixed(2)}" fill="${palette.batteryLevel}" font-size="10" text-anchor="middle">${value}%</text>`);
        });

        hours.forEach((row, index) => {
            const slotTop = margin.top + (index * slotHeight);
            const slotCenter = slotTop + (slotHeight / 2);
            const laneGap = 4;
            const laneHeight = Math.max(4, ((slotHeight - laneGap) / 2) * 0.72);
            const chargeLaneY = slotCenter - laneGap / 2 - laneHeight;
            const gridLaneY = slotCenter + laneGap / 2;

            const charge = Number(row.charged_wh) || 0;
            const discharge = Number(row.discharged_wh) || 0;
            const gridFrom = Number(row.grid_from_wh) || 0;
            const gridTo = Number(row.grid_to_wh) || 0;
            const chargeWidth = (clamp(charge, 0, fixedEnergyMax) / fixedEnergyMax) * energyHalfWidth;
            const dischargeWidth = (clamp(discharge, 0, fixedEnergyMax) / fixedEnergyMax) * energyHalfWidth;
            const fromWidth = (clamp(gridFrom, 0, fixedEnergyMax) / fixedEnergyMax) * energyHalfWidth;
            const toWidth = (clamp(gridTo, 0, fixedEnergyMax) / fixedEnergyMax) * energyHalfWidth;
            const hourlyPnl = computeHourlyPnl(row);
            const tooltipSummary = [
                `Hour ${row.hour || '--'}`,
                `Charged: ${formatWh(Number(row.charged_wh))}`,
                `Discharged: ${formatWh(Number(row.discharged_wh))}`,
                `Grid from: ${formatWh(Number(row.grid_from_wh))}`,
                `Grid to: ${formatWh(Number(row.grid_to_wh))}`,
                `Net cost: ${formatEur(Number(row.net_cost))}`,
                `P&L: ${formatEur(hourlyPnl)}`
            ].join('\n');

            bars.push(rectHorizontal(baselineX, chargeLaneY, chargeWidth, laneHeight, palette.charge, 'right', tooltipSummary));
            bars.push(rectHorizontal(baselineX - dischargeWidth, chargeLaneY, dischargeWidth, laneHeight, palette.discharge, 'left', tooltipSummary));
            bars.push(rectHorizontal(baselineX, gridLaneY, fromWidth, laneHeight, palette.gridFrom, 'right', tooltipSummary));
            bars.push(rectHorizontal(baselineX - toWidth, gridLaneY, toWidth, laneHeight, palette.gridTo, 'left', tooltipSummary));

            const cumulativeNetCost = cumulativeCostValues[index];
            if (Number.isFinite(cumulativeNetCost)) {
                const x = baselineX + ((clamp(cumulativeNetCost, -fixedCostMax, fixedCostMax) / fixedCostMax) * costHalfWidth);
                costPoints.push(`${x.toFixed(2)},${slotCenter.toFixed(2)}`);
            }

            const cumulativePnl = cumulativePnlValues[index];
            if (Number.isFinite(cumulativePnl)) {
                const x = baselineX + ((clamp(cumulativePnl, -fixedCostMax, fixedCostMax) / fixedCostMax) * costHalfWidth);
                pnlPoints.push(`${x.toFixed(2)},${slotCenter.toFixed(2)}`);
            }

            const batteryLevel = toFiniteNumber(row.battery_pct_end) ?? toFiniteNumber(row.battery_pct_start);
            if (Number.isFinite(batteryLevel)) {
                const x = margin.left + ((clamp(batteryLevel, 0, 100) / 100) * plotWidth);
                batteryPoints.push(`${x.toFixed(2)},${slotCenter.toFixed(2)}`);
            }

            labels.push(`
                <line x1="${margin.left.toFixed(2)}" y1="${slotCenter.toFixed(2)}" x2="${(margin.left + plotWidth).toFixed(2)}" y2="${slotCenter.toFixed(2)}" stroke="rgba(255,255,255,0.03)" stroke-width="1"></line>
                <text x="${(margin.left - 10).toFixed(2)}" y="${(slotCenter + 4).toFixed(2)}" fill="${palette.textMuted}" font-size="11" text-anchor="end">${escapeHtml(row.hour || '--')}</text>
            `);
        });

        chartEl.innerHTML = `
            <rect x="${margin.left}" y="${margin.top}" width="${plotWidth}" height="${plotHeight}" fill="rgba(255,255,255,0.02)" rx="18"></rect>
            <line x1="${baselineX.toFixed(2)}" y1="${margin.top.toFixed(2)}" x2="${baselineX.toFixed(2)}" y2="${(margin.top + plotHeight).toFixed(2)}" stroke="${palette.textMuted}" stroke-width="1.2"></line>
            ${guides.join('')}
            ${energyAxis.join('')}
            ${costAxis.join('')}
            ${batteryAxis.join('')}
            ${bars.join('')}
            ${costPoints.length > 1 ? `<polyline points="${costPoints.join(' ')}" fill="none" stroke="${palette.cost}" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"></polyline>` : ''}
            ${pnlPoints.length > 1 ? `<polyline points="${pnlPoints.join(' ')}" fill="none" stroke="${palette.pnlLine}" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"></polyline>` : ''}
            ${batteryPoints.length > 1 ? `<polyline points="${batteryPoints.join(' ')}" fill="none" stroke="${palette.batteryLevel}" stroke-width="2" stroke-dasharray="8 5" stroke-linecap="round" stroke-linejoin="round"></polyline>` : ''}
            ${labels.join('')}
            <text x="${margin.left}" y="${(margin.top - 46).toFixed(2)}" fill="${palette.textSoft}" font-size="11">Cost/P&amp;L scale</text>
            <text x="${margin.left}" y="${(height - 12).toFixed(2)}" fill="${palette.textSoft}" font-size="11">Energy scale: left = discharge/export, right = charge/import</text>
        `;

        chartScrollEl.hidden = false;
    }

    function renderPnl(payload) {
        const report = payload.report || {};
        const totals = report.totals || {};
        const hours = Array.isArray(report.hours) ? report.hours : [];
        const netCost = Number(totals.net_cost);
        const savings = Number(totals.savings_eur);
        const chargeCost = Number(totals.charge_cost_eur);
        const spotNetCost = computeSpotNetCost(hours);
        const spotChargeCost = computeSpotChargeCost(hours);
        const pnl = Number.isFinite(netCost) && Number.isFinite(savings) && Number.isFinite(chargeCost)
            ? (chargeCost - savings + netCost) * -1
            : null;
        const spotPnl = Number.isFinite(spotNetCost) && Number.isFinite(savings) && Number.isFinite(spotChargeCost)
            ? (spotChargeCost - savings + spotNetCost) * -1
            : null;

        setText('pnl-total', formatEur(pnl));
        setText('pnl-spot-total', Number.isFinite(spotPnl) ? `Spot ${formatEur(spotPnl)}` : '--');
    }

    async function loadReport(date) {
        if (chartScrollEl) chartScrollEl.hidden = true;
        updateQueryDate(date);
        syncNavButtons(date);

        const url = new URL(apiUrl, window.location.href);
        url.searchParams.set('date', date);

        const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
        const payload = await response.json();
        if (!response.ok || !payload.success) {
            throw new Error(payload && payload.error ? payload.error : 'Failed to load daily report.');
        }

        currentDate = payload.requestedDate || date;
        setChartTitle(payload.report && payload.report.date ? payload.report.date : currentDate);
        syncNavButtons(currentDate);
        renderPnl(payload);
        buildEnergyChart(payload.report && payload.report.hours ? payload.report.hours : []);
    }

    function handleError(error) {
        if (chartScrollEl) chartScrollEl.hidden = true;
        syncNavButtons(currentDate);
    }

    if (prevDayEl) {
        prevDayEl.addEventListener('click', () => {
            loadReport(shiftDate(currentDate, -1)).catch(handleError);
        });
    }

    if (nextDayEl) {
        nextDayEl.addEventListener('click', () => {
            if (nextDayEl.disabled) return;
            loadReport(shiftDate(currentDate, 1)).catch(handleError);
        });
    }

    if (todayJumpEl) {
        todayJumpEl.addEventListener('click', () => {
            loadReport(todayDate).catch(handleError);
        });
    }

    syncNavButtons(currentDate);
    loadReport(currentDate).catch(handleError);
})();
