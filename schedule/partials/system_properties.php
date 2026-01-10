<?php
/**
 * System Properties Partial
 * Displays 6 system metrics (Total Power, Discharging/Charging, Battery Level, Grid Status, Unit Temperature, WiFi Signal)
 * and AC Status in a compact grid
 * Data is fetched from data_api.php?type=zendure and data_api.php?type=zendure_p1
 */

// Include helper files
require_once __DIR__ . '/../includes/status.php';  // For color constants and helper functions
require_once __DIR__ . '/../includes/bars.php';
require_once __DIR__ . '/../includes/renderers.php';
require_once __DIR__ . '/../includes/metrics.php';

// Fetch system properties data from API
$zendureData = null;
$p1Data = null;
$systemPropertiesError = null;

// Build HTTP URL to the API endpoints
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
// Get base path: remove /schedule/charge_schedule.php to get base path
$basePath = dirname(dirname($scriptName));
$zendureApiUrl = $scheme . '://' . $host . $basePath . '/data/api/data_api.php?type=zendure';
$p1ApiUrl = $scheme . '://' . $host . $basePath . '/data/api/data_api.php?type=zendure_p1';

try {
    // Fetch Zendure data
    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'ignore_errors' => true,
            'method' => 'GET',
            'header' => 'User-Agent: Charge-Schedule-Page'
        ]
    ]);
    
    $zendureJsonData = @file_get_contents($zendureApiUrl, false, $context);
    
    if ($zendureJsonData === false || empty($zendureJsonData)) {
        // Try alternative: direct file path (for local file access)
        $apiFilePath = __DIR__ . '/../../data/api/data_api.php';
        if (file_exists($apiFilePath)) {
            // Temporarily set GET parameters and capture output
            $originalGet = $_GET;
            $_GET['type'] = 'zendure';
            
            ob_start();
            include $apiFilePath;
            $zendureJsonData = ob_get_clean();
            
            $_GET = $originalGet;
        }
    }
    
    // Fetch P1 data
    $p1JsonData = @file_get_contents($p1ApiUrl, false, $context);
    
    if ($p1JsonData === false || empty($p1JsonData)) {
        // Try alternative: direct file path (for local file access)
        if (file_exists($apiFilePath ?? __DIR__ . '/../../data/api/data_api.php')) {
            $originalGet = $_GET;
            $_GET['type'] = 'zendure_p1';
            
            ob_start();
            include __DIR__ . '/../../data/api/data_api.php';
            $p1JsonData = ob_get_clean();
            
            $_GET = $originalGet;
        }
    }
    
    // Parse Zendure data
    if (!empty($zendureJsonData)) {
        $zendureApiResponse = json_decode($zendureJsonData, true);
        if ($zendureApiResponse && isset($zendureApiResponse['success']) && $zendureApiResponse['success']) {
            $zendureData = $zendureApiResponse['data'] ?? null;
        }
    }
    
    // Parse P1 data
    if (!empty($p1JsonData)) {
        $p1ApiResponse = json_decode($p1JsonData, true);
        if ($p1ApiResponse && isset($p1ApiResponse['success']) && $p1ApiResponse['success']) {
            $p1Data = $p1ApiResponse['data'] ?? null;
        }
    }
    
    if (!$zendureData) {
        $systemPropertiesError = 'Failed to load system properties data from API';
    }
    
} catch (Exception $e) {
    $systemPropertiesError = 'System properties data unavailable: ' . htmlspecialchars($e->getMessage());
}

// Extract and prepare data
$properties = $zendureData['properties'] ?? [];
$packData = $zendureData['packData'] ?? [];
$timestamp = $zendureData['timestamp'] ?? '';
$p1TotalPower = $p1Data['total_power'] ?? 0;

// Calculate derived values
$hyperTmpCelsius = isset($properties['hyperTmp']) ? convertHyperTmp($properties['hyperTmp']) : 0;
$rssiScale = isset($properties['rssi']) ? convertRssiToScale($properties['rssi']) : 0;

