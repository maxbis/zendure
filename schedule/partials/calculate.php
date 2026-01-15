<?php
/**
 * Schedule Calculator Partial
 * Calculates charge/discharge sums for today and tomorrow based on schedule API data.
 * 
 * This partial can be included in any page to display calculation results.
 * It uses the existing card styling from the parent page.
 */

// Ensure server timezone matches local expectation (if not already set)
if (!ini_get('date.timezone')) {
    date_default_timezone_set('Europe/Amsterdam');
}

// Constants for netzero evaluation in calculations
define('NETZERO_VALUE', -350);      // netzero evaluates to -350 watts (discharge)
define('NETZERO_PLUS_VALUE', 350);  // netzero+ evaluates to +350 watts (charge)

// Ensure ConfigLoader is available
if (!class_exists('ConfigLoader')) {
    require_once __DIR__ . '/../includes/config_loader.php';
}

// Load API URL from centralized config loader
$calculateApiUrl = ConfigLoader::get('scheduleApiUrl');
$zendureFetchApiUrl = ConfigLoader::getWithLocation('zendureFetchApiUrl');

// Fallback if config not found
if ($calculateApiUrl === null) {
    $calculateApiUrl = 'http://www.wijs.ovh/zendure/data/api/data_api.php?type=schedule&resolved=1';
}

// Helper function to fetch API data
function fetchScheduleDataForCalculate($apiUrl, $date) {
    $url = $apiUrl;
    // Add date parameter if not already present
    if (strpos($url, '&date=') === false && strpos($url, '?date=') === false) {
        $separator = strpos($url, '?') !== false ? '&' : '?';
        $url .= $separator . 'date=' . urlencode($date);
    } else {
        // Replace existing date parameter
        $url = preg_replace('/([?&])date=[^&]*/', '$1date=' . urlencode($date), $url);
    }
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'ignore_errors' => true
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return ['success' => false, 'error' => 'Failed to fetch data from API'];
    }
    
    $data = json_decode($response, true);
    if ($data === null || !isset($data['success']) || !$data['success']) {
        return ['success' => false, 'error' => $data['error'] ?? 'Invalid API response'];
    }
    
    return $data;
}

// Helper function to parse time string to minutes since midnight
function timeToMinutesForCalculate($timeStr) {
    // Handle both string "0000" and numeric 1000 formats
    if (is_numeric($timeStr)) {
        $timeStr = str_pad((string)$timeStr, 4, '0', STR_PAD_LEFT);
    } else {
        $timeStr = str_pad($timeStr, 4, '0', STR_PAD_LEFT);
    }
    
    $hours = (int)substr($timeStr, 0, 2);
    $minutes = (int)substr($timeStr, 2, 2);
    return $hours * 60 + $minutes;
}

// Helper function to resolve netzero values to numeric equivalents
function resolveNetzeroValueForCalculate($value) {
    if ($value === 'netzero') {
        return NETZERO_VALUE;
    } elseif ($value === 'netzero+') {
        return NETZERO_PLUS_VALUE;
    } elseif (is_numeric($value)) {
        return (float)$value;
    } else {
        return 0;
    }
}

