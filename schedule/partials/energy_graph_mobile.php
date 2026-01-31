<?php
/**
 * Energy Graph Partial - Mobile Version
 * Graph-only (no daily totals), dark mode styling.
 * Shows only the last 3 days to save horizontal space.
 */
require_once __DIR__ . '/energy_graph_data.php';

$mobileRetentionDays = 3;
$dates = array_unique(array_map(function ($row) {
    return substr($row['hourLabel'], 0, 10);
}, $whPerHour));
rsort($dates, SORT_STRING);
$lastDates = array_slice($dates, 0, $mobileRetentionDays);
$whPerHourMobile = array_filter($whPerHour, function ($row) use ($lastDates) {
    return in_array(substr($row['hourLabel'], 0, 10), $lastDates);
});
$whPerHourMobile = array_values($whPerHourMobile);
?>
<div class="card energy-graph-mobile">
    <h2>Watt-hours per hour <span class="energy-unit">(Wh)</span></h2>
    <div class="energy-graph-canvas-mobile">
        <canvas id="energyChartMobile"></canvas>
    </div>
</div>
<script>
    window.energyWhDataMobile = <?php echo json_encode($whPerHourMobile); ?>;
</script>
<script>
    (function() {
        var data = window.energyWhDataMobile || [];
        var values = data.map(function(d) { return Number(d.wh || 0); });
        var barColors = values.map(function(v) {
            return v >= 0 ? 'rgba(129, 199, 132, 0.7)' : 'rgba(229, 115, 115, 0.7)';
        });
        var borderColors = values.map(function(v) {
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
        var isDateLabel = data.map(function(d) {
            var label = d.hourLabel;
            if (!label || typeof label !== 'string') return false;
            var parts = label.split(' ');
            var hour = parseInt((parts[1] || '00:00').split(':')[0], 10) || 0;
            return hour === 0;
        });

        var ctx = document.getElementById('energyChartMobile');
        if (!ctx || typeof Chart === 'undefined') return;

        var maxValue = values.reduce(function(acc, v) { return Math.max(acc, Math.abs(v)); }, 0);
        var suggestedMax = Math.max(10, Math.ceil(maxValue * 1.2));

        var tickColor = '#b0b0b0';
        var dateLabelColor = '#64b5f6';
        var gridColor = '#404040';

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: displayLabels,
                datasets: [{
                    label: 'Watt-hours',
                    data: values,
                    backgroundColor: barColors,
                    borderColor: borderColors,
                    borderWidth: 1,
                    minBarLength: 0,
                    barPercentage: 0.9,
                    categoryPercentage: 0.9
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: { display: false },
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function(context) {
                                var i = context[0].dataIndex;
                                return (data[i] && data[i].hourLabel) ? data[i].hourLabel : context[0].label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        suggestedMax: suggestedMax,
                        title: { display: false },
                        ticks: { color: tickColor, font: { size: 11 } },
                        grid: { color: gridColor }
                    },
                    x: {
                        type: 'category',
                        title: { display: false },
                        ticks: {
                            autoSkip: false,
                            maxRotation: 45,
                            minRotation: 45,
                            color: function(context) {
                                return isDateLabel[context.index] ? dateLabelColor : tickColor;
                            },
                            font: { size: 11 }
                        },
                        grid: { color: gridColor }
                    }
                }
            }
        });
    })();
</script>
