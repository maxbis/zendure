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
    .energy-graph-card h2 { margin: 0 0 4px 0; font-size: 1.25rem; }
    .energy-graph-subtitle { margin: 0 0 14px 0; color: #666; font-size: 0.9rem; }
    .energy-graph-content { display: flex; gap: 20px; align-items: stretch; }
    .energy-graph-chart { flex: 0 0 65%; min-width: 0; }
    .energy-graph-table { flex: 1; min-width: 0; }
    .energy-graph-canvas { height: 220px; background: #f8fafc; border-radius: 10px; padding: 8px 10px 4px; }
    .energy-graph-canvas canvas { display: block; width: 100%; height: 100%; }
    .energy-graph-daily { border-left: 1px solid #eee; padding-left: 16px; }
    .energy-graph-daily h3 { margin: 0 0 8px 0; font-size: 1rem; font-weight: 700; }
    .energy-graph-daily table { border-collapse: collapse; width: 100%; max-width: 380px; border-spacing: 0; }
    .energy-graph-daily th, .energy-graph-daily td { text-align: left; padding: 4px 8px 4px 0; }

    @media (max-width: 900px) {
        .energy-graph-content { flex-direction: column; }
        .energy-graph-chart { flex-basis: auto; }
        .energy-graph-daily { border-left: none; border-top: 1px solid #e0e0e0; padding-left: 0; padding-top: 12px; }
    }
</style>

<div class="energy-graph-wrapper">
    <div class="card energy-graph-card">
        <h2>Watt-hours per hour</h2>
        <p class="energy-graph-subtitle">Data from automation status (last <?php echo $retentionDays; ?> days).</p>
        <div class="energy-graph-content">
            <div class="energy-graph-chart">
                <div class="energy-graph-canvas">
                    <canvas id="energyChart"></canvas>
                </div>
            </div>
            <?php if (!empty($whPerDay)) : ?>
                <div class="energy-graph-table energy-graph-daily">
                    <h3>Daily totals</h3>
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
        // Short labels: date at 00:00, "02:00", "04:00" etc every 2h, blank at odd hours
        var displayLabels = data.map(function(d) {
            var label = d.hourLabel;
            if (!label || typeof label !== 'string') return '';
            var parts = label.split(' ');
            var timePart = parts[1] || '00:00';
            var hour = parseInt(timePart.split(':')[0], 10) || 0;
            if (hour === 0) return parts[0] || '';
            if (hour % 2 === 0) return ('0' + hour).slice(-2) + ':00';
            return ' '; // odd hours: minimal label
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
                        minBarLength: 2,
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
                            color: '#333',
                            font: { size: 11 }
                        }
                    }
                }
            }
        });
    })();
</script>
