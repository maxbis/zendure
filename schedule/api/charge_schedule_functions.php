<?php
// schedule/api/charge_schedule_functions.php

// Helper Functions

function loadSchedule($dataFile)
{
    if (!file_exists($dataFile)) {
        return [];
    }
    $json = file_get_contents($dataFile);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }
    // Normalize keys to strings
    $normalized = [];
    foreach ($data as $k => $v) {
        $normalized[(string) $k] = $v;
    }
    return $normalized;
}

function writeScheduleAtomic($dataFile, $schedule)
{
    $tempFile = $dataFile . '.tmp';
    $json = json_encode($schedule, JSON_PRETTY_PRINT);
    if (file_put_contents($tempFile, $json) === false) {
        return false;
    }
    return rename($tempFile, $dataFile);
}

function calculateSpecificity($key)
{
    $score = 0;
    for ($i = 0; $i < strlen($key); $i++) {
        if ($key[$i] !== '*')
            $score++;
    }
    return $score;
}

function extractTimeFromKey($key)
{
    if (strlen($key) < 12)
        return null;
    $timePart = substr($key, 8, 4);
    if (strpos($timePart, '*') !== false)
        return null;
    return $timePart;
}

function matchesAndBeforeTime($entryKey, $datetime, $slotTime)
{
    $datePart = substr($datetime, 0, 8);
    $entryDatePart = substr($entryKey, 0, 8);

    // Check Date Match
    for ($i = 0; $i < 8; $i++) {
        if ($entryDatePart[$i] !== '*' && $entryDatePart[$i] !== $datePart[$i]) {
            return false;
        }
    }

    $entryTime = extractTimeFromKey($entryKey);
    // Wildcard time matches as fallback (conceptually "always available")
    if ($entryTime === null) {
        return true;
    }

    return $entryTime <= $slotTime;
}

function resolveScheduleForDate($schedule, $dateYYYYMMDD)
{
    $result = [];

    // 1. Collect all unique times
    $allTimes = [];
    // Hourly slots
    for ($h = 0; $h < 24; $h++) {
        $allTimes[sprintf("%02d00", $h)] = true;
    }
    // Schedule times
    foreach ($schedule as $key => $value) {
        // Validation of value happens loosely here, or we filter?
        // Spec: Integers, "netzero", "netzero+"
        if ($value !== 'netzero' && $value !== 'netzero+' && !is_numeric($value))
            continue;

        $t = extractTimeFromKey((string) $key);
        if ($t !== null) {
            $allTimes[$t] = true;
        }
    }
    ksort($allTimes);

    // Prepare entries list for easier processing
    $entries = [];
    foreach ($schedule as $k => $v) {
        if ($v !== 'netzero' && $v !== 'netzero+' && !is_numeric($v))
            continue;
        $entries[] = [
            'key' => (string) $k,
            'value' => $v,
            'time' => extractTimeFromKey((string) $k)
        ];
    }

    // 2. Resolve per slot
    foreach (array_keys($allTimes) as $slotTime) {
        $datetime = $dateYYYYMMDD . $slotTime;

        $candidates = [];
        foreach ($entries as $entry) {
            if (matchesAndBeforeTime($entry['key'], $datetime, $slotTime)) {
                $candidates[] = $entry;
            }
        }

        $selected = null;
        if (!empty($candidates)) {
            // Sort
            usort($candidates, function ($a, $b) {
                // 1. Wildcards in TIME last (null vs specific)
                if ($a['time'] === null && $b['time'] !== null)
                    return 1;
                if ($a['time'] !== null && $b['time'] === null)
                    return -1;

                // 2. Most recent TIME first (Descending)
                if ($a['time'] !== null && $b['time'] !== null) {
                    $cmp = strcmp($b['time'], $a['time']);
                    if ($cmp !== 0)
                        return $cmp;
                }

                // 3. Higher SPECIFICITY first (Descending)
                $specA = calculateSpecificity($a['key']);
                $specB = calculateSpecificity($b['key']);
                if ($specA !== $specB) {
                    return $specB - $specA;
                }

                // 4. Tie-breaker (Lexicographical key desc)
                return strcmp($b['key'], $a['key']);
            });
            $selected = $candidates[0];
        }

        $result[] = [
            'time' => $slotTime,
            'value' => $selected ? $selected['value'] : null, // or 0? Spec doesn't strictly say default. Null is safer.
            'key' => $selected ? $selected['key'] : null
        ];
    }

    return $result;
}

