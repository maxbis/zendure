<?php
/**
 * Color Functions and Constants
 * Defines color constants and functions for system metrics
 */

// Color constants for power flow directions
define('COLOR_EXPORTING', '#ff9800');   // Orange - exporting to grid
define('COLOR_IMPORTING', '#64b5f6');   // Blue - importing from grid
define('COLOR_CHARGING', '#66bb6a');    // Green - battery charging
define('COLOR_DISCHARGING', '#ef5350'); // Red - battery discharging

/**
 * Get color for temperature display with enhanced gradient
 * Blue (cold) -> Light yellow -> Yellow -> Green -> Orange -> Red (hot)
 * Scale: -10 to +40 degrees Celsius
 * 
 * @param float $temp Temperature in Celsius (-10 to +40)
 * @return string Hex color code
 */
function getTempColorEnhanced($temp) {
    // Clamp temperature to range
    $temp = max(-10, min(40, $temp));
    
    // Simplified color mapping for -10 to +40 range
    // -10 to 0: Blue (#2196f3)
    // 0 to 10: Light yellow (#fff9c4)
    // 10 to 20: Yellow (#fff176)
    // 20 to 30: Green (#81c784)
    // 30 to 35: Orange (#ff9800)
    // 35 to 40: Red (#e57373)
    
    if ($temp <= 0) {
        return '#4fc3f7'; // Blue
    } elseif ($temp <= 5) {
        return '#fff176'; // Light yellow
    } elseif ($temp <= 15) {
        return '#ffe500'; // Yellow
    } elseif ($temp <= 25) {
        return '#81c784'; // Green
    } elseif ($temp <= 30) {
        return '#ff9800'; // Orange
    } else {
        return '#e57373'; // Red
    }
}

/**
 * Get color for WiFi signal strength (RSSI)
 * Green for good signal (8-10), yellow for medium (5-7), red for poor (0-4)
 * 
 * @param float $rssi RSSI value on 0-10 scale
 * @return string Hex color code
 */
function getRssiColor($rssi) {
    if ($rssi >= 8) {
        return '#81c784'; // Green
    } elseif ($rssi >= 5) {
        return '#fff176'; // Yellow
    } else {
        return '#e57373'; // Red
    }
}

/**
 * Get color for battery level
 * Green for high (80-100%), yellow for medium (50-79%), orange for low (20-49%), red for critical (0-19%)
 * 
 * @param float $level Battery level percentage (0-100)
 * @return string Hex color code
 */
function getBatteryLevelColor($level) {
    if ($level >= 50) {
        return '#81c784'; // Green
    } elseif ($level >= 30) {
        return '#fff176'; // Yellow
    } elseif ($level >= 25) {
        return '#ffb74d'; // Orange
    } else {
        return '#e57373'; // Red
    }
}
