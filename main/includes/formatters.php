<?php
/**
 * Formatting and Conversion Functions
 * Functions for formatting dates/times and converting units
 */

/**
 * Convert hyperTmp from one-tenth Kelvin to Celsius
 * Formula: (hyperTmp - 2731) / 10.0
 */
function convertHyperTmp($hyperTmp) {
    return ($hyperTmp - 2731) / 10.0;
}

/**
 * Convert RSSI (dBm) to a 0-10 scale
 * - 10 is perfect (-30 dBm or better)
 * - 5 is the unreliable threshold (-80 dBm)
 * - 0 is no connection (-130 dBm or worse)
 */
function convertRssiToScale($rssi) {
    // Linear formula: score = 0.1 * RSSI + 13
    $score = (0.1 * $rssi) + 13;
    
    // Clamp the score between 0 and 10
    $score = max(0, min(10, $score));
    
    // Return rounded to one decimal place
    return round($score, 1);
}