// Helper function to calculate sums from resolved array
// Each value applies until the next time slot, so we multiply by duration
function calculateSumsForPartial($resolved) {
    $total = 0;
    $positive = 0;
    $negative = 0;
    
    if (empty($resolved)) {
        return [
            'total' => 0,
            'positive' => 0,
            'negative' => 0
        ];
    }
    
    // Sort entries by time to ensure correct order
    $sorted = $resolved;
    usort($sorted, function($a, $b) {
        $timeA = $a['time'] ?? '';
        $timeB = $b['time'] ?? '';
        $minutesA = timeToMinutesForCalculate($timeA);
        $minutesB = timeToMinutesForCalculate($timeB);
        return $minutesA - $minutesB;
    });
    
    // Calculate duration for each entry
    for ($i = 0; $i < count($sorted); $i++) {
        $entry = $sorted[$i];
        $value = $entry['value'] ?? 0;
        
        // Resolve netzero values to numeric equivalents
        $value = resolveNetzeroValueForCalculate($value);
        
        // Calculate duration until next entry (or end of day)
        $currentTime = $entry['time'] ?? '';
        $currentMinutes = timeToMinutesForCalculate($currentTime);
        
        if ($i < count($sorted) - 1) {
            // Duration until next entry
            $nextTime = $sorted[$i + 1]['time'] ?? '';
            $nextMinutes = timeToMinutesForCalculate($nextTime);
            $durationHours = ($nextMinutes - $currentMinutes) / 60.0;
        } else {
            // Last entry: duration until end of day (24:00)
            $durationHours = (24 * 60 - $currentMinutes) / 60.0;
        }
        
        // Multiply value by duration (in hours) to get total energy
        $energy = $value * $durationHours;
        
        $total += $energy;
        if ($value > 0) {
            $positive += $energy;
        } elseif ($value < 0) {
            $negative += $energy;
        }
    }
    
    return [
        'total' => $total,
        'positive' => $positive,
        'negative' => $negative
    ];
}

// Helper function to calculate sums from a specific start time
// This handles the case where the current time is in the middle of a time slot
function calculateSumsFromTimeForPartial($resolved, $startTime) {
    $total = 0;
    $positive = 0;
    $negative = 0;
    
    if (empty($resolved)) {
        return [
            'total' => 0,
            'positive' => 0,
            'negative' => 0
        ];
    }
    
    // Sort entries by time to ensure correct order
    $sorted = $resolved;
    usort($sorted, function($a, $b) {
        $timeA = $a['time'] ?? '';
        $timeB = $b['time'] ?? '';
        $minutesA = timeToMinutesForCalculate($timeA);
        $minutesB = timeToMinutesForCalculate($timeB);
        return $minutesA - $minutesB;
    });
    
    $startMinutes = timeToMinutesForCalculate($startTime);
    
    // Find the entry that is active at the start time (most recent entry with time <= startTime)
    $activeEntry = null;
    $activeIndex = -1;
    for ($i = 0; $i < count($sorted); $i++) {
        $entryTime = $sorted[$i]['time'] ?? '';
        $entryMinutes = timeToMinutesForCalculate($entryTime);
        if ($entryMinutes <= $startMinutes) {
            $activeEntry = $sorted[$i];
            $activeIndex = $i;
        } else {
            break;
        }
    }
    
    // If we found an active entry, calculate from start time to next entry
    if ($activeEntry !== null && $activeIndex < count($sorted) - 1) {
        $value = $activeEntry['value'] ?? 0;
        // Resolve netzero values to numeric equivalents
        $value = resolveNetzeroValueForCalculate($value);
        
        $nextTime = $sorted[$activeIndex + 1]['time'] ?? '';
        $nextMinutes = timeToMinutesForCalculate($nextTime);
        $durationHours = ($nextMinutes - $startMinutes) / 60.0;
        
        if ($durationHours > 0) {
            $energy = $value * $durationHours;
            $total += $energy;
            if ($value > 0) {
                $positive += $energy;
            } elseif ($value < 0) {
                $negative += $energy;
            }
        }
        
        // Continue from the next entry
        $startIndex = $activeIndex + 1;
    } else {
        // No active entry found, start from first entry after start time
        $startIndex = 0;
        for ($i = 0; $i < count($sorted); $i++) {
            $entryTime = $sorted[$i]['time'] ?? '';
            $entryMinutes = timeToMinutesForCalculate($entryTime);
            if ($entryMinutes > $startMinutes) {
                $startIndex = $i;
                break;
            }
        }
    }
    
    // Calculate duration for remaining entries
    for ($i = $startIndex; $i < count($sorted); $i++) {
        $entry = $sorted[$i];
        $value = $entry['value'] ?? 0;
        
        // Resolve netzero values to numeric equivalents
        $value = resolveNetzeroValueForCalculate($value);
        
        // Calculate duration until next entry (or end of day)
        $currentTime = $entry['time'] ?? '';
        $currentMinutes = timeToMinutesForCalculate($currentTime);
        
        if ($i < count($sorted) - 1) {
            // Duration until next entry
            $nextTime = $sorted[$i + 1]['time'] ?? '';
            $nextMinutes = timeToMinutesForCalculate($nextTime);
            $durationHours = ($nextMinutes - $currentMinutes) / 60.0;
        } else {
            // Last entry: duration until end of day (24:00)
            $durationHours = (24 * 60 - $currentMinutes) / 60.0;
        }
        
        // Multiply value by duration (in hours) to get total energy
        $energy = $value * $durationHours;
        
        $total += $energy;
        if ($value > 0) {
            $positive += $energy;
        } elseif ($value < 0) {
            $negative += $energy;
        }
    }
    
    return [
        'total' => $total,
        'positive' => $positive,
        'negative' => $negative
    ];
}

