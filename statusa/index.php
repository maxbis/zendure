<?php
/**
 * Zendure SolarFlow Status Viewer - Main View
 * Displays current status from zendure_data.json
 */

// Include helper functions
require_once __DIR__ . '/includes/helpers.php';

// Load configuration
$config = require __DIR__ . '/includes/config_loader.php';

// Auto-update: Fetch fresh data from devices on every page load
// Require the read_zendure class
require_once __DIR__ . '/classes/read_zendure.php';

// Fetch fresh data from device
$solarflow = new SolarFlow2400($config['deviceIp']);
$solarflow->getStatus(false); // Non-verbose, just saves data

// Require the read_zendure_p1 class
require_once __DIR__ . '/classes/read_zendure_p1.php';

// Fetch fresh P1 meter data
$p1Meter = new ZendureP1Meter($config['p1MeterIp']);
$p1Meter->update(false); // Non-verbose, just saves data

$dataFile = $config['dataFile'];

// Load Zendure data
$dataResult = loadZendureData($dataFile);
$zendureData = $dataResult['data'];
$errorMessage = $dataResult['error'] ? $dataResult['message'] : null;

// Load P1 meter data
// Resolve P1 data file path (same way as main data file)
$p1DataDirPath = $config['dataDir'];
if (strpos($p1DataDirPath, '../') === 0) {
    // Relative path - resolve from parent directory
    $p1DataDir = dirname(__DIR__) . '/' . trim(str_replace('../', '', $p1DataDirPath), '/');
} else {
    // Absolute or relative to current directory
    $p1DataDir = $p1DataDirPath;
}
$p1DataFile = rtrim($p1DataDir, '/') . '/zendure_p1_data.json';
$p1DataResult = loadZendureData($p1DataFile);
$p1Data = $p1DataResult['data'];
$p1TotalPower = $p1Data['total_power'] ?? 0;
$p1Status = ($p1TotalPower < 0) ? "EXPORTING ☀️" : "IMPORTING ⚡";

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
    $properties['solarInputPower'] ?? 0,
    $properties['electricLevel'] ?? 0
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zendure Status</title>
    <link rel="stylesheet" href="assets/css/zendure.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <h1>🔋 Zendure Status</h1>
                    <span style="font-size: 0.8rem; color: #666;font-style: italic;">
                    <?php if ($timestamp): ?>
                        <?php echo htmlspecialchars(formatDate($timestamp)); ?> | <?php echo htmlspecialchars(formatTimestamp($timestamp)); ?>
                    <?php else: ?>
                        No data available
                    <?php endif; ?>
                    </span> 
            </div>
            <?php if ($zendureData): ?>
                <a href="?update=1" class="update-button">🔄 Update</a>
            <?php endif; ?>
        </div>

        <?php if ($errorMessage): ?>
            <div class="card">
                <div class="error-message">
                    <h3>Error</h3>
                    <p><?php echo htmlspecialchars($errorMessage); ?></p>
                </div>
            </div>
        <?php elseif ($zendureData): ?>


            <!-- Properties Section -->
            <div class="card">
                <div class="metric-section">
                    <h3>⚙️ System Properties</h3>

                    <?php
                    // Prepare all metric data using helper function
                    $metrics = prepareMetricData($properties, $p1TotalPower, $hyperTmpCelsius, $rssiScale);
                    
                    // Extract values for easier access in template
                    $chargePowerValue = $metrics['chargePower']['value'];
                    $chargeBarData = $metrics['chargePower']['barData'];
                    $dischargePowerValue = $metrics['dischargePower']['value'];
                    $dischargeBarData = $metrics['dischargePower']['barData'];
                    $totalPowerValue = $metrics['totalPower']['value'];
                    $totalPowerBarData = $metrics['totalPower']['barData'];
                    $totalPowerColor = $metrics['totalPower']['color'];
                    $gridBarData = $metrics['gridStatus']['barData'];
                    $gridStatusColor = $metrics['gridStatus']['color'];
                    $tempBarData = $metrics['temperature']['barData'];
                    $tempColor = $metrics['temperature']['color'];
                    $rssiBarData = $metrics['rssi']['barData'];
                    $rssiColor = $metrics['rssi']['color'];
                    $batteryLevelValue = $metrics['batteryLevel']['value'];
                    $batteryLevelBarData = $metrics['batteryLevel']['barData'];
                    $batteryLevelColor = $metrics['batteryLevel']['color'];
                    $packCount = is_array($packData) ? count($packData) : 0;
                    $totalCapacityKwh = $packCount * 2.88;
                    $batteryRemainingKwh = $totalCapacityKwh * ($batteryLevelValue / 100);
                    $batteryRemainingDisplay = number_format($batteryRemainingKwh, 2);
                    // Convert minSoc (e.g., 200 = 20%) to percent and compute usable energy above min level
                    $minSocRaw = $properties['minSoc'] ?? null;
                    if (is_numeric($minSocRaw)) {
                        $minSocPercent = ($minSocRaw > 100) ? $minSocRaw / 10 : $minSocRaw;
                        $minSocPercent = max(0, min(100, $minSocPercent));
                    } else {
                        $minSocPercent = 0;
                    }
                    $batteryRemainingAboveMinKwh = $totalCapacityKwh * max(0, ($batteryLevelValue - $minSocPercent) / 100);
                    $batteryRemainingAboveMinDisplay = number_format($batteryRemainingAboveMinKwh, 2);
                    // Convert socSet (e.g., 900 = 90%) to percent for max charge target
                    $socSetRaw = $properties['socSet'] ?? null;
                    if (is_numeric($socSetRaw)) {
                        $socSetPercent = ($socSetRaw > 100) ? $socSetRaw / 10 : $socSetRaw;
                        $socSetPercent = max(0, min(100, $socSetPercent));
                    } else {
                        $socSetPercent = 100;
                    }
                    $chargeDischargeValue = $metrics['chargeDischarge']['value'];
                    $chargeDischargeBarData = $metrics['chargeDischarge']['barData'];
                    $chargeDischargeColor = $metrics['chargeDischarge']['color'];
                    $chargeDischargeBarClass = $metrics['chargeDischarge']['class'];
                    ?>

                    <!-- Heat State Row -->
                    <div class="metrics-grid">
                        <!-- 1. Total Power (swapped with Charging/Discharging) -->
                        <?php
                        $totalPowerExtraContent = $totalPowerBarData['exceedsMax'] 
                            ? '<span style="color: #ff9800; font-size: 0.85em;" title="Value exceeds maximum range">⚠️</span>' 
                            : null;
                        $totalPowerRightLabel = $totalPowerBarData['exceedsMax'] ? '2000+ W' : '2000 W';
                        renderMetricBar(
                            '1. Total Power',
                            $totalPowerValue,
                            $totalPowerBarData,
                            $totalPowerColor,
                            0,
                            2000,
                            null,
                            'W',
                            '0',
                            $totalPowerRightLabel,
                            'Total Power' . ($totalPowerBarData['exceedsMax'] ? ' (exceeds maximum)' : ''),
                            false,
                            $totalPowerExtraContent
                        );
                        ?>

                        <!-- 2. Charging/Discharging (swapped with Total Power) -->
                        <?php
                        // Calculate time to reach socSet or minSoc at current charge/discharge rate
                        $chargeDischargeTimeDisplay = null;
                        $powerW = $chargeDischargeValue;
                        $powerAbsW = abs($powerW);
                        if ($powerAbsW > 0) {
                            if ($powerW > 0 && $batteryLevelValue < $socSetPercent) {
                                // Charging: time to reach socSet
                                $energyToTargetKwh = $totalCapacityKwh * max(0, ($socSetPercent - $batteryLevelValue) / 100);
                                if ($energyToTargetKwh > 0) {
                                    $hours = $energyToTargetKwh * 1000 / $powerAbsW;
                                }
                            } elseif ($powerW < 0 && $batteryRemainingAboveMinKwh > 0) {
                                // Discharging: time to reach minSoc
                                $energyToTargetKwh = $batteryRemainingAboveMinKwh;
                                $hours = $energyToTargetKwh * 1000 / $powerAbsW;
                            }
                            if (isset($hours) && $hours > 0) {
                                $totalMinutes = (int)round($hours * 60);
                                $h = intdiv($totalMinutes, 60);
                                $m = $totalMinutes % 60;
                                $chargeDischargeTimeDisplay = sprintf('%d:%02d', $h, $m);
                            }
                        }
                        $chargeDischargeExtraContent = $chargeDischargeTimeDisplay ? ' (' . $chargeDischargeTimeDisplay . ' h)' : null;

                        renderMetricBidirectional(
                            '2. Charging/Discharging',
                            $chargeDischargeValue,
                            $chargeDischargeBarData,
                            $chargeDischargeBarClass,
                            -1200,
                            1200,
                            $chargeDischargeColor,
                            'W',
                            '-1200 W',
                            '0',
                            '1200 W',
                            'Charging/Discharging',
                            ($chargeDischargeValue > 0) ? 'charging' : (($chargeDischargeValue < 0) ? 'discharging' : 'idle'),
                            $chargeDischargeExtraContent
                        );
                        ?>

                        <!-- 3. Battery Level (swapped with Battery Heating) -->
                        <?php
                        renderMetricBar(
                            '3. Battery Level',
                            $batteryLevelValue,
                            $batteryLevelBarData,
                            $batteryLevelColor,
                            0,
                            100,
                            "#000000",
                            '%',
                            '0%',
                            '100%',
                            'Battery Level',
                            false,
                            [' (' . $batteryRemainingDisplay . '/' . $batteryRemainingAboveMinDisplay . ' kWh)']
                        );
                        ?>
                    </div>

                    <!-- First Row: Power Graphs (Charging, Discharging, Battery Heating) -->
                    <div class="metrics-grid">
                        <!-- 4. Grid Status -->
                        <?php
                        renderMetricBidirectional(
                            '4. Grid Status',
                            $p1TotalPower,
                            $gridBarData,
                            $gridBarData['class'],
                            -2000,
                            2000,
                            $gridStatusColor,
                            'W',
                            '-2000 W',
                            '0',
                            '2000 W',
                            'Grid Status',
                            ($p1TotalPower < 0) ? 'exporting' : 'importing'
                        );
                        ?>

                        <!-- 5. Unit Temperature (hyperTmp) -->
                        <?php
                        renderMetricBar(
                            '5. Unit Temperature',
                            $hyperTmpCelsius,
                            $tempBarData,
                            $tempColor,
                            -10,
                            40,
                            null,
                            '°C',
                            '-10°C',
                            '+40°C',
                            'Unit Temperature: ' . number_format($hyperTmpCelsius, 1) . ' degrees Celsius'
                        );
                        ?>

                        <!-- 6. Battery Heating (swapped with Battery Level) -->
                        <?php
                        $heatState = $properties['heatState'] ?? 0;
                        $heatValue = $heatState == 1 ? '🔥 <strong>Heating ON</strong>' : '❄️ No Heating';
                        renderMetricSimple('6. Battery Heating', $heatValue, null, 'Battery Heating Status');
                        ?>
                    </div>

                    <!-- Second Row: Grid Status, Unit Temperature, WiFi Strength -->
                    <div class="metrics-grid">
                        <!-- 7. Charging Power (outputPackPower) -->
                        <?php
                        renderMetricBar(
                            '7. Charging',
                            $chargePowerValue,
                            $chargeBarData,
                            COLOR_CHARGING,
                            0,
                            1200,
                            null,
                            'W',
                            '0 W',
                            '1200 W',
                            'Charging Power',
                            true
                        );
                        ?>

                        <!-- 8. Discharging Power (outputHomePower) -->
                        <?php
                        renderMetricBar(
                            '8. Discharging',
                            $dischargePowerValue,
                            $dischargeBarData,
                            COLOR_DISCHARGING,
                            0,
                            1200,
                            null,
                            'W',
                            '0 W',
                            '1200 W',
                            'Discharging Power',
                            true
                        );
                        ?>

                        <!-- 9. WiFi Signal Strength (rssi) -->
                        <?php
                        renderMetricBar(
                            '9. WiFi Signal (RSSI)',
                            $rssiScale,
                            $rssiBarData,
                            $rssiColor,
                            0,
                            10,
                            $rssiColor,
                            '/10',
                            '0',
                            '10',
                            'WiFi Signal Strength: ' . $rssiScale . ' out of 10',
                            true
                        );
                        ?>
                    </div>

                    <!-- AC Status -->
                    <?php
                    $acStatusValue = '<span class="icon-large">' . getAcStatusIcon($properties['acStatus'] ?? 0) . '</span><strong>' . getAcStatusText($properties['acStatus'] ?? 0) . '</strong>';
                    renderMetricSimple('AC Status', $acStatusValue, null, 'AC Status');
                    ?>
                </div>
            </div>

            <!-- Battery Packs Section -->
            <div class="card">
                <div class="metric-section">
                    <h3>🔋 Battery Packs</h3>
                    <div class="battery-grid">
                        <?php foreach ($packData as $index => $pack): ?>
                            <?php
                            // Calculate battery temperature (using same conversion as Unit Temperature)
                            $batteryMaxTemp = $pack['maxTemp'] ?? 0;
                            $batteryTempCelsius = convertHyperTmp($batteryMaxTemp);
                            $batteryTempBarData = calculateBarWidth($batteryTempCelsius, -10, 40);
                            $batteryTempColor = getTempColor($batteryTempCelsius);
                            ?>
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
                                    <?php
                                    $socPercent = $pack['socLevel'] ?? 0;
                                    $socColor = getBatteryLevelColor($socPercent);
                                    $socBarData = calculateBarWidth($socPercent, 0, 100);
                                    // Use generic component with single centered label style and no wrapper
                                    renderMetricBar(
                                        'Charge Level',
                                        $socPercent,
                                        $socBarData,
                                        $socColor,
                                        0,
                                        100,
                                        $socColor,
                                        '%',
                                        '0%',  // Single centered label (dash indicates single label mode)
                                        null,      // No right label
                                        'Battery Charge Level: ' . $socPercent . ' percent',
                                        ($socPercent > 8),  // Show value in bar if > 8%
                                        null,      // No extra content
                                        true       // No wrapper (we're inside battery-card)
                                    );
                                    ?>
                                </div>

                                <!-- Battery Temperature (maxTemp) -->
                                <div style="margin-bottom: 15px;">
                                    <?php
                                    renderMetricBar(
                                        'Temperature (maxTemp)',
                                        $batteryTempCelsius,
                                        $batteryTempBarData,
                                        $batteryTempColor,
                                        -10,
                                        40,
                                        $batteryTempColor,
                                        '°C',
                                        '-10°C',
                                        '+40°C',
                                        'Battery Temperature: ' . number_format($batteryTempCelsius, 1) . ' degrees Celsius',
                                        true,  // showValueInBar
                                        null,  // extraValueContent
                                        true   // noWrapper (we're inside battery-card)
                                    );
                                    ?>
                                </div>

                                <!-- Battery State -->
                                <?php
                                $batteryState = $pack['state'] ?? 0;
                                $stateClass = '';
                                switch ($batteryState) {
                                    case 0: $stateClass = 'stand-by'; break;
                                    case 1: $stateClass = 'charging'; break;
                                    case 2: $stateClass = 'discharging'; break;
                                }
                                ?>
                                <div class="status-indicator <?php echo $stateClass; ?>">
                                    <div class="status-indicator-dot"></div>
                                    <div class="status-indicator-text">
                                        <!-- <span><?php echo getBatteryStateIcon($batteryState); ?></span> -->
                                        <span><?php echo getBatteryStateText($batteryState); ?></span>
                                    </div>
                                </div>
                                
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="assets/js/zendure.js"></script>
</body>
</html>