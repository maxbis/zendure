<?php
/**
 * Energy Graph Proxy
 * Same-origin proxy for wh_per_hour API to avoid CORS.
 * Fetches from URL in config, caches transformed response for N minutes (default 5).
 * On API failure, serves cache if any (even stale). No fallback to automation_status.json.
 */
date_default_timezone_set('Europe/Amsterdam');

require_once __DIR__ . '/../../login/validate.php';
require_once __DIR__ . '/../includes/config_loader.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$cachePath = __DIR__ . '/../data/energy_graph_cache.json';
$ttlSeconds = (int) ConfigLoader::get('whPerHourCacheMinutes', 5) * 60;
$baseWh = (int) ConfigLoader::get('baseWh', 5760);
$energyGraphDaysBack = 3;
$energyTableDaysBack = 7;

// Resolve upstream URL from config
$rawUrl = ConfigLoader::get('wh-per-hourApi');
if (empty($rawUrl) || !is_string($rawUrl)) {
    http_response_code(502);
    echo json_encode(['error' => 'wh-per-hourApi not configured']);
    exit();
}

$baseUrl = ConfigLoader::get('apiBaseUrlPiControl');
if (empty($baseUrl) || !is_string($baseUrl)) {
    http_response_code(502);
    echo json_encode(['error' => 'apiBaseUrlPiControl not configured']);
    exit();
}

$upstreamUrl = str_replace('${apiBaseUrlPiControl}', $baseUrl, $rawUrl);

/**
 * Transform external API response to front-end format.
 * External: { "YYYY-MM-DD": [ { "hour": "00".."23", "charged_wh", "discharged_wh", "electric_level" }, ... ], ... }
 * Return: [ 'whPerHour' => [...], 'whPerDay' => [...], 'baseWh' => 5760 ]
 */
function transformWhPerHourResponse(array $external, $baseWh, $energyGraphDaysBack, $energyTableDaysBack) {
    $now = time();
    $graphAllowedDates = [];
    for ($i = 0; $i <= $energyGraphDaysBack; $i++) {
        $graphAllowedDates[] = date('Y-m-d', strtotime("-$i days", $now));
    }
    $tableAllowedDates = [];
    for ($i = 0; $i <= $energyTableDaysBack; $i++) {
        $tableAllowedDates[] = date('Y-m-d', strtotime("-$i days", $now));
    }

    $whPerHour = [];
    $whPerDayByDate = [];

    $dates = array_keys($external);
    sort($dates);

    foreach ($dates as $date) {
        if (!is_array($external[$date])) {
            continue;
        }
        $dayPos = 0.0;
        $dayNeg = 0.0;
        foreach ($external[$date] as $row) {
            $hour = isset($row['hour']) ? str_pad((string) $row['hour'], 2, '0', STR_PAD_LEFT) : '00';
            $charged = isset($row['charged_wh']) ? (float) $row['charged_wh'] : 0;
            $discharged = isset($row['discharged_wh']) ? (float) $row['discharged_wh'] : 0;
            $electricLevel = null;
            if (isset($row['electric_level']) && $row['electric_level'] !== null && $row['electric_level'] !== '') {
                $electricLevel = (float) $row['electric_level'];
                $electricLevel = max(0, min(100, $electricLevel));
            }
            $wh = $charged - $discharged;
            $dayPos += $charged;
            $dayNeg += $discharged;

            if (in_array($date, $graphAllowedDates, true)) {
                $hourLabel = $date . ' ' . $hour . ':00';
                $whPerHour[] = [
                    'hourLabel' => $hourLabel,
                    'wh' => round($wh, 2),
                    'electricLevel' => $electricLevel
                ];
            }
        }
        if (in_array($date, $tableAllowedDates, true)) {
            $whPerDayByDate[$date] = ['pos' => round($dayPos, 2), 'neg' => round(-$dayNeg, 2)];
        }
    }
    krsort($whPerDayByDate, SORT_STRING);

    return [
        'whPerHour' => $whPerHour,
        'whPerDay'  => $whPerDayByDate,
        'baseWh'    => $baseWh
    ];
}

/**
 * Read cache file; return decoded payload or null.
 */
function readCache($path) {
    if (!file_exists($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['cachedAt'], $data['whPerHour'], $data['whPerDay'], $data['baseWh'])) {
        return null;
    }
    return $data;
}

/**
 * Write cache file.
 */
function writeCache($path, $payload) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        return false;
    }
    $toWrite = json_encode([
        'cachedAt'  => $payload['cachedAt'],
        'whPerHour' => $payload['whPerHour'],
        'whPerDay'  => $payload['whPerDay'],
        'baseWh'    => $payload['baseWh']
    ], JSON_UNESCAPED_SLASHES);
    return @file_put_contents($path, $toWrite, LOCK_EX) !== false;
}

// 1) Try fresh cache
$cached = readCache($cachePath);
if ($cached !== null && (time() - (int) $cached['cachedAt']) <= $ttlSeconds) {
    echo json_encode([
        'whPerHour' => $cached['whPerHour'],
        'whPerDay'  => $cached['whPerDay'],
        'baseWh'    => (int) $cached['baseWh']
    ], JSON_UNESCAPED_SLASHES);
    exit();
}

// 2) Fetch upstream
$context = stream_context_create([
    'http' => [
        'timeout' => 10,
        'ignore_errors' => true,
        'method' => 'GET',
        'header' => 'User-Agent: Energy-Graph-Proxy'
    ]
]);
$jsonData = @file_get_contents($upstreamUrl, false, $context);

if ($jsonData === false || $jsonData === '') {
    if ($cached !== null) {
        echo json_encode([
            'whPerHour' => $cached['whPerHour'],
            'whPerDay'  => $cached['whPerDay'],
            'baseWh'    => (int) $cached['baseWh']
        ], JSON_UNESCAPED_SLASHES);
        exit();
    }
    http_response_code(502);
    echo json_encode(['error' => 'Failed to fetch wh_per_hour API']);
    exit();
}

$external = json_decode($jsonData, true);
if (!is_array($external)) {
    if ($cached !== null) {
        echo json_encode([
            'whPerHour' => $cached['whPerHour'],
            'whPerDay'  => $cached['whPerDay'],
            'baseWh'    => (int) $cached['baseWh']
        ], JSON_UNESCAPED_SLASHES);
        exit();
    }
    http_response_code(502);
    echo json_encode(['error' => 'wh_per_hour API returned invalid JSON']);
    exit();
}

// 3) Transform and cache
$payload = transformWhPerHourResponse($external, $baseWh, $energyGraphDaysBack, $energyTableDaysBack);
$payload['cachedAt'] = time();
writeCache($cachePath, $payload);

echo json_encode([
    'whPerHour' => $payload['whPerHour'],
    'whPerDay'  => $payload['whPerDay'],
    'baseWh'    => $payload['baseWh']
], JSON_UNESCAPED_SLASHES);
