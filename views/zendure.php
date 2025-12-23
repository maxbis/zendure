<?php
/**
 * Zendure SolarFlow Status Viewer
 * Displays current status from zendure_data.json
 */

// Configuration
$dataDir = '../data/';
$dataFile = $dataDir . 'zendure_data.json';

// Load and parse Zendure data
$zendureData = null;
$errorMessage = null;

if (file_exists($dataFile)) {
    $jsonContent = file_get_contents($dataFile);
    $zendureData = json_decode($jsonContent, true);
    
    if (!$zendureData) {
        $errorMessage = "Failed to parse JSON data.";
    }
} else {
    $errorMessage = "Data file not found. Please run read_zendure.py to fetch data.";
}

// Helper functions
function formatTimestamp($timestamp) {
    try {
        $dt = new DateTime($timestamp);
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return $timestamp;
    }
}

function formatDate($timestamp) {
    try {
        $dt = new DateTime($timestamp);
        return $dt->format('d-m-Y');
    } catch (Exception $e) {
        return $timestamp;
    }
}

function convertHyperTmp($hyperTmp) {
    // hyperTmp is stored as one-tenth of Kelvin temperature
    // Formula: (hyperTmp - 2731) / 10.0
    return ($hyperTmp - 2731) / 10.0;
}

function convertRssiToScale($rssi) {
    /**
     * Converts RSSI (dBm) to a 0-10 scale.
     * - 10 is perfect (-30 dBm or better)
     * - 5 is the unreliable threshold (-80 dBm)
     * - 0 is no connection (-130 dBm or worse)
     */
    // Linear formula: score = 0.1 * RSSI + 13
    $score = (0.1 * $rssi) + 13;
    
    // Clamp the score between 0 and 10
    $score = max(0, min(10, $score));
    
    // Return rounded to one decimal place
    return round($score, 1);
}

function getTempColor($temp) {
    // Blue for cold (-10 to 0), transitioning to red for warm (30 to 40)
    if ($temp <= 0) {
        return '#4fc3f7'; // Light blue
    } elseif ($temp <= 15) {
        return '#81c784'; // Green
    } elseif ($temp <= 25) {
        return '#fff176'; // Yellow
    } elseif ($temp <= 35) {
        return '#ffb74d'; // Orange
    } else {
        return '#e57373'; // Red
    }
}

function getRssiColor($rssi) {
    // Green for good signal (8-10), yellow for medium (5-7), red for poor (0-4)
    if ($rssi >= 8) {
        return '#81c784'; // Green
    } elseif ($rssi >= 5) {
        return '#fff176'; // Yellow
    } else {
        return '#e57373'; // Red
    }
}

function getBatteryStateIcon($state) {
    switch ($state) {
        case 0: return '⚪'; // Stand-by
        case 1: return '🔵'; // Charging
        case 2: return '🔴'; // Discharging
        default: return '❓';
    }
}

function getBatteryStateText($state) {
    switch ($state) {
        case 0: return 'Stand-by';
        case 1: return 'Charging';
        case 2: return 'Discharging';
        default: return 'Unknown';
    }
}

function getBatteryStateColor($state) {
    switch ($state) {
        case 0: return '#9e9e9e'; // Gray
        case 1: return '#64b5f6'; // Blue
        case 2: return '#ef5350'; // Red
        default: return '#757575';
    }
}

function getAcStatusIcon($status) {
    switch ($status) {
        case 0: return '⏸️';
        case 1: return '🔌';
        case 2: return '🔋';
        default: return '❓';
    }
}

function getAcStatusText($status) {
    switch ($status) {
        case 0: return 'Stopped';
        case 1: return 'Grid-connected';
        case 2: return 'Charging';
        default: return 'Unknown';
    }
}

