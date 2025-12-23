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
        // Spec: Integers, "netzero"
        if ($value !== 'netzero' && !is_numeric($value))
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
        if ($v !== 'netzero' && !is_numeric($v))
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
