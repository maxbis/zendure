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
 * Get color for temperature display
 * Blue for cold (-10 to 0), transitioning to red for warm (30 to 40)
 * 
 * @param float $temp Temperature in Celsius
 * @return string Hex color code
 */
function getTempColor($temp) {
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

