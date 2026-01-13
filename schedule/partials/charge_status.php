<?php
/**
 * Charge/Discharge Status Partial (Core)
 * Displays current battery charge/discharge status from cached Zendure data.
 * 
 * This core partial renders:
 * - Status indicator (standby/charging/discharging)
 * - Power value and bar
 * - Main battery level bar
 *
 * Shared data loading is handled by charge_status_data.php so it can be reused
 * by other partials without duplicating HTTP calls or config parsing.
 */

require_once __DIR__ . '/charge_status_data.php';
?>
<!-- Charge/Discharge Status Section -->
<div class="card">
    <div class="metric-section">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <h3>🔋 Charge/Discharge</h3>
        <button class="charge-refresh-btn" id="charge-refresh-btn" onclick="window.location.reload();" title="Refresh charge status">
            <span class="refresh-icon">↻</span>
            <span class="refresh-text">Refresh</span>
        </button>
    </div>
    <?php
    if ($chargeStatusError):
    ?>
            <div class="charge-status-error" id="charge-status-error">
                <p><?php echo htmlspecialchars($chargeStatusError); ?></p>
            </div>
        <?php elseif ($zendureData && isset($zendureData['properties'])): 
            $properties = $zendureData['properties'];
            
            // Extract properties for status determination
            $acMode = $properties['acMode'] ?? 0;
            $outputPackPower = $properties['outputPackPower'] ?? 0;
            $outputHomePower = $properties['outputHomePower'] ?? 0;
            $acStatus = $properties['acStatus'] ?? 0;
            $electricLevel = $properties['electricLevel'] ?? 0;
            $solarInputPower = $properties['solarInputPower'] ?? 0;
            
            // Calculate charge/discharge value (positive = charging, negative = discharging)
            $chargeDischargeValue = ($outputPackPower > 0) ? $outputPackPower : (($outputHomePower > 0) ? -$outputHomePower : 0);
            
            // Get system status info using helper function (acMode: 0=Stand-by, 1=Charging, 2=Discharging)
            $systemStatus = getSystemStatusInfo($acMode, $outputPackPower, $outputHomePower, $solarInputPower, $electricLevel);
            
            // Use system status values (from helper function)
            $statusClass = $systemStatus['class'];
            $statusIcon = $systemStatus['icon'];
            $statusText = $systemStatus['title'];
            
            // Determine power color (green for charging, red for discharging, gray for standby)
            // Note: Power value uses different color scheme than status indicator
            // Colors should match CSS variables defined in charge_status_defines.css:
            // --charge-status-charging: #66bb6a, --charge-status-discharging: #ef5350, --charge-status-standby: #9e9e9e
            if ($chargeDischargeValue > 0) {
                $powerColor = '#66bb6a'; // Green for positive (charging) - should match --charge-status-charging
            } elseif ($chargeDischargeValue < 0) {
                $powerColor = '#ef5350'; // Red for negative (discharging) - should match --charge-status-discharging
            } else {
                $powerColor = '#9e9e9e'; // Gray for zero (standby) - should match --charge-status-standby
            }
        ?>
          
            
            <div class="charge-status-content" id="charge-status-content">
                <!-- Status Indicator -->
                <div class="charge-status-indicator <?php echo $statusClass; ?>">
                    <div class="charge-status-icon"><?php echo $statusIcon; ?></div>
                    <div class="charge-status-text">
                        <div class="charge-status-title"><?php echo htmlspecialchars($statusText); ?></div>
                        <div class="charge-status-subtitle"><?php echo htmlspecialchars($systemStatus['subtitle']); ?></div>
                    </div>
                </div>
                
                <!-- Power Value -->
                <div class="charge-power-display">
                    <div class="charge-power-label-value">
                        <span class="charge-power-label">Power:</span>
                        <span class="charge-power-value" style="color: <?php echo htmlspecialchars($powerColor); ?>;">
                            <?php 
                            $powerDisplay = '0 W';
                            $timeEstimate = '';
                            
                            if ($chargeDischargeValue > 0) {
                                // Charging - calculate time until max level
                                $powerDisplay = '+' . number_format($chargeDischargeValue) . ' W';
                                $capacityToMaxKwh = (($MAX_CHARGE_LEVEL - $electricLevel) / 100) * $TOTAL_CAPACITY_KWH;
                                $capacityToMaxWh = $capacityToMaxKwh * 1000;
                                if ($chargeDischargeValue > 0 && $capacityToMaxWh > 0) {
                                    $hoursToMax = $capacityToMaxWh / $chargeDischargeValue;
                                    if ($hoursToMax < 0.0167) { // Less than 1 minute
                                        $timeEstimate = '< 1m left';
                                    } elseif ($hoursToMax < 1) {
                                        $minutes = round($hoursToMax * 60);
                                        $timeEstimate = $minutes . 'm left';
                                    } else {
                                        $hours = floor($hoursToMax);
                                        $minutes = round(($hoursToMax - $hours) * 60);
                                        if ($minutes >= 60) {
                                            $hours++;
                                            $minutes = 0;
                                        }
                                        $timeEstimate = $hours . ':' . str_pad($minutes, 2, '0', STR_PAD_LEFT) . 'h left';
                                    }
                                }
                            } elseif ($chargeDischargeValue < 0) {
                                // Discharging - calculate time until min level
                                $powerDisplay = number_format($chargeDischargeValue) . ' W';
                                $capacityToMinKwh = (($electricLevel - $MIN_CHARGE_LEVEL) / 100) * $TOTAL_CAPACITY_KWH;
                                $capacityToMinWh = $capacityToMinKwh * 1000;
                                $absPower = abs($chargeDischargeValue);
                                if ($absPower > 0 && $capacityToMinWh > 0) {
                                    $hoursToMin = $capacityToMinWh / $absPower;
                                    if ($hoursToMin < 0.0167) { // Less than 1 minute
                                        $timeEstimate = '< 1m left';
                                    } elseif ($hoursToMin < 1) {
                                        $minutes = round($hoursToMin * 60);
                                        $timeEstimate = $minutes . 'm left';
                                    } else {
                                        $hours = floor($hoursToMin);
                                        $minutes = round(($hoursToMin - $hours) * 60);
                                        if ($minutes >= 60) {
                                            $hours++;
                                            $minutes = 0;
                                        }
                                        $timeEstimate = $hours . ':' . str_pad($minutes, 2, '0', STR_PAD_LEFT) . 'h left';
                                    }
                                }
                            } else {
                                $powerDisplay = '0 W';
                            }
                            
                            echo $powerDisplay;
                            if ($timeEstimate) {
                                echo ' <span class="charge-power-time">(' . htmlspecialchars($timeEstimate) . ')</span>';
                            }
                            ?>
                        </span>
                    </div>
                    <?php
                    // Calculate bar width for -1200 to +1200 range
                    $minPower = -1200;
                    $maxPower = 1200;
                    $clampedValue = max($minPower, min($maxPower, $chargeDischargeValue));
                    $barClass = 'charging'; // Default, will be overridden if negative
                    
                    if ($clampedValue > 0) {
                        // Positive - bar extends right from center (green)
                        $barWidth = abs($clampedValue) / abs($maxPower) * 50; // 50% max (half container)
                        $barWidth = max(6, $barWidth); // Minimum 6% for visibility (increased from 2%)
                        $barClass = 'charging';
                    } elseif ($clampedValue < 0) {
                        // Negative - bar extends left from center (red)
                        $barWidth = abs($clampedValue) / abs($minPower) * 50; // 50% max (half container)
                        $barWidth = max(6, $barWidth); // Minimum 6% for visibility (increased from 2%)
                        $barClass = 'discharging';
                    } else {
                        // Zero - no bar
                        $barWidth = 0;
                        $barClass = '';
                    }
                    ?>
                    <div class="charge-power-bar-container">
                        <div class="charge-power-bar-label left">-1200 W</div>
                        <div class="charge-power-bar-label center">0</div>
                        <div class="charge-power-bar-label right">1200 W</div>
                        <div class="charge-power-bar-center"></div>
                        <?php if ($barWidth > 0): ?>
                            <div class="charge-power-bar-fill <?php echo htmlspecialchars($barClass); ?>" style="width: <?php echo $barWidth; ?>%;"></div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Battery Level -->
                <div class="charge-battery-display">
                    <div class="charge-battery-label-value">
                        <span class="charge-battery-label">Battery Level:</span>
                        <span class="charge-battery-value">
                            <?php 
                            // Calculate capacity values
                            $totalCapacityLeftKwh = ($electricLevel / 100) * $TOTAL_CAPACITY_KWH;
                            $usableCapacityAboveMinKwh = max(0, (($electricLevel - $MIN_CHARGE_LEVEL) / 100) * $TOTAL_CAPACITY_KWH);
                            echo number_format($electricLevel) . '% (' . number_format($totalCapacityLeftKwh, 2) . ' kWh/' . number_format($usableCapacityAboveMinKwh, 2) . ' kWh)';
                            ?>
                        </span>
                    </div>
                    <div class="charge-battery-bar">
                        <div class="charge-battery-bar-marker min" style="left: <?php echo $MIN_CHARGE_LEVEL; ?>%;" title="Minimum: <?php echo $MIN_CHARGE_LEVEL; ?>%"></div>
                        <div class="charge-battery-bar-marker max" style="left: <?php echo $MAX_CHARGE_LEVEL; ?>%;" title="Maximum: <?php echo $MAX_CHARGE_LEVEL; ?>%"></div>
                        <div class="charge-battery-bar-fill" style="width: <?php echo htmlspecialchars(min(100, max(0, $electricLevel))); ?>%; background-color: <?php echo htmlspecialchars($systemStatus['color']); ?>;"></div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="charge-status-empty" id="charge-status-empty">
                <p>No charge status data available</p>
            </div>
        <?php endif; ?>
        <?php if ($lastUpdate): ?>
                <div class="charge-status-header">
                    <span class="charge-last-update" id="charge-last-update">
                        Last update: <?php echo htmlspecialchars(formatRelativeTime($lastUpdate)); ?>
                        <span class="charge-timestamp-full">(<?php echo htmlspecialchars(date('Y-m-d H:i:s', $lastUpdate)); ?>)</span>
                    </span>
                </div>
            <?php endif; ?>
    </div>
</div>
