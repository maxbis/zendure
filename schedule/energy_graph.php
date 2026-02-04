<?php
/**
 * Energy (Watt-Hours per Hour) Graph
 * Bar chart of Wh per hour from automation_status.json (type=change entries with numeric newValue).
 */
date_default_timezone_set('Europe/Amsterdam');
require_once __DIR__ . '/../login/validate.php';

$dataFile = __DIR__ . '/../data/automation_status.json';
$energyGraphDaysBack = 3; // graph: today plus 3 days back
$energyTableDaysBack = 7; // table: today plus 7 days back (up to 8 lines)
$retentionSeconds = ($energyTableDaysBack + 1) * 24 * 60 * 60;
$baseWh = 5760; // 5.76 kWh – base for daily percentage

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
function computeWhPerHour(array $entries, $now) {
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
$whPerHourFull = computeWhPerHour($entries, $now);

$today = date('Y-m-d', $now);

// Table: today + last 7 days (up to 8 lines)
$tableAllowedDates = [];
for ($i = 0; $i <= $energyTableDaysBack; $i++) {
    $tableAllowedDates[] = date('Y-m-d', strtotime("-$i days", $now));
}
$whPerDay = [];
foreach ($whPerHourFull as $row) {
    $date = substr($row['hourLabel'], 0, 10);
    if (!in_array($date, $tableAllowedDates, true)) {
        continue;
    }
    if (!isset($whPerDay[$date])) {
        $whPerDay[$date] = 0;
    }
    $whPerDay[$date] += $row['wh'];
}
krsort($whPerDay, SORT_STRING); // most recent first

// Graph: restrict to today and the last 3 days only
$graphAllowedDates = [];
for ($i = 0; $i <= $energyGraphDaysBack; $i++) {
    $graphAllowedDates[] = date('Y-m-d', strtotime("-$i days", $now));
}
$whPerHour = array_values(array_filter($whPerHourFull, function ($row) use ($graphAllowedDates) {
    return in_array(substr($row['hourLabel'], 0, 10), $graphAllowedDates, true);
}));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Energy (Wh per Hour)</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        body { font-family: system-ui, sans-serif; margin: 1rem; background: #f5f5f5; }
        .page { max-width: 960px; margin: 0 auto; }
        .header { margin-bottom: 1rem; }
        .header h1 { margin: 0 0 0.25rem 0; font-size: 1.5rem; }
        .subtitle { margin: 0; color: #666; font-size: 0.9rem; }
        .card { background: #fff; padding: 1rem; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .card canvas { max-height: 400px; }
        .daily-totals { margin-top: 1rem; border: 1px solid #ddd; }
        .daily-totals h2 { margin: 0 0 0.5rem 0; font-size: 1rem; font-weight: 700; }
        .daily-totals table { border-collapse: collapse; width: 100%; max-width: 380px; border-spacing: 0; }
        .daily-totals th, .daily-totals td { text-align: left; padding: 0.25rem 0.5rem 0.25rem 0; border: 1px solid #ddd; }
        .daily-totals th { font-weight: 700; }
        .daily-totals th:nth-child(2), .daily-totals th:nth-child(3),
        .daily-totals td:nth-child(2), .daily-totals td:nth-child(3) { text-align: right; font-variant-numeric: tabular-nums; }
        .back { display: inline-block; margin-top: 1rem; color: #1976d2; text-decoration: none; }
        .back:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <main class="page">
        <header class="header">
            <h1>Watt-hours per hour</h1>
            <p class="subtitle">Data from automation status (today and last <?php echo $energyGraphDaysBack; ?> days).</p>
        </header>
        <section class="card">
            <canvas id="energyChart" height="120"></canvas>
        </section>
        <?php if (!empty($whPerDay)) : ?>
        <section class="card daily-totals">
            <h2>Daily totals</h2>
            <table>
                <thead>
                    <tr><th>Date</th><th>Wh</th><th>% of 5.76 kWh</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($whPerDay as $date => $wh) : ?>
                    <tr><td><?php echo htmlspecialchars($date); ?></td><td><?php echo number_format(round($wh, 0)); ?></td><td><?php echo number_format(($wh / $baseWh) * 100, 2); ?>%</td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <?php endif; ?>
        <a href="charge_schedule.php" class="back">← Back to Schedule</a>
    </main>
    <script>
        window.energyWhData = <?php echo json_encode($whPerHour); ?>;
    </script>
    <script>
        (function() {
            var data = window.energyWhData || [];
            
            // Non-linear transform functions
            function transformWh(v) {
                if (v === 0) return 0;
                var sign = v < 0 ? -1 : 1;
                var abs = Math.abs(v);
                if (abs <= 200) return sign * (abs / 200);
                if (abs <= 400) return sign * (1 + (abs - 200) / 200);
                if (abs <= 800) return sign * (2 + (abs - 400) / 400);
                return sign * 3; // clamped at ±800
            }
            
            function inverseTransformWh(tv) {
                if (tv === 0) return 0;
                var sign = tv < 0 ? -1 : 1;
                var abs = Math.abs(tv);
                if (abs <= 1) return sign * (abs * 200);
                if (abs <= 2) return sign * (200 + (abs - 1) * 200);
                return sign * (400 + (abs - 2) * 400);
            }
            
            // Data preparation
            var originalValues = data.map(function(d) { return Number(d.wh || 0); });
            var clippedValues = originalValues.map(function(v) { return Math.max(-800, Math.min(800, v)); });
            var values = clippedValues.map(transformWh);
            
            // Colors based on original sign
            var barColors = originalValues.map(function(v) {
                return v >= 0 ? 'rgba(102, 187, 106, 0.7)' : 'rgba(239, 83, 80, 0.7)';
            });
            var barBorderColors = originalValues.map(function(v) {
                return v >= 0 ? 'rgba(102, 187, 106, 1)' : 'rgba(239, 83, 80, 1)';
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
            var isDateLabel = data.map(function(d) {
                var parts = (d.hourLabel || '').split(' ');
                var hour = parseInt((parts[1] || '00:00').split(':')[0], 10) || 0;
                return hour === 0;
            });

            var ctx = document.getElementById('energyChart');
            if (!ctx) return;

            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: displayLabels,
                    datasets: [{
                        label: 'Watt-hours',
                        data: values,
                        backgroundColor: barColors,
                        borderColor: barBorderColors,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Watt-hours per hour'
                        },
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
                                    if (v > 800) {
                                        label += ' (clipped at 800)';
                                    } else if (v < -800) {
                                        label += ' (clipped at -800)';
                                    }
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
                            ticks: {
                                stepSize: 1,
                                callback: function(tickValue) {
                                    return inverseTransformWh(tickValue).toFixed(0);
                                }
                            },
                            title: { display: true, text: 'Wh (non-linear scale)' }
                        },
                        x: {
                            type: 'category',
                            title: { display: true, text: 'Hour' },
                            grid: {
                                color: function(context) {
                                    return isDateLabel[context.index] ? '#555' : '#e8e8e8';
                                }
                            },
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
</body>
</html>
