<?php

declare(strict_types=1);

/**
 * get_prices_v7 – EnergyZero-backed today/tomorrow API.
 *
 * Returns v6-style JSON: today/tomorrow hour maps, dates, updateResults.
 * Reads from main/data/price/YYYYMM/priceYYYYMMDD.json when present.
 * If no file for today: fetches from EnergyZero, saves, returns data.
 * If no file for tomorrow and time (NL) >= 14:00: fetches tomorrow, saves, returns data.
 */

require_once __DIR__ . '/../includes/price_conversion.php';
require_once __DIR__ . '/energyzero_hour_prices.php';

const ENERGYZERO_BASE_URL = 'https://public.api.energyzero.nl/public/v1/prices';
const TIMEZONE_NL = 'Europe/Amsterdam';
const TOMORROW_FETCH_HOUR = 14;

define('PRICE_DIR', __DIR__ . '/../data/price');

function isRunningInCLI(): bool {
    return php_sapi_name() === 'cli' || php_sapi_name() === 'phpdbg';
}

function sendJsonResponse(array $data, int $statusCode = 200): void {
    if (!isRunningInCLI()) {
        http_response_code($statusCode);
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Content-Type: application/json');
    }
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
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

function parseDecimalValue(mixed $value): ?float {
    if (is_int($value) || is_float($value)) {
        return (float)$value;
    }
    if (!is_string($value)) {
        return null;
    }
    $normalized = str_replace(',', '.', trim($value));
    if ($normalized === '' || !is_numeric($normalized)) {
        return null;
    }
    return (float)$normalized;
}

/**
 * Build EnergyZero URL for one NL calendar day.
 */
function buildEnergyzeroUrlForDate(string $dateStr): ?string {
    if (strlen($dateStr) !== 8) {
        return null;
    }

    $dateFormatted = substr($dateStr, 6, 2) . '-' . substr($dateStr, 4, 2) . '-' . substr($dateStr, 0, 4);

    return ENERGYZERO_BASE_URL
        . '?energyType=ENERGY_TYPE_ELECTRICITY'
        . '&date=' . rawurlencode($dateFormatted)
        . '&interval=INTERVAL_HOUR';
}

/**
 * @return array<string, mixed>|null
 */
function fetchJson(string $url): ?array {
    $ch = curl_init($url);
    if ($ch === false) {
        error_log('get_prices_v7: Failed to initialize cURL');
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
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($response === false || $curlError !== '') {
        error_log('get_prices_v7: cURL error: ' . $curlError);
        return null;
    }
    if ($httpCode !== 200) {
        error_log('get_prices_v7: HTTP ' . $httpCode);
        return null;
    }
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string, mixed> $payload
 * @return array<int, array<string, mixed>>
 */
function parseHourlyPrices(array $payload): array {
    $rows = $payload['base'] ?? null;
    if (!is_array($rows)) {
        return [];
    }

    $tzUtc = new DateTimeZone('UTC');
    $tzNl = new DateTimeZone(TIMEZONE_NL);
    $groups = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $start = $row['start'] ?? null;
        $priceValue = is_array($row['price'] ?? null) ? (($row['price']['value'] ?? null)) : null;
        if (!is_string($start)) {
            continue;
        }

        $sourcePrice = parseDecimalValue($priceValue);
        if ($sourcePrice === null) {
            continue;
        }

        try {
            $dtUtc = new DateTimeImmutable($start, $tzUtc);
        } catch (Exception $e) {
            continue;
        }

        $dtNl = $dtUtc->setTimezone($tzNl);
        $dateNl = $dtNl->format('Y-m-d');
        $hourNl = $dtNl->format('H');
        $hourKey = $dateNl . ' ' . $hourNl;

        if (!isset($groups[$hourKey])) {
            $groups[$hourKey] = [
                'date_nl' => $dateNl,
                'hour_nl' => $hourNl,
                'hour_start_nl' => $dateNl . ' ' . $hourNl . ':00:00',
                'source_prices' => [],
            ];
        }

        $groups[$hourKey]['source_prices'][] = round($sourcePrice, 6);
    }

    ksort($groups);
    $result = [];
    foreach ($groups as $group) {
        $sourcePrices = $group['source_prices'];
        $count = count($sourcePrices);
        $averageSource = $count > 0 ? round(array_sum($sourcePrices) / $count, 6) : null;
        $consumerPrice = convertSpotToConsumerPrice($averageSource);

        $result[] = [
            'date_nl' => $group['date_nl'],
            'hour_nl' => $group['hour_nl'],
            'hour_start_nl' => $group['hour_start_nl'],
            'entries_found' => $count,
            'average_source_price' => $averageSource,
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
            error_log('get_prices_v7: Failed to create directory ' . $dir);
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
        error_log('get_prices_v7: Failed to write ' . $filePath);
        return false;
    }
    return $filePath;
}

/**
 * Fetch EnergyZero for one NL date, save to price file, return complete hour map.
 *
 * @return array<string, float>|null Hour "00"-"23" => consumer price, or null on failure
 */
function fetchEnergyzeroForDate(string $dateStr): ?array {
    $hourPrices = fetchEnergyzeroHourPricesForDate($dateStr, true);
    if ($hourPrices === null) {
        return null;
    }
    if (savePriceFile($dateStr, $hourPrices) === false) {
        return null;
    }
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

    if (priceFileExists($todayStr)) {
        $todayData = loadPriceFile($todayStr);
    } else {
        $todayData = fetchEnergyzeroForDate($todayStr);
        $updateToday = $todayData !== null;
    }

    if (priceFileExists($tomorrowStr)) {
        $tomorrowData = loadPriceFile($tomorrowStr);
    } elseif ($hourNl >= TOMORROW_FETCH_HOUR) {
        $tomorrowData = fetchEnergyzeroForDate($tomorrowStr);
        $updateTomorrow = $tomorrowData !== null;
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
    ];
}

function shouldRunGetPricesV7Entrypoint(): bool {
    $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
    return is_string($script) && $script !== '' && realpath($script) === __FILE__;
}

if (shouldRunGetPricesV7Entrypoint()) {
    $result = getPriceData();
    sendJsonResponse($result, 200);
}
