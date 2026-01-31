<?php
/**
 * Energy Graph Partial
 * Bar chart of Wh per hour from automation_status.json (type=change entries with numeric newValue).
 * Self-contained so it can stand alone when other schedule parts are removed.
 */
date_default_timezone_set('Europe/Amsterdam');
require_once __DIR__ . '/../../login/validate.php';

$dataFile = __DIR__ . '/../../data/automation_status.json';
$retentionDays = 4;
$retentionSeconds = $retentionDays * 24 * 60 * 60;
$baseWh = 5760; // 5.76 kWh – base for daily percentage
$baseKwh = $baseWh / 1000;

// Load automation status data
$entries = [];
if (file_exists($dataFile)) {
    $json = file_get_contents($dataFile);
    $data = json_decode($json, true);
    if (is_array($data) && isset($data['entries'])) {
        $entries = $data['entries'];
    }
}

/**
 * Compute watt-hours per calendar hour from automation entries.
 * Uses only type=change entries with numeric newValue (watts).
 * Step integration: power constant between consecutive readings; bucket by hour.
 *
 * @param array $entries Raw entries from automation_status.json
 * @param int $now Current Unix timestamp (for extending last segment)
 * @return array List of ['hourLabel' => 'Y-m-d H:00', 'wh' => float]
 */
function computeWhPerHour(array $entries, $now)
{
    $points = [];
    foreach ($entries as $e) {
        if (!isset($e['type']) || $e['type'] !== 'change') {
            continue;
        }
        $nv = isset($e['newValue']) ? $e['newValue'] : null;
        if ($nv === null || !is_numeric($nv)) {
            continue;
        }
        $ts = isset($e['timestamp']) ? (int)$e['timestamp'] : 0;
        $points[] = ['t' => $ts, 'watts' => (float)$nv];
    }
    if (empty($points)) {
        return [];
    }
    usort($points, function ($a, $b) {
        return $a['t'] - $b['t'];
    });

    $whByHour = [];
    $n = count($points);
    for ($i = 0; $i < $n; $i++) {
        $tStart = $points[$i]['t'];
        $tEnd = ($i < $n - 1) ? $points[$i + 1]['t'] : $now;
        $power = $points[$i]['watts'];
        $cur = $tStart;
        while ($cur < $tEnd) {
            $hourStart = strtotime(date('Y-m-d H:00:00', $cur));
            $hourEnd = $hourStart + 3600;
            $clipStart = max($tStart, $hourStart);
            $clipEnd = min($tEnd, $hourEnd);
            if ($clipStart < $clipEnd) {
                $hourLabel = date('Y-m-d H:00', $hourStart);
                if (!isset($whByHour[$hourLabel])) {
                    $whByHour[$hourLabel] = 0;
                }
                $whByHour[$hourLabel] += $power * ($clipEnd - $clipStart) / 3600;
            }
            $cur = $hourEnd;
        }
    }

    ksort($whByHour);
    $result = [];
    foreach ($whByHour as $hourLabel => $wh) {
        $result[] = ['hourLabel' => $hourLabel, 'wh' => round($wh, 2)];
    }
    return $result;
}

$now = time();
$whPerHour = computeWhPerHour($entries, $now);

// Aggregate Wh per day (date = first 10 chars of hourLabel)
$whPerDay = [];
foreach ($whPerHour as $row) {
    $date = substr($row['hourLabel'], 0, 10);
    if (!isset($whPerDay[$date])) {
        $whPerDay[$date] = ['pos' => 0, 'neg' => 0];
    }
    $wh = $row['wh'];
    if ($wh >= 0) {
        $whPerDay[$date]['pos'] += $wh;
    } else {
        $whPerDay[$date]['neg'] += $wh;
    }
}
# krsort($whPerDay, SORT_STRING); // most recent first
# ksort($whPerDay, SORT_STRING); // oldest first
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
