<?php

declare(strict_types=1);

/**
 * get_prices_v5 – Jeroen API–backed today/tomorrow API.
 *
 * Returns v6-style JSON: today/tomorrow hour maps, dates, updateResults.
 * Reads from main/data/price/YYYYMM/priceYYYYMMDD.json when present.
 * If no file for today: fetches "vandaag", saves, returns data.
 * If no file for tomorrow and time (NL) >= 14:00: fetches "morgen", saves, returns data.
 */

require_once __DIR__ . '/../includes/price_conversion.php';

const JEROEN_BASE_URL = 'https://jeroen.nl/api/dynamische-energieprijzen/v2/?period=morgen&type=json';
const TIMEZONE_NL = 'Europe/Amsterdam';
const TOMORROW_FETCH_HOUR = 14;

define('PRICE_DIR', __DIR__ . '/../data/price');

function isRunningInCLI(): bool {
    return php_sapi_name() === 'cli' || php_sapi_name() === 'phpdbg';
}

function encodeJsonPayload(array $data): string {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json !== false) {
        return $json . PHP_EOL;
    }

    $fallback = [
        'error' => 'json_encode_failed',
        'message' => json_last_error_msg(),
    ];
    $fallbackJson = json_encode($fallback, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($fallbackJson !== false) {
        return $fallbackJson . PHP_EOL;
    }

    return "{\"error\":\"json_encode_failed_unrecoverable\"}\n";
}

function clearOutputBuffers(): void {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
}

function sendJsonResponse(array $data, int $statusCode = 200): void {
    clearOutputBuffers();

    if (!isRunningInCLI()) {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');
        }
    }

    $GLOBALS['__get_prices_v5_response_sent'] = true;
    echo encodeJsonPayload($data);
}

function initializeRuntimeResilience(): void {
    if (($GLOBALS['__get_prices_v5_runtime_initialized'] ?? false) === true) {
        return;
    }
    $GLOBALS['__get_prices_v5_runtime_initialized'] = true;
    $GLOBALS['__get_prices_v5_response_sent'] = false;

    if (!isRunningInCLI()) {
        @ini_set('display_errors', '0');
        @ini_set('html_errors', '0');
        @ini_set('log_errors', '1');
    }

    if (ob_get_level() === 0) {
        ob_start();
    }

    register_shutdown_function(static function (): void {
        if (($GLOBALS['__get_prices_v5_response_sent'] ?? false) === true) {
            return;
        }

        $lastError = error_get_last();
        if (!is_array($lastError)) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
        if (!in_array($lastError['type'], $fatalTypes, true)) {
            return;
        }

        $tzNl = new DateTimeZone(TIMEZONE_NL);
        $nowNl = new DateTimeImmutable('now', $tzNl);
        $todayStr = $nowNl->format('Ymd');
        $tomorrowStr = $nowNl->modify('+1 day')->format('Ymd');

        sendJsonResponse([
            'today' => null,
            'tomorrow' => null,
            'dates' => [
                'today' => $todayStr,
                'tomorrow' => $tomorrowStr,
            ],
            'updateResults' => [
                'today' => false,
                'tomorrow' => false,
            ],
            'diagnostics' => [
                'fatal' => [
                    'type' => $lastError['type'] ?? null,
                    'message' => $lastError['message'] ?? null,
                    'file' => $lastError['file'] ?? null,
                    'line' => $lastError['line'] ?? null,
                ],
            ],
        ], 500);
    });
}

function getJeroenSecurityKey(): string {
    $path = __DIR__ . '/config.json';
    if (!is_readable($path)) {
        return '';
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return '';
    }
    $data = json_decode($raw, true);
    return is_array($data) && isset($data['JEROEN_SECURITY_KEY']) && is_string($data['JEROEN_SECURITY_KEY'])
        ? trim($data['JEROEN_SECURITY_KEY'])
        : '';
}

function getPriceFilePath(string $dateStr): ?string {
    if (strlen($dateStr) !== 8) {
        return null;
    }
    $yearMonth = substr($dateStr, 0, 6);
    return PRICE_DIR . '/' . $yearMonth . '/price' . $dateStr . '.json';
}

function priceFileExists(string $dateStr): bool {
    $path = getPriceFilePath($dateStr);
    return $path !== null && file_exists($path);
}

/**
 * @return array<string, float>|null Hour "00"-"23" => consumer price, or null
 */
function loadPriceFile(string $dateStr): ?array {
    $path = getPriceFilePath($dateStr);
    if ($path === null || !file_exists($path)) {
        return null;
    }
    $json = file_get_contents($path);
    if ($json === false) {
        return null;
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return null;
    }
    return $decoded;
}

function buildJeroenUrl(string $period, ?string &$reason = null): ?string {
    $reason = null;
    if ($period !== 'vandaag' && $period !== 'morgen') {
        $reason = 'invalid_period';
        return null;
    }
    $key = getJeroenSecurityKey();
    if ($key === '') {
        $reason = 'missing_jeroen_security_key';
        return null;
    }
    return JEROEN_BASE_URL . '&period=' . rawurlencode($period) . '&key=' . rawurlencode($key);
}

