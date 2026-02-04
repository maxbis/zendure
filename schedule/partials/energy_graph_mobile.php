<?php
/**
 * Energy Graph Partial - Mobile Version
 * Tabs: Graph (Wh per hour) and Daily totals. Dark mode styling.
 * Graph: today + 3 days (same as desktop). Table: today + 7 days (8 lines, scroll).
 */
require_once __DIR__ . '/energy_graph_data.php';
?>
<div class="card energy-graph-mobile">
    <h3 class="card-header">Watt-hours per hour <span class="energy-unit">(Wh)</span></h3>
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
            <h3 class="card-header">Daily totals</h3>
            <div class="energy-graph-mobile-daily-table">
                <?php if (!empty($whPerDay)) : ?>
                <table>
                    <colgroup>
                        <col class="col-date">
                        <col class="col-day">
                        <col class="col-wh"><col class="col-wh"><col class="col-pct"><col class="col-pct"><col class="col-pct">
                        <col class="col-fill">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Day</th>
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
                                $isToday = ($date === date('Y-m-d'));
                            ?>
                            <tr<?php echo $isToday ? ' class="row-current"' : ''; ?>>
                                <td><?php echo htmlspecialchars($date); ?></td>
                                <td><?php echo htmlspecialchars(strtolower(substr(date('l', strtotime($date)), 0, 2))); ?></td>
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
    window.energyWhDataMobile = <?php echo json_encode($whPerHour); ?>;
</script>
<script>
    (function() {
        var data = window.energyWhDataMobile || [];

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
        var isDateLabel = data.map(function(d) {
            var label = d.hourLabel;
            if (!label || typeof label !== 'string') return false;
            var parts = label.split(' ');
            var hour = parseInt((parts[1] || '00:00').split(':')[0], 10) || 0;
            return hour === 0;
        });

        var ctx = document.getElementById('energyChartMobile');
        if (!ctx || typeof Chart === 'undefined') return;

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
                            },
                            label: function(context) {
                                var i = context.dataIndex;
                                var v = originalValues[i] || 0;
                                var label = v.toFixed(0) + ' Wh';
                                if (v > 800) label += ' (clipped at 800)';
                                else if (v < -800) label += ' (clipped at -800)';
                                return label;
                            }
                        }
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
                    x: {
                        type: 'category',
                        title: { display: false },
                        grid: {
                            color: function(context) {
                                return isDateLabel[context.index] ? '#888' : gridColor;
                            }
                        },
                        ticks: {
                            autoSkip: false,
                            maxRotation: 45,
                            minRotation: 45,
                            color: function(context) {
                                return isDateLabel[context.index] ? dateLabelColor : tickColor;
                            },
                            font: { size: 11 }
                        }
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
