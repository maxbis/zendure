(function () {
    'use strict';

    const boot = window.DAILY_REPORT_MOBILE_BOOT || {};
    const apiUrl = boot.apiUrl || 'api/report_data.php';
    const rootStyles = window.getComputedStyle(document.documentElement);

    const chartEl = document.querySelector('[data-role="chart"]');
    const chartScrollEl = document.querySelector('[data-role="chart-scroll"]');
    const chartStatusEl = document.querySelector('[data-role="chart-status"]');
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

    function setText(role, value) {
        const el = document.querySelector(`[data-role="${role}"]`);
        if (el) el.textContent = value;
    }

    function setChartStatus(message) {
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
                start = Number.isFinite(startValue) ? startValue : endValue;
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
        return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
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
        const hours = Array.isArray(report.hours) ? report.hours : [];
        const batteryStats = computeBatteryStats(hours);
        const priceVariation = computePriceVariationStats(hours);
        const batteryDeltaRange = Number.isFinite(batteryStats.min) && Number.isFinite(batteryStats.max)
            ? Math.abs(batteryStats.max - batteryStats.min)
            : null;
        const netCost = Number(totals.net_cost);
        const spotNetCost = computeSpotNetCost(hours);
        const savings = Number(totals.savings_eur);
        const chargeCost = Number(totals.charge_cost_eur);
        const spotChargeCost = computeSpotChargeCost(hours);
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
        setText('price-variation-range', priceVariation.hasPrices ? `Min ${formatPrice(priceVariation.min)} / Max ${formatPrice(priceVariation.max)}` : '--');
        setText('price-variation-indicator', priceVariation.hasPrices ? `CV ${formatPercentNeutral(priceVariation.cvPct)}` : 'No hourly prices');
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

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function rect(x, y, width, height, fill, roundSide, titleText = null) {
        const safeHeight = Math.max(0, height);
        const title = titleText ? `<title>${escapeHtml(titleText)}</title>` : '';
        const radius = Math.min(3, Math.max(0, width / 2), Math.max(0, safeHeight / 2));

        if (radius <= 0) {
            return `<rect x="${x.toFixed(2)}" y="${y.toFixed(2)}" width="${width.toFixed(2)}" height="${safeHeight.toFixed(2)}" fill="${fill}">${title}</rect>`;
        }

        const right = x + width;
        const bottom = y + safeHeight;
        let path;
        if (roundSide === 'bottom') {
            path = [
                `M ${x.toFixed(2)} ${y.toFixed(2)}`,
                `H ${right.toFixed(2)}`,
                `V ${(bottom - radius).toFixed(2)}`,
                `Q ${right.toFixed(2)} ${bottom.toFixed(2)} ${(right - radius).toFixed(2)} ${bottom.toFixed(2)}`,
                `H ${(x + radius).toFixed(2)}`,
                `Q ${x.toFixed(2)} ${bottom.toFixed(2)} ${x.toFixed(2)} ${(bottom - radius).toFixed(2)}`,
                'Z'
            ].join(' ');
        } else {
            path = [
                `M ${x.toFixed(2)} ${bottom.toFixed(2)}`,
                `V ${(y + radius).toFixed(2)}`,
                `Q ${x.toFixed(2)} ${y.toFixed(2)} ${(x + radius).toFixed(2)} ${y.toFixed(2)}`,
                `H ${(right - radius).toFixed(2)}`,
                `Q ${right.toFixed(2)} ${y.toFixed(2)} ${right.toFixed(2)} ${(y + radius).toFixed(2)}`,
                `V ${bottom.toFixed(2)}`,
                'Z'
            ].join(' ');
        }

        return `<path d="${path}" fill="${fill}">${title}</path>`;
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
            setChartStatus('No hourly chart data available.');
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
            batteryAxis.push(`
                <text x="${x.toFixed(2)}" y="${(margin.top - 28).toFixed(2)}" fill="${palette.batteryLevel}" font-size="10" text-anchor="middle">${value}%</text>
            `);
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
            <text x="${margin.left}" y="${(margin.top - 28).toFixed(2)}" fill="${palette.batteryLevel}" font-size="11">Battery scale</text>
            <text x="${margin.left}" y="${(height - 12).toFixed(2)}" fill="${palette.textSoft}" font-size="11">Energy scale: left = discharge/export, right = charge/import</text>
        `;

        chartScrollEl.hidden = false;
        setChartStatus('Hours run top-to-bottom. Values extend left and right from the center line.');
    }

    function applyPayload(payload) {
        renderSummary(payload);
        buildEnergyChart(payload.report && payload.report.hours ? payload.report.hours : []);
        setRegenerateButtonState(Boolean(payload && payload.canRegenerate), false);
    }

    async function loadReport(date) {
        setChartStatus('Loading report...');
        if (chartScrollEl) chartScrollEl.hidden = true;
        if (dateInputEl) dateInputEl.value = date;
        updateQueryDate(date);
        setRegenerateButtonState(false, false);

        const url = new URL(apiUrl, window.location.href);
        url.searchParams.set('date', date);

        const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
        const payload = await response.json();
        if (!response.ok || !payload.success) {
            throw new Error(payload && payload.error ? payload.error : 'Failed to load daily report.');
        }

        applyPayload(payload);
    }

    async function regenerateReport(date) {
        setChartStatus('Regenerating report...');
        setRegenerateButtonState(true, true);

        const response = await fetch(new URL(apiUrl, window.location.href).toString(), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: new URLSearchParams({ date, action: 'regenerate' }).toString()
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
        setChartStatus(error && error.message ? error.message : 'Failed to load daily report.');
        if (chartScrollEl) chartScrollEl.hidden = true;
        setRegenerateButtonState(Boolean(regenerateButtonEl && !regenerateButtonEl.hidden), false);
    }

    if (dateInputEl) {
        dateInputEl.addEventListener('click', function openDatePicker() {
            if (typeof this.showPicker === 'function') {
                try {
                    this.showPicker();
                } catch {
                    /* ignore */
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
