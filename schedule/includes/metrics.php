<?php
/**
 * Metric Data Preparation Functions
 * Functions for preparing and calculating metric data for display
 */

/**
 * Prepare all metric data for display
 * Calculates colors and values for all system metrics
 * Note: Bar widths are now calculated in renderMetricBar() from the value parameter
 * 
 * @param array $properties Zendure device properties
 * @param float $p1TotalPower P1 meter total power value
 * @param float $hyperTmpCelsius Unit temperature in Celsius
 * @param float $rssiScale WiFi signal strength (0-10 scale)
 * @return array Associative array containing all prepared metric data
 */
function prepareMetricData($properties, $p1TotalPower, $hyperTmpCelsius, $rssiScale) {
    // Charging Power
    $chargePowerValue = $properties['outputPackPower'] ?? 0;
    
    // Discharging Power
    $dischargePowerValue = $properties['outputHomePower'] ?? 0;
    
    // Total Power (store signed value for display)
    $totalPowerValue = $p1TotalPower; // Keep signed value for display
    $totalPowerColor = ($p1TotalPower < 0) ? COLOR_EXPORTING : COLOR_IMPORTING;
    
    // Grid Status (bidirectional) - bar data still calculated here for bidirectional bars
    $gridBarData = getBidirectionalBarDataNonLinear($p1TotalPower, -2000, 2000, 'power', 0.7);
    $gridStatusColor = ($p1TotalPower < 0) ? COLOR_EXPORTING : COLOR_IMPORTING;
    
    // Unit Temperature
    $tempColor = getTempColor($hyperTmpCelsius);
    
    // WiFi Signal Strength
    $rssiColor = getRssiColor($rssiScale);
    
    // Battery Level
    $batteryLevelValue = $properties['electricLevel'] ?? 0;
    $batteryLevelColor = getBatteryLevelColor($batteryLevelValue);
    
    // Charging/Discharging (bidirectional) - bar data still calculated here for bidirectional bars
    // Positive = charging, Negative = discharging
    $chargeDischargeValue = ($chargePowerValue > 0) ? $chargePowerValue : (($dischargePowerValue > 0) ? -$dischargePowerValue : 0);
    $chargeDischargeBarData = getBidirectionalBarDataNonLinear($chargeDischargeValue, -1200, 1200, 'power', 0.7);
    if ($chargeDischargeValue > 0) {
        $chargeDischargeColor = defined('COLOR_CHARGING') ? COLOR_CHARGING : '#66bb6a'; // Green for charging
        $chargeDischargeBarClass = 'charging';
    } elseif ($chargeDischargeValue < 0) {
        $chargeDischargeColor = defined('COLOR_DISCHARGING') ? COLOR_DISCHARGING : '#ef5350'; // Red for discharging
        $chargeDischargeBarClass = 'discharging';
    } else {
        $chargeDischargeColor = '#424242'; // Dark gray for zero/idle
        $chargeDischargeBarClass = '';
    }
    
    return [
        'chargePower' => [
            'value' => $chargePowerValue,
        ],
        'dischargePower' => [
            'value' => $dischargePowerValue,
        ],
        'totalPower' => [
            'value' => $totalPowerValue,
            'color' => $totalPowerColor,
        ],
        'gridStatus' => [
            'barData' => $gridBarData,
            'color' => $gridStatusColor,
        ],
        'temperature' => [
            'color' => $tempColor,
        ],
        'rssi' => [
            'color' => $rssiColor,
        ],
        'batteryLevel' => [
            'value' => $batteryLevelValue,
            'color' => $batteryLevelColor,
        ],
        'chargeDischarge' => [
            'value' => $chargeDischargeValue,
            'barData' => $chargeDischargeBarData,
            'color' => $chargeDischargeColor,
            'class' => $chargeDischargeBarClass,
        ],
    ];
}
