<?php
/**
 * Zendure SolarFlow Status Viewer - Main View
 * Displays current status from zendure_data.json
 */

// Include helper functions
require_once __DIR__ . '/includes/helpers.php';

// Load configuration
try {
    $config = require __DIR__ . '/includes/config_loader.php';
    
    // Validate that config was loaded correctly
    if (!is_array($config)) {
        throw new Exception("Configuration did not return an array");
    }
} catch (Exception $e) {
    die("Configuration error: " . htmlspecialchars($e->getMessage()));
}

// Auto-detect: Try to read from device (local network) or use existing JSON (remote server)
$updateAttempted = false;
$updateSuccess = false;

// Include the read function
require_once __DIR__ . '/includes/read_zendure.php';

// Try to read Zendure data from device
try {
    $deviceIp = $config['deviceIp'] ?? null;
    
    if (!empty($deviceIp)) {
        $zendureData = readZendureData($deviceIp);
        
        if ($zendureData !== false) {
            // Store data via data_api.php endpoint
            $dataApiUrl = $config['dataApiUrl'] ?? null;
            
            // Construct API URL if not in config
            if (empty($dataApiUrl)) {
                // Default to relative path from status directory
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $scriptPath = dirname($_SERVER['SCRIPT_NAME'] ?? '');
                $dataApiUrl = $protocol . '://' . $host . $scriptPath . '/../data/api/data_api.php?type=zendure';
            }
            
            // Make POST request to store data
            $ch = curl_init($dataApiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($zendureData),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 5
            ]);
            
            $apiResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($apiResponse !== false && $httpCode === 200) {
                $apiData = json_decode($apiResponse, true);
                if ($apiData && isset($apiData['success']) && $apiData['success'] === true) {
                    $updateSuccess = true;
                    $updateAttempted = true;
                }
            }
        }
    }
} catch (Exception $e) {
    // Device not reachable or API error (likely remote server)
    // Silently continue - will load existing JSON file below
    $updateAttempted = true;
} catch (Throwable $e) {
    // Catch any other errors
    $updateAttempted = true;
}

// Ensure HTML content type is set (in case API set JSON header)
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

// Always try P1 meter update (same auto-detection logic)
require_once __DIR__ . '/classes/read_zendure_p1.php';
try {
    $p1Meter = new ZendureP1Meter($config['p1MeterIp']);
    $p1Meter->update(false);
} catch (Exception $e) {
    // P1 meter not reachable - continue with existing data
}

// Ensure HTML content type is set (override any JSON header from API)
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8', true);
}

// Determine update source for display
$updateSource = $updateSuccess ? 'local' : 'remote';

// Get data file path from config, with fallback
$dataFile = $config['dataFile'] ?? null;

// If dataFile is not set, try to construct it from dataDir
if (($dataFile === null || $dataFile === '') && isset($config['dataDir'])) {
    $dataDir = $config['dataDir'];
    $dataFile = rtrim($dataDir, '/') . '/zendure_data.json';
}

// Final fallback: construct from default path if still not set
if ($dataFile === null || $dataFile === '') {
    // Default to data directory relative to Energy root
    $defaultDataDir = dirname(__DIR__) . '/data/';
    $dataFile = $defaultDataDir . 'zendure_data.json';
}

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

