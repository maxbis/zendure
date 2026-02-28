<?php

declare(strict_types=1);

/**
 * Cache-first price entrypoint.
 *
 * Query parameter:
 * - v: upstream implementation version (allowed: 5, 6, 7; default: 7)
 *
 * Behavior:
 * - If cache can satisfy the response, return from cache.
 * - If cache miss, include get_prices_v<n>.php and let it handle fetch + response.
 */

const ROUTER_TIMEZONE_NL = 'Europe/Amsterdam';
const ROUTER_TOMORROW_FETCH_HOUR = 14;
const ROUTER_PRICE_DIR = __DIR__ . '/../data/price';

function gpRouterIsRunningInCLI(): bool {
    return php_sapi_name() === 'cli' || php_sapi_name() === 'phpdbg';
}

function gpRouterSendJsonResponse(array $data, int $statusCode = 200): void {
    if (!gpRouterIsRunningInCLI()) {
        http_response_code($statusCode);
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

function gpRouterGetPriceFilePath(string $dateStr): ?string {
    if (strlen($dateStr) !== 8) {
        return null;
    }
    $yearMonth = substr($dateStr, 0, 6);
    return ROUTER_PRICE_DIR . '/' . $yearMonth . '/price' . $dateStr . '.json';
}

function gpRouterPriceFileExists(string $dateStr): bool {
    $path = gpRouterGetPriceFilePath($dateStr);
    return $path !== null && file_exists($path);
}

/**
 * @return array<string, float>|null
 */
function gpRouterLoadPriceFile(string $dateStr): ?array {
    $path = gpRouterGetPriceFilePath($dateStr);
    if ($path === null || !file_exists($path)) {
        return null;
    }
    $json = file_get_contents($path);
    if ($json === false) {
        return null;
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : null;
}

function gpRouterGetRequestedVersion(): int {
    $raw = $_GET['v'] ?? '5';
    if (!is_string($raw) || !preg_match('/^\d+$/', $raw)) {
        return 0;
    }

    $version = (int)$raw;
    return in_array($version, [5, 6, 7], true) ? $version : 0;
}

/**
 * @param int $version
 * @return array<string, mixed>
 */
function gpRouterBuildCacheResponse(int $version): array {
    $tzNl = new DateTimeZone(ROUTER_TIMEZONE_NL);
    $nowNl = new DateTimeImmutable('now', $tzNl);
    $todayStr = $nowNl->format('Ymd');
    $tomorrowStr = $nowNl->modify('+1 day')->format('Ymd');
    $hourNl = (int)$nowNl->format('H');

    $todayData = gpRouterLoadPriceFile($todayStr);
    if ($todayData === null) {
        return ['cache_ready' => false];
    }

    $tomorrowData = gpRouterLoadPriceFile($tomorrowStr);
    if ($tomorrowData === null && $hourNl >= ROUTER_TOMORROW_FETCH_HOUR) {
        return ['cache_ready' => false];
    }

    $response = [
        'cache_ready' => true,
        'data' => [
            'today' => $todayData,
            'tomorrow' => $tomorrowData,
            'dates' => [
                'today' => $todayStr,
                'tomorrow' => $tomorrowData !== null ? $tomorrowStr : null,
            ],
            'updateResults' => [
                'today' => false,
                'tomorrow' => false,
            ],
        ],
    ];

    if ($version === 5) {
        $response['data']['diagnostics'] = [
            'today' => [
                'source' => 'cache',
                'status' => 'cache_hit',
                'date' => $todayStr,
            ],
            'tomorrow' => $tomorrowData !== null
                ? [
                    'source' => 'cache',
                    'status' => 'cache_hit',
                    'date' => $tomorrowStr,
                ]
                : [
                    'source' => 'api',
                    'status' => 'not_attempted_before_fetch_hour',
                    'currentHourNl' => $hourNl,
                    'fetchHourNl' => ROUTER_TOMORROW_FETCH_HOUR,
                ],
        ];
    }

    return $response;
}

$version = gpRouterGetRequestedVersion();
if ($version === 0) {
    gpRouterSendJsonResponse([
        'error' => 'invalid_version',
        'message' => 'Use query parameter v=5, v=6, or v=7.',
    ], 400);
    return;
}

$cacheResponse = gpRouterBuildCacheResponse($version);
if (($cacheResponse['cache_ready'] ?? false) === true) {
    /** @var array<string, mixed> $data */
    $data = $cacheResponse['data'];
    gpRouterSendJsonResponse($data, 200);
    return;
}

$entrypoint = __DIR__ . '/get_prices_v' . $version . '.php';
if (!is_file($entrypoint)) {
    gpRouterSendJsonResponse([
        'error' => 'version_entrypoint_not_found',
        'version' => $version,
    ], 500);
    return;
}

require $entrypoint;
