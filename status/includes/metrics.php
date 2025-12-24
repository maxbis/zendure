<?php
/**
 * Metric Data Preparation Functions
 * Functions for preparing and calculating metric data for display
 */

/**
 * Prepare all metric data for display
 * Calculates bar widths, colors, and values for all system metrics
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
    $chargeBarData = calculateBarWidthNonLinear($chargePowerValue, 0, 2000, 'power', 0.7);
    
    // Discharging Power
    $dischargePowerValue = $properties['outputHomePower'] ?? 0;
    $dischargeBarData = calculateBarWidthNonLinear($dischargePowerValue, 0, 2000, 'power', 0.7);
    
    // Total Power (store signed value for display, use abs for bar calculation)
    $totalPowerValue = $p1TotalPower; // Keep signed value for display
    $totalPowerBarData = calculateBarWidthNonLinear(abs($p1TotalPower), 0, 2000, 'power', 0.7);
    $totalPowerColor = ($p1TotalPower < 0) ? COLOR_EXPORTING : COLOR_IMPORTING;
    
    // Grid Status (bidirectional)
    $gridBarData = getBidirectionalBarDataNonLinear($p1TotalPower, -2000, 2000, 'power', 0.7);
    $gridStatusColor = ($p1TotalPower < 0) ? COLOR_EXPORTING : COLOR_IMPORTING;
    
    // Unit Temperature
    $tempBarData = calculateBarWidth($hyperTmpCelsius, -10, 40);
    $tempColor = getTempColor($hyperTmpCelsius);
    
    // WiFi Signal Strength
    $rssiBarData = calculateBarWidth($rssiScale, 0, 10);
    $rssiColor = getRssiColor($rssiScale);
    
    // Battery Level
    $batteryLevelValue = $properties['electricLevel'] ?? 0;
    $batteryLevelBarData = calculateBarWidth($batteryLevelValue, 0, 100);
    $batteryLevelColor = getBatteryLevelColor($batteryLevelValue);
    
    // Charging/Discharging (bidirectional)
    // Positive = charging, Negative = discharging
    $chargeDischargeValue = ($chargePowerValue > 0) ? $chargePowerValue : (($dischargePowerValue > 0) ? -$dischargePowerValue : 0);
    $chargeDischargeBarData = getBidirectionalBarDataNonLinear($chargeDischargeValue, -1200, 1200, 'power', 0.7);
    if ($chargeDischargeValue > 0) {
        $chargeDischargeColor = '#66bb6a'; // Green for charging
        $chargeDischargeBarClass = 'charging';
    } elseif ($chargeDischargeValue < 0) {
        $chargeDischargeColor = '#ef5350'; // Red for discharging
        $chargeDischargeBarClass = 'discharging';
    } else {
        $chargeDischargeColor = '#424242'; // Dark gray for zero/idle
        $chargeDischargeBarClass = '';
    }
    
    return [
        'chargePower' => [
            'value' => $chargePowerValue,
            'barData' => $chargeBarData,
        ],
        'dischargePower' => [
            'value' => $dischargePowerValue,
            'barData' => $dischargeBarData,
        ],
        'totalPower' => [
            'value' => $totalPowerValue,
            'barData' => $totalPowerBarData,
            'color' => $totalPowerColor,
        ],
        'gridStatus' => [
            'barData' => $gridBarData,
            'color' => $gridStatusColor,
        ],
        'temperature' => [
            'barData' => $tempBarData,
            'color' => $tempColor,
        ],
        'rssi' => [
            'barData' => $rssiBarData,
            'color' => $rssiColor,
        ],
        'batteryLevel' => [
            'value' => $batteryLevelValue,
            'barData' => $batteryLevelBarData,
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