function getSystemStatusInfo($packState, $outputPackPower, $outputHomePower, $gridInputPower, $solarInputPower, $electricLevel) {
    $status = [
        'state' => $packState,
        'class' => 'standby',
        'icon' => '⚪',
        'title' => 'Standby',
        'subtitle' => 'No active power flow',
        'color' => '#9e9e9e'
    ];

    // Determine state based on packState and actual power values
    if ($packState == 1 || $outputPackPower > 0) {
        // Charging - either from packState or if outputPackPower is active
        $status['class'] = 'charging';
        $status['icon'] = '🔵';
        $status['title'] = 'Charging';
        $status['subtitle'] = 'Battery is being charged';
        $status['color'] = '#64b5f6';
    } elseif ($packState == 2 || $outputHomePower > 0) {
        // Discharging - either from packState or if outputHomePower is active
        $status['class'] = 'discharging';
        $status['icon'] = '🔴';
        $status['title'] = 'Discharging';
        $status['subtitle'] = 'Battery is powering the home';
        $status['color'] = '#ef5350';
    }

    return $status;
}

// Extract data if available
$properties = $zendureData['properties'] ?? [];
$packData = $zendureData['packData'] ?? [];
$timestamp = $zendureData['timestamp'] ?? '';

// Calculate values
$hyperTmpCelsius = isset($properties['hyperTmp']) ? convertHyperTmp($properties['hyperTmp']) : 0;
$rssiScale = isset($properties['rssi']) ? convertRssiToScale($properties['rssi']) : 0;

