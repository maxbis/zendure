<?php
/**
 * Charge/Discharge Status Details Partial
 * Displays detailed system metrics:
 * - Grid power
 * - WiFi signal strength
 * - System temperature
 * - Battery 1 & 2 temperatures
 * - Battery 1 & 2 charge levels
 *
 * Relies on shared data loading from charge_status_data.php.
 */

require_once __DIR__ . '/charge_status_data.php';
?>
<!-- Charge/Discharge Status Details Section -->
<div class="card">
    <div class="metric-section">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0;">🌡️ System &amp; Grid Status</h3>
            <button class="charge-refresh-btn" id="charge-details-toggle" onclick="toggleChargeStatusDetails()" title="Show/hide additional status details" style="margin-left: auto;">
                <span class="refresh-icon charge-details-toggle-icon">▼</span>
                <span class="refresh-text charge-details-toggle-text">Show more</span>
            </button>
        </div>
        
        <?php
        if ($chargeStatusError):
        ?>
            <div class="charge-status-error">
                <p><?php echo htmlspecialchars($chargeStatusError); ?></p>
            </div>
        <?php elseif ($zendureData && isset($zendureData['properties'])): 
            $properties = $zendureData['properties'];
        ?>
            <div class="charge-status-header">

            </div>
        <?php 
            // RSSI (WiFi signal)
            $rssi = $properties['rssi'] ?? -90; // Default to min_rssi if not available
            $minRssi = -90;
            $maxRssi = -30;
            $rssiScore = (($rssi - $minRssi) / ($maxRssi - $minRssi)) * 10;
            $rssiScore = max(0, min(10, $rssiScore)); // Clamp between 0 and 10

            // Determine RSSI color based on score
            $rssiColor = '#e57373'; // Default: Red
            if ($rssiScore >= 8) {
                $rssiColor = '#81c784'; // Green - Good/strong (≥-59)
            } elseif ($rssiScore >= 5) {
                $rssiColor = '#fff176'; // Yellow - Fair/usable (-69 to -60)
            } elseif ($rssiScore >= 3) {
                $rssiColor = '#ff9800'; // Orange - Weak/unreliable (-79 to -70)
            } else {
                $rssiColor = '#e57373'; // Red - Very weak/unusable (≤-80)
            }

            // Temperatures (system and battery packs)
            $hyperTmp = $properties['hyperTmp'] ?? 2731; // Default to 0°C if not available
            $systemTempCelsius = convertHyperTmp($hyperTmp);
            $systemHeatState = $properties['heatState'] ?? 0;
            $systemTempColor = getTempColorEnhanced($systemTempCelsius);

            // Battery pack data
            $packData = $zendureData['packData'] ?? [];
            $pack1TempCelsius = 0;
            $pack1HeatState = 0;
            $pack1TempColor = '#81c784'; // Default green
            $pack2TempCelsius = 0;
            $pack2HeatState = 0;
            $pack2TempColor = '#81c784'; // Default green

            if (isset($packData[0]) && isset($packData[0]['maxTemp'])) {
                $pack1TempCelsius = convertHyperTmp($packData[0]['maxTemp']);
                $pack1HeatState = $packData[0]['heatState'] ?? 0;
                $pack1TempColor = getTempColorEnhanced($pack1TempCelsius);
            }

            if (isset($packData[1]) && isset($packData[1]['maxTemp'])) {
                $pack2TempCelsius = convertHyperTmp($packData[1]['maxTemp']);
                $pack2HeatState = $packData[1]['heatState'] ?? 0;
                $pack2TempColor = getTempColorEnhanced($pack2TempCelsius);
            }

            // Temperature bar calculation helper (scale -10 to +40)
            $minTemp = -10;
            $maxTemp = 40;
            $systemTempPercent = (($systemTempCelsius - $minTemp) / ($maxTemp - $minTemp)) * 100;
            $systemTempPercent = max(0, min(100, $systemTempPercent));
            $pack1TempPercent = (($pack1TempCelsius - $minTemp) / ($maxTemp - $minTemp)) * 100;
            $pack1TempPercent = max(0, min(100, $pack1TempPercent));
            $pack2TempPercent = (($pack2TempCelsius - $minTemp) / ($maxTemp - $minTemp)) * 100;
            $pack2TempPercent = max(0, min(100, $pack2TempPercent));

            // Battery pack SoC and per-pack capacity for level boxes
            $packNum = $properties['packNum'] ?? count($packData);
            if (!$packNum || $packNum < 1) {
                $packNum = max(1, count($packData));
            }
            $packCapacityKwh = $TOTAL_CAPACITY_KWH / max(1, $packNum);

            $pack1Soc = $packData[0]['socLevel'] ?? null;
            $pack2Soc = $packData[1]['socLevel'] ?? null;

            if ($pack1Soc === null) {
                $pack1Soc = 0;
            }
            if ($pack2Soc === null) {
                $pack2Soc = 0;
            }

            $pack1TotalCapacityLeftKwh = ($pack1Soc / 100) * $packCapacityKwh;
            $pack1UsableCapacityAboveMinKwh = max(0, (($pack1Soc - $MIN_CHARGE_LEVEL) / 100) * $packCapacityKwh);

            $pack2TotalCapacityLeftKwh = ($pack2Soc / 100) * $packCapacityKwh;
            $pack2UsableCapacityAboveMinKwh = max(0, (($pack2Soc - $MIN_CHARGE_LEVEL) / 100) * $packCapacityKwh);
        ?>


            <div class="charge-status-content" id="charge-status-details-content">
                <!-- Grid -->
                <div class="charge-power-box">
                    <div class="charge-power-box-content">
                        <?php
                        $p1TotalPower = $p1Data['total_power'] ?? 0;
                        $gridPowerDisplay = number_format($p1TotalPower) . ' W';

                        // Calculate bar width for -2800 to +2800 range
                        $minGridPower = -2800;
                        $maxGridPower = 2800;
                        $clampedGridValue = max($minGridPower, min($maxGridPower, $p1TotalPower));
                        $gridBarClass = 'positive'; // Default, will be overridden if negative
                        $gridBarWidth = 0;

                        if ($clampedGridValue > 0) {
                            // Positive - bar extends right from center (blue)
                            $gridBarWidth = abs($clampedGridValue) / abs($maxGridPower) * 50; // 50% max (half container)
                            $gridBarWidth = max(6, $gridBarWidth); // Minimum 6% for visibility
                            $gridBarClass = 'positive';
                        } elseif ($clampedGridValue < 0) {
                            // Negative - bar extends left from center (green)
                            $gridBarWidth = abs($clampedGridValue) / abs($minGridPower) * 50; // 50% max (half container)
                            $gridBarWidth = max(6, $gridBarWidth); // Minimum 6% for visibility
                            $gridBarClass = 'negative';
                        } else {
                            // Zero - no bar
                            $gridBarWidth = 0;
                            $gridBarClass = '';
                        }
                        ?>
                        <div class="charge-power-label-value">
                            <span class="charge-power-label">Grid:</span>
                            <span class="charge-power-value"><?php echo htmlspecialchars($gridPowerDisplay); ?></span>
                        </div>
                        <div class="charge-grid-bar-container">
                            <div class="charge-grid-bar-label left">-2800 W</div>
                            <div class="charge-grid-bar-label center">0</div>
                            <div class="charge-grid-bar-label right">+2800 W</div>
                            <div class="charge-grid-bar-center"></div>
                            <?php if ($gridBarWidth > 0): ?>
                                <div class="charge-grid-bar-fill <?php echo htmlspecialchars($gridBarClass); ?>" style="width: <?php echo $gridBarWidth; ?>%;"></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- RSSI (WiFi Signal) -->
                <div class="charge-battery-display">
                    <div class="charge-battery-label-value">
                        <span class="charge-battery-label">WiFi Signal:</span>
                        <span class="charge-battery-value">
                            <?php 
                            echo number_format($rssiScore, 1) . '/10 (' . number_format($rssi) . ' dBm)';
                            ?>
                        </span>
                    </div>
                    <div class="charge-battery-bar">
                        <div class="charge-battery-bar-fill" style="width: <?php echo htmlspecialchars(min(100, max(0, $rssiScore * 10))); ?>%; background-color: <?php echo htmlspecialchars($rssiColor); ?>;"></div>
                    </div>
                </div>

                <!-- System Temperature -->
                <div class="charge-battery-display">
                    <div class="charge-battery-label-value">
                        <span class="charge-battery-label">System Temp:</span>
                        <span class="charge-battery-value">
                            <?php 
                            $systemHeatIcon = $systemHeatState == 1 ? '🔥' : '❄️';
                            echo number_format($systemTempCelsius, 1) . '°C ' . $systemHeatIcon;
                            ?>
                        </span>
                    </div>
                    <div class="charge-battery-bar">
                        <div class="charge-battery-bar-fill" style="width: <?php echo htmlspecialchars($systemTempPercent); ?>%; background-color: <?php echo htmlspecialchars($systemTempColor); ?>;"></div>
                    </div>
                </div>

                <!-- Collapsible section: rows 2-3 (Battery 1 & 2 levels and temps) -->
                <div class="charge-status-details-collapsible" id="charge-status-details-collapsible">
                    <!-- Empty placeholder before Battery 1 Level (box alignment) -->
                    <div class="charge-empty-box"></div>

                    <!-- Battery 1 Level -->
                    <div class="charge-battery-display">
                        <div class="charge-battery-label-value">
                            <span class="charge-battery-label">Battery 1 Level:</span>
                            <span class="charge-battery-value">
                                <?php
                                echo number_format($pack1Soc) . '% (' . number_format($pack1TotalCapacityLeftKwh, 2) . ' kWh/' . number_format($pack1UsableCapacityAboveMinKwh, 2) . ' kWh)';
                                ?>
                            </span>
                        </div>
                        <div class="charge-battery-bar">
                            <div class="charge-battery-bar-marker min" style="left: <?php echo $MIN_CHARGE_LEVEL; ?>%;" title="Minimum: <?php echo $MIN_CHARGE_LEVEL; ?>%"></div>
                            <div class="charge-battery-bar-marker max" style="left: <?php echo $MAX_CHARGE_LEVEL; ?>%;" title="Maximum: <?php echo $MAX_CHARGE_LEVEL; ?>%"></div>
                            <div class="charge-battery-bar-fill" style="width: <?php echo htmlspecialchars(min(100, max(0, $pack1Soc))); ?>%; background-color: #81c784;"></div>
                        </div>
                    </div>

                    <!-- Battery 1 Temperature -->
                    <div class="charge-battery-display">
                        <div class="charge-battery-label-value">
                            <span class="charge-battery-label">Battery 1 Temp:</span>
                            <span class="charge-battery-value">
                                <?php 
                                $pack1HeatIcon = $pack1HeatState == 1 ? '🔥' : '❄️';
                                echo number_format($pack1TempCelsius, 1) . '°C ' . $pack1HeatIcon;
                                ?>
                            </span>
                        </div>
                        <div class="charge-battery-bar">
                            <div class="charge-battery-bar-fill" style="width: <?php echo htmlspecialchars($pack1TempPercent); ?>%; background-color: <?php echo htmlspecialchars($pack1TempColor); ?>;"></div>
                        </div>
                    </div>

                    <!-- Empty placeholder before Battery 2 Level (box alignment) -->
                    <div class="charge-empty-box"></div>

                    <!-- Battery 2 Level -->
                    <div class="charge-battery-display">
                        <div class="charge-battery-label-value">
                            <span class="charge-battery-label">Battery 2 Level:</span>
                            <span class="charge-battery-value">
                                <?php
                                echo number_format($pack2Soc) . '% (' . number_format($pack2TotalCapacityLeftKwh, 2) . ' kWh/' . number_format($pack2UsableCapacityAboveMinKwh, 2) . ' kWh)';
                                ?>
                            </span>
                        </div>
                        <div class="charge-battery-bar">
                            <div class="charge-battery-bar-marker min" style="left: <?php echo $MIN_CHARGE_LEVEL; ?>%;" title="Minimum: <?php echo $MIN_CHARGE_LEVEL; ?>%"></div>
                            <div class="charge-battery-bar-marker max" style="left: <?php echo $MAX_CHARGE_LEVEL; ?>%;" title="Maximum: <?php echo $MAX_CHARGE_LEVEL; ?>%"></div>
                            <div class="charge-battery-bar-fill" style="width: <?php echo htmlspecialchars(min(100, max(0, $pack2Soc))); ?>%; background-color: #81c784;"></div>
                        </div>
                    </div>

                    <!-- Battery 2 Temperature -->
                    <div class="charge-battery-display">
                        <div class="charge-battery-label-value">
                            <span class="charge-battery-label">Battery 2 Temp:</span>
                            <span class="charge-battery-value">
                                <?php 
                                $pack2HeatIcon = $pack2HeatState == 1 ? '🔥' : '❄️';
                                echo number_format($pack2TempCelsius, 1) . '°C ' . $pack2HeatIcon;
                                ?>
                            </span>
                        </div>
                        <div class="charge-battery-bar">
                            <div class="charge-battery-bar-fill" style="width: <?php echo htmlspecialchars($pack2TempPercent); ?>%; background-color: <?php echo htmlspecialchars($pack2TempColor); ?>;"></div>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="charge-status-empty">
                <p>No charge status data available</p>
            </div>
        <?php endif; ?>
    </div>
</div>

