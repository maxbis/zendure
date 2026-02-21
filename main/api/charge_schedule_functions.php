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

/**
 * Given a 12-char schedule key (YYYYMMDDHHmm) with no wildcards,
 * return the key for the next calendar hour, or null if key is invalid.
 */
function getNextHourKey($key)
{
    if (strlen($key) !== 12 || strpos($key, '*') !== false) {
        return null;
    }
    $datePart = substr($key, 0, 8);
    $timePart = substr($key, 8, 4);
    $dateStr = substr($datePart, 0, 4) . '-' . substr($datePart, 4, 2) . '-' . substr($datePart, 6, 2);
    $timeStr = substr($timePart, 0, 2) . ':' . substr($timePart, 2, 2) . ':00';
    $ts = strtotime($dateStr . ' ' . $timeStr);
    if ($ts === false) {
        return null;
    }
    $ts += 3600;
    return date('Ymd', $ts) . date('H', $ts) . '00';
}

/**
 * Build the explicit H+1 entry for limit1hour behavior.
 *
 * New business rule ("true 1-hour override"):
 * - At H, set the user-selected value.
 * - At H+1, restore what H+1 resolved to before the change.
 * - If H+1 was empty before the change, restore to 0.
 * - Never overwrite an already explicit concrete H+1 key.
 *
 * @param array $schedule Current schedule map before setting key H
 * @param string $key Current edited concrete key (YYYYMMDDHHmm)
 * @return array|null ['key' => string, 'value' => mixed] or null when no restore entry should be added
 */
function getLimit1HourRestoreEntry(array $schedule, string $key): ?array
{
    if (strlen($key) !== 12 || strpos($key, '*') !== false) {
        return null;
    }

    $nextKey = getNextHourKey($key);
    if ($nextKey === null) {
        return null;
    }

    // Keep explicit next-hour entries untouched.
    if (isset($schedule[$nextKey])) {
        return null;
    }

    $nextDate = substr($nextKey, 0, 8);
    $nextTime = substr($nextKey, 8, 4);
    $resolved = resolveScheduleForDate($schedule, $nextDate);

    $restoreValue = 0; // Empty fallback
    foreach ($resolved as $slot) {
        if (!isset($slot['time']) || (string) $slot['time'] !== $nextTime) {
            continue;
        }
        if (array_key_exists('value', $slot) && $slot['value'] !== null) {
            $restoreValue = $slot['value'];
        }
        break;
    }

    return [
        'key' => $nextKey,
        'value' => $restoreValue
    ];
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
            'time' => (string) $slotTime,
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
 * Automatically remove outdated schedule entries for atomic saves.
 *
 * Rules:
 * - Only entries with a fully concrete date part (YYYYMMDD with no '*')
 *   are considered candidates for deletion.
 * - An entry is outdated if its concrete date is strictly before yesterday
 *   in server local time.
 * - Any entry whose date part contains at least one '*' (yearless or
 *   partially wildcarded dates) is treated as non-concrete and is never
 *   deleted by this helper.
 *
 * This is intentionally simpler and more conservative than clearOldEntries():
 * we prefer to keep ambiguous wildcard patterns rather than risk deleting
 * schedule definitions that might still be relevant.
 *
 * @param array $schedule
 * @return array Filtered schedule with outdated concrete-date entries removed
 */
function cleanOutdatedScheduleEntries(array $schedule): array
{
    // Yesterday's date in YYYYMMDD format, using server timezone
    $yesterdayDate = date('Ymd', strtotime('-1 day'));

    foreach ($schedule as $key => $value) {
        $keyStr = (string) $key;

        // Keys must follow the 12-char schedule format (YYYYMMDDHHmm)
        if (strlen($keyStr) !== 12) {
            continue;
        }

        $datePart = extractDateFromKey($keyStr);

        // Wildcard date parts (any '*') are treated as non-concrete and kept
        if (strpos($datePart, '*') !== false) {
            continue;
        }

        // Only act on fully concrete dates (8 digits)
        if (!preg_match('/^\d{8}$/', $datePart)) {
            continue;
        }

        // Delete only if the date is strictly before yesterday
        if ($datePart < $yesterdayDate) {
            unset($schedule[$key]);
        }
    }

    return $schedule;
}