function maskApiKeyInUrl(string $url): string {
    return (string)preg_replace('/([?&]key=)[^&]*/', '$1***', $url);
}

/**
 * @return array<int, array<string, mixed>>|null
 */
function fetchJson(string $url, array &$httpMeta = []): ?array {
    $httpMeta = [
        'url' => maskApiKeyInUrl($url),
        'httpCode' => null,
        'curlErrno' => null,
        'curlError' => null,
    ];

    $ch = curl_init($url);
    if ($ch === false) {
        $httpMeta['curlError'] = 'curl_init_failed';
        error_log('get_prices_v5: Failed to initialize cURL');
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    $httpMeta['httpCode'] = $httpCode;
    $httpMeta['curlErrno'] = $curlErrno;
    $httpMeta['curlError'] = $curlError !== '' ? $curlError : null;

    if ($response === false || $curlError !== '') {
        error_log('get_prices_v5: cURL error (' . $curlErrno . '): ' . $curlError);
        return null;
    }
    if ($httpCode !== 200) {
        error_log('get_prices_v5: HTTP ' . $httpCode);
        return null;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        error_log('get_prices_v5: Invalid JSON response');
        return null;
    }

    return $decoded;
}

function parseDutchDecimal(string $value): ?float {
    $normalized = str_replace(',', '.', trim($value));
    if ($normalized === '' || !is_numeric($normalized)) {
        return null;
    }
    return (float)$normalized;
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function parseHourlyPrices(array $rows): array {
    $groups = [];
    $tzNl = new DateTimeZone(TIMEZONE_NL);

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $datumNl = isset($row['datum_nl']) && is_string($row['datum_nl']) ? trim($row['datum_nl']) : '';
        $priceRaw = isset($row['prijs_excl_belastingen']) && is_string($row['prijs_excl_belastingen']) ? $row['prijs_excl_belastingen'] : '';
        if ($datumNl === '' || $priceRaw === '') {
            continue;
        }

        try {
            $dtNl = new DateTimeImmutable($datumNl, $tzNl);
        } catch (Exception $e) {
            continue;
        }

        $priceExcl = parseDutchDecimal($priceRaw);
        if ($priceExcl === null) {
            continue;
        }

        $dateNl = $dtNl->format('Y-m-d');
        $hourNl = $dtNl->format('H');
        $hourKey = $dateNl . ' ' . $hourNl;

        if (!isset($groups[$hourKey])) {
            $groups[$hourKey] = [
                'date_nl' => $dateNl,
                'hour_nl' => $hourNl,
                'hour_start_nl' => $dateNl . ' ' . $hourNl . ':00:00',
                'quarter_prices_excl' => [],
            ];
        }
        $groups[$hourKey]['quarter_prices_excl'][] = round($priceExcl, 6);
    }

    ksort($groups);
    $result = [];
    foreach ($groups as $group) {
        $quarterPrices = $group['quarter_prices_excl'];
        $count = count($quarterPrices);
        $averageExcl = $count > 0 ? round(array_sum($quarterPrices) / $count, 6) : null;
        $consumerPrice = convertSpotToConsumerPrice($averageExcl);

        $result[] = [
            'date_nl' => $group['date_nl'],
            'hour_nl' => $group['hour_nl'],
            'hour_start_nl' => $group['hour_start_nl'],
            'quarters_found' => $count,
            'average_price_excl' => $averageExcl,
            'consumer_price' => $consumerPrice,
        ];
    }

    return $result;
}

/**
 * @param array<int, array<string, mixed>> $hours
 * @return array<string, array<string, float>>
 */
function buildPriceFilesByDate(array $hours): array {
    $byDate = [];
    foreach ($hours as $row) {
        $date = $row['date_nl'];
        $hour = str_pad((string)(int)$row['hour_nl'], 2, '0', STR_PAD_LEFT);
        $price = $row['consumer_price'];
        if ($price === null) {
            continue;
        }
        if (!isset($byDate[$date])) {
            $byDate[$date] = [];
        }
        $byDate[$date][$hour] = $price;
    }
    return $byDate;
}

/**
 * @param array<string, float> $prices
 * @return string|false
 */
function savePriceFile(string $dateStr, array $prices): string|false {
    if (strlen($dateStr) !== 8) {
        return false;
    }
    $yearMonth = substr($dateStr, 0, 6);
    $dir = PRICE_DIR . '/' . $yearMonth;
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            error_log('get_prices_v5: Failed to create directory ' . $dir);
            return false;
        }
    }
    $filePath = $dir . '/price' . $dateStr . '.json';
    ksort($prices);
    $json = json_encode($prices, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    umask(0002);
    if (file_put_contents($filePath, $json, LOCK_EX) === false) {
        error_log('get_prices_v5: Failed to write ' . $filePath);
        return false;
    }
    return $filePath;
}

/**
 * Fetch Jeroen for one period, save to price file, return hour map.
 *
 * @return array<string, float>|null Hour "00"-"23" => consumer price, or null on failure
 */
