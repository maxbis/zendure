<?php
/**
 * Energy Graph Data
 * Shared data logic for energy graph partials.
 * Loads automation_status.json and computes Wh per hour, Wh per day.
 */
date_default_timezone_set('Europe/Amsterdam');
require_once __DIR__ . '/../../login/validate.php';

$dataFile = __DIR__ . '/../../data/automation_status.json';
$retentionDays = 4;
$retentionSeconds = $retentionDays * 24 * 60 * 60;
$baseWh = 5760; // 5.76 kWh – base for daily percentage
$baseKwh = $baseWh / 1000;

// Load automation status data
$entries = [];
if (file_exists($dataFile)) {
    $json = file_get_contents($dataFile);
    $data = json_decode($json, true);
    if (is_array($data) && isset($data['entries'])) {
        $entries = $data['entries'];
    }
}

/**
 * Compute watt-hours per calendar hour from automation entries.
 * Uses only type=change entries with numeric newValue (watts).
 * Step integration: power constant between consecutive readings; bucket by hour.
 *
 * @param array $entries Raw entries from automation_status.json
 * @param int $now Current Unix timestamp (for extending last segment)
 * @return array List of ['hourLabel' => 'Y-m-d H:00', 'wh' => float]
 */
function computeWhPerHour(array $entries, $now)
{
    $points = [];
    foreach ($entries as $e) {
        if (!isset($e['type']) || $e['type'] !== 'change') {
            continue;
        }
        $nv = isset($e['newValue']) ? $e['newValue'] : null;
        if ($nv === null || !is_numeric($nv)) {
            continue;
        }
        $ts = isset($e['timestamp']) ? (int)$e['timestamp'] : 0;
        $points[] = ['t' => $ts, 'watts' => (float)$nv];
    }
    if (empty($points)) {
        return [];
    }
    usort($points, function ($a, $b) {
        return $a['t'] - $b['t'];
    });

    $whByHour = [];
    $n = count($points);
    for ($i = 0; $i < $n; $i++) {
        $tStart = $points[$i]['t'];
        $tEnd = ($i < $n - 1) ? $points[$i + 1]['t'] : $now;
        $power = $points[$i]['watts'];
        $cur = $tStart;
        while ($cur < $tEnd) {
            $hourStart = strtotime(date('Y-m-d H:00:00', $cur));
            $hourEnd = $hourStart + 3600;
            $clipStart = max($tStart, $hourStart);
            $clipEnd = min($tEnd, $hourEnd);
            if ($clipStart < $clipEnd) {
                $hourLabel = date('Y-m-d H:00', $hourStart);
                if (!isset($whByHour[$hourLabel])) {
                    $whByHour[$hourLabel] = 0;
                }
                $whByHour[$hourLabel] += $power * ($clipEnd - $clipStart) / 3600;
            }
            $cur = $hourEnd;
        }
    }

    ksort($whByHour);
    $result = [];
    foreach ($whByHour as $hourLabel => $wh) {
        $result[] = ['hourLabel' => $hourLabel, 'wh' => round($wh, 2)];
    }
    return $result;
}

$now = time();
$whPerHour = computeWhPerHour($entries, $now);

// Aggregate Wh per day (date = first 10 chars of hourLabel)
$whPerDay = [];
foreach ($whPerHour as $row) {
    $date = substr($row['hourLabel'], 0, 10);
    if (!isset($whPerDay[$date])) {
        $whPerDay[$date] = ['pos' => 0, 'neg' => 0];
    }
    $wh = $row['wh'];
    if ($wh >= 0) {
        $whPerDay[$date]['pos'] += $wh;
    } else {
        $whPerDay[$date]['neg'] += $wh;
    }
}
