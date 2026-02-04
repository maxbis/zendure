<?php
/**
 * Energy Graph Partial
 * Bar chart of Wh per hour from automation_status.json (type=change entries with numeric newValue).
 * Self-contained so it can stand alone when other schedule parts are removed.
 */
require_once __DIR__ . '/energy_graph_data.php';
?>

<style>
    .energy-graph-wrapper { margin-top: 20px; }
    .energy-graph-card h2 { margin: 0 0 4px 0; }
    .energy-graph-subtitle { margin: 0 0 16px 0; color: #666; font-size: 0.9rem; }
    .energy-graph-header { display: flex; gap: 24px; align-items: flex-start; flex-wrap: wrap; }
    .energy-graph-heading { flex: 1 1 420px; min-width: 320px; }
    .energy-graph-chart { width: 100%; min-width: 0; margin-top: 8px; }
    .energy-graph-table { flex: 0 0 320px; min-width: 280px; }
    .energy-graph-canvas { height: 220px; max-height: 220px; background: #f8fafc; border-radius: 10px; padding: 8px 10px 4px; }
    .energy-graph-canvas canvas { display: block; width: 100%; height: 100%; }
    .energy-graph-daily { border: 1px solid #eee; border-radius: 8px; padding: 12px 16px; background: #fff; }
    .energy-graph-daily .section-title { margin: 0 0 8px 0; font-weight: 700; }
    .energy-graph-daily-table-wrapper {
        max-height: 13em; /* ~8 rows including header */
        overflow-y: auto;
        overflow-x: hidden;
        margin-top: 4px;
        scrollbar-width: thin;
    }
    .energy-graph-daily-table-wrapper::-webkit-scrollbar {
        width: 8px;
    }
    .energy-graph-daily-table-wrapper::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 4px;
    }
    .energy-graph-daily-table-wrapper::-webkit-scrollbar-thumb {
        background: #ccc;
        border-radius: 4px;
    }
    .energy-graph-daily-table-wrapper::-webkit-scrollbar-thumb:hover {
        background: #bbb;
    }
    .energy-graph-daily table { border-collapse: collapse; width: 100%; max-width: 380px; border-spacing: 0; }
    .energy-graph-daily th, .energy-graph-daily td { text-align: left; padding: 4px 8px 4px 0; }

    @media (max-width: 900px) {
        .energy-graph-header { flex-direction: column; }
        .energy-graph-heading,
        .energy-graph-table { width: 100%; min-width: 0; }
        .energy-graph-chart { margin-top: 16px; }
        .energy-graph-daily { padding: 12px; }
    }
</style>

<div class="energy-graph-wrapper">
    <div class="card energy-graph-card">
        <div class="energy-graph-header">
            <div class="energy-graph-heading">
                <h2 class="section-title">Watt-hours per hour</h2>
                <p class="energy-graph-subtitle">Data from automation status (last <?php echo $retentionDays; ?> days).</p>
                <div class="energy-graph-chart">
                    <div class="energy-graph-canvas">
                        <canvas id="energyChart"></canvas>
                    </div>
                </div>
            </div>
            <?php if (!empty($whPerDay)) : ?>
                <div class="energy-graph-table energy-graph-daily">
                    <h2 class="section-title">Daily totals</h2>
                    <div class="energy-graph-daily-table-wrapper">
                        <table>
                            <thead>
                            <tr>
                                <th>Date</th>
                                <th>Wh+</th>
                                <th>Wh-</th>
                                <th title="<?php echo htmlspecialchars('% of ' . number_format($baseKwh, 2) . ' kWh (net)'); ?>">%</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($whPerDay as $date => $totals) : ?>
                                <?php
                                    $pos = $totals['pos'];
                                    $neg = $totals['neg'];
                                    $net = $pos + $neg;
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($date); ?></td>
                                    <td style="color: #007321;font-weight: 500;">+<?php echo number_format(round($pos, 0)); ?></td>
                                    <td style="color: #e53935;font-weight: 500;"><?php echo number_format(round($neg, 0)); ?></td>
                                    <td><?php echo number_format(($net / $baseWh) * 100, 2); ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
    window.energyWhData = <?php echo json_encode($whPerHour); ?>;
</script>
<script>
    (function() {
        var data = window.energyWhData || [];
        var values = data.map(function(d) { return Number(d.wh || 0); });
        var barColors = values.map(function(v) {
            return v >= 0 ? 'rgba(76, 175, 80, 0.7)' : 'rgba(229, 57, 53, 0.7)';
        });
        var borderColors = values.map(function(v) {
            return v >= 0 ? 'rgba(76, 175, 80, 1)' : 'rgba(229, 57, 53, 1)';
        });
        // Short labels: date at 00:00 (DD-MM), 06:00, 12:00, 18:00 only
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

        var ctx = document.getElementById('energyChart');
        if (!ctx || typeof Chart === 'undefined') return;

        var maxValue = values.reduce(function(acc, v) { return Math.max(acc, v); }, 0);
        var suggestedMax = Math.max(10, Math.ceil(maxValue * 1.2));

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
                    title: {
                        display: false,
                        text: 'Watt-hours per hour'
                    },
                    legend: {
                        display: false
                    },
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
                            title: { display: true, text: 'Wh' }
                    },
                    x: {
                        type: 'category',
                        title: { display: false, text: 'Hour' },
                        ticks: {
                            autoSkip: false,
                            maxRotation: 45,
                            minRotation: 45,
                            color: function(context) {
                                return isDateLabel[context.index] ? '#1976d2' : '#333';
                            },
                            font: { size: 11 }
                        }
                    }
                }
            }
        });
    })();
</script>
