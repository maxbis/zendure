<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Amsterdam');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($requestMethod !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use GET or OPTIONS.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

$latitude = isset($_GET['latitude']) ? (float) $_GET['latitude'] : 52.3;
$longitude = isset($_GET['longitude']) ? (float) $_GET['longitude'] : 4.863;
$timezone = isset($_GET['timezone']) && is_string($_GET['timezone']) && $_GET['timezone'] !== ''
    ? $_GET['timezone']
    : 'Europe/Amsterdam';
$cacheTtlSeconds = 2 * 60 * 60;
$cachePath = buildShortwaveCachePath($latitude, $longitude, $timezone);

$apiUrl = sprintf(
    'https://api.open-meteo.com/v1/forecast?latitude=%s&longitude=%s&hourly=shortwave_radiation&timezone=%s',
    rawurlencode((string) $latitude),
    rawurlencode((string) $longitude),
    rawurlencode($timezone)
);

try {
    $responsePayload = readShortwaveCache($cachePath);
    if ($responsePayload === null || isCacheExpired($responsePayload, $cacheTtlSeconds)) {
        $payload = fetchOpenMeteoJson($apiUrl);
        $hourlySeries = extractHourlyShortwaveSeries($payload);
        $responsePayload = [
            'success' => true,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'timezone' => isset($payload['timezone']) ? (string) $payload['timezone'] : $timezone,
            'unit' => dailyShortwaveUnit(),
            'days' => extractDailyShortwaveTotals($hourlySeries['time'], $hourlySeries['shortwave_radiation']),
            'hourly' => $hourlySeries,
            'hourly_units' => [
                'shortwave_radiation' => hourlyShortwaveUnit($payload),
            ],
            'cachedAt' => time(),
        ];
        writeShortwaveCache($cachePath, $responsePayload);
    }

    echo json_encode([
        'success' => true,
        'latitude' => $responsePayload['latitude'],
        'longitude' => $responsePayload['longitude'],
        'timezone' => $responsePayload['timezone'],
        'unit' => $responsePayload['unit'],
        'days' => $responsePayload['days'],
        'hourly' => $responsePayload['hourly'],
        'hourly_units' => $responsePayload['hourly_units'],
        'cachedAt' => $responsePayload['cachedAt'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(resolveStatusCode($e->getMessage()));
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function fetchOpenMeteoJson(string $url): array
{
    $body = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'zendure-shortwave-radiation-api/1.0',
        ]);

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $statusCode >= 400) {
            $message = $curlError !== '' ? $curlError : ('HTTP ' . $statusCode);
            throw new RuntimeException('Failed to fetch Open-Meteo data: ' . $message);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 20,
                'header' => "User-Agent: zendure-shortwave-radiation-api/1.0\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('Failed to fetch Open-Meteo data with file_get_contents().');
        }
    }

    try {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('Failed to decode API response: ' . $e->getMessage(), 0, $e);
    }

    if (!is_array($decoded)) {
        throw new RuntimeException('The API response is not a JSON object.');
    }

    return $decoded;
}

function extractHourlyShortwaveSeries(array $payload): array
{
    $hourly = $payload['hourly'] ?? null;
    $times = is_array($hourly['time'] ?? null) ? $hourly['time'] : [];
    $values = is_array($hourly['shortwave_radiation'] ?? null) ? $hourly['shortwave_radiation'] : [];

    if (count($times) === 0 || count($times) !== count($values)) {
        throw new RuntimeException('The API returned invalid hourly shortwave radiation data.');
    }

    return [
        'time' => array_map(static fn ($time): string => (string) $time, $times),
        'shortwave_radiation' => array_map(static fn ($value): float => (float) $value, $values),
    ];
}

function extractDailyShortwaveTotals(array $times, array $values): array
{
    if (count($times) === 0 || count($times) !== count($values)) {
        throw new RuntimeException('The API returned invalid hourly shortwave radiation data.');
    }

    $groups = [];
    foreach ($times as $index => $timestamp) {
        try {
            $dt = new DateTimeImmutable((string) $timestamp);
        } catch (Exception $e) {
            throw new RuntimeException('The API returned an invalid hourly timestamp.', 0, $e);
        }

        $dateKey = $dt->format('Y-m-d');
        if (!isset($groups[$dateKey])) {
            $groups[$dateKey] = 0.0;
        }
        $groups[$dateKey] += (float) $values[$index];
    }

    $days = [];
    foreach ($groups as $date => $total) {
        $days[] = [
            'date' => $date,
            'value' => (int) round($total),
        ];
    }

    return $days;
}

function buildShortwaveCachePath(float $latitude, float $longitude, string $timezone): string
{
    $cacheKey = md5(json_encode([
        'latitude' => $latitude,
        'longitude' => $longitude,
        'timezone' => $timezone,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    return __DIR__ . '/../data/shortwave_radiation_cache_' . $cacheKey . '.json';
}

function readShortwaveCache(string $path): ?array
{
    if (!file_exists($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (
        !is_array($decoded) ||
        !isset(
            $decoded['cachedAt'],
            $decoded['latitude'],
            $decoded['longitude'],
            $decoded['timezone'],
            $decoded['unit'],
            $decoded['days'],
            $decoded['hourly'],
            $decoded['hourly_units']
        ) ||
        !is_array($decoded['days']) ||
        !is_array($decoded['hourly']) ||
        !is_array($decoded['hourly_units']) ||
        !is_array($decoded['hourly']['time'] ?? null) ||
        !is_array($decoded['hourly']['shortwave_radiation'] ?? null) ||
        !isset($decoded['hourly_units']['shortwave_radiation'])
    ) {
        return null;
    }

    return $decoded;
}

function writeShortwaveCache(string $path, array $payload): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        return false;
    }

    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        return false;
    }

    return @file_put_contents($path, $encoded, LOCK_EX) !== false;
}

function isCacheExpired(array $payload, int $ttlSeconds): bool
{
    return !isset($payload['cachedAt']) || (time() - (int) $payload['cachedAt']) > $ttlSeconds;
}

function resolveStatusCode(string $message): int
{
    if (stripos($message, 'Failed to fetch Open-Meteo data') === 0) {
        return 502;
    }

    if (
        stripos($message, 'Failed to decode API response') === 0 ||
        stripos($message, 'The API returned invalid') === 0 ||
        stripos($message, 'The API returned an invalid hourly timestamp.') === 0
    ) {
        return 502;
    }

    return 500;
}

function dailyShortwaveUnit(): string
{
    return "Wh/m\u{00B2}";
}

function hourlyShortwaveUnit(array $payload): string
{
    $units = $payload['hourly_units'] ?? [];
    $raw = is_string($units['shortwave_radiation'] ?? null) ? $units['shortwave_radiation'] : '';
    $normalized = str_replace(["Â²", "Ã‚Â²"], "²", trim($raw));

    if ($normalized === '' || stripos($normalized, 'w/m') === false) {
        return "W/m\u{00B2}";
    }

    return $normalized;
}
