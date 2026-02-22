/**
 * Energy Graph Refresh
 * Fetches Wh per hour and Wh per day from the API and updates the energy graph
 * chart(s) and daily totals table(s). Called from _refreshScheduleAndPricesInternal().
 */

(function() {
    var API_URL = typeof ENERGY_GRAPH_API_URL !== 'undefined' ? ENERGY_GRAPH_API_URL : 'api/energy_graph_api.php';
    var selectedMobileDay = null;
    var latestWhPerHour = [];
    // In focused-day mode, show labels every N hours on the X axis.
    var FOCUSED_TICK_INTERVAL_HOURS = 2;

    function transformWh(v) {
        if (v === 0) return 0;
        var sign = v < 0 ? -1 : 1;
        var abs = Math.abs(v);
        if (abs <= 200) return sign * (abs / 200);
        if (abs <= 400) return sign * (1 + (abs - 200) / 200);
        if (abs <= 800) return sign * (2 + (abs - 400) / 400);
        return sign * 3;
    }

    function inverseTransformWh(tv) {
        if (tv === 0) return 0;
        var sign = tv < 0 ? -1 : 1;
        var abs = Math.abs(tv);
        if (abs <= 1) return sign * (abs * 200);
        if (abs <= 2) return sign * (200 + (abs - 1) * 200);
        return sign * (400 + (abs - 2) * 400);
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
        var clippedValues = originalValues.map(function(v) { return Math.max(-800, Math.min(800, v)); });
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
                    return pct == null ? 'Battery: n/a' : ('Battery: ' + pct.toFixed(0) + '%');
                }
                var v = cd.originalValues[i] || 0;
                var whLabel = v.toFixed(0) + ' Wh';
                if (v > 800) whLabel += ' (clipped at 800)';
                else if (v < -800) whLabel += ' (clipped at -800)';
                return whLabel;
            }
        };
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
                        borderColor: 'rgba(100, 181, 246, 0.95)',
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
                    var day = extractDatePart(hourLabels[idx]);
                    if (!day) return;
                    selectedMobileDay = (selectedMobileDay === day) ? null : day;
                    rerenderMobileChartFromSelection();
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
        window.energyChartMobile = chart;
    }

    function escapeHtml(str) {
        if (str == null) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function renderMobileDailyTable(whPerDay, baseWh) {
        var container = document.querySelector('.energy-graph-mobile-daily-table');
        if (!container) return;
        baseWh = baseWh || 5760;
        var dates = Object.keys(whPerDay || {}).sort().reverse();
        if (dates.length === 0) {
            container.innerHTML = '<p class="energy-graph-mobile-no-data">No data</p>';
            return;
        }
        var today = new Date().toISOString().slice(0, 10);
        var dayNames = ['su', 'mo', 'tu', 'we', 'th', 'fr', 'sa'];
        var html = '<table><colgroup><col class="col-date"><col class="col-day">' +
            '<col class="col-wh"><col class="col-wh"><col class="col-pct"><col class="col-pct"><col class="col-pct"><col class="col-fill"></colgroup>' +
            '<thead><tr><th>Date</th><th>Day</th><th>Wh+</th><th>Wh-</th>' +
            '<th title="% of ' + (baseWh / 1000).toFixed(2) + ' kWh (net)">%</th></tr></thead><tbody>';
        for (var i = 0; i < dates.length; i++) {
            var date = dates[i];
            var tot = whPerDay[date];
            var pos = tot.pos != null ? Number(tot.pos) : 0;
            var neg = tot.neg != null ? Number(tot.neg) : 0;
            var net = pos + neg;
            var pctNet = ((net / baseWh) * 100).toFixed(1);
            var d = new Date(date + 'T12:00:00');
            var day = dayNames[d.getDay()];
            var rowClass = date === today ? ' class="row-current"' : '';
            html += '<tr' + rowClass + '><td>' + escapeHtml(date) + '</td><td>' + escapeHtml(day) + '</td>' +
                '<td class="wh-pos">+' + Math.round(pos).toLocaleString() + '</td>' +
                '<td class="wh-neg">' + Math.round(neg).toLocaleString() + '</td>' +
                '<td>' + pctNet + '%</td></tr>';
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

            latestWhPerHour = Array.isArray(whPerHour) ? whPerHour : [];
            if (selectedMobileDay) {
                var hasSelectedDay = latestWhPerHour.some(function(d) {
                    return extractDatePart(d && d.hourLabel) === selectedMobileDay;
                });
                if (!hasSelectedDay) selectedMobileDay = null;
            }

            var cdMobile = buildChartDataMobile(filterWhPerHourByDay(latestWhPerHour, selectedMobileDay));
            ensureMobileChartExists();
            updateMobileChart(cdMobile);
            renderMobileDailyTable(whPerDay, baseWh);
        } catch (e) {
            console.warn('Energy graph refresh failed:', e);
        }
    }

    window.refreshEnergyGraph = refreshEnergyGraph;
})();
