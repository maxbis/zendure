<?php
/**
 * Charge/Discharge Status Partial - Mobile Version
 * Displays as three distinct boxes: Status Indicator, Power Display, Battery Level
 */

require_once __DIR__ . '/charge_status_data.php';
?>
<!-- Charge/Discharge Status Section - Mobile (Three Boxes) -->
<div class="card">
    <div class="metric-section">
        <h3>🔋 Charge/Discharge</h3>
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
            
            // Get system status info using helper function
            $systemStatus = getSystemStatusInfo($acMode, $outputPackPower, $outputHomePower, $solarInputPower, $electricLevel);
            
            // Use system status values
            $statusClass = $systemStatus['class'];
            $statusIcon = $systemStatus['icon'];
            $statusText = $systemStatus['title'];
            
            // Determine power color
            if ($chargeDischargeValue > 0) {
                $powerColor = '#66bb6a';
            } elseif ($chargeDischargeValue < 0) {
                $powerColor = '#ef5350';
            } else {
                $powerColor = '#9e9e9e';
            }
        ?>
            <div class="charge-status-mobile" id="charge-status-content">
                <!-- Box 1: Status Indicator -->
                <div class="charge-status-box">
                    <div class="charge-status-box-title">Status</div>
                    <div class="charge-status-box-content">
                        <div class="charge-status-indicator <?php echo $statusClass; ?>" style="padding: 10px; border-radius: 6px;">
                            <div class="charge-status-icon" style="font-size: 1.5rem;"><?php echo $statusIcon; ?></div>
                            <div class="charge-status-text">
                                <div class="charge-status-title" style="font-size: 0.95rem;"><?php echo htmlspecialchars($statusText); ?></div>
                                <div class="charge-status-subtitle" style="font-size: 0.75rem;"><?php echo htmlspecialchars($systemStatus['subtitle']); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Box 2: Power Display -->
                <div class="charge-status-box">
                    <div class="charge-status-box-title">Power</div>
                    <div class="charge-status-box-content">
                        <div class="charge-power-display" style="padding: 10px; border-radius: 6px;">
                            <div class="charge-power-label-value" style="margin-bottom: 8px;">
                                <span class="charge-power-value" style="color: <?php echo htmlspecialchars($powerColor); ?>; font-size: 1.1rem; font-weight: 700;">
                                    <?php 
                                    $powerDisplay = '0 W';
                                    $timeEstimate = '';
                                    
                                    if ($chargeDischargeValue > 0) {
                                        $powerDisplay = '+' . number_format($chargeDischargeValue) . ' W';
                                        $capacityToMaxKwh = (($MAX_CHARGE_LEVEL - $electricLevel) / 100) * $TOTAL_CAPACITY_KWH;
                                        $capacityToMaxWh = $capacityToMaxKwh * 1000;
                                        if ($chargeDischargeValue > 0 && $capacityToMaxWh > 0) {
                                            $hoursToMax = $capacityToMaxWh / $chargeDischargeValue;
                                            if ($hoursToMax < 0.0167) {
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
                                        $powerDisplay = number_format($chargeDischargeValue) . ' W';
                                        $capacityToMinKwh = (($electricLevel - $MIN_CHARGE_LEVEL) / 100) * $TOTAL_CAPACITY_KWH;
                                        $capacityToMinWh = $capacityToMinKwh * 1000;
                                        $absPower = abs($chargeDischargeValue);
                                        if ($absPower > 0 && $capacityToMinWh > 0) {
                                            $hoursToMin = $capacityToMinWh / $absPower;
                                            if ($hoursToMin < 0.0167) {
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
                                        echo ' <span class="charge-power-time" style="font-size: 0.8rem;">(' . htmlspecialchars($timeEstimate) . ')</span>';
                                    }
                                    ?>
                                </span>
                            </div>
                            <?php
                            // Calculate bar width for -1200 to +1200 range
                            $minPower = -1200;
                            $maxPower = 1200;
                            $clampedValue = max($minPower, min($maxPower, $chargeDischargeValue));
                            $barClass = 'charging';
                            
                            if ($clampedValue > 0) {
                                $barWidth = abs($clampedValue) / abs($maxPower) * 50;
                                $barWidth = max(6, $barWidth);
                                $barClass = 'charging';
                            } elseif ($clampedValue < 0) {
                                $barWidth = abs($clampedValue) / abs($minPower) * 50;
                                $barWidth = max(6, $barWidth);
                                $barClass = 'discharging';
                            } else {
                                $barWidth = 0;
                                $barClass = '';
                            }
                            ?>
                            <div class="charge-power-bar-container" style="height: 14px;">
                                <div class="charge-power-bar-label left" style="font-size: 0.6rem;">-1200</div>
                                <div class="charge-power-bar-label center" style="font-size: 0.6rem;">0</div>
                                <div class="charge-power-bar-label right" style="font-size: 0.6rem;">1200</div>
                                <div class="charge-power-bar-center"></div>
                                <?php if ($barWidth > 0): ?>
                                    <div class="charge-power-bar-fill <?php echo htmlspecialchars($barClass); ?>" style="width: <?php echo $barWidth; ?>%;"></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Box 3: Battery Level -->
                <div class="charge-status-box">
                    <div class="charge-status-box-title">Battery</div>
                    <div class="charge-status-box-content">
                        <div class="charge-battery-display" style="padding: 10px; border-radius: 6px;">
                            <div class="charge-battery-label-value" style="margin-bottom: 8px;">
                                <span class="charge-battery-value" style="font-size: 1rem; font-weight: 600;">
                                    <?php 
                                    $usableNetKwh = max(0, (($electricLevel - $MIN_CHARGE_LEVEL) / 100) * $TOTAL_CAPACITY_KWH);
                                    $roomToChargeKwh = max(0, (($MAX_CHARGE_LEVEL - $electricLevel) / 100) * $TOTAL_CAPACITY_KWH);
                                    echo number_format($electricLevel) . '% (' . number_format($usableNetKwh, 2) . ' kWh - ' . number_format($roomToChargeKwh, 2) . ' kWh)';
                                    ?>
                                </span>
                            </div>
                            <div class="charge-battery-bar" style="height: 14px;">
                                <div class="charge-battery-bar-marker min" style="left: <?php echo $MIN_CHARGE_LEVEL; ?>%;" title="Minimum: <?php echo $MIN_CHARGE_LEVEL; ?>%"></div>
                                <div class="charge-battery-bar-marker max" style="left: <?php echo $MAX_CHARGE_LEVEL; ?>%;" title="Maximum: <?php echo $MAX_CHARGE_LEVEL; ?>%"></div>
                                <div class="charge-battery-bar-fill" style="width: <?php echo htmlspecialchars(min(100, max(0, $electricLevel))); ?>%; background-color: <?php echo htmlspecialchars($systemStatus['color']); ?>;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="charge-status-empty" id="charge-status-empty">
                <p>No charge status data available</p>
            </div>
        <?php endif; ?>
        <?php if ($lastUpdate): ?>
            <div class="charge-status-header" style="margin-top: 10px;">
                <span class="charge-last-update" id="charge-last-update" style="font-size: 0.75rem; color: var(--text-tertiary);">
                    Last update: <?php echo htmlspecialchars(formatRelativeTime($lastUpdate)); ?>
                </span>
            </div>
        <?php endif; ?>
    </div>
</div>
