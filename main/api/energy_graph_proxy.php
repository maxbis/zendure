<?php
/**
 * Energy Graph Proxy
 * Same-origin proxy for wh_per_hour API to avoid CORS.
 * Fetches from URL in config, caches transformed response for N minutes (default 5).
 * On API failure, serves cache if any (even stale). No fallback to automation_status.json.
 */
// require_once __DIR__ . '/../../login/validate.php';
require_once dirname(__DIR__, 2) . '/common/php/system_config.php';
require_once __DIR__ . '/../includes/config_loader.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

const WH_PER_HOUR_DAYS_DEFAULT = 3;
const WH_PER_HOUR_DAYS_MAX = 30;

try {
    $energyGraphSystemConfig = loadSystemConfig();
    date_default_timezone_set($energyGraphSystemConfig['installation']['timezone']);
} catch (SystemConfigException $error) {
    http_response_code(500);
    echo json_encode(['error' => 'Shared system configuration: ' . $error->getMessage()]);
    exit();
}

// if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
//     http_response_code(200);
//     exit();
// }

$ttlSeconds = (int) ConfigLoader::get('whPerHourCacheMinutes', 5) * 60;
$baseWh = $energyGraphSystemConfig['battery']['capacityWh'];
$requestedDays = resolveRequestedDays($_GET['days'] ?? null);
$cachePath = __DIR__ . '/../data/energy_graph_cache_days_' . $requestedDays . '.json';
$energyGraphDaysBack = $requestedDays;
$energyTableDaysBack = max(7, $requestedDays);

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
$upstreamUrl = appendDaysQueryParam($upstreamUrl, $requestedDays);

function resolveRequestedDays($rawValue): int {
    if ($rawValue === null || $rawValue === '') {
        return WH_PER_HOUR_DAYS_DEFAULT;
    }
    if (!is_numeric($rawValue)) {
        return WH_PER_HOUR_DAYS_DEFAULT;
    }
    $days = (int) $rawValue;
    if ($days < 0) {
        return WH_PER_HOUR_DAYS_DEFAULT;
    }
    return min($days, WH_PER_HOUR_DAYS_MAX);
}

function appendDaysQueryParam(string $url, int $days): string {
    $separator = (strpos($url, '?') === false) ? '?' : '&';
    return $url . $separator . 'days=' . rawurlencode((string) $days);
}

/**
 * Transform external API response to front-end format.
 * External: { "YYYY-MM-DD": [ { "hour": "00".."23", "charged_wh", "discharged_wh", "electric_level" }, ... ], ... }
 * Return: [ 'whPerHour' => [...], 'whPerDay' => [...], 'baseWh' => configured capacity ]
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

/**
 * Emit a normalized API response payload.
 */
function emitPayload(array $payload, array $cacheInfo = []) {
    echo json_encode([
        'whPerHour' => $payload['whPerHour'],
        'whPerDay'  => $payload['whPerDay'],
        'baseWh'    => (int) $payload['baseWh'],
        'cacheInfo' => $cacheInfo
    ], JSON_UNESCAPED_SLASHES);
}

// 1) Try fresh cache
$cached = readCache($cachePath);
if ($cached !== null) {
    // Capacity is configuration, not cached upstream data.
    $cached['baseWh'] = $baseWh;
}
if ($cached !== null && (time() - (int) $cached['cachedAt']) <= $ttlSeconds) {
    emitPayload($cached, [
        'source' => 'cache',
        'cachedAt' => (int) $cached['cachedAt'],
        'days' => $requestedDays,
        'isStale' => false
    ]);
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
        emitPayload($cached, [
            'source' => 'cache',
            'cachedAt' => (int) $cached['cachedAt'],
            'days' => $requestedDays,
            'isStale' => true,
            'upstreamError' => 'fetch_failed'
        ]);
        exit();
    }
    http_response_code(502);
    echo json_encode(['error' => 'Failed to fetch wh_per_hour API']);
    exit();
}

$external = json_decode($jsonData, true);
if (!is_array($external)) {
    if ($cached !== null) {
        emitPayload($cached, [
            'source' => 'cache',
            'cachedAt' => (int) $cached['cachedAt'],
            'days' => $requestedDays,
            'isStale' => true,
            'upstreamError' => 'invalid_json'
        ]);
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

emitPayload($payload, [
    'source' => 'upstream',
    'cachedAt' => (int) $payload['cachedAt'],
    'days' => $requestedDays,
    'isStale' => false
]);