// Get dates
$calculateToday = date('Ymd');
$calculateTomorrow = date('Ymd', strtotime('+1 day'));
$calculateCurrentTime = date('Hi'); // HHmm format

// Fetch data for today and tomorrow
$calculateTodayData = fetchScheduleDataForCalculate($calculateApiUrl, $calculateToday);
$calculateTomorrowData = fetchScheduleDataForCalculate($calculateApiUrl, $calculateTomorrow);

// Calculate sums for today (full day)
$calculateTodaySums = ['total' => 0, 'positive' => 0, 'negative' => 0];
if ($calculateTodayData['success'] && isset($calculateTodayData['resolved'])) {
    $calculateTodaySums = calculateSumsForPartial($calculateTodayData['resolved']);
}

// Calculate sums for today (from current time onwards)
$calculateTodayFromNowSums = ['total' => 0, 'positive' => 0, 'negative' => 0];
if ($calculateTodayData['success'] && isset($calculateTodayData['resolved'])) {
    $calculateTodayFromNowSums = calculateSumsFromTimeForPartial($calculateTodayData['resolved'], $calculateCurrentTime);
}

// Calculate sums for tomorrow (full day)
$calculateTomorrowSums = ['total' => 0, 'positive' => 0, 'negative' => 0];
if ($calculateTomorrowData['success'] && isset($calculateTomorrowData['resolved'])) {
    $calculateTomorrowSums = calculateSumsForPartial($calculateTomorrowData['resolved']);
}

?>

