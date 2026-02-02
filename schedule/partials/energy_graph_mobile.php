<?php
/**
 * Energy Graph Partial - Mobile Version
 * Tabs: Graph (Wh per hour) and Daily totals. Dark mode styling.
 * Shows only the last 3 days in the graph to save horizontal space.
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
    <div class="energy-graph-mobile-tabs" role="tablist">
        <button type="button" class="energy-graph-mobile-tab active" data-tab="graph" role="tab" aria-selected="true">Graph</button>
        <button type="button" class="energy-graph-mobile-tab" data-tab="daily" role="tab" aria-selected="false">Daily totals</button>
    </div>
    <div class="energy-graph-mobile-tab-panels">
        <div class="energy-graph-mobile-tab-panel active" data-tab="graph" role="tabpanel" aria-hidden="false">
            <div class="energy-graph-canvas-mobile">
                <canvas id="energyChartMobile"></canvas>
            </div>
        </div>
        <div class="energy-graph-mobile-tab-panel" data-tab="daily" role="tabpanel" aria-hidden="true">
            <h3 class="energy-graph-mobile-daily-title">Daily totals</h3>
            <div class="energy-graph-mobile-daily-table">
                <?php if (!empty($whPerDay)) : ?>
                <table>
                    <colgroup>
                        <col class="col-date">
                        <col class="col-wh"><col class="col-wh"><col class="col-pct"><col class="col-pct"><col class="col-pct">
                        <col class="col-fill">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Wh+</th>
                            <th>Wh-</th>
                            <!-- <th title="<?php echo htmlspecialchars('% of ' . number_format($baseKwh, 2) . ' kWh (gained)'); ?>">%+</th> -->
                            <!-- <th title="<?php echo htmlspecialchars('% of ' . number_format($baseKwh, 2) . ' kWh (lost)'); ?>">%-</th> -->
                            <th title="<?php echo htmlspecialchars('% of ' . number_format($baseKwh, 2) . ' kWh (net)'); ?>">%</th>
                            <!-- <th></th> -->
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($whPerDay as $date => $totals) : ?>
                            <?php
                                $pos = $totals['pos'];
                                $neg = $totals['neg'];
                                $net = $pos + $neg;
                                $pctPos = ($pos / $baseWh) * 100;
                                $pctNeg = ($neg / $baseWh) * 100;
                                $pctNet = ($net / $baseWh) * 100;
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($date); ?></td>
                                <td class="wh-pos">+<?php echo number_format(round($pos, 0)); ?></td>
                                <td class="wh-neg"><?php echo number_format(round($neg, 0)); ?></td>
                                <!-- <td class="wh-pos"><?php echo number_format($pctPos, 1); ?>%</td> -->
                                <!-- <td class="wh-neg"><?php echo number_format($pctNeg, 1); ?>%</td> -->
                                <td><?php echo number_format($pctNet, 1); ?>%</td>
                                <!-- <td></td> -->
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else : ?>
                    <p class="energy-graph-mobile-no-data">No data</p>
                <?php endif; ?>
            </div>
        </div>
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

        var chart = new Chart(ctx, {
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
        window.energyChartMobile = chart;
    })();

    (function() {
        var tabs = document.querySelectorAll('.energy-graph-mobile-tab');
        var panels = document.querySelectorAll('.energy-graph-mobile-tab-panel');
        tabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                var targetTab = this.getAttribute('data-tab');
                tabs.forEach(function(t) {
                    t.classList.toggle('active', t.getAttribute('data-tab') === targetTab);
                    t.setAttribute('aria-selected', t.getAttribute('data-tab') === targetTab ? 'true' : 'false');
                });
                panels.forEach(function(panel) {
                    var isActive = panel.getAttribute('data-tab') === targetTab;
                    panel.classList.toggle('active', isActive);
                    panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
                });
                if (targetTab === 'graph' && window.energyChartMobile) {
                    window.energyChartMobile.resize();
                }
            });
        });
    })();
</script>
