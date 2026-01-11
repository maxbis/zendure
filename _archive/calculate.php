<?php
/**
 * Schedule Calculator
 * Calculates charge/discharge sums for today and tomorrow based on schedule API data.
 */

// Ensure server timezone matches local expectation
date_default_timezone_set('Europe/Amsterdam');

// Load API URL from config file
$apiUrl = null;
$configPath = __DIR__ . '/../config/config.json';
if (file_exists($configPath)) {
    $configJson = file_get_contents($configPath);
    if ($configJson !== false) {
        $config = json_decode($configJson, true);
        if ($config !== null && isset($config['apiUrl'])) {
            $apiUrl = $config['apiUrl'];
        }
    }
}

// Fallback if config not found
if ($apiUrl === null) {
    $apiUrl = 'http://www.wijs.ovh/zendure/data/api/data_api.php?type=schedule&resolved=1';
}

// Helper function to fetch API data
function fetchScheduleData($apiUrl, $date) {
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
function timeToMinutes($timeStr) {
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

// Helper function to calculate sums from resolved array
// Each value applies until the next time slot, so we multiply by duration
function calculateSums($resolved) {
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
        $minutesA = timeToMinutes($timeA);
        $minutesB = timeToMinutes($timeB);
        return $minutesA - $minutesB;
    });
    
    // Calculate duration for each entry
    for ($i = 0; $i < count($sorted); $i++) {
        $entry = $sorted[$i];
        $value = $entry['value'] ?? 0;
        
        // Handle non-numeric values (netzero, netzero+)
        if (!is_numeric($value)) {
            $value = 0;
        }
        
        // Calculate duration until next entry (or end of day)
        $currentTime = $entry['time'] ?? '';
        $currentMinutes = timeToMinutes($currentTime);
        
        if ($i < count($sorted) - 1) {
            // Duration until next entry
            $nextTime = $sorted[$i + 1]['time'] ?? '';
            $nextMinutes = timeToMinutes($nextTime);
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
$today = date('Ymd');
$tomorrow = date('Ymd', strtotime('+1 day'));
$currentTime = date('Hi'); // HHmm format

// Fetch data for today and tomorrow
$todayData = fetchScheduleData($apiUrl, $today);
$tomorrowData = fetchScheduleData($apiUrl, $tomorrow);

// Calculate sums for today (full day)
$todaySums = ['total' => 0, 'positive' => 0, 'negative' => 0];
if ($todayData['success'] && isset($todayData['resolved'])) {
    $todaySums = calculateSums($todayData['resolved']);
}

// Helper function to calculate sums from a specific start time
// This handles the case where the current time is in the middle of a time slot
function calculateSumsFromTime($resolved, $startTime) {
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
        $minutesA = timeToMinutes($timeA);
        $minutesB = timeToMinutes($timeB);
        return $minutesA - $minutesB;
    });
    
    $startMinutes = timeToMinutes($startTime);
    
    // Find the entry that is active at the start time (most recent entry with time <= startTime)
    $activeEntry = null;
    $activeIndex = -1;
    for ($i = 0; $i < count($sorted); $i++) {
        $entryTime = $sorted[$i]['time'] ?? '';
        $entryMinutes = timeToMinutes($entryTime);
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
        if (!is_numeric($value)) {
            $value = 0;
        }
        
        $nextTime = $sorted[$activeIndex + 1]['time'] ?? '';
        $nextMinutes = timeToMinutes($nextTime);
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
            $entryMinutes = timeToMinutes($entryTime);
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
        
        // Handle non-numeric values (netzero, netzero+)
        if (!is_numeric($value)) {
            $value = 0;
        }
        
        // Calculate duration until next entry (or end of day)
        $currentTime = $entry['time'] ?? '';
        $currentMinutes = timeToMinutes($currentTime);
        
        if ($i < count($sorted) - 1) {
            // Duration until next entry
            $nextTime = $sorted[$i + 1]['time'] ?? '';
            $nextMinutes = timeToMinutes($nextTime);
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

// Calculate sums for today (from current time onwards)
$todayFromNowSums = ['total' => 0, 'positive' => 0, 'negative' => 0];
if ($todayData['success'] && isset($todayData['resolved'])) {
    $todayFromNowSums = calculateSumsFromTime($todayData['resolved'], $currentTime);
}

// Calculate sums for tomorrow (full day)
$tomorrowSums = ['total' => 0, 'positive' => 0, 'negative' => 0];
if ($tomorrowData['success'] && isset($tomorrowData['resolved'])) {
    $tomorrowSums = calculateSums($tomorrowData['resolved']);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Calculator</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5;
            padding: 20px;
            color: #333;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header h1 {
            color: #2c3e50;
            margin-bottom: 10px;
        }
        .header .timestamp {
            color: #7f8c8d;
            font-size: 14px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .card h2 {
            color: #2c3e50;
            margin-bottom: 15px;
            font-size: 18px;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }
        .card h3 {
            color: #34495e;
            margin-top: 15px;
            margin-bottom: 10px;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .metric {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #ecf0f1;
        }
        .metric:last-child {
            border-bottom: none;
        }
        .metric-label {
            font-weight: 500;
            color: #555;
        }
        .metric-value {
            font-weight: 600;
            font-size: 18px;
        }
        .metric-value.positive {
            color: #27ae60;
        }
        .metric-value.negative {
            color: #e74c3c;
        }
        .metric-value.neutral {
            color: #7f8c8d;
        }
        .error {
            background: #fee;
            color: #c33;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #e74c3c;
        }
        .info {
            background: #e8f4f8;
            color: #2980b9;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #3498db;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⚡ Schedule Calculator</h1>
            <div class="timestamp">
                Current Time: <?php echo date('Y-m-d H:i:s'); ?> (<?php echo $currentTime; ?>)
            </div>
        </div>

        <?php if (!$todayData['success'] || !$tomorrowData['success']): ?>
            <div class="error">
                <strong>Error:</strong>
                <?php if (!$todayData['success']): ?>
                    <div>Today: <?php echo htmlspecialchars($todayData['error'] ?? 'Unknown error'); ?></div>
                <?php endif; ?>
                <?php if (!$tomorrowData['success']): ?>
                    <div>Tomorrow: <?php echo htmlspecialchars($tomorrowData['error'] ?? 'Unknown error'); ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="grid">
            <!-- Today - Full Day -->
            <div class="card">
                <h2>Today (<?php echo substr($today, 0, 4) . '-' . substr($today, 4, 2) . '-' . substr($today, 6, 2); ?>) - Full Day</h2>
                
                <div class="metric">
                    <span class="metric-label">Total Sum:</span>
                    <span class="metric-value <?php 
                        echo $todaySums['total'] > 0 ? 'positive' : ($todaySums['total'] < 0 ? 'negative' : 'neutral'); 
                    ?>">
                        <?php echo number_format($todaySums['total'], 0); ?> Wh
                    </span>
                </div>
                
                <div class="metric">
                    <span class="metric-label">Charge (Positive):</span>
                    <span class="metric-value positive">
                        <?php echo number_format($todaySums['positive'], 0); ?> Wh
                    </span>
                </div>
                
                <div class="metric">
                    <span class="metric-label">Discharge (Negative):</span>
                    <span class="metric-value negative">
                        <?php echo number_format($todaySums['negative'], 0); ?> Wh
                    </span>
                </div>
            </div>

            <!-- Today - From Current Time -->
            <div class="card">
                <h2>Today (<?php echo substr($today, 0, 4) . '-' . substr($today, 4, 2) . '-' . substr($today, 6, 2); ?>) - From <?php echo substr($currentTime, 0, 2) . ':' . substr($currentTime, 2, 2); ?></h2>
                
                <div class="metric">
                    <span class="metric-label">Total Sum:</span>
                    <span class="metric-value <?php 
                        echo $todayFromNowSums['total'] > 0 ? 'positive' : ($todayFromNowSums['total'] < 0 ? 'negative' : 'neutral'); 
                    ?>">
                        <?php echo number_format($todayFromNowSums['total'], 0); ?> Wh
                    </span>
                </div>
                
                <div class="metric">
                    <span class="metric-label">Charge (Positive):</span>
                    <span class="metric-value positive">
                        <?php echo number_format($todayFromNowSums['positive'], 0); ?> Wh
                    </span>
                </div>
                
                <div class="metric">
                    <span class="metric-label">Discharge (Negative):</span>
                    <span class="metric-value negative">
                        <?php echo number_format($todayFromNowSums['negative'], 0); ?> Wh
                    </span>
                </div>
            </div>

            <!-- Tomorrow - Full Day -->
            <div class="card">
                <h2>Tomorrow (<?php echo substr($tomorrow, 0, 4) . '-' . substr($tomorrow, 4, 2) . '-' . substr($tomorrow, 6, 2); ?>) - Full Day</h2>
                
                <div class="metric">
                    <span class="metric-label">Total Sum:</span>
                    <span class="metric-value <?php 
                        echo $tomorrowSums['total'] > 0 ? 'positive' : ($tomorrowSums['total'] < 0 ? 'negative' : 'neutral'); 
                    ?>">
                        <?php echo number_format($tomorrowSums['total'], 0); ?> Wh
                    </span>
                </div>
                
                <div class="metric">
                    <span class="metric-label">Charge (Positive):</span>
                    <span class="metric-value positive">
                        <?php echo number_format($tomorrowSums['positive'], 0); ?> Wh
                    </span>
                </div>
                
                <div class="metric">
                    <span class="metric-label">Discharge (Negative):</span>
                    <span class="metric-value negative">
                        <?php echo number_format($tomorrowSums['negative'], 0); ?> Wh
                    </span>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