// Prepare metrics if we have data
$metrics = null;
if ($zendureData) {
    $metrics = prepareMetricData($properties, $p1TotalPower, $hyperTmpCelsius, $rssiScale);
    
    // Extract values for easier access
    $totalPowerValue = $metrics['totalPower']['value'];
    $totalPowerColor = $metrics['totalPower']['color'];
    $gridBarData = $metrics['gridStatus']['barData'];
    $gridStatusColor = $metrics['gridStatus']['color'];
    $tempColor = $metrics['temperature']['color'];
    $rssiColor = $metrics['rssi']['color'];
    $batteryLevelValue = $metrics['batteryLevel']['value'];
    $batteryLevelColor = $metrics['batteryLevel']['color'];
    $chargeDischargeValue = $metrics['chargeDischarge']['value'];
    $chargeDischargeBarData = $metrics['chargeDischarge']['barData'];
    $chargeDischargeColor = $metrics['chargeDischarge']['color'];
    $chargeDischargeBarClass = $metrics['chargeDischarge']['class'];
    
    // Calculate battery capacity and remaining
    $packCount = is_array($packData) ? count($packData) : 0;
    $totalCapacityKwh = $packCount * 2.88;
    $batteryRemainingKwh = $totalCapacityKwh * ($batteryLevelValue / 100);
    $batteryRemainingDisplay = number_format($batteryRemainingKwh, 2);
    
    // Convert minSoc to percent
    $minSocRaw = $properties['minSoc'] ?? null;
    if (is_numeric($minSocRaw)) {
        $minSocPercent = ($minSocRaw > 100) ? $minSocRaw / 10 : $minSocRaw;
        $minSocPercent = max(0, min(100, $minSocPercent));
    } else {
        $minSocPercent = 0;
    }
    $batteryRemainingAboveMinKwh = $totalCapacityKwh * max(0, ($batteryLevelValue - $minSocPercent) / 100);
    $batteryRemainingAboveMinDisplay = number_format($batteryRemainingAboveMinKwh, 2);
    
    // Convert socSet to percent
    $socSetRaw = $properties['socSet'] ?? null;
    if (is_numeric($socSetRaw)) {
        $socSetPercent = ($socSetRaw > 100) ? $socSetRaw / 10 : $socSetRaw;
        $socSetPercent = max(0, min(100, $socSetPercent));
    } else {
        $socSetPercent = 100;
    }
}

?>

<!-- System Properties Section -->
<div class="card" style="margin-top: 12px; padding-bottom: 12px;">
    <div class="metric-section">
        <h3>⚙️ System Properties</h3>
        
        <?php if ($systemPropertiesError || !$metrics): ?>
            <div class="system-properties-error" id="system-properties-error">
                <p><?php echo htmlspecialchars($systemPropertiesError ?? 'No system properties data available'); ?></p>
            </div>
        <?php else: ?>
            <!-- Row 1: Total Power, Discharging/Charging, Battery Level -->
            <div class="system-properties-grid">
                <!-- 1. Total Power -->
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

                <!-- 2. Discharging/Charging -->
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

                <!-- 3. Battery Level -->
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
                    [' (' . $batteryRemainingDisplay . '/' . $batteryRemainingAboveMinDisplay . ' kWh)'],
                    false,
                    'Remaining kWh / Usable kWh above min SoC'
                );
                ?>
            </div>

            <!-- Row 2: Grid Status, Unit Temperature, WiFi Signal -->
            <div class="system-properties-grid">
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

                <!-- 5. Unit Temperature -->
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
                    0.7,
                    null,
                    null,
                    '°C ',
                    '-10°C',
                    '+40°C',
                    'Unit Temperature: ' . number_format($hyperTmpCelsius, 1) . ' degrees Celsius',
                    false
                );
                ?>

                <!-- 6. WiFi Signal (RSSI) -->
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
        <?php endif; ?>
    </div>
</div>
