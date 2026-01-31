<?php
/**
 * Energy (Watt-Hours per Hour) Graph
 * Bar chart of Wh per hour from automation_status.json (type=change entries with numeric newValue).
 */
date_default_timezone_set('Europe/Amsterdam');
require_once __DIR__ . '/../login/validate.php';

$dataFile = __DIR__ . '/../data/automation_status.json';
$retentionDays = 3;
$retentionSeconds = $retentionDays * 24 * 60 * 60;
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
$whPerHour = computeWhPerHour($entries, $now);

// Aggregate Wh per day (date = first 10 chars of hourLabel)
$whPerDay = [];
foreach ($whPerHour as $row) {
    $date = substr($row['hourLabel'], 0, 10);
    if (!isset($whPerDay[$date])) {
        $whPerDay[$date] = 0;
    }
    $whPerDay[$date] += $row['wh'];
}
krsort($whPerDay, SORT_STRING); // most recent first
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
            <p class="subtitle">Data from automation status (last <?php echo $retentionDays; ?> days).</p>
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
            var values = data.map(function(d) { return d.wh; });
            // Green for charging (positive), red for discharging (negative) – match schedule_renderer.js
            var barColors = values.map(function(v) {
                return v >= 0 ? 'rgba(102, 187, 106, 0.7)' : 'rgba(239, 83, 80, 0.7)';
            });
            var barBorderColors = values.map(function(v) {
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
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: { display: true, text: 'Wh' }
                        },
                        x: {
                            type: 'category',
                            title: { display: true, text: 'Hour' },
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
