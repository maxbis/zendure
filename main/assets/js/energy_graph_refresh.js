/**
 * Energy Graph Refresh
 * Fetches Wh per hour and Wh per day from the API and updates the energy graph
 * chart(s) and daily totals table(s). Called from _refreshScheduleAndPricesInternal().
 */

(function() {
    var API_URL = typeof ENERGY_GRAPH_API_URL !== 'undefined' ? ENERGY_GRAPH_API_URL : 'api/energy_graph_api.php';
    var DAILY_PNL_API_URL = typeof DAILY_TOTALS_PNL_API_URL !== 'undefined' ? DAILY_TOTALS_PNL_API_URL : '../daily_report/api/pnl_data.php';
    var selectedMobileDay = null;
    var latestWhPerHour = [];
    var latestWhPerDay = {};
    var latestBaseWh = 5760;
    var latestDailyPnlByDate = {};
    var latestDailyPnlRequestKey = '';
    var energyGraphControlsBound = false;
    // In focused-day mode, show labels every N hours on the X axis.
    var FOCUSED_TICK_INTERVAL_HOURS = 2;
    var DAILY_TOTALS_PNL_DAY_COUNT = 4;

    function transformWh(v) {
        if (v === 0) return 0;
        var sign = v < 0 ? -1 : 1;
        var abs = Math.abs(v);
        if (abs <= 200) return sign * (abs / 200);
        if (abs <= 500) return sign * (1 + (abs - 200) / 300);
        if (abs <= 1200) return sign * (2 + (abs - 500) / 700);
        return sign * 3;
    }

    function inverseTransformWh(tv) {
        if (tv === 0) return 0;
        var sign = tv < 0 ? -1 : 1;
        var abs = Math.abs(tv);
        if (abs <= 1) return sign * (abs * 200);
        if (abs <= 2) return sign * (200 + (abs - 1) * 300);
        return sign * (500 + (abs - 2) * 700);
    }

    function computeIsDateLabel(hourLabels) {
        var labels = hourLabels || [];
        return labels.map(function(label) {
            if (!label || typeof label !== 'string') return false;
            var parts = label.split(' ');
            var timePart = parts[1] || '00:00';
            var hour = parseInt(timePart.split(':')[0], 10) || 0;
            return hour === 0;
        });
    }

    function extractDatePart(hourLabel) {
        if (!hourLabel || typeof hourLabel !== 'string') return null;
        var parts = hourLabel.split(' ');
        var datePart = parts[0] || '';
        return /^\d{4}-\d{2}-\d{2}$/.test(datePart) ? datePart : null;
    }

    function filterWhPerHourByDay(whPerHour, selectedDay) {
        if (!Array.isArray(whPerHour) || whPerHour.length === 0) return [];
        if (!selectedDay) return whPerHour;
        return whPerHour.filter(function(d) {
            return extractDatePart(d && d.hourLabel) === selectedDay;
        });
    }

    function rerenderMobileChartFromSelection() {
        if (!Array.isArray(latestWhPerHour) || latestWhPerHour.length === 0) return;
        var filtered = filterWhPerHourByDay(latestWhPerHour, selectedMobileDay);
        var cdMobile = buildChartDataMobile(filtered);
        updateMobileChart(cdMobile);
    }

    function getAvailableMobileDays() {
        if (!Array.isArray(latestWhPerHour) || latestWhPerHour.length === 0) return [];
        var seen = {};
        var days = [];
        latestWhPerHour.forEach(function(entry) {
            var day = extractDatePart(entry && entry.hourLabel);
            if (!day || seen[day]) return;
            seen[day] = true;
            days.push(day);
        });
        days.sort();
        return days;
    }

    function getDefaultMobileDay() {
        var days = getAvailableMobileDays();
        if (days.length === 0) return null;
        var today = new Date().toISOString().slice(0, 10);
        return days.indexOf(today) !== -1 ? today : days[days.length - 1];
    }

    function formatFocusedDayLabel(day) {
        if (!day) return 'All days';
        var today = new Date().toISOString().slice(0, 10);
        var yesterdayDate = new Date();
        yesterdayDate.setDate(yesterdayDate.getDate() - 1);
        var yesterday = yesterdayDate.toISOString().slice(0, 10);
        if (day === today) return 'Today';
        if (day === yesterday) return 'Yesterday';
        var date = new Date(day + 'T12:00:00');
        if (isNaN(date.getTime())) return day;
        var weekday = date.toLocaleDateString([], { weekday: 'short' });
        var dateLabel = date.toLocaleDateString([], { day: '2-digit', month: '2-digit' });
        return weekday + ' ' + dateLabel;
    }

    function syncMobileZoomUi() {
        var card = document.querySelector('.energy-graph-mobile');
        if (!card) return;
        var toggle = card.querySelector('[data-energy-graph-zoom-toggle]');
        var nav = card.querySelector('#energy-graph-focus-nav');
        var label = card.querySelector('[data-energy-graph-focus-label]');
        var prevBtn = card.querySelector('[data-energy-graph-nav="prev"]');
        var nextBtn = card.querySelector('[data-energy-graph-nav="next"]');
        var isFocused = !!selectedMobileDay;
        var days = getAvailableMobileDays();
        var dayIndex = selectedMobileDay ? days.indexOf(selectedMobileDay) : -1;

        card.classList.toggle('energy-graph-mobile-focused', isFocused);
        if (toggle) {
            toggle.setAttribute('aria-pressed', isFocused ? 'true' : 'false');
            toggle.textContent = isFocused ? 'All days' : 'Zoom';
        }
        if (nav) nav.hidden = !isFocused;
        if (label) label.textContent = formatFocusedDayLabel(selectedMobileDay);
        if (prevBtn) prevBtn.disabled = !isFocused || dayIndex <= 0;
        if (nextBtn) nextBtn.disabled = !isFocused || dayIndex === -1 || dayIndex >= days.length - 1;

        var totalsTab = card.querySelector('.energy-graph-mobile-tab[data-tab="daily"]');
        var totalsTitle = card.querySelector('[data-energy-graph-totals-title]');
        var totalsLabel = isFocused ? 'Hourly totals' : 'Daily totals';
        if (totalsTab) totalsTab.textContent = totalsLabel;
        if (totalsTitle) totalsTitle.textContent = totalsLabel;
    }

    function setSelectedMobileDay(day, options) {
        options = options || {};
        var availableDays = getAvailableMobileDays();
        if (!day || availableDays.indexOf(day) === -1) {
            selectedMobileDay = null;
        } else {
            selectedMobileDay = day;
        }
        syncMobileZoomUi();
        if (options.rerender !== false) {
            var chart = window.energyChartMobile;
            if (chart) {
                chart.$energyGraphSuppressTooltipRestore = !!options.suppressTooltipRestore;
                if (options.suppressTooltipRestore) {
                    chart.$energyGraphActiveHourLabel = null;
                }
            }
            rerenderMobileChartFromSelection();
            renderMobileTotalsTable();
        }
    }

    function toggleMobileZoom() {
        if (selectedMobileDay) {
            setSelectedMobileDay(null, { suppressTooltipRestore: true });
            return;
        }
        setSelectedMobileDay(getDefaultMobileDay(), { suppressTooltipRestore: true });
    }

    function stepMobileZoomDay(direction) {
        var days = getAvailableMobileDays();
        if (days.length === 0) return;
        var currentDay = selectedMobileDay || getDefaultMobileDay();
        var currentIndex = days.indexOf(currentDay);
        if (currentIndex === -1) currentIndex = days.length - 1;
        var nextIndex = currentIndex + direction;
        if (nextIndex < 0 || nextIndex >= days.length) return;
        setSelectedMobileDay(days[nextIndex], { suppressTooltipRestore: true });
    }

    function ensureMobileControlsBound() {
        if (energyGraphControlsBound) return;
        var card = document.querySelector('.energy-graph-mobile');
        if (!card) return;
        var toggle = card.querySelector('[data-energy-graph-zoom-toggle]');
        var prevBtn = card.querySelector('[data-energy-graph-nav="prev"]');
        var nextBtn = card.querySelector('[data-energy-graph-nav="next"]');
        if (toggle) {
            toggle.addEventListener('click', function() {
                toggleMobileZoom();
            });
        }
        if (prevBtn) {
            prevBtn.addEventListener('click', function() {
                stepMobileZoomDay(-1);
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function() {
                stepMobileZoomDay(1);
            });
        }
        energyGraphControlsBound = true;
        syncMobileZoomUi();
    }

    function isChartInstance(obj) {
        return !!(obj &&
            typeof obj.update === 'function' &&
            obj.data &&
            obj.data.datasets &&
            obj.data.datasets.length);
    }

    function safeDestroyChart(obj) {
        if (obj && typeof obj.destroy === 'function') {
            try { obj.destroy(); } catch (e) { /* ignore */ }
        }
    }

    function buildChartDataMobile(whPerHour) {
        var data = whPerHour || [];
        var isFocused = !!selectedMobileDay;
        var originalValues = data.map(function(d) { return Number(d.wh || 0); });
        var electricLevels = data.map(function(d) {
            if (!d || d.electricLevel == null || d.electricLevel === '') return null;
            var parsed = Number(d.electricLevel);
            if (!isFinite(parsed)) return null;
            return Math.max(0, Math.min(100, parsed));
        });
        var clippedValues = originalValues.map(function(v) { return Math.max(-1200, Math.min(1200, v)); });
        var values = clippedValues.map(transformWh);
        var barColors = originalValues.map(function(v) {
            return v >= 0 ? 'rgba(129, 199, 132, 0.7)' : 'rgba(229, 115, 115, 0.7)';
        });
        var borderColors = originalValues.map(function(v) {
            return v >= 0 ? 'rgba(129, 199, 132, 1)' : 'rgba(229, 115, 115, 1)';
        });
        var displayLabels = data.map(function(d) {
            var label = d.hourLabel;
            if (!label || typeof label !== 'string') return '';
            var parts = label.split(' ');
            var timePart = parts[1] || '00:00';
            var hour = parseInt(timePart.split(':')[0], 10) || 0;
            if (isFocused) {
                // Focused day: denser labels (e.g., every 3 hours).
                return (hour % FOCUSED_TICK_INTERVAL_HOURS === 0) ? timePart : '';
            }
            if (hour === 0) {
                var datePart = parts[0] || '';
                if (datePart && /^\d{4}-\d{2}-\d{2}$/.test(datePart)) {
                    var p = datePart.split('-');
                    return p[2] + '-' + p[1];
                }
                return datePart;
            }
            if (hour === 6) return '06:00';
            if (hour === 12) return '12:00';
            if (hour === 18) return '18:00';
            return '';
        });
        var hourLabels = data.map(function(d) { return (d && d.hourLabel) ? d.hourLabel : ''; });
        return {
            labels: displayLabels,
            values: values,
            backgroundColor: barColors,
            borderColor: borderColors,
            originalValues: originalValues,
            electricLevels: electricLevels,
            hourLabels: hourLabels
        };
    }

    function updateMobileChart(cd) {
        var chart = window.energyChartMobile;
        if (!cd) return;
        if (!isChartInstance(chart)) {
            ensureMobileChartExists();
            chart = window.energyChartMobile;
        }
        if (!isChartInstance(chart)) return;
        chart.$energyGraphIsDateLabel = computeIsDateLabel(cd.hourLabels);
        chart.$energyGraphHourLabels = cd.hourLabels || [];
        chart.data.labels = cd.labels;
        chart.data.datasets[0].data = cd.values;
        chart.data.datasets[0].backgroundColor = cd.backgroundColor;
        chart.data.datasets[0].borderColor = cd.borderColor;
        chart.data.datasets[1].data = cd.electricLevels;
        chart.options.plugins.tooltip.callbacks = {
            title: function(context) {
                var i = context[0].dataIndex;
                return cd.hourLabels[i] || context[0].label;
            },
            label: function(context) {
                var i = context.dataIndex;
                if (context.datasetIndex === 1) {
                    var pct = cd.electricLevels[i];
                    if (pct == null) return 'Battery: n/a';
                    var prevPct = i > 0 ? cd.electricLevels[i - 1] : null;
                    if (prevPct == null) return 'Battery: ' + pct.toFixed(0) + '%';
                    return 'Battery: ' + prevPct.toFixed(0) + '%-' + pct.toFixed(0) + '%';
                }
                var v = cd.originalValues[i] || 0;
                var whLabel = v.toFixed(0) + ' Wh';
                if (v > 1200) whLabel += ' (clipped at 1200)';
                else if (v < -1200) whLabel += ' (clipped at -1200)';
                return whLabel;
            }
        };
        chart.update('none');

        var shouldRestoreTooltip = !chart.$energyGraphSuppressTooltipRestore;
        chart.$energyGraphSuppressTooltipRestore = false;
        if (!shouldRestoreTooltip || !chart.tooltip || typeof chart.setActiveElements !== 'function') {
            if (chart.tooltip && typeof chart.setActiveElements === 'function') {
                chart.setActiveElements([]);
                chart.tooltip.setActiveElements([], { x: 0, y: 0 });
                chart.update('none');
            }
            return;
        }

        var activeHourLabel = chart.$energyGraphActiveHourLabel;
        var tooltipIndex = activeHourLabel ? cd.hourLabels.indexOf(activeHourLabel) : -1;
        if (tooltipIndex === -1) {
            chart.$energyGraphActiveHourLabel = null;
            chart.setActiveElements([]);
            chart.tooltip.setActiveElements([], { x: 0, y: 0 });
            chart.update('none');
            return;
        }

        var meta = chart.getDatasetMeta(0);
        var element = meta && meta.data ? meta.data[tooltipIndex] : null;
        if (!element) return;
        var centerPoint = typeof element.getCenterPoint === 'function' ? element.getCenterPoint() : { x: element.x, y: element.y };
        chart.setActiveElements([{ datasetIndex: 0, index: tooltipIndex }]);
        chart.tooltip.setActiveElements([{ datasetIndex: 0, index: tooltipIndex }], centerPoint);
        chart.update('none');
    }

    function ensureMobileChartExists() {
        if (isChartInstance(window.energyChartMobile)) return;
        safeDestroyChart(window.energyChartMobile);
        window.energyChartMobile = null;
        var ctx = document.getElementById('energyChartMobile');
        if (!ctx || typeof Chart === 'undefined') return;

        var tickColor = '#b0b0b0';
        var dateLabelColor = '#64b5f6';
        var gridColor = '#404040';

        var chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: [],
                datasets: [
                    {
                        label: 'Watt-hours',
                        data: [],
                        backgroundColor: [],
                        borderColor: [],
                        borderWidth: 1,
                        minBarLength: 0,
                        barPercentage: 0.9,
                        categoryPercentage: 0.9,
                        yAxisID: 'y'
                    },
                    {
                        type: 'line',
                        label: 'Battery %',
                        data: [],
                        yAxisID: 'yPercent',
                        borderColor: 'rgba(136, 207, 254, 0.95)',
                        backgroundColor: 'rgba(100, 181, 246, 0.25)',
                        borderWidth: 1,
                        pointRadius: 0,
                        pointHoverRadius: 8,
                        tension: 0.25,
                        spanGaps: false
                    }
                ]
            },
            options: {
                onClick: function(event, elements, chartRef) {
                    var chartObj = chartRef || window.energyChartMobile;
                    if (!isChartInstance(chartObj)) return;
                    var active = elements && elements.length
                        ? elements
                        : chartObj.getElementsAtEventForMode(event, 'nearest', { intersect: false }, false);
                    var idx = null;

                    if (active && active.length) {
                        idx = active[0].index;
                    } else {
                        // Fallback for touch cases where no active element is returned.
                        var xScale = chartObj.scales && chartObj.scales.x;
                        var xPixel = (event && typeof event.x === 'number') ? event.x : null;
                        if (xScale && xPixel !== null) {
                            var rawIdx = xScale.getValueForPixel(xPixel);
                            if (typeof rawIdx === 'number' && isFinite(rawIdx)) {
                                idx = Math.round(rawIdx);
                            }
                        }
                    }

                    if (idx === null || idx < 0) return;
                    var hourLabels = chartObj.$energyGraphHourLabels || [];
                    if (idx >= hourLabels.length) return;
                    var meta = chartObj.getDatasetMeta(0);
                    var element = meta && meta.data ? meta.data[idx] : null;
                    if (!element || !chartObj.tooltip || typeof chartObj.setActiveElements !== 'function') return;
                    var centerPoint = typeof element.getCenterPoint === 'function' ? element.getCenterPoint() : { x: element.x, y: element.y };
                    chartObj.$energyGraphActiveHourLabel = hourLabels[idx] || null;
                    chartObj.setActiveElements([{ datasetIndex: 0, index: idx }]);
                    chartObj.tooltip.setActiveElements([{ datasetIndex: 0, index: idx }], centerPoint);
                    chartObj.update('none');
                },
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: true
                },
                plugins: {
                    title: { display: false },
                    legend: { display: false },
                    tooltip: {
                        mode: 'index',
                        intersect: true,
                        backgroundColor: 'rgba(0, 0, 0, 0.5)',
                        callbacks: {}
                    }
                },
                scales: {
                    y: {
                        min: -3,
                        max: 3,
                        beginAtZero: true,
                        title: { display: false },
                        ticks: {
                            stepSize: 1,
                            color: tickColor,
                            font: { size: 11 },
                            callback: function(tickValue) {
                                return inverseTransformWh(tickValue).toFixed(0);
                            }
                        },
                        grid: { color: gridColor }
                    },
                    yPercent: {
                        position: 'right',
                        min: 0,
                        max: 100,
                        title: { display: false },
                        ticks: {
                            stepSize: 20,
                            color: '#64b5f6',
                            font: { size: 11 },
                            callback: function(v) {
                                return v + '%';
                            }
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    },
                    x: {
                        type: 'category',
                        title: { display: false },
                        grid: {
                            color: function(context) {
                                var arr = (context.chart && context.chart.$energyGraphIsDateLabel) ? context.chart.$energyGraphIsDateLabel : [];
                                return arr[context.index] ? '#888' : gridColor;
                            }
                        },
                        ticks: {
                            autoSkip: false,
                            maxRotation: 45,
                            minRotation: 45,
                            color: function(context) {
                                var arr = (context.chart && context.chart.$energyGraphIsDateLabel) ? context.chart.$energyGraphIsDateLabel : [];
                                return arr[context.index] ? dateLabelColor : tickColor;
                            },
                            font: { size: 11 }
                        }
                    }
                }
            }
        });
        chart.$energyGraphIsDateLabel = [];
        chart.$energyGraphHourLabels = [];
        chart.$energyGraphActiveHourLabel = null;
        chart.$energyGraphSuppressTooltipRestore = false;
        window.energyChartMobile = chart;
    }

    function escapeHtml(str) {
        if (str == null) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function formatCacheTimestamp(unixSeconds) {
        var value = Number(unixSeconds);
        if (!isFinite(value) || value <= 0) return '';
        var date = new Date(value * 1000);
        if (isNaN(date.getTime())) return '';
        return date.toLocaleString([], {
            year: 'numeric',
            month: 'short',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function renderCacheStatus(cacheInfo) {
        var card = document.querySelector('.energy-graph-mobile');
        if (!card) return;
        var statusEl = card.querySelector('.energy-graph-mobile-status');
        if (!statusEl) {
            statusEl = document.createElement('p');
            statusEl.className = 'energy-graph-mobile-status';
            var tabs = card.querySelector('.energy-graph-mobile-tabs');
            if (tabs && tabs.parentNode) {
                tabs.parentNode.insertBefore(statusEl, tabs.nextSibling);
            } else {
                card.insertBefore(statusEl, card.firstChild.nextSibling);
            }
        }

        if (!cacheInfo || cacheInfo.isStale !== true) {
            statusEl.hidden = true;
            statusEl.textContent = '';
            return;
        }

        var updatedAt = formatCacheTimestamp(cacheInfo.cachedAt);
        var text = 'Showing cached energy data';
        if (updatedAt) {
            text += ' from ' + updatedAt;
        }
        text += '. Live refresh from the control API failed.';
        statusEl.textContent = text;
        statusEl.hidden = false;
    }

    function sortHourRowsForTable(rows) {
        if (!Array.isArray(rows)) return [];
        return rows.slice().sort(function(a, b) {
            return ((a && a.hourLabel) || '').localeCompare((b && b.hourLabel) || '');
        });
    }

    function extractTimeFromHourLabel(hourLabel) {
        if (!hourLabel || typeof hourLabel !== 'string') return '00:00';
        var parts = hourLabel.split(' ');
        return parts[1] || '00:00';
    }

    function hourQuarterFromTimeStr(timeStr) {
        var h = parseInt((String(timeStr).split(':')[0] || '0'), 10);
        if (!isFinite(h)) h = 0;
        h = Math.max(0, Math.min(23, h));
        if (h >= 18) return 3;
        if (h >= 12) return 2;
        if (h >= 6) return 1;
        return 0;
    }

    function entryElectricLevel(entry) {
        if (!entry || entry.electricLevel == null || entry.electricLevel === '') return null;
        var parsed = Number(entry.electricLevel);
        if (!isFinite(parsed)) return null;
        return Math.max(0, Math.min(100, parsed));
    }

    function getVisibleDailyTotalDates(whPerDay) {
        return Object.keys(whPerDay || {}).sort().reverse().slice(0, DAILY_TOTALS_PNL_DAY_COUNT);
    }

    function toFiniteNumber(value) {
        if (typeof value === 'number' && isFinite(value)) return value;
        if (typeof value === 'string' && value.trim() !== '') {
            var parsed = Number(value);
            return isFinite(parsed) ? parsed : null;
        }
        return null;
    }

    function formatDailyPnlValue(value) {
        var numericValue = toFiniteNumber(value);
        if (!Number.isFinite(numericValue)) return '--';
        var prefix = numericValue > 0 ? '+' : '';
        return prefix + numericValue.toFixed(2);
    }

    function dailyPnlCellClass(value) {
        var numericValue = toFiniteNumber(value);
        if (!Number.isFinite(numericValue) || numericValue === 0) return '';
        return numericValue > 0 ? 'wh-pos' : 'wh-neg';
    }

    async function ensureDailyPnlForDates(dates) {
        if (!Array.isArray(dates) || dates.length === 0) {
            latestDailyPnlByDate = {};
            latestDailyPnlRequestKey = '';
            return;
        }
        if (!DAILY_PNL_API_URL) {
            latestDailyPnlByDate = {};
            latestDailyPnlRequestKey = dates.join(',');
            return;
        }

        var newestDate = dates[0];
        var requestKey = newestDate + '|' + dates.join(',');
        if (requestKey === latestDailyPnlRequestKey) {
            return;
        }

        try {
            var url = new URL(DAILY_PNL_API_URL, window.location.href);
            url.searchParams.set('date', newestDate);
            url.searchParams.set('n', String(DAILY_TOTALS_PNL_DAY_COUNT));
            var response = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
            var payload = await response.json();
            if (!response.ok || !payload || payload.success !== true || !Array.isArray(payload.days)) {
                throw new Error(payload && payload.error ? payload.error : 'Failed to load daily P&L data.');
            }

            var pnlByDate = {};
            payload.days.forEach(function(day) {
                if (!day || typeof day.date !== 'string') return;
                pnlByDate[day.date] = {
                    pnl_eur: toFiniteNumber(day.pnl_eur)
                };
            });

            latestDailyPnlByDate = pnlByDate;
            latestDailyPnlRequestKey = requestKey;
        } catch (error) {
            latestDailyPnlByDate = {};
            latestDailyPnlRequestKey = requestKey;
            console.warn('Daily P&L refresh failed:', error);
        }
    }

    function renderMobileTotalsTable() {
        var container = document.querySelector('.energy-graph-mobile-daily-table');
        if (!container) return;
        if (selectedMobileDay) {
            container.classList.add('energy-graph-mobile-totals-table--hourly');
            var rows = sortHourRowsForTable(filterWhPerHourByDay(latestWhPerHour, selectedMobileDay));
            renderMobileHourlyTable(container, rows);
        } else {
            container.classList.remove('energy-graph-mobile-totals-table--hourly');
            renderMobileDailyTable(latestWhPerDay, latestBaseWh, latestDailyPnlByDate);
        }
    }

    function renderMobileHourlyTable(container, rows) {
        if (!rows || rows.length === 0) {
            container.innerHTML = '<p class="energy-graph-mobile-no-data">No data</p>';
            return;
        }
        var electricLevels = rows.map(function(d) {
            return entryElectricLevel(d);
        });
        var sectionLabels = ['00:00 – 06:00', '06:00 – 12:00', '12:00 – 18:00', '18:00 – 00:00'];
        var html = '<table><thead><tr><th>Time</th><th>W</th><th>Battery</th>' +
            '<th title="Change vs previous hour">Δ%</th></tr></thead><tbody>';
        var prevQuarter = -1;
        var dataRowIndex = 0;
        for (var i = 0; i < rows.length; i++) {
            var d = rows[i];
            var timeStr = extractTimeFromHourLabel(d && d.hourLabel);
            var quarter = hourQuarterFromTimeStr(timeStr);
            if (quarter !== prevQuarter) {
                html += '<tr class="hourly-section-label"><td colspan="4">' +
                    escapeHtml(sectionLabels[quarter]) + '</td></tr>';
                prevQuarter = quarter;
            }
            var wh = d && d.wh != null ? Number(d.wh) : 0;
            var wCellClass = '';
            var wText;
            if (wh > 0) {
                wCellClass = 'wh-pos';
                wText = '+' + Math.round(wh).toLocaleString() + ' W';
            } else if (wh < 0) {
                wCellClass = 'wh-neg';
                wText = Math.round(wh).toLocaleString() + ' W';
            } else {
                wText = '0 W';
            }
            var pct = electricLevels[i];
            var prevPct = i > 0 ? electricLevels[i - 1] : null;
            var batText;
            if (pct == null) {
                batText = 'n/a';
            } else {
                if (prevPct == null) {
                    batText = pct.toFixed(0) + '%';
                } else {
                    batText = prevPct.toFixed(0) + '%-' + pct.toFixed(0) + '%';
                }
            }
            var deltaText;
            var deltaClass = '';
            if (pct == null || prevPct == null) {
                deltaText = pct == null ? 'n/a' : '—';
            } else {
                var deltaInt = Math.round(pct - prevPct);
                if (deltaInt > 0) {
                    deltaClass = 'wh-pos';
                    deltaText = '+' + deltaInt + '%';
                } else if (deltaInt < 0) {
                    deltaClass = 'wh-neg';
                    deltaText = deltaInt + '%';
                } else {
                    deltaText = '0%';
                }
            }
            var zebraClass = (dataRowIndex % 2 === 1) ? ' hourly-zebra-alt' : '';
            dataRowIndex++;
            html += '<tr class="hourly-data' + zebraClass + '"><td>' + escapeHtml(timeStr) + '</td><td' +
                (wCellClass ? ' class="' + wCellClass + '"' : '') + '>' + escapeHtml(wText) + '</td>' +
                '<td class="hourly-bat">' + escapeHtml(batText) + '</td><td' +
                (deltaClass ? ' class="' + deltaClass + '"' : '') + '>' + escapeHtml(deltaText) + '</td></tr>';
        }
        html += '</tbody></table>';
        container.innerHTML = html;
    }

    function renderMobileDailyTable(whPerDay, baseWh, pnlByDate) {
        var container = document.querySelector('.energy-graph-mobile-daily-table');
        if (!container) return;
        baseWh = baseWh || 5760;
        var dates = getVisibleDailyTotalDates(whPerDay);
        if (dates.length === 0) {
            container.innerHTML = '<p class="energy-graph-mobile-no-data">No data</p>';
            return;
        }
        var today = new Date().toISOString().slice(0, 10);
        var dayNames = ['su', 'mo', 'tu', 'we', 'th', 'fr', 'sa'];
        var html = '<table><colgroup><col class="col-date"><col class="col-day">' +
            '<col class="col-wh"><col class="col-wh"><col class="col-pct"><col class="col-pnl"><col class="col-fill"></colgroup>' +
            '<thead><tr><th>Date</th><th>Day</th><th>Wh+</th><th>Wh-</th>' +
            '<th title="% of ' + (baseWh / 1000).toFixed(2) + ' kWh (net)">%</th><th title="P&L (EUR, main price)">P&amp;L</th></tr></thead><tbody>';
        for (var i = 0; i < dates.length; i++) {
            var date = dates[i];
            var tot = whPerDay[date];
            var pos = tot.pos != null ? Number(tot.pos) : 0;
            var neg = tot.neg != null ? Number(tot.neg) : 0;
            var net = pos + neg;
            var pctNet = ((net / baseWh) * 100).toFixed(1);
            var pnlValue = pnlByDate && pnlByDate[date] ? pnlByDate[date].pnl_eur : null;
            var pnlText = formatDailyPnlValue(pnlValue);
            var pnlClass = dailyPnlCellClass(pnlValue);
            var d = new Date(date + 'T12:00:00');
            var day = dayNames[d.getDay()];
            var rowClass = date === today ? ' class="row-current"' : '';
            html += '<tr' + rowClass + '><td>' + escapeHtml(date) + '</td><td>' + escapeHtml(day) + '</td>' +
                '<td class="wh-pos">+' + Math.round(pos).toLocaleString() + '</td>' +
                '<td class="wh-neg">' + Math.round(neg).toLocaleString() + '</td>' +
                '<td>' + pctNet + '%</td>' +
                '<td' + (pnlClass ? ' class="' + pnlClass + '"' : '') + ' title="' + escapeHtml(pnlText === '--' ? '--' : ('EUR ' + pnlText)) + '">' + escapeHtml(pnlText) + '</td></tr>';
        }
        html += '</tbody></table>';
        container.innerHTML = html;
    }

    async function refreshEnergyGraph() {
        console.log('[energy_graph_refresh] refreshEnergyGraph() called');
        if (!API_URL) return;
        try {
            var res = await fetch(API_URL);
            if (!res.ok) return;
            var json = await res.json();
            var whPerHour = json.whPerHour;
            var whPerDay = json.whPerDay;
            var baseWh = json.baseWh != null ? Number(json.baseWh) : 5760;
            var cacheInfo = json.cacheInfo || null;

            latestWhPerHour = Array.isArray(whPerHour) ? whPerHour : [];
            latestWhPerDay = whPerDay && typeof whPerDay === 'object' ? whPerDay : {};
            latestBaseWh = baseWh;
            ensureMobileControlsBound();
            if (selectedMobileDay) {
                var hasSelectedDay = latestWhPerHour.some(function(d) {
                    return extractDatePart(d && d.hourLabel) === selectedMobileDay;
                });
                if (!hasSelectedDay) {
                    selectedMobileDay = getDefaultMobileDay();
                }
            }

            await ensureDailyPnlForDates(getVisibleDailyTotalDates(latestWhPerDay));

            var cdMobile = buildChartDataMobile(filterWhPerHourByDay(latestWhPerHour, selectedMobileDay));
            ensureMobileChartExists();
            updateMobileChart(cdMobile);
            syncMobileZoomUi();
            renderMobileTotalsTable();
            renderCacheStatus(cacheInfo);
        } catch (e) {
            console.warn('Energy graph refresh failed:', e);
        }
    }

    window.refreshEnergyGraph = refreshEnergyGraph;
})();