// Get system status
$systemStatus = getSystemStatusInfo(
    $properties['packState'] ?? 0,
    $properties['outputPackPower'] ?? 0,
    $properties['outputHomePower'] ?? 0,
    $properties['gridInputPower'] ?? 0,
    $properties['solarInputPower'] ?? 0,
    $properties['electricLevel'] ?? 0
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zendure SolarFlow Status</title>
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

        .error-message {
            background: #fee;
            border-left: 4px solid #f00;
            padding: 20px;
            border-radius: 4px;
            color: #c00;
            text-align: center;
        }

        .timestamp {
            background: #e3f2fd;
            border-left: 4px solid #64b5f6;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            text-align: center;
        }

        .timestamp h3 {
            color: #333;
            margin-bottom: 5px;
            font-size: 1.1rem;
        }

        .timestamp .date {
            color: #666;
            font-size: 1rem;
        }

        .metric-section {
            margin-bottom: 30px;
        }

        .metric-section h3 {
            color: #333;
            margin-bottom: 15px;
            font-size: 1.3rem;
            border-bottom: 2px solid #64b5f6;
            padding-bottom: 8px;
        }

        .metric-item {
            background: #fff;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 15px;
        }

        .metrics-grid .metric-item {
            margin-bottom: 0;
        }

        @media (max-width: 1024px) {
            .metrics-grid {
                grid-template-columns: 1fr;
            }
        }

        .metric-label {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .metric-label span {
            font-weight: 600;
            color: #333;
        }

        .metric-value {
            font-size: 1.1rem;
            color: #666;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .bar-container {
            width: 100%;
            height: 30px;
            background: #e0e0e0;
            border-radius: 15px;
            overflow: hidden;
            position: relative;
        }

        .bar-fill {
            height: 100%;
            border-radius: 15px;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 8px;
            color: #333;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .bar-label {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-weight: 600;
            font-size: 0.9rem;
            color: #333;
        }

        .battery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .battery-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #64b5f6;
        }

        .battery-card h4 {
            color: #333;
            margin-bottom: 15px;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-top: 8px;
        }

        .icon-large {
            font-size: 1.5rem;
        }

        .status-banner {
            background: linear-gradient(135deg, #fafafa 0%, #f0f0f0 100%);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            border: 3px solid;
            transition: all 0.3s ease;
        }

        .status-banner.charging {
            border-color: #64b5f6;
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
        }

        .status-banner.discharging {
            border-color: #ef5350;
            background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
        }

        .status-banner.standby {
            border-color: #9e9e9e;
            background: linear-gradient(135deg, #f5f5f5 0%, #e0e0e0 100%);
        }

        .status-main {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .status-icon-text {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .status-icon {
            font-size: 4rem;
            line-height: 1;
        }

        .status-text {
            display: flex;
            flex-direction: column;
        }

        .status-title {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
            color: #333;
        }

        .status-subtitle {
            font-size: 1.1rem;
            color: #666;
            margin-top: 5px;
        }

        .status-power-flow {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 10px;
        }

        .power-flow-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1rem;
        }

        .power-value {
            font-weight: 700;
            font-size: 1.3rem;
            color: #333;
        }

        .power-label {
            color: #666;
            font-size: 0.95rem;
        }

        .power-arrow {
            font-size: 1.5rem;
        }

        .status-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            padding-top: 20px;
            border-top: 2px solid rgba(0, 0, 0, 0.1);
        }

        .status-detail-item {
            text-align: center;
        }

        .status-detail-label {
            font-size: 0.85rem;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }

        .status-detail-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #333;
        }

        @media (max-width: 768px) {
            .status-main {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
            }

            .status-icon {
                font-size: 3rem;
            }

            .status-title {
                font-size: 1.5rem;
            }

            .status-power-flow {
                align-items: flex-start;
                width: 100%;
            }

            .header h1 {
                font-size: 2rem;
            }

            .card {
                padding: 20px;
            }

            .battery-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔋 Zendure SolarFlow Status</h1>
            <p>Real-time System Monitoring</p>
        </div>

        <?php if ($errorMessage): ?>
            <div class="card">
                <div class="error-message">
                    <h3>Error</h3>
                    <p><?php echo htmlspecialchars($errorMessage); ?></p>
                </div>
            </div>
        <?php elseif ($zendureData): ?>
            <!-- Timestamp Section -->
            <div class="card">
                <div class="timestamp">
                    <h3>📅 Data Timestamp</h3>
                    <div class="date">
                        <strong>Date:</strong> <?php echo htmlspecialchars(formatDate($timestamp)); ?> | 
                        <strong>Time:</strong> <?php echo htmlspecialchars(formatTimestamp($timestamp)); ?>
                    </div>
                </div>
            </div>

            <!-- System Status Banner -->
            <div class="card status-banner <?php echo $systemStatus['class']; ?>">
                <div class="status-main">
                    <div class="status-icon-text">
                        <div class="status-icon"><?php echo $systemStatus['icon']; ?></div>
                        <div class="status-text">
                            <h2 class="status-title"><?php echo $systemStatus['title']; ?></h2>
                            <p class="status-subtitle"><?php echo $systemStatus['subtitle']; ?></p>
                        </div>
                    </div>
                    <div class="status-power-flow">
                        <?php 
                        $chargePower = $properties['outputPackPower'] ?? 0;
                        $dischargePower = $properties['outputHomePower'] ?? 0;
                        $gridPower = $properties['gridInputPower'] ?? 0;
                        $solarPower = $properties['solarInputPower'] ?? 0;
                        ?>
                        <?php if ($chargePower > 0): ?>
                            <div class="power-flow-item">
                                <span class="power-arrow">⬇️</span>
                                <span class="power-value" style="color: <?php echo $systemStatus['color']; ?>;"><?php echo $chargePower; ?> W</span>
                                <span class="power-label">Charging</span>
                            </div>
                        <?php endif; ?>
                        <?php if ($dischargePower > 0): ?>
                            <div class="power-flow-item">
                                <span class="power-arrow">⬆️</span>
                                <span class="power-value" style="color: <?php echo $systemStatus['color']; ?>;"><?php echo $dischargePower; ?> W</span>
                                <span class="power-label">Discharging</span>
                            </div>
                        <?php endif; ?>
                        <?php if ($gridPower > 0): ?>
                            <div class="power-flow-item">
                                <span class="power-arrow">➡️</span>
                                <span class="power-value" style="color: #66bb6a;"><?php echo $gridPower; ?> W</span>
                                <span class="power-label">Grid Feed-in</span>
                            </div>
                        <?php endif; ?>
                        <?php if ($chargePower == 0 && $dischargePower == 0 && $gridPower == 0): ?>
                            <div class="power-flow-item">
                                <span class="power-value" style="color: #9e9e9e;">0 W</span>
                                <span class="power-label">No active power flow</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="status-details">
                    <div class="status-detail-item">
                        <div class="status-detail-label">Battery Level</div>
                        <div class="status-detail-value" style="color: <?php echo $systemStatus['color']; ?>;">
                            <?php echo $properties['electricLevel'] ?? 0; ?>%
                        </div>
                    </div>
                    <div class="status-detail-item">
                        <div class="status-detail-label">Solar Input</div>
                        <div class="status-detail-value" style="color: #ff9800;">
                            <?php echo $solarPower; ?> W
                        </div>
                    </div>
                    <div class="status-detail-item">
                        <div class="status-detail-label">Total Packs</div>
                        <div class="status-detail-value">
                            <?php echo count($packData); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Properties Section -->
            <div class="card">
                <div class="metric-section">
                    <h3>⚙️ System Properties</h3>

                    <!-- Heat State -->
                    <div class="metric-item">
                        <div class="metric-label">
                            <span>Battery Heating</span>
                            <div class="metric-value">
                                <?php 
                                $heatState = $properties['heatState'] ?? 0;
                                echo $heatState == 1 ? '🔥 <strong>Heating ON</strong>' : '❄️ No Heating';
                                ?>
                            </div>
                        </div>
                    </div>

                    <!-- First Row: Power Graphs (Charging, Discharging, Grid Feed-in) -->
                    <div class="metrics-grid">
                        <!-- Charging Power (outputPackPower) -->
                        <div class="metric-item">
                            <div class="metric-label">
                                <span>Charging Power (outputPackPower)</span>
                                <div class="metric-value"><?php echo $properties['outputPackPower'] ?? 0; ?> W</div>
                            </div>
                            <div class="bar-container">
                                <div class="bar-label">0-2000 W</div>
                                <?php 
                                $chargePower = min(2000, max(0, $properties['outputPackPower'] ?? 0));
                                $chargePercent = ($chargePower / 2000) * 100;
                                ?>
                                <div class="bar-fill" style="width: <?php echo $chargePercent; ?>%; background: #64b5f6;">
                                    <?php if ($chargePercent > 10) echo $chargePower . ' W'; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Discharging Power (outputHomePower) -->
                        <div class="metric-item">
                            <div class="metric-label">
                                <span>Discharging Power (outputHomePower)</span>
                                <div class="metric-value"><?php echo $properties['outputHomePower'] ?? 0; ?> W</div>
                            </div>
                            <div class="bar-container">
                                <div class="bar-label">0-2000 W</div>
                                <?php 
                                $dischargePower = min(2000, max(0, $properties['outputHomePower'] ?? 0));
                                $dischargePercent = ($dischargePower / 2000) * 100;
                                ?>
                                <div class="bar-fill" style="width: <?php echo $dischargePercent; ?>%; background: #ef5350;">
                                    <?php if ($dischargePercent > 10) echo $dischargePower . ' W'; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Grid Input Power -->
                        <div class="metric-item">
                            <div class="metric-label">
                                <span>Grid Feed-in (gridInputPower)</span>
                                <div class="metric-value"><?php echo $properties['gridInputPower'] ?? 0; ?> W</div>
                            </div>
                            <div class="bar-container">
                                <div class="bar-label">0-2000 W</div>
                                <?php 
                                $gridPower = min(2000, max(0, $properties['gridInputPower'] ?? 0));
                                $gridPercent = ($gridPower / 2000) * 100;
                                ?>
                                <div class="bar-fill" style="width: <?php echo $gridPercent; ?>%; background: #66bb6a;">
                                    <?php if ($gridPercent > 10) echo $gridPower . ' W'; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Second Row: Grid Feed-in, Unit Temperature, WiFi Strength -->
                    <div class="metrics-grid">
                        <!-- Grid Input Power -->
                        <div class="metric-item">
                            <div class="metric-label">
                                <span>Grid Feed-in (gridInputPower)</span>
                                <div class="metric-value"><?php echo $properties['gridInputPower'] ?? 0; ?> W</div>
                            </div>
                            <div class="bar-container">
                                <div class="bar-label">0-2000 W</div>
                                <?php 
                                $gridPower = min(2000, max(0, $properties['gridInputPower'] ?? 0));
                                $gridPercent = ($gridPower / 2000) * 100;
                                ?>
                                <div class="bar-fill" style="width: <?php echo $gridPercent; ?>%; background: #66bb6a;">
                                    <?php if ($gridPercent > 10) echo $gridPower . ' W'; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Unit Temperature (hyperTmp) -->
                        <div class="metric-item">
                            <div class="metric-label">
                                <span>Unit Temperature (hyperTmp)</span>
                                <div class="metric-value"><?php echo number_format($hyperTmpCelsius, 1); ?>°C</div>
                            </div>
                            <div class="bar-container">
                                <div class="bar-label">-10°C to +40°C</div>
                                <?php 
                                $tempPercent = (($hyperTmpCelsius + 10) / 50) * 100;
                                $tempPercent = max(0, min(100, $tempPercent));
                                $tempColor = getTempColor($hyperTmpCelsius);
                                ?>
                                <div class="bar-fill" style="width: <?php echo $tempPercent; ?>%; background: <?php echo $tempColor; ?>;">
                                    <?php if ($tempPercent > 15) echo number_format($hyperTmpCelsius, 1) . '°C'; ?>
                                </div>
                            </div>
                        </div>

                        <!-- WiFi Signal Strength (rssi) -->
                        <div class="metric-item">
                            <div class="metric-label">
                                <span>WiFi Signal Strength (rssi)</span>
                                <div class="metric-value">
                                    <?php echo $properties['rssi'] ?? 0; ?> dBm 
                                    (Scale: <?php echo $rssiScale; ?>/10)
                                </div>
                            </div>
                            <div class="bar-container">
                                <div class="bar-label">0-10 Scale</div>
                                <?php 
                                $rssiPercent = ($rssiScale / 10) * 100;
                                $rssiColor = getRssiColor($rssiScale);
                                ?>
                                <div class="bar-fill" style="width: <?php echo $rssiPercent; ?>%; background: <?php echo $rssiColor; ?>;">
                                    <?php if ($rssiPercent > 20) echo $rssiScale . '/10'; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- AC Status -->
                    <div class="metric-item">
                        <div class="metric-label">
                            <span>AC Status</span>
                            <div class="metric-value">
                                <span class="icon-large"><?php echo getAcStatusIcon($properties['acStatus'] ?? 0); ?></span>
                                <strong><?php echo getAcStatusText($properties['acStatus'] ?? 0); ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Battery Packs Section -->
            <div class="card">
                <div class="metric-section">
                    <h3>🔋 Battery Packs</h3>
                    <div class="battery-grid">
                        <?php foreach ($packData as $index => $pack): ?>
                            <div class="battery-card">
                                <h4>
                                    <?php echo getBatteryStateIcon($pack['state'] ?? 0); ?>
                                    Battery Pack <?php echo $index + 1; ?>
                                    <?php if (isset($pack['sn'])): ?>
                                        <span style="font-size: 0.75rem; color: #999; font-weight: normal;">
                                            (<?php echo substr($pack['sn'], -8); ?>)
                                        </span>
                                    <?php endif; ?>
                                </h4>

                                <!-- Battery Charge Level (socLevel) -->
                                <div style="margin-bottom: 15px;">
                                    <div class="metric-label">
                                        <span>Charge Level</span>
                                        <div class="metric-value"><?php echo $pack['socLevel'] ?? 0; ?>%</div>
                                    </div>
                                    <div class="bar-container">
                                        <div class="bar-label">0-100%</div>
                                        <?php 
                                        $socPercent = $pack['socLevel'] ?? 0;
                                        $socColor = $socPercent > 50 ? '#66bb6a' : ($socPercent > 20 ? '#fff176' : '#ef5350');
                                        ?>
                                        <div class="bar-fill" style="width: <?php echo $socPercent; ?>%; background: <?php echo $socColor; ?>;">
                                            <?php if ($socPercent > 8) echo $socPercent . '%'; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Battery State -->
                                <div>
                                    <span class="status-badge" style="background: <?php echo getBatteryStateColor($pack['state'] ?? 0); ?>; color: #fff;">
                                        <span class="icon-large"><?php echo getBatteryStateIcon($pack['state'] ?? 0); ?></span>
                                        <?php echo getBatteryStateText($pack['state'] ?? 0); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
