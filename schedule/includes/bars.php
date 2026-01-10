<?php
/**
 * Bar Calculation Functions
 * Functions for calculating bar widths, percentages, and scaling
 */

/**
 * Calculate bar width percentage with clamping
 * @param float $value The current value
 * @param float $min The minimum value (default 0)
 * @param float $max The maximum value
 * @return array Returns ['percent' => float, 'clamped' => bool, 'exceedsMax' => bool]
 */
function calculateBarWidth($value, $min, $max) {
    $clamped = false;
    $exceedsMax = false;
    
    // Clamp value to range
    if ($value < $min) {
        $clampedValue = $min;
        $clamped = true;
    } elseif ($value > $max) {
        $clampedValue = $max;
        $clamped = true;
        $exceedsMax = true;
    } else {
        $clampedValue = $value;
    }
    
    // Calculate percentage
    $range = $max - $min;
    if ($range <= 0) {
        $percent = 0;
    } else {
        $percent = (($clampedValue - $min) / $range) * 100;
    }
    
    // Ensure percent is between 0 and 100
    $percent = max(0, min(100, $percent));
    
    return [
        'percent' => $percent,
        'clamped' => $clamped,
        'exceedsMax' => $exceedsMax,
        'clampedValue' => $clampedValue,
        'originalValue' => $value
    ];
}

/**
 * Calculate bar width with non-linear scaling options
 * @param float $value The value to display
 * @param float $min The minimum value in the range
 * @param float $max The maximum value in the range
 * @param string $scaleType The type of scaling: 'linear', 'power', 'sqrt', or 'log' (default: 'linear')
 * @param float $exponent The exponent for power scaling (default: 0.7)
 * @return array Returns bar properties including percent, clamped, exceedsMax, clampedValue, and originalValue
 */
function calculateBarWidthNonLinear($value, $min, $max, $scaleType = 'linear', $exponent = 0.7) {
    $clamped = false;
    $exceedsMax = false;
    
    // Clamp value to range
    if ($value < $min) {
        $clampedValue = $min;
        $clamped = true;
    } elseif ($value > $max) {
        $clampedValue = $max;
        $clamped = true;
        $exceedsMax = true;
    } else {
        $clampedValue = $value;
    }
    
    // Calculate percentage with non-linear scaling
    $range = $max - $min;
    if ($range <= 0) {
        $percent = 0;
    } else {
        // Normalize value to 0-1 range
        $normalized = ($clampedValue - $min) / $range;
        
        // Apply non-linear scaling
        switch ($scaleType) {
            case 'power':
                // Power scale: normalized^exponent
                if ($normalized <= 0) {
                    $scaled = 0;
                } else {
                    $scaled = pow($normalized, $exponent);
                }
                break;
                
            case 'sqrt':
                // Square root scale: sqrt(normalized)
                if ($normalized <= 0) {
                    $scaled = 0;
                } else {
                    $scaled = sqrt($normalized);
                }
                break;
                
            case 'log':
                // Logarithmic scale: log(value+1)/log(max+1)
                $adjustedValue = $clampedValue - $min + 1;
                $adjustedMax = $max - $min + 1;
                if ($adjustedValue <= 1) {
                    $scaled = 0;
                } else {
                    $scaled = log($adjustedValue) / log($adjustedMax);
                }
                break;
                
            case 'linear':
            default:
                // Linear scale (default)
                $scaled = $normalized;
                break;
        }
        
        // Convert to percentage
        $percent = $scaled * 100;
    }
    
    // Ensure percent is between 0 and 100
    $percent = max(0, min(100, $percent));
    
    return [
        'percent' => $percent,
        'clamped' => $clamped,
        'exceedsMax' => $exceedsMax,
        'clampedValue' => $clampedValue,
        'originalValue' => $value
    ];
}

/**
 * Apply non-linear scaling to a normalized value (0-1)
 * @param float $normalized The normalized value (0-1)
 * @param string $scaleType The type of scaling: 'linear', 'power', 'sqrt', or 'log'
 * @param float $exponent The exponent for power scaling
 * @return float The scaled value (0-1)
 */
