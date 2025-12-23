<?php
/**
 * Electricity Price Graph Viewer
 * Displays the last two days of electricity prices from the data directory
 */

// Configuration
$dataDir = '../data/';
$filePattern = 'price*.json';

// Function to extract date from filename
function extractDateFromFilename($filename) {
    // Extract date from priceYYYYMMDD.json format
    if (preg_match('/price(\d{8})\.json$/', $filename, $matches)) {
        return $matches[1];
    }
    return null;
}

// Function to format date for display
function formatDateDisplay($dateStr) {
    $year = substr($dateStr, 0, 4);
    $month = substr($dateStr, 4, 2);
    $day = substr($dateStr, 6, 2);
    return "$day-$month-$year";
}

// Scan data directory for price files
$priceFiles = [];
$files = glob($dataDir . $filePattern);

foreach ($files as $file) {
    $filename = basename($file);
    $date = extractDateFromFilename($filename);
    if ($date) {
        $priceFiles[$date] = $file;
    }
}

// Sort by date descending and get last two days
krsort($priceFiles);
$selectedFiles = array_slice($priceFiles, 0, 2, true);

// Load and parse price data
$priceData = [];
$dates = [];

foreach ($selectedFiles as $date => $file) {
    $jsonContent = file_get_contents($file);
    $data = json_decode($jsonContent, true);
    
    if ($data && is_array($data)) {
        // Sort by hour (00-23)
        ksort($data);
        $priceData[$date] = $data;
        $dates[] = $date;
    }
}

// Reverse dates so oldest is first (left side), newest is last (right side)
$dates = array_reverse($dates);

// Prepare data for Chart.js - combine both days sequentially
$allLabels = [];
$allPrices = [];
$dayLabels = [];

// Generate hours array (00-23)
$hours = [];
for ($i = 0; $i < 24; $i++) {
    $hours[] = str_pad($i, 2, '0', STR_PAD_LEFT);
}

// Combine both days sequentially
foreach ($dates as $dateIndex => $date) {
    $dayLabel = formatDateDisplay($date);
    foreach ($hours as $hour) {
        $price = isset($priceData[$date][$hour]) ? floatval($priceData[$date][$hour]) : null;
        $allPrices[] = $price;
        $allLabels[] = $hour;
        $dayLabels[] = $dayLabel;
    }
}

// Get current date and hour
$currentDate = date('Ymd');
$currentHour = date('H');

// Find current price and index
$currentPrice = null;
$currentIndex = -1;

foreach ($dates as $dateIndex => $date) {
    if ($date == $currentDate) {
        // This is today
        if (isset($priceData[$date][$currentHour])) {
            $currentPrice = floatval($priceData[$date][$currentHour]);
            // Calculate index: dateIndex * 24 + hour index
            $hourIndex = array_search($currentHour, $hours);
            $currentIndex = $dateIndex * 24 + $hourIndex;
        }
        break;
    }
}

// Create background colors array - highlight current hour
$backgroundColorArray = [];
$borderColorArray = [];
$normalColor = 'rgba(100, 181, 246, 0.7)';
$normalBorderColor = 'rgb(100, 181, 246)';
$currentColor = 'rgba(255, 152, 0, 0.8)'; // Orange for current hour
$currentBorderColor = 'rgb(255, 152, 0)';

for ($i = 0; $i < count($allPrices); $i++) {
    if ($i === $currentIndex) {
        $backgroundColorArray[] = $currentColor;
        $borderColorArray[] = $currentBorderColor;
    } else {
        $backgroundColorArray[] = $normalColor;
        $borderColorArray[] = $normalBorderColor;
    }
}