function fetchJeroenForPeriod(string $period, string $expectedDateStr, array &$diagnostics = []): ?array {
    $diagnostics = [
        'period' => $period,
        'expectedDate' => $expectedDateStr,
        'source' => 'api',
        'status' => 'started',
    ];

    $buildUrlReason = null;
    $url = buildJeroenUrl($period, $buildUrlReason);
    if ($url === null) {
        $diagnostics['status'] = 'build_url_failed';
        $diagnostics['buildUrlReason'] = $buildUrlReason;
        return null;
    }

    $httpMeta = [];
    $rows = fetchJson($url, $httpMeta);
    $diagnostics['request'] = $httpMeta;
    if ($rows === null || empty($rows)) {
        $diagnostics['status'] = 'fetch_failed_or_empty';
        return null;
    }

    $hours = parseHourlyPrices($rows);
    if (empty($hours)) {
        $diagnostics['status'] = 'parse_failed_or_empty';
        return null;
    }

    $byDate = buildPriceFilesByDate($hours);
    if (empty($byDate)) {
        $diagnostics['status'] = 'no_hourly_prices_built';
        return null;
    }

    $expectedDateYmd = substr($expectedDateStr, 0, 4) . '-' . substr($expectedDateStr, 4, 2) . '-' . substr($expectedDateStr, 6, 2);
    $selectedDateYmd = $expectedDateYmd;

    if (!isset($byDate[$selectedDateYmd])) {
        $dates = array_keys($byDate);
        sort($dates);
        $selectedDateYmd = $dates[0] ?? '';
        if ($selectedDateYmd === '') {
            $diagnostics['status'] = 'no_date_selected';
            return null;
        }
    }

    $hourPrices = $byDate[$selectedDateYmd] ?? null;
    if ($hourPrices === null || count($hourPrices) < 24) {
        $diagnostics['status'] = 'insufficient_hour_data';
        $diagnostics['hoursFound'] = is_array($hourPrices) ? count($hourPrices) : 0;
        return null;
    }

    $selectedDateStr = str_replace('-', '', $selectedDateYmd);
    if (savePriceFile($selectedDateStr, $hourPrices) === false) {
        $diagnostics['status'] = 'save_failed';
        $diagnostics['selectedDate'] = $selectedDateStr;
        return null;
    }

    $diagnostics['status'] = 'ok';
    $diagnostics['selectedDate'] = $selectedDateStr;
    $diagnostics['hoursFound'] = count($hourPrices);
    return $hourPrices;
}

/**
 * @return array{today: array<string, float>|null, tomorrow: array<string, float>|null, dates: array{today: string, tomorrow: string|null}, updateResults: array{today: bool, tomorrow: bool}}
 */
function getPriceData(): array {
    $tzNl = new DateTimeZone(TIMEZONE_NL);
    $nowNl = new DateTimeImmutable('now', $tzNl);
    $todayStr = $nowNl->format('Ymd');
    $tomorrowStr = $nowNl->modify('+1 day')->format('Ymd');
    $hourNl = (int)$nowNl->format('H');

    $todayData = null;
    $tomorrowData = null;
    $updateToday = false;
    $updateTomorrow = false;
    $diagnostics = [
        'today' => ['source' => null, 'status' => null],
        'tomorrow' => ['source' => null, 'status' => null],
    ];

    if (priceFileExists($todayStr)) {
        $todayData = loadPriceFile($todayStr);
        $diagnostics['today'] = [
            'source' => 'cache',
            'status' => 'cache_hit',
            'date' => $todayStr,
        ];
    } else {
        $todayFetchDiagnostics = [];
        $todayData = fetchJeroenForPeriod('vandaag', $todayStr, $todayFetchDiagnostics);
        $updateToday = $todayData !== null;
        $diagnostics['today'] = $todayFetchDiagnostics;
    }

    if (priceFileExists($tomorrowStr)) {
        $tomorrowData = loadPriceFile($tomorrowStr);
        $diagnostics['tomorrow'] = [
            'source' => 'cache',
            'status' => 'cache_hit',
            'date' => $tomorrowStr,
        ];
    } elseif ($hourNl >= TOMORROW_FETCH_HOUR) {
        $tomorrowFetchDiagnostics = [];
        $tomorrowData = fetchJeroenForPeriod('morgen', $tomorrowStr, $tomorrowFetchDiagnostics);
        $updateTomorrow = $tomorrowData !== null;
        $diagnostics['tomorrow'] = $tomorrowFetchDiagnostics;
    } else {
        $diagnostics['tomorrow'] = [
            'source' => 'api',
            'status' => 'not_attempted_before_fetch_hour',
            'currentHourNl' => $hourNl,
            'fetchHourNl' => TOMORROW_FETCH_HOUR,
        ];
    }

    return [
        'today' => $todayData,
        'tomorrow' => $tomorrowData,
        'dates' => [
            'today' => $todayStr,
            'tomorrow' => $tomorrowData !== null ? $tomorrowStr : null,
        ],
        'updateResults' => [
            'today' => $updateToday,
            'tomorrow' => $updateTomorrow,
        ],
        'diagnostics' => $diagnostics,
    ];
}

initializeRuntimeResilience();
$result = getPriceData();
sendJsonResponse($result, 200);