<!-- Schedule Calculator Section -->
<div class="card" style="margin-top: 12px; padding-bottom: 12px;">
    <div class="metric-section">
        <h3>⚡ Schedule Calculator</h3>
        <div class="automation-last-update">
            <span id="calculator-current-time">Current Time: <?php echo date('Y-m-d H:i:s'); ?> (<?php echo $calculateCurrentTime; ?>)</span>
        </div>

        <?php if (!$calculateTodayData['success'] || !$calculateTomorrowData['success']): ?>
            <div class="automation-status-error">
                <strong>Error:</strong>
                <?php if (!$calculateTodayData['success']): ?>
                    <div>Today: <?php echo htmlspecialchars($calculateTodayData['error'] ?? 'Unknown error'); ?></div>
                <?php endif; ?>
                <?php if (!$calculateTomorrowData['success']): ?>
                    <div>Tomorrow: <?php echo htmlspecialchars($calculateTomorrowData['error'] ?? 'Unknown error'); ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="calculate-grid">
            <!-- Today - Full Day -->
            <div class="card">
                <h2 style="margin-bottom:0px">Today - Full Day</h2>
                <small>(<?php echo substr($calculateToday, 0, 4) . '-' . substr($calculateToday, 4, 2) . '-' . substr($calculateToday, 6, 2); ?>)</small>
                
                <table style="width: 100%; margin-top: 8px; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 2px 0; font-weight: 500; color: #555; text-align: left;">Total Sum:</td>
                        <td id="calc-today-full-total" style="padding: 2px 0; font-weight: 600; font-size: 1rem; text-align: right;" class="calculate-value <?php 
                            echo $calculateTodaySums['total'] > 0 ? 'charge' : ($calculateTodaySums['total'] < 0 ? 'discharge' : 'neutral'); 
                        ?>">
                            <?php echo number_format($calculateTodaySums['total'], 0); ?> Wh
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 2px 0; font-weight: 500; color: #555; text-align: left;">Charge (Positive):</td>
                        <td id="calc-today-full-positive" style="padding: 2px 0; font-weight: 600; font-size: 1rem; text-align: right;" class="charge">
                            <?php echo number_format($calculateTodaySums['positive'], 0); ?> Wh
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 2px 0; font-weight: 500; color: #555; text-align: left;">Discharge (Negative):</td>
                        <td id="calc-today-full-negative" style="padding: 2px 0; font-weight: 600; font-size: 1rem; text-align: right;" class="discharge">
                            <?php echo number_format($calculateTodaySums['negative'], 0); ?> Wh
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Today - From Current Time -->
            <div class="card">
                <h2 style="margin-bottom:0px">Today - From <?php echo substr($calculateCurrentTime, 0, 2) . ':' . substr($calculateCurrentTime, 2, 2); ?></h2>
                <small> (<?php echo substr($calculateToday, 0, 4) . '-' . substr($calculateToday, 4, 2) . '-' . substr($calculateToday, 6, 2); ?>)</small>
                
                <table style="width: 100%; margin-top: 8px; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 2px 0; font-weight: 500; color: #555; text-align: left;">Total Sum:</td>
                        <td id="calc-today-from-now-total" style="padding: 2px 0; font-weight: 600; font-size: 1rem; text-align: right;" class="calculate-value <?php 
                            echo $calculateTodayFromNowSums['total'] > 0 ? 'charge' : ($calculateTodayFromNowSums['total'] < 0 ? 'discharge' : 'neutral'); 
                        ?>">
                            <?php echo number_format($calculateTodayFromNowSums['total'], 0); ?> Wh
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 2px 0; font-weight: 500; color: #555; text-align: left;">Charge (Positive):</td>
                        <td id="calc-today-from-now-positive" style="padding: 2px 0; font-weight: 600; font-size: 1rem; text-align: right;" class="charge">
                            <?php echo number_format($calculateTodayFromNowSums['positive'], 0); ?> Wh
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 2px 0; font-weight: 500; color: #555; text-align: left;">Discharge (Negative):</td>
                        <td id="calc-today-from-now-negative" style="padding: 2px 0; font-weight: 600; font-size: 1rem; text-align: right;" class="discharge">
                            <?php echo number_format($calculateTodayFromNowSums['negative'], 0); ?> Wh
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Tomorrow - Full Day -->
            <div class="card">
                <h2 style="margin-bottom:0px">Tomorrow  - Full Day</h2>
                <small>(<?php echo substr($calculateTomorrow, 0, 4) . '-' . substr($calculateTomorrow, 4, 2) . '-' . substr($calculateTomorrow, 6, 2); ?>)</small>
                
                <table style="width: 100%; margin-top: 8px; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 2px 0; font-weight: 500; color: #555; text-align: left;">Total Sum:</td>
                        <td id="calc-tomorrow-full-total" style="padding: 2px 0; font-weight: 600; font-size: 1rem; text-align: right;" class="calculate-value <?php 
                            echo $calculateTomorrowSums['total'] > 0 ? 'charge' : ($calculateTomorrowSums['total'] < 0 ? 'discharge' : 'neutral'); 
                        ?>">
                            <?php echo number_format($calculateTomorrowSums['total'], 0); ?> Wh
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 2px 0; font-weight: 500; color: #555; text-align: left;">Charge (Positive):</td>
                        <td id="calc-tomorrow-full-positive" style="padding: 2px 0; font-weight: 600; font-size: 1rem; text-align: right;" class="charge">
                            <?php echo number_format($calculateTomorrowSums['positive'], 0); ?> Wh
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 2px 0; font-weight: 500; color: #555; text-align: left;">Discharge (Negative):</td>
                        <td id="calc-tomorrow-full-negative" style="padding: 2px 0; font-weight: 600; font-size: 1rem; text-align: right;" class="discharge">
                            <?php echo number_format($calculateTomorrowSums['negative'], 0); ?> Wh
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>