// Create single dataset with combined data
$datasets = [[
    'label' => 'Price',
    'data' => $allPrices,
    'borderColor' => $borderColorArray,
    'backgroundColor' => $backgroundColorArray,
    'borderWidth' => 1
]];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Electricity Price Graph</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
        }

        .header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 10px;
            color: #64b5f6;
        }

        .header p {
            font-size: 1.1rem;
            color: #666;
        }

        .card {
            background: #fafafa;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            padding: 30px;
            margin-bottom: 20px;
        }

        .chart-container {
            position: relative;
            height: 500px;
            margin-top: 20px;
        }

        .info-box {
            background: #f0f0f0;
            border-left: 4px solid #64b5f6;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .info-box h3 {
            color: #333;
            margin-bottom: 10px;
            font-size: 1.2rem;
        }

        .info-box p {
            color: #666;
            line-height: 1.6;
        }

        .error-message {
            background: #fee;
            border-left: 4px solid #f00;
            padding: 20px;
            border-radius: 4px;
            color: #c00;
            text-align: center;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .stat-card {
            background: #90caf9;
            color: #333;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            border: 1px solid #64b5f6;
        }

        .stat-card h4 {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .stat-card .value {
            font-size: 2rem;
            font-weight: 700;
        }

        .current-price {
            background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%);
            color: white;
            padding: 25px;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(255, 152, 0, 0.3);
        }

        .current-price h3 {
            font-size: 1rem;
            opacity: 0.95;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 500;
        }

        .current-price .price-value {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .current-price .price-time {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        @media (max-width: 768px) {
            .header h1 {
                font-size: 2rem;
            }

            .card {
                padding: 20px;
            }

            .chart-container {
                height: 400px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⚡ Electricity Price Graph</h1>
            <p>Last Two Days of Pricing Data</p>
        </div>

        <?php if (count($priceData) == 0): ?>
            <div class="card">
                <div class="error-message">
                    <h3>No Data Available</h3>
                    <p>No price data files found in the data directory. Please run get_prices.py to fetch price data.</p>
                </div>
            </div>
        <?php elseif (count($priceData) < 2): ?>
            <div class="card">
                <div class="info-box">
                    <h3>Limited Data Available</h3>
                    <p>Only <?php echo count($priceData); ?> day(s) of data available. Showing available data.</p>
                </div>
            </div>
        <?php endif; ?>

        <?php if (count($priceData) > 0): ?>
           
            <div class="card">
                <div class="chart-container">
                    <canvas id="priceChart"></canvas>
                </div>
            </div>

            <?php
            // Calculate statistics
            $allPrices = [];
            foreach ($priceData as $date => $prices) {
                foreach ($prices as $price) {
                    $allPrices[] = $price;
                }
            }
            $minPrice = min($allPrices);
            $maxPrice = max($allPrices);
            $avgPrice = array_sum($allPrices) / count($allPrices);
            
            // Calculate current price percentile
            $currentPercentile = null;
            if ($currentPrice !== null && $maxPrice > $minPrice) {
                $currentPercentile = (($currentPrice - $minPrice) / ($maxPrice - $minPrice)) * 100;
            } elseif ($currentPrice !== null && $maxPrice == $minPrice) {
                // All prices are the same
                $currentPercentile = 50.0;
            }
            ?>

            <div class="stats">
                <div class="stat-card">
                    <h4>Minimum Price</h4>
                    <div class="value">€<?php echo number_format($minPrice, 4); ?></div>
                </div>
                <div class="stat-card">
                    <h4>Maximum Price</h4>
                    <div class="value">€<?php echo number_format($maxPrice, 4); ?></div>
                </div>
                <div class="stat-card">
                    <h4>Average Price</h4>
                    <div class="value">€<?php echo number_format($avgPrice, 4); ?></div>
                </div>
                <?php if ($currentPrice !== null && $currentPercentile !== null): ?>
                <div class="stat-card">
                    <h4>Current Price</h4>
                    <div class="value">€<?php echo number_format($currentPrice, 4); ?></div>
                    <div style="font-size: 1.2rem; margin-top: 8px; font-weight: 600; color: #666;">
                        <?php echo number_format($currentPercentile, 1); ?>% percentile
                    </div>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        <?php if (count($priceData) > 0): ?>
        <?php
        // Generate x-axis title with actual dates
        $xAxisTitle = 'Hours';
        if (count($dates) >= 1) {
            $xAxisTitle .= ' (' . formatDateDisplay($dates[0]) . ': 00-23';
            if (count($dates) >= 2) {
                $xAxisTitle .= ', ' . formatDateDisplay($dates[1]) . ': 00-23';
            }
            $xAxisTitle .= ')';
        }
        ?>
        const ctx = document.getElementById('priceChart').getContext('2d');
        const dayLabels = <?php echo json_encode($dayLabels); ?>;
        const priceChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($allLabels); ?>,
                datasets: <?php echo json_encode($datasets); ?>
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Hourly Electricity Prices (€/kWh)',
                        font: {
                            size: 18,
                            weight: 'bold'
                        },
                        padding: {
                            top: 10,
                            bottom: 30
                        }
                    },
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            font: {
                                size: 14
                            },
                            padding: 15,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            title: function(context) {
                                const index = context[0].dataIndex;
                                const dayLabel = dayLabels[index] || '';
                                const hour = context[0].label;
                                return dayLabel + ' - ' + hour + ':00';
                            },
                            label: function(context) {
                                if (context.parsed.y !== null) {
                                    return '€' + context.parsed.y.toFixed(4) + ' /kWh';
                                }
                                return '';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        title: {
                            display: true,
                            text: '<?php echo $xAxisTitle; ?>',
                            font: {
                                size: 14,
                                weight: 'bold'
                            }
                        },
                        ticks: {
                            font: {
                                size: 11
                            },
                            maxRotation: 45,
                            minRotation: 45
                        },
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        title: {
                            display: true,
                            text: 'Price (€/kWh)',
                            font: {
                                size: 14,
                                weight: 'bold'
                            }
                        },
                        ticks: {
                            font: {
                                size: 12
                            },
                            callback: function(value) {
                                return '€' + value.toFixed(3);
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        beginAtZero: false
                    }
                },
                interaction: {
                    mode: 'index',
                    axis: 'x',
                    intersect: false
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>