function applyNonLinearScale($normalized, $scaleType, $exponent = 0.7) {
    switch ($scaleType) {
        case 'power':
            // Power scale: normalized^exponent
            if ($normalized <= 0) {
                return 0;
            } else {
                return pow($normalized, $exponent);
            }
            
        case 'sqrt':
            // Square root scale: sqrt(normalized)
            if ($normalized <= 0) {
                return 0;
            } else {
                return sqrt($normalized);
            }
            
        case 'log':
            // Logarithmic scale (not applicable for bidirectional, but included for consistency)
            if ($normalized <= 0) {
                return 0;
            } else {
                // Use log base 10: log10(normalized * 9 + 1) / log10(10)
                return log10($normalized * 9 + 1);
            }
            
        case 'linear':
        default:
            // Linear scale (default)
            return $normalized;
    }
}

/**
 * Calculate bidirectional bar properties with non-linear scaling
 * @param float $value The power value (can be negative for exporting, positive for importing)
 * @param float $min The minimum value (typically negative, e.g., -2000)
 * @param float $max The maximum value (typically positive, e.g., 2000)
 * @param string $scaleType The type of scaling: 'linear', 'power', 'sqrt', or 'log' (default: 'linear')
 * @param float $exponent The exponent for power scaling (default: 0.7)
 * @return array Returns bar properties including width, class, value, and position
 */
function getBidirectionalBarDataNonLinear($value, $min, $max, $scaleType = 'linear', $exponent = 0.7) {
    // Clamp value between min and max for bar width calculation
    $clampedValue = max($min, min($max, $value));
    
    // Use actual value for display (not clamped)
    $actualValue = $value;
    
    if ($clampedValue > 0) {
        // Positive - bar extends to the right from center
        $normalized = abs($clampedValue) / abs($max); // Normalize to 0-1
        
        // Apply non-linear scaling
        $scaled = applyNonLinearScale($normalized, $scaleType, $exponent);
        
        $barWidth = $scaled * 50; // 50% max (half the container)
        // Ensure minimum width of 2% for visibility
        $barWidth = max(2, $barWidth);
        // Use 'importing' as default, but caller can override class if needed
        $barClass = 'importing';
        $barValue = abs($actualValue) . ' W';
        $valuePosition = 50 + $barWidth; // Position at end of bar (right side)
        $exceedsMax = $actualValue > $max;
    } elseif ($clampedValue < 0) {
        // Negative - bar extends to the left from center
        $normalized = abs($clampedValue) / abs($min); // Normalize to 0-1
        
        // Apply non-linear scaling
        $scaled = applyNonLinearScale($normalized, $scaleType, $exponent);
        
        $barWidth = $scaled * 50; // 50% max (half the container)
        // Ensure minimum width of 2% for visibility
        $barWidth = max(2, $barWidth);
        // Use 'exporting' as default, but caller can override class if needed
        $barClass = 'exporting';
        $barValue = abs($actualValue) . ' W';
        $valuePosition = 50 - $barWidth; // Position at end of bar (left side)
        $exceedsMax = $actualValue < $min;
    } else {
        // Zero - no bar
        $barWidth = 0;
        $barClass = '';
        $barValue = '';
        $valuePosition = 50;
        $exceedsMax = false;
    }
    
    return [
        'width' => $barWidth,
        'class' => $barClass,
        'value' => $barValue,
        'valuePosition' => $valuePosition,
        'exceedsMax' => $exceedsMax,
        'actualValue' => $actualValue
    ];
}

/**
 * Determine if value should be displayed inside the bar
 * @param float $percent The bar fill percentage
 * @param float $threshold Minimum percentage to show value (default 10)
 * @return bool True if value should be displayed
 */
function shouldDisplayBarValue($percent, $threshold = 10) {
    return $percent >= $threshold;
}
