/**
 * Energy Graph Refresh
 * Fetches Wh per hour and Wh per day from the API and updates the energy graph
 * chart(s) and daily totals table(s). Called from _refreshScheduleAndPricesInternal().
 */

(function() {
    var API_URL = typeof ENERGY_GRAPH_API_URL !== 'undefined' ? ENERGY_GRAPH_API_URL : 'api/energy_graph_api.php';

    function transformWh(v) {
        if (v === 0) return 0;
        var sign = v < 0 ? -1 : 1;
        var abs = Math.abs(v);
        if (abs <= 200) return sign * (abs / 200);
        if (abs <= 400) return sign * (1 + (abs - 200) / 200);
        if (abs <= 800) return sign * (2 + (abs - 400) / 400);
        return sign * 3;
    }

    function buildChartData(whPerHour) {
        var data = whPerHour || [];
        var originalValues = data.map(function(d) { return Number(d.wh || 0); });
        var clippedValues = originalValues.map(function(v) { return Math.max(-800, Math.min(800, v)); });
        var values = clippedValues.map(transformWh);
        var barColors = originalValues.map(function(v) {
            return v >= 0 ? 'rgba(76, 175, 80, 0.7)' : 'rgba(229, 57, 53, 0.7)';
        });
        var borderColors = originalValues.map(function(v) {
            return v >= 0 ? 'rgba(76, 175, 80, 1)' : 'rgba(229, 57, 53, 1)';
        });
        var displayLabels = data.map(function(d) {
            var label = d.hourLabel;
            if (!label || typeof label !== 'string') return '';
            var parts = label.split(' ');
            var timePart = parts[1] || '00:00';
            var hour = parseInt(timePart.split(':')[0], 10) || 0;
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
            hourLabels: hourLabels
        };
    }

    function buildChartDataMobile(whPerHour) {
        var data = whPerHour || [];
        var originalValues = data.map(function(d) { return Number(d.wh || 0); });
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
            hourLabels: hourLabels
        };
    }

    function updateDesktopChart(cd) {
        var chart = window.energyChart;
        if (!chart || !cd) return;
        chart.data.labels = cd.labels;
        chart.data.datasets[0].data = cd.values;
        chart.data.datasets[0].backgroundColor = cd.backgroundColor;
        chart.data.datasets[0].borderColor = cd.borderColor;
        chart.options.plugins.tooltip.callbacks = {
            title: function(context) {
                var i = context[0].dataIndex;
                return cd.hourLabels[i] || context[0].label;
            },
            label: function(context) {
                var i = context.dataIndex;
                var v = cd.originalValues[i] || 0;
                var label = v.toFixed(0) + ' Wh';
                if (v > 800) label += ' (clipped at 800)';
                else if (v < -800) label += ' (clipped at -800)';
                return label;
            }
        };
        chart.update('none');
    }

    function updateMobileChart(cd) {
        var chart = window.energyChartMobile;
        if (!chart || !cd) return;
        chart.data.labels = cd.labels;
        chart.data.datasets[0].data = cd.values;
        chart.data.datasets[0].backgroundColor = cd.backgroundColor;
        chart.data.datasets[0].borderColor = cd.borderColor;
        chart.options.plugins.tooltip.callbacks = {
            title: function(context) {
                var i = context[0].dataIndex;
                return cd.hourLabels[i] || context[0].label;
            },
            label: function(context) {
                var i = context.dataIndex;
                var v = cd.originalValues[i] || 0;
                var label = v.toFixed(0) + ' Wh';
                if (v > 800) label += ' (clipped at 800)';
                else if (v < -800) label += ' (clipped at -800)';
                return label;
            }
        };
        chart.update('none');
    }

    function escapeHtml(str) {
        if (str == null) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function renderDesktopDailyTable(whPerDay, baseWh) {
        var wrapper = document.querySelector('.energy-graph-daily-table-wrapper');
        if (!wrapper) return;
        var tbody = wrapper.querySelector('tbody');
        if (!tbody) return;
        baseWh = baseWh || 5760;
        var dates = Object.keys(whPerDay || {}).sort().reverse();
        var html = '';
        for (var i = 0; i < dates.length; i++) {
            var date = dates[i];
            var tot = whPerDay[date];
            var pos = tot.pos != null ? Number(tot.pos) : 0;
            var neg = tot.neg != null ? Number(tot.neg) : 0;
            var net = pos + neg;
            var pct = ((net / baseWh) * 100).toFixed(2);
            html += '<tr><td>' + escapeHtml(date) + '</td>' +
                '<td style="color: #007321;font-weight: 500;">+' + Math.round(pos).toLocaleString() + '</td>' +
                '<td style="color: #e53935;font-weight: 500;">' + Math.round(neg).toLocaleString() + '</td>' +
                '<td>' + pct + '%</td></tr>';
        }
        tbody.innerHTML = html || '<tr><td colspan="4">No data</td></tr>';
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
        if (!API_URL) return;
        try {
            var res = await fetch(API_URL);
            if (!res.ok) return;
            var json = await res.json();
            var whPerHour = json.whPerHour;
            var whPerDay = json.whPerDay;
            var baseWh = json.baseWh != null ? Number(json.baseWh) : 5760;

            var cd = buildChartData(whPerHour);
            var cdMobile = buildChartDataMobile(whPerHour);
            updateDesktopChart(cd);
            updateMobileChart(cdMobile);
            renderDesktopDailyTable(whPerDay, baseWh);
            renderMobileDailyTable(whPerDay, baseWh);
        } catch (e) {
            console.warn('Energy graph refresh failed:', e);
        }
    }

    window.refreshEnergyGraph = refreshEnergyGraph;
})();