/**
 * Extract date part from key (first 8 characters)
 * @param string $key - The schedule key (12 characters: YYYYMMDDHHmm)
 * @return string - Date part (YYYYMMDD)
 */
function extractDateFromKey($key)
{
    if (strlen($key) < 8) {
        return '';
    }
    return substr($key, 0, 8);
}

/**
 * Check if a wildcard pattern can match any date >= yesterday
 * @param string $datePattern - Date pattern with wildcards (e.g., "2024****")
 * @param string $yesterdayDate - Yesterday's date in YYYYMMDD format
 * @return bool - True if pattern should be kept (can match yesterday or later), false if should be deleted
 */
function shouldKeepWildcard($datePattern, $yesterdayDate)
{
    // If pattern has no wildcards, treat as exact match
    if (strpos($datePattern, '*') === false) {
        return $datePattern >= $yesterdayDate;
    }

    $pattern = str_split($datePattern);
    $yesterdayChars = str_split($yesterdayDate);
    
    // Strategy 1: Build the latest possible date that matches the pattern (wildcards as 9)
    $latestDate = '';
    for ($i = 0; $i < 8; $i++) {
        $latestDate .= ($pattern[$i] === '*') ? '9' : $pattern[$i];
    }
    
    // If the latest possible date is < yesterday, all matches are older -> delete
    if ($latestDate < $yesterdayDate) {
        return false;
    }
    
    // Strategy 2: Try to construct a date >= yesterday by replacing wildcards with yesterday's digits
    $testDate = '';
    for ($i = 0; $i < 8; $i++) {
        $testDate .= ($pattern[$i] === '*') ? $yesterdayChars[$i] : $pattern[$i];
    }
    
    // If this date matches the pattern and is >= yesterday, keep it
    if ($testDate >= $yesterdayDate) {
        // Verify the test date matches the pattern
        $matches = true;
        for ($i = 0; $i < 8; $i++) {
            if ($pattern[$i] !== '*' && $pattern[$i] !== $testDate[$i]) {
                $matches = false;
                break;
            }
        }
        if ($matches) {
            return true;
        }
    }
    
    // Strategy 3: Try to construct the earliest date >= yesterday that matches the pattern
    // This is more complex, but for safety, if we can't easily determine, keep it
    // (Better to keep a few extra entries than delete something that might be needed)
    return true;
}

/**
 * Clear old schedule entries (older than yesterday)
 * @param array $schedule - The schedule array
 * @param bool $simulate - If true, only count entries without deleting
 * @return array - Array with 'count' and optionally 'entries' (keys to delete)
 */
function clearOldEntries($schedule, $simulate = true)
{
    // Calculate yesterday's date (YYYYMMDD format)
    $yesterdayDate = date('Ymd', strtotime('-1 day'));
    
    $keysToDelete = [];
    
    foreach ($schedule as $key => $value) {
        $keyStr = (string) $key;
        
        // Skip invalid keys (must be 12 characters)
        if (strlen($keyStr) !== 12) {
            continue;
        }
        
        // Extract date part (first 8 characters)
        $datePart = extractDateFromKey($keyStr);
        
        // Check if date part has wildcards
        $hasWildcard = (strpos($datePart, '*') !== false);
        
        if ($hasWildcard) {
            // For wildcard patterns, check if ALL matching dates are older than yesterday
            // If the pattern can match any date >= yesterday, keep it
            if (!shouldKeepWildcard($datePart, $yesterdayDate)) {
                $keysToDelete[] = $keyStr;
            }
        } else {
            // For exact dates, check if date < =yesterday
            if ($datePart <= $yesterdayDate) {
                $keysToDelete[] = $keyStr;
            }
        }
    }
    
    // Always return entries so they can be deleted if not simulating
    return [
        'count' => count($keysToDelete),
        'entries' => $keysToDelete
    ];
}