// Initialize variables for JavaScript config (defaults in case zendureData is not available)
$totalCapacityKwh = 0;
$batteryLevelValue = 0;
$socSetPercent = 90;
$minSocPercent = 20;
$batteryRemainingAboveMinKwh = 0;

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
                        <?php if (isset($updateSource)): ?>
                            | <span style="color: <?php echo $updateSource === 'local' ? '#4caf50' : '#ff9800'; ?>;">
                                <?php echo $updateSource === 'local' ? '🔄 Local' : '📡 Remote'; ?>
                            </span>
                        <?php endif; ?>
                    <?php else: ?>
                        No data available
                    <?php endif; ?>
                    </span> 
            </div>
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
                    $dischargePowerValue = $metrics['dischargePower']['value'];
                    $totalPowerValue = $metrics['totalPower']['value'];
                    $totalPowerColor = $metrics['totalPower']['color'];
                    $gridBarData = $metrics['gridStatus']['barData'];  // Still needed for bidirectional bar
                    $gridStatusColor = $metrics['gridStatus']['color'];
                    $tempColor = $metrics['temperature']['color'];
                    $rssiColor = $metrics['rssi']['color'];
                    $batteryLevelValue = $metrics['batteryLevel']['value'];
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
                        // Calculate exceedsMax separately for custom label/content
                        $totalPowerBarDataForCheck = calculateBarWidthNonLinear(abs($totalPowerValue), 0, 2000, 'power', 0.7);
                        $totalPowerExtraContent = $totalPowerBarDataForCheck['exceedsMax'] 
                            ? '<span style="color: #ff9800; font-size: 0.85em;" title="Value exceeds maximum range">⚠️</span>' 
                            : null;
                        $totalPowerRightLabel = $totalPowerBarDataForCheck['exceedsMax'] ? '2000+ W' : '2000 W';
                        renderMetricBar(
                            '1. Total Power',
                            $totalPowerValue,
                            $totalPowerColor,
                            0,
                            2000,
                            'power',
                            0.7,
                            abs($totalPowerValue),  // Calculate with abs, but display signed
                            null,
                            'W',
                            '0',
                            $totalPowerRightLabel,
                            'Total Power' . ($totalPowerBarDataForCheck['exceedsMax'] ? ' (exceeds maximum)' : ''),
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
                            '2. Discharging/Charging',
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
                            $batteryLevelColor,
                            0,
                            100,
                            'linear',
                            0.7,
                            null,
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
                        $heatState = $properties['heatState'] ?? 0;
                        $heatValue = $heatState == 1 ? '🔥 Heating ON' : '❄️ No Heating';
 
                        renderMetricBar(
                            '5. Unit Temperature ('.$heatValue.')',
                            $hyperTmpCelsius,
                            $tempColor,
                            -10,
                            40,
                            'linear',
                            0.7,  // Exponent (not used for linear, but keeping for consistency)
                            null, // valueForCalculation
                            null, // valueColor
                            '°C ',
                            '-10°C',
                            '+40°C',
                            'Unit Temperature: ' . number_format($hyperTmpCelsius, 1) . ' degrees Celsius',
                            false  // showValueInBar
                        );
                        ?>

                        <!-- 9. WiFi Signal Strength (rssi) -->
                        <?php
                        renderMetricBar(
                            '6. WiFi Signal (RSSI)',
                            $rssiScale,
                            $rssiColor,
                            0,
                            10,
                            'linear',
                            0.7,
                            null,
                            $rssiColor,
                            '/10',
                            '0',
                            '10',
                            'WiFi Signal Strength: ' . $rssiScale . ' out of 10',
                            true
                        );
                        ?>
                    </div>

                    <!-- Second Row: Grid Status, Unit Temperature, WiFi Strength -->
                    <div class="metrics-grid">
                        <!-- 7. Charging Power (outputPackPower) -->
                        <!-- <?php
                        renderMetricBar(
                            '7. Charging',
                            $chargePowerValue,
                            COLOR_CHARGING,
                            0,
                            1200,
                            'power',
                            0.7,
                            null,
                            null,
                            'W',
                            '0 W',
                            '1200 W',
                            'Charging Power',
                            true
                        );
                        ?> -->

                        <!-- 8. Discharging Power (outputHomePower) -->
                        <!-- <?php
                        renderMetricBar(
                            '8. Discharging',
                            $dischargePowerValue,
                            COLOR_DISCHARGING,
                            0,
                            1200,
                            'power',
                            0.7,
                            null,
                            null,
                            'W',
                            '0 W',
                            '1200 W',
                            'Discharging Power',
                            true
                        );
                        ?> -->

                        <!-- 6. Battery Heating (swapped with Battery Level) -->
                        <!-- <?php
                        $heatState = $properties['heatState'] ?? 0;
                        $heatValue = $heatState == 1 ? '🔥 <strong>Heating ON</strong>' : '❄️ No Heating';
                        renderMetricSimple('9. Battery Heating', $heatValue, null, 'Battery Heating Status');
                        ?> -->
                    </div>

                    <!-- AC Status -->
                    <?php
                    // Calculate time projection for battery full/empty
                    $acStatusProjection = null;
                    $acStatus = $properties['acStatus'] ?? 0;
                    $packState = $properties['packState'] ?? 0;
                    $outputPackPower = $properties['outputPackPower'] ?? 0;
                    $outputHomePower = $properties['outputHomePower'] ?? 0;
                    
                    // Determine if charging or discharging
                    $isCharging = ($acStatus == 2 || $packState == 1 || $outputPackPower > 0);
                    $isDischarging = ($packState == 2 || $outputHomePower > 0);
                    
                    if ($isCharging || $isDischarging) {
                        // Use the charge/discharge power value for calculation
                        $powerW = $isCharging ? $outputPackPower : -$outputHomePower;
                        $powerAbsW = abs($powerW);
                        
                        if ($powerAbsW > 0) {
                            $hours = 0;
                            if ($isCharging && $batteryLevelValue < $socSetPercent) {
                                // Charging: time to reach socSet (full)
                                $energyToTargetKwh = $totalCapacityKwh * max(0, ($socSetPercent - $batteryLevelValue) / 100);
                                if ($energyToTargetKwh > 0) {
                                    $hours = $energyToTargetKwh * 1000 / $powerAbsW;
                                }
                            } elseif ($isDischarging && $batteryRemainingAboveMinKwh > 0) {
                                // Discharging: time to reach minSoc (empty)
                                $energyToTargetKwh = $batteryRemainingAboveMinKwh;
                                $hours = $energyToTargetKwh * 1000 / $powerAbsW;
                            }
                            
                            if ($hours > 0 && $timestamp) {
                                try {
                                    $currentTime = new DateTime($timestamp);
                                    $currentTime->modify('+' . round($hours * 3600) . ' seconds');
                                    $timeStr = $currentTime->format('H:i');
                                    $isTomorrow = ($currentTime->format('Y-m-d') != (new DateTime($timestamp))->format('Y-m-d'));
                                    if ($isTomorrow) {
                                        $timeStr .= ' (tomorrow)';
                                    }
                                    $acStatusProjection = ($isCharging ? 'Full at ' : 'Empty at ') . $timeStr;
                                } catch (Exception $e) {
                                    // Fallback to duration if timestamp parsing fails
                                    $totalMinutes = (int)round($hours * 60);
                                    $h = intdiv($totalMinutes, 60);
                                    $m = $totalMinutes % 60;
                                    $timeStr = sprintf('%d:%02d', $h, $m);
                                    $acStatusProjection = ($isCharging ? 'Full in ' : 'Empty in ') . $timeStr . ' h';
                                }
                            }
                        }
                    }
                    
                    $acStatusValue = '<span class="icon-large">' . getAcStatusIcon($acStatus) . '</span><strong>' . getAcStatusText($acStatus) . '</strong>';
                    if ($acStatusProjection) {
                        $acStatusValue .= ' <span style="font-size: 0.85em; color: #666; font-weight: normal;">(' . htmlspecialchars($acStatusProjection) . ')</span>';
                    }
                    renderMetricSimple('AC Status', $acStatusValue, null, 'AC Status');
                    ?>
                </div>
            </div>

                 <!-- Power Control Section -->
                 <div class="card">
                <div class="power-control-section">
                    <h3>⚡ Manual Power Control</h3>
                    <div class="power-control-buttons">
                        <div class="power-control-label-left">Discharge</div>
                        <button class="power-control-button discharge" data-watts="-800">-800W</button>
                        <button class="power-control-button discharge" data-watts="-600">-600W</button>
                        <button class="power-control-button discharge" data-watts="-400">-400W</button>
                        <button class="power-control-button discharge" data-watts="-200">-200W</button>
                        <button class="power-control-button discharge" data-watts="-100">-100W</button>
                        <button class="power-control-button stop" data-watts="0">0W</button>
                        <button class="power-control-button charge" data-watts="100">100W</button>
                        <button class="power-control-button charge" data-watts="200">200W</button>
                        <button class="power-control-button charge" data-watts="400">400W</button>
                        <button class="power-control-button charge" data-watts="600">600W</button>
                        <button class="power-control-button charge" data-watts="800">800W</button>
                        <div class="power-control-label-right">Charge</div>
                    </div>
                    <div class="countdown-display" id="countdown-display"></div>
                </div>
            </div>

            <!-- Power control section -->
            <div class="card">
                <div class="power-control-section">
                    <h3>⚡ Manual Power Control (Slider)</h3>
                    <div class="power-control-slider-container">
                        <div class="power-control-slider-labels">
                            <span class="power-control-label-left">Discharge</span>
                            <span class="power-control-label-right">Charge</span>
                        </div>
                        <div class="power-control-slider-wrapper">
                            <?php
                            // Round charge/discharge value to nearest 100W step to match slider step
                            $sliderValue = round($chargeDischargeValue / 100) * 100;
                            // Clamp to slider min/max range
                            $sliderValue = max(-800, min(1200, $sliderValue));
                            ?>
                            <input type="range" 
                                   id="power-control-slider" 
                                   class="power-control-slider" 
                                   min="-800" 
                                   max="1200" 
                                   step="100" 
                                   value="<?php echo htmlspecialchars((string)$sliderValue, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="power-control-slider-value" id="power-control-slider-value"><?php echo htmlspecialchars((string)$chargeDischargeValue, ENT_QUOTES, 'UTF-8'); ?>W</div>
                        </div>
                        <div class="countdown-display" id="countdown-display-slider"></div>
                    </div>
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
                                    // Use generic component with single centered label style and no wrapper
                                    renderMetricBar(
                                        'Charge Level',
                                        $socPercent,
                                        $socColor,
                                        0,
                                        100,
                                        'linear',
                                        0.7,
                                        null,
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
                                        $batteryTempColor,
                                        -10,
                                        40,
                                        'linear',
                                        0.7,
                                        null,
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

    <script>
        // Pass battery and charge/discharge data to JavaScript for validation and tooltips
        window.zendureConfig = {
            currentElectricLevel: <?php echo $properties['electricLevel'] ?? 0; ?>,
            minChargeLevel: <?php echo $minSocPercent ?? 20; ?>,
            maxChargeLevel: <?php echo $socSetPercent ?? 90; ?>,
            totalCapacityKwh: <?php echo $totalCapacityKwh; ?>,
            batteryLevelPercent: <?php echo $batteryLevelValue; ?>,
            socSetPercent: <?php echo $socSetPercent; ?>,
            minSocPercent: <?php echo $minSocPercent; ?>,
            batteryRemainingAboveMinKwh: <?php echo $batteryRemainingAboveMinKwh; ?>,
            chargeDischargeValue: <?php echo $chargeDischargeValue ?? 0; ?>
        };
    </script>
    <script src="assets/js/zendure.js"></script>
</body>
</html>