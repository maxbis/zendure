<?php
// schedule/api/calculate_schedule_api.php

// Ensure server timezone matches local expectation
date_default_timezone_set('Europe/Amsterdam');

require_once __DIR__ . '/charge_schedule_functions.php';

header('Content-Type: application/json');

// Constants
define('MIN_PRICE_DELTA', 0.05); // Minimum price difference in EUR/kWh required to trade
define('CHARGE_POWER', 500); // Charge power in watts
define('DISCHARGE_POWER', 500); // Discharge power in watts

// Configuration
$dataFile = __DIR__ . '/../../data/charge_schedule.json';
$configPath = __DIR__ . '/../../config/config.json';
$fallbackConfigPath = __DIR__ . '/../../run_schedule/config/config.json';

$response = ['success' => false];

try {
    // Load configuration
    $configPathToUse = file_exists($configPath) ? $configPath : (file_exists($fallbackConfigPath) ? $fallbackConfigPath : null);
    
    if (!$configPathToUse || !file_exists($configPathToUse)) {
        throw new Exception('Configuration file not found');
    }
    
    $configJson = file_get_contents($configPathToUse);
    if ($configJson === false) {
        throw new Exception('Failed to read configuration file');
    }
    
    $config = json_decode($configJson, true);
    if ($config === null) {
        throw new Exception('Failed to parse configuration file');
    }
    
    // Get price API URL
    $priceApiUrl = null;
    if (isset($config['priceUrls']['get_prices'])) {
        $priceApiUrl = $config['priceUrls']['get_prices'];
    } elseif (isset($config['priceUrls']['get_price'])) {
        $priceApiUrl = $config['priceUrls']['get_price'];
    }
    
    if (!$priceApiUrl) {
        throw new Exception('Price API URL not configured');
    }
    
    // Fetch price data
    $priceResponse = @file_get_contents($priceApiUrl);
    if ($priceResponse === false) {
        throw new Exception('Failed to fetch price data from API');
    }
    
    $priceData = json_decode($priceResponse, true);
    if ($priceData === null || !isset($priceData['today']) || !isset($priceData['tomorrow'])) {
        throw new Exception('Invalid price data format from API');
    }
    
    $todayPrices = $priceData['today'] ?? [];
    $tomorrowPrices = $priceData['tomorrow'] ?? [];
    $todayDate = $priceData['dates']['today'] ?? date('Ymd');
    $tomorrowDate = $priceData['dates']['tomorrow'] ?? null;
    
    // Load current schedule
    $schedule = loadSchedule($dataFile);
    
    // Get current time
    $now = new DateTime();
    $currentHour = (int)$now->format('H');
    $currentMinute = (int)$now->format('i');
    
    // Start from next full hour
    $startHour = $currentHour;
    if ($currentMinute > 0) {
        $startHour = $currentHour + 1;
    }
    
    // Build list of all future hours with prices
    $futureHours = [];
    
    // Add today's future hours
    for ($h = $startHour; $h < 24; $h++) {
        $hourKey = sprintf('%02d', $h);
        if (isset($todayPrices[$hourKey]) && $todayPrices[$hourKey] !== null) {
            $futureHours[] = [
                'date' => $todayDate,
                'hour' => $h,
                'hourKey' => $hourKey,
                'price' => (float)$todayPrices[$hourKey],
                'datetime' => $todayDate . $hourKey . '00'
            ];
        }
    }
    
    // Add tomorrow's hours if available
    if ($tomorrowDate && is_array($tomorrowPrices)) {
        for ($h = 0; $h < 24; $h++) {
            $hourKey = sprintf('%02d', $h);
            if (isset($tomorrowPrices[$hourKey]) && $tomorrowPrices[$hourKey] !== null) {
                $futureHours[] = [
                    'date' => $tomorrowDate,
                    'hour' => $h,
                    'hourKey' => $hourKey,
                    'price' => (float)$tomorrowPrices[$hourKey],
                    'datetime' => $tomorrowDate . $hourKey . '00'
                ];
            }
        }
    }
    
    if (empty($futureHours)) {
        throw new Exception('No price data available for future hours');
    }
    
    // Helper function to check if an hour is empty in schedule
    $isHourEmpty = function($date, $hourKey) use ($schedule) {
        $resolved = resolveScheduleForDate($schedule, $date);
        foreach ($resolved as $slot) {
            if ($slot['time'] === $hourKey . '00') {
                $value = $slot['value'];
                // Empty means null, 0, or not set
                return ($value === null || $value === 0 || $value === '0');
            }
        }
        return true; // Not found in resolved schedule means empty
    };
    
    // Iterative matching algorithm
    $entriesAdded = 0;
    $usedHours = []; // Track hours that have been used
    
    while (true) {
        // Collect empty hours that haven't been used
        $emptyHours = [];
        foreach ($futureHours as $hour) {
            $hourKey = $hour['hourKey'];
            $datetime = $hour['datetime'];
            
            // Skip if already used
            if (in_array($datetime, $usedHours)) {
                continue;
            }
            
            // Check if hour is empty in schedule
            if ($isHourEmpty($hour['date'], $hourKey)) {
                $emptyHours[] = $hour;
            }
        }
        
        if (count($emptyHours) < 2) {
            // Need at least 2 hours to create a pair
            break;
        }
        
        // Find minimum and maximum price hours
        $minHour = null;
        $maxHour = null;
        $minPrice = PHP_FLOAT_MAX;
        $maxPrice = PHP_FLOAT_MIN;
        
        foreach ($emptyHours as $hour) {
            $price = $hour['price'];
            if ($price < $minPrice) {
                $minPrice = $price;
                $minHour = $hour;
            }
            if ($price > $maxPrice) {
                $maxPrice = $price;
                $maxHour = $hour;
            }
        }
        
        // Check if we have valid min and max hours
        if ($minHour === null || $maxHour === null || $minHour['datetime'] === $maxHour['datetime']) {
            break;
        }
        
        // Calculate delta
        $delta = $maxPrice - $minPrice;
        
        if ($delta < MIN_PRICE_DELTA) {
            // Delta too small, stop searching
            break;
        }
        
        // Add schedule entries
        $chargeKey = $minHour['datetime'];
        $dischargeKey = $maxHour['datetime'];
        
        // Add charge entry (positive value)
        $schedule[$chargeKey] = CHARGE_POWER;
        
        // Add discharge entry (negative value)
        $schedule[$dischargeKey] = -DISCHARGE_POWER;
        
        // Mark hours as used
        $usedHours[] = $chargeKey;
        $usedHours[] = $dischargeKey;
        
        $entriesAdded += 2;
    }
    
    // Save schedule if entries were added
    if ($entriesAdded > 0) {
        if (!writeScheduleAtomic($dataFile, $schedule)) {
            throw new Exception('Failed to save schedule');
        }
    }
    
    $response = [
        'success' => true,
        'entries_added' => $entriesAdded
    ];
    
} catch (Exception $e) {
    $response = [
        'success' => false,
        'error' => $e->getMessage()
    ];
}

echo json_encode($response);
