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
    $GLOBALS['WRITE_SCHEDULE_ATOMIC_LAST_ERROR'] = null;
    $tempFile = $dataFile . '.tmp';
    $json = json_encode($schedule, JSON_PRETTY_PRINT);
    if (file_put_contents($tempFile, $json) === false) {
        $GLOBALS['WRITE_SCHEDULE_ATOMIC_LAST_ERROR'] = "Failed to write temp file: $tempFile";
        return false;
    }
    if (!rename($tempFile, $dataFile)) {
        $GLOBALS['WRITE_SCHEDULE_ATOMIC_LAST_ERROR'] = "Failed to rename temp file to: $dataFile";
        @unlink($tempFile);
        return false;
    }
    return true;
}

function getLastWriteScheduleAtomicError()
{
    return isset($GLOBALS['WRITE_SCHEDULE_ATOMIC_LAST_ERROR'])
        ? $GLOBALS['WRITE_SCHEDULE_ATOMIC_LAST_ERROR']
        : null;
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
            'time' => (string) $slotTime,
            'value' => $selected ? $selected['value'] : null, // or 0? Spec doesn't strictly say default. Null is safer.
            'key' => $selected ? $selected['key'] : null
        ];
    }

    return $result;
}

/**
 * Read include_conditions flag from config/config.json.
 */
function cs_getIncludeConditionsConfigFlag()
{
    $configPath = __DIR__ . '/../config/config.json';
    if (!file_exists($configPath) || !is_readable($configPath)) {
        return false;
    }
    $raw = file_get_contents($configPath);
    if ($raw === false) {
        return false;
    }
    $cfg = json_decode($raw, true);
    if (!is_array($cfg) || !array_key_exists('include_conditions', $cfg)) {
        return false;
    }
    $parsed = filter_var($cfg['include_conditions'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed === null ? false : $parsed;
}

/**
 * Resolve condition rules for a specific date by invoking the standalone resolver.
 *
 * @return array|null list of resolved items for the date, or null when unavailable
 */
function cs_getConditionalResolvedForDate($dateYYYYMMDD)
{
    $resolverPath = __DIR__ . '/../data/resolve_schedule_conditions.php';
    if (!file_exists($resolverPath)) {
        return null;
    }

    $cmd = 'php ' . escapeshellarg($resolverPath) . ' 2>/dev/null';
    $raw = @shell_exec($cmd);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || empty($decoded['success']) || !isset($decoded['resolved']) || !is_array($decoded['resolved'])) {
        return null;
    }

    foreach ($decoded['resolved'] as $group) {
        if (!is_array($group) || !isset($group['date']) || !isset($group['items']) || !is_array($group['items'])) {
            continue;
        }
        if ((string) $group['date'] !== (string) $dateYYYYMMDD) {
            continue;
        }
        return $group['items'];
    }

    return null;
}

/**
 * Merge base schedule resolution with condition-rule output for a date.
 */
function cs_mergeResolvedWithConditional($resolved, $dateYYYYMMDD)
{
    if (!is_array($resolved)) {
        return $resolved;
    }

    $conditionalItems = cs_getConditionalResolvedForDate($dateYYYYMMDD);
    if (!is_array($conditionalItems) || empty($conditionalItems)) {
        return $resolved;
    }

    $byTime = [];
    foreach ($conditionalItems as $item) {
        if (!is_array($item) || !isset($item['time']) || !array_key_exists('value', $item)) {
            continue;
        }
        $time = str_pad((string) $item['time'], 4, '0', STR_PAD_LEFT);
        $byTime[$time] = [
            'value' => $item['value'],
            'runtime_conditions' => (isset($item['runtime_conditions']) && is_array($item['runtime_conditions']))
                ? array_values($item['runtime_conditions'])
                : null,
            'fallback_value' => array_key_exists('fallback_value', $item) ? $item['fallback_value'] : null,
            'rule_name' => (isset($item['rule_name']) && is_string($item['rule_name']) && trim($item['rule_name']) !== '')
                ? trim((string) $item['rule_name'])
                : null,
            'rule_index' => (array_key_exists('rule_index', $item) && is_numeric($item['rule_index']))
                ? ((int) $item['rule_index'])
                : null,
        ];
    }

    if (empty($byTime)) {
        return $resolved;
    }

    foreach ($resolved as &$slot) {
        if (!is_array($slot) || !isset($slot['time'])) {
            continue;
        }
        $slotTime = str_pad((string) $slot['time'], 4, '0', STR_PAD_LEFT);
        if (!array_key_exists($slotTime, $byTime)) {
            continue;
        }

        $slotKey = isset($slot['key']) ? (string) $slot['key'] : '';
        $isManualNonWildcard = $slotKey !== '' && strpos($slotKey, '*') === false;
        // A slot value of 0 (integer) is treated as "transparent" (auto) and may be
        // overridden by conditions, even when it originates from an exact-date key.
        // Any other explicit non-wildcard value blocks condition override.
        $slotValue = array_key_exists('value', $slot) ? $slot['value'] : null;
        $isZeroValue = is_numeric($slotValue) && (int) $slotValue === 0;
        // Preserve manual exact entries (non-zero); conditions can override wildcard/empty/zero slots.
        if ($isManualNonWildcard && !$isZeroValue) {
            continue;
        }

        $slotMeta = $byTime[$slotTime];
        $slot['value'] = $slotMeta['value'];
        $slot['source'] = 'condition';
        if (is_array($slotMeta['runtime_conditions']) && !empty($slotMeta['runtime_conditions'])) {
            $slot['runtime_conditions'] = $slotMeta['runtime_conditions'];
        } else {
            unset($slot['runtime_conditions']);
        }
        if ($slotMeta['fallback_value'] !== null) {
            $slot['fallback_value'] = $slotMeta['fallback_value'];
        } else {
            unset($slot['fallback_value']);
        }
        if ($slotMeta['rule_name'] !== null) {
            $slot['rule_name'] = $slotMeta['rule_name'];
        } else {
            unset($slot['rule_name']);
        }
        if ($slotMeta['rule_index'] !== null) {
            $slot['rule_index'] = $slotMeta['rule_index'];
        } else {
            unset($slot['rule_index']);
        }
    }
    unset($slot);

    return $resolved;
}

/**
 * Resolve schedule for a date and optionally merge conditional rule output.
 */
function resolveScheduleForDateWithConditions($schedule, $dateYYYYMMDD, $includeConditions = null)
{
    if ($includeConditions === null) {
        $includeConditions = cs_getIncludeConditionsConfigFlag();
    } else {
        $parsed = filter_var($includeConditions, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $includeConditions = ($parsed === null) ? false : $parsed;
    }

    $resolved = resolveScheduleForDate($schedule, $dateYYYYMMDD);
    if ($includeConditions) {
        $resolved = cs_mergeResolvedWithConditional($resolved, $dateYYYYMMDD);
    }
    return $resolved;
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
