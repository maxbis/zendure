<?php
/**
 * Solar & Price Data Graph
 * Displays combined_solar_price.json (direct radiation, price, spot_price, sun_degrees) in a Chart.js graph.
 */
require_once __DIR__ . '/../login/validate.php';

date_default_timezone_set('Europe/Amsterdam');

$jsonPath = __DIR__ . '/data/combined_solar_price.json';
$chartData = null;
$meta = [];
$error = null;

if (!file_exists($jsonPath)) {
    $error = 'Data file not found: data/combined_solar_price.json';
} else {
    $raw = file_get_contents($jsonPath);
    $data = $raw ? json_decode($raw, true) : null;
    if (!$data || !isset($data['combined'])) {
        $error = 'Invalid or empty data file.';
    } else {
        $meta = [
            'timezone' => $data['timezone'] ?? '',
            'location' => $data['location'] ?? '',
            'generated_at' => $data['generated_at'] ?? '',
            'range' => $data['range'] ?? []
        ];
        $labels = [];
        $directRadiation = [];
        $price = [];
        $spotPrice = [];
        $sunDegrees = [];
        $combined = $data['combined'];
        $dates = array_keys($combined);
        sort($dates);
        foreach ($dates as $date) {
            $hours = $combined[$date];
            if (!is_array($hours)) continue;
            for ($h = 0; $h < 24; $h++) {
                $hourKey = (string) $h;
                $point = isset($hours[$hourKey]) ? $hours[$hourKey] : [];
                $labels[] = $date . ' ' . str_pad($h, 2, '0', STR_PAD_LEFT) . ':00';
                $directRadiation[] = isset($point['direct_radiation']) ? (float) $point['direct_radiation'] : null;
                $price[] = isset($point['price']) ? (float) $point['price'] / 1000 : null;
                $spotPrice[] = isset($point['spot_price']) ? (float) $point['spot_price'] / 1000 : null;
                $sunDegrees[] = isset($point['sun_degrees']) ? (float) $point['sun_degrees'] : null;
            }
        }
        $chartData = [
            'labels' => $labels,
            'direct_radiation' => $directRadiation,
            'price' => $price,
            'spot_price' => $spotPrice,
            'sun_degrees' => $sunDegrees,
            'dates' => $dates,
            'today' => date('Y-m-d')
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solar & Price Data</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: #f8fafc;
            padding: 24px;
            min-height: 100vh;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { margin-bottom: 8px; font-size: 1.5rem; color: #1a1a1a; }
        .meta {
            color: #666;
            font-size: 0.875rem;
            margin-bottom: 20px;
        }
        .meta span { margin-right: 16px; }
        .chart-wrapper {
            background: #fff;
            border-radius: 10px;
            padding: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .chart-canvas { height: 220px; width: 100%; }
        .chart-controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 20px;
            margin-bottom: 16px;
        }
        .chart-controls label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            font-size: 0.875rem;
            user-select: none;
        }
        .chart-controls label input { cursor: pointer; }
        .day-nav {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .day-nav button {
            padding: 6px 14px;
            font-size: 0.875rem;
            border: 1px solid #cbd5e0;
            background: #fff;
            border-radius: 6px;
            cursor: pointer;
        }
        .day-nav button:hover:not(:disabled) {
            background: #f1f5f9;
        }
        .day-nav button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .day-nav .day-range {
            font-size: 0.875rem;
            color: #4a5568;
            min-width: 180px;
        }
        .error {
            padding: 24px;
            background: #fff5f5;
            color: #c53030;
            border-radius: 8px;
            border: 1px solid #feb2b2;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php else: ?>
            <h1>Solar & Price Data</h1>
            <div class="meta">
                <?php if (!empty($meta['location'])): ?><span><?php echo htmlspecialchars($meta['location']); ?></span><?php endif; ?>
                <?php if (!empty($meta['timezone'])): ?><span><?php echo htmlspecialchars($meta['timezone']); ?></span><?php endif; ?>
                <?php if (!empty($meta['range']['start_date']) && !empty($meta['range']['end_date'])): ?>
                    <span><?php echo htmlspecialchars($meta['range']['start_date'] . ' → ' . $meta['range']['end_date']); ?></span>
                <?php endif; ?>
                <?php if (!empty($meta['generated_at'])): ?><span>Generated: <?php echo htmlspecialchars($meta['generated_at']); ?></span><?php endif; ?>
            </div>
            <div class="chart-wrapper">
                <div class="chart-controls">
                    <label><input type="checkbox" id="cb-price" checked> Price (€/kWh)</label>
                    <label><input type="checkbox" id="cb-spot-price"> Spot price (€/kWh)</label>
                    <div class="day-nav">
                        <button type="button" id="btn-prev" title="Previous day">← Prev</button>
                        <span class="day-range" id="day-range"></span>
                        <button type="button" id="btn-next" title="Next day">Next →</button>
                    </div>
                </div>
                <div class="chart-canvas">
                    <canvas id="solarPriceChart"></canvas>
                </div>
            </div>
            <script>
            (function() {
                var chartData = <?php echo json_encode($chartData); ?>;
                if (!chartData || !chartData.labels || chartData.labels.length === 0) return;

                var dates = chartData.dates || [];
                var today = chartData.today || '';
                var labels = chartData.labels;
                var directRadiation = chartData.direct_radiation;
                var price = chartData.price;
                var spotPrice = chartData.spot_price;
                var sunDegrees = chartData.sun_degrees;

                var POINTS_PER_DAY = 24;
                var todayIdx = dates.indexOf(today);
                if (todayIdx < 0) todayIdx = Math.floor(dates.length / 2);
                var centerIdx = todayIdx;

                function sliceForWindow() {
                    var startIdx = Math.max(0, centerIdx - 1);
                    var endIdx = Math.min(dates.length, centerIdx + 2);
                    var start = startIdx * POINTS_PER_DAY;
                    var count = (endIdx - startIdx) * POINTS_PER_DAY;
                    return {
                        start: start,
                        count: count,
                        startIdx: startIdx,
                        endIdx: endIdx,
                        dayCount: endIdx - startIdx
                    };
                }

                function getSlicedData() {
                    var w = sliceForWindow();
                    return {
                        labels: labels.slice(w.start, w.start + w.count),
                        direct_radiation: directRadiation.slice(w.start, w.start + w.count),
                        price: price.slice(w.start, w.start + w.count),
                        spot_price: spotPrice.slice(w.start, w.start + w.count),
                        sun_degrees: sunDegrees.slice(w.start, w.start + w.count)
                    };
                }

                function formatDayRange() {
                    var w = sliceForWindow();
                    return (dates[w.startIdx] || '') + ' — ' + (dates[w.endIdx - 1] || '');
                }

                function canPrev() { return centerIdx > 1; }
                function canNext() { return centerIdx < dates.length - 2; }

                var displayLabels = [];
                var fullLabels = [];
                var fullSunDegrees = [];
                var chart = null;

                function buildDisplayLabels(lbls) {
                    return lbls.map(function(l, i) { return (i % 6 === 0) ? l : ''; });
                }

                function dayBoundaryIndices(dayCount) {
                    var out = [];
                    for (var d = 1; d < dayCount; d++) out.push(d * POINTS_PER_DAY);
                    return out;
                }

                var dayDividerPlugin = {
                    id: 'dayDividers',
                    beforeDraw: function(chart) {
                        var w = sliceForWindow();
                        if (w.dayCount < 2) return;
                        var ctx = chart.ctx;
                        var ca = chart.chartArea;
                        var xScale = chart.scales.x;
                        if (!xScale || !ca) return;
                        var bounds = dayBoundaryIndices(w.dayCount);
                        ctx.save();
                        ctx.strokeStyle = '#666';
                        ctx.lineWidth = 1.5;
                        ctx.setLineDash([]);
                        bounds.forEach(function(idx) {
                            var xPos = xScale.getPixelForValue(chart.data.labels[idx]);
                            if (xPos >= ca.left && xPos <= ca.right) {
                                ctx.beginPath();
                                ctx.moveTo(xPos, ca.top);
                                ctx.lineTo(xPos, ca.bottom);
                                ctx.stroke();
                            }
                        });
                        ctx.restore();
                    }
                };

                function render() {
                    var sliced = getSlicedData();
                    fullLabels = sliced.labels;
                    fullSunDegrees = sliced.sun_degrees;
                    displayLabels = buildDisplayLabels(sliced.labels);

                    var priceShown = document.getElementById('cb-price').checked;
                    var spotPriceShown = document.getElementById('cb-spot-price').checked;

                    if (!chart) {
                        var ctx = document.getElementById('solarPriceChart');
                        if (!ctx || typeof Chart === 'undefined') return;
                        chart = new Chart(ctx, {
                            type: 'line',
                            data: {
                                labels: displayLabels,
                                datasets: [
                                    {
                                        label: 'Price (€/kWh)',
                                        data: sliced.price,
                                        borderColor: 'rgba(25, 118, 210, 1)',
                                        backgroundColor: 'rgba(25, 118, 210, 0.1)',
                                        borderWidth: 2,
                                        fill: false,
                                        tension: 0.2,
                                        yAxisID: 'y',
                                        hidden: !priceShown
                                    },
                                    {
                                        label: 'Spot price (€/kWh)',
                                        data: sliced.spot_price,
                                        borderColor: 'rgba(156, 39, 176, 1)',
                                        backgroundColor: 'rgba(156, 39, 176, 0.1)',
                                        borderWidth: 2,
                                        fill: false,
                                        tension: 0.2,
                                        yAxisID: 'y',
                                        hidden: !spotPriceShown
                                    },
                                    {
                                        label: 'Direct radiation (W/m²)',
                                        data: sliced.direct_radiation,
                                        borderColor: 'rgba(255, 152, 0, 1)',
                                        backgroundColor: 'rgba(255, 152, 0, 0.2)',
                                        borderWidth: 2,
                                        fill: true,
                                        tension: 0.2,
                                        yAxisID: 'y1'
                                    }
                                ]
                            },
                            plugins: [dayDividerPlugin],
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                interaction: { mode: 'index', intersect: false },
                                plugins: {
                                    legend: { display: false },
                                    tooltip: {
                                        callbacks: {
                                            title: function(context) {
                                                var i = context[0].dataIndex;
                                                return fullLabels[i] || '';
                                            },
                                            afterBody: function(context) {
                                                var i = context[0].dataIndex;
                                                var deg = fullSunDegrees[i];
                                                if (deg != null) return 'Sun: ' + deg.toFixed(1) + '°';
                                                return '';
                                            }
                                        }
                                    }
                                },
                                scales: {
                                    y: {
                                        type: 'linear',
                                        display: true,
                                        position: 'left',
                                        title: { display: true, text: '€/kWh' },
                                        min: 0
                                    },
                                    y1: {
                                        type: 'linear',
                                        display: true,
                                        position: 'right',
                                        title: { display: true, text: 'W/m²' },
                                        min: 0,
                                        grid: { drawOnChartArea: false }
                                    },
                                    x: {
                                        grid: { color: '#e8e8e8' },
                                        ticks: { maxRotation: 45, minRotation: 45, maxTicksLimit: 24 }
                                    }
                                }
                            }
                        });
                    } else {
                        chart.data.labels = displayLabels;
                        chart.data.datasets[0].data = sliced.price;
                        chart.data.datasets[0].hidden = !priceShown;
                        chart.data.datasets[1].data = sliced.spot_price;
                        chart.data.datasets[1].hidden = !spotPriceShown;
                        chart.data.datasets[2].data = sliced.direct_radiation;
                        chart.update('none');
                    }

                    document.getElementById('day-range').textContent = formatDayRange();
                    document.getElementById('btn-prev').disabled = !canPrev();
                    document.getElementById('btn-next').disabled = !canNext();
                }

                document.getElementById('cb-price').addEventListener('change', render);
                document.getElementById('cb-spot-price').addEventListener('change', render);
                document.getElementById('btn-prev').addEventListener('click', function() {
                    if (canPrev()) { centerIdx--; render(); }
                });
                document.getElementById('btn-next').addEventListener('click', function() {
                    if (canNext()) { centerIdx++; render(); }
                });

                render();
            })();
            </script>
        <?php endif; ?>
    </div>
</body>
</html>
