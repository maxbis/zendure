<?php

declare(strict_types=1);

/**
 * get_prices_v6 – ENTSO-E–backed today/tomorrow API.
 *
 * Returns v5-style JSON: today/tomorrow hour maps, dates, updateResults.
 * Reads from main/data/price/YYYYMM/priceYYYYMMDD.json when present.
 * If no file for today: fetches from ENTSO-E A44, saves, returns data.
 * If no file for tomorrow and time (NL) >= 14:00: fetches tomorrow from ENTSO-E, saves, returns data.
 */

require_once __DIR__ . '/entsoe_config.php';
require_once __DIR__ . '/../includes/price_conversion.php';

const ENTSOE_BASE_URL = 'https://web-api.tp.entsoe.eu/api?documentType=A44&in_Domain=10YNL----------L&out_Domain=10YNL----------L';
const TIMEZONE_NL = 'Europe/Amsterdam';
const TOMORROW_FETCH_HOUR = 14;

if (!defined('PRICE_DIR')) {
    define('PRICE_DIR', __DIR__ . '/../data/price');
}

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

/**
 * Build ENTSO-E A44 URL for one NL calendar day.
 * periodStart/periodEnd are in UTC (YYYYMMDDHHmm).
 */
function buildEntsoeUrlForDate(string $dateStr): ?string {
    if (strlen($dateStr) !== 8) {
        return null;
    }

    $y = substr($dateStr, 0, 4);
    $m = substr($dateStr, 4, 2);
    $d = substr($dateStr, 6, 2);

    try {
        // Always treat delivery date as UTC midnight
        $startUtc = new DateTimeImmutable(
            $y . '-' . $m . '-' . $d . ' 00:00:00',
            new DateTimeZone('UTC')
        );

        $endUtc = $startUtc->modify('+1 day');

    } catch (Exception $e) {
        return null;
    }

    $periodStart = $startUtc->format('YmdHi'); // e.g. 202602280000
    $periodEnd   = $endUtc->format('YmdHi');   // e.g. 202603010000

    // echo 'periodStart: ' . $periodStart . ', periodEnd: ' . $periodEnd . ', securityToken: ' . getEntsoeSecurityToken();
    // echo '<br>';
    // echo '<br>';
    // exit;   
    
    return ENTSOE_BASE_URL
        . '&periodStart=' . $periodStart
        . '&periodEnd='   . $periodEnd
        . '&securityToken=' . getEntsoeSecurityToken();
}

function fetchXml(string $url): ?string {
    $ch = curl_init($url);
    if ($ch === false) {
        error_log('get_prices_v6: Failed to initialize cURL');
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
        error_log('get_prices_v6: cURL error: ' . $curlError);
        return null;
    }
    if ($httpCode !== 200) {
        error_log('get_prices_v6: HTTP ' . $httpCode);
        return null;
    }
    return $response;
}

/**
 * @return array<int, array<string, mixed>>
 */
function parseHourlyPrices(string $xmlContent): array {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlContent);
    if ($xml === false) {
        return [];
    }
    $namespaces = $xml->getNamespaces(true);
    $defaultNs = $namespaces[''] ?? null;
    if ($defaultNs === null) {
        return [];
    }
    $xml->registerXPathNamespace('ns', $defaultNs);
    $periods = $xml->xpath('//ns:TimeSeries/ns:Period');
    if ($periods === false || empty($periods)) {
        return [];
    }
    $tzUtc = new DateTimeZone('UTC');
    $tzNl = new DateTimeZone(TIMEZONE_NL);
    $groups = [];
    foreach ($periods as $period) {
        if (trim((string)$period->resolution) !== 'PT15M') {
            continue;
        }
        $startStr = trim((string)$period->timeInterval->start);
        if ($startStr === '') {
            continue;
        }
        try {
            $periodStartUtc = new DateTimeImmutable($startStr, $tzUtc);
        } catch (Exception $e) {
            continue;
        }
        foreach ($period->Point as $point) {
            $position = (int)$point->position;
            $price = (float)$point->{'price.amount'};
            if ($position <= 0) {
                continue;
            }
            $offsetMinutes = ($position - 1) * 15;
            $quarterStartUtc = $periodStartUtc->modify('+' . $offsetMinutes . ' minutes');
            $quarterStartNl = $quarterStartUtc->setTimezone($tzNl);
            $hourKey = $quarterStartNl->format('Y-m-d H');
            if (!isset($groups[$hourKey])) {
                $groups[$hourKey] = [
                    'date_nl' => $quarterStartNl->format('Y-m-d'),
                    'hour_nl' => $quarterStartNl->format('H'),
                    'hour_start_nl' => $quarterStartNl->format('Y-m-d H:00:00'),
                    'quarter_prices_eur_mwh' => [],
                ];
            }
            $groups[$hourKey]['quarter_prices_eur_mwh'][] = round($price, 2);
        }
    }
    ksort($groups);
    $result = [];
    foreach ($groups as $group) {
        $quarterPrices = $group['quarter_prices_eur_mwh'];
        $count = count($quarterPrices);
        $average = $count > 0 ? round(array_sum($quarterPrices) / $count, 4) : null;
        $kwhPrice = $average !== null ? round($average / 1000, 4) : null;
        $consumerPrice = convertSpotToConsumerPrice($kwhPrice);
        $result[] = [
            'date_nl' => $group['date_nl'],
            'hour_nl' => $group['hour_nl'],
            'hour_start_nl' => $group['hour_start_nl'],
            'quarter_prices_eur_mwh' => $quarterPrices,
            'quarters_found' => $count,
            'average_price_eur_mwh' => $average,
            'kwh_price' => $kwhPrice,
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

function isSpringDstTransitionDay(string $dateYmd): bool {
    $tzNl = new DateTimeZone(TIMEZONE_NL);
    $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateYmd . ' 00:00:00', $tzNl);
    if ($start === false) {
        return false;
    }

    $end = $start->modify('+1 day');
    $hoursInDay = (int)round(($end->getTimestamp() - $start->getTimestamp()) / 3600);
    if ($hoursInDay !== 23) {
        return false;
    }

    return $start->getOffset() < $end->getOffset();
}

function findNearestEarlierHourKey(array $hourPrices, string $targetHourKey): ?string {
    $targetHour = (int)$targetHourKey;
    for ($hour = $targetHour - 1; $hour >= 0; $hour--) {
        $hourKey = str_pad((string)$hour, 2, '0', STR_PAD_LEFT);
        if (array_key_exists($hourKey, $hourPrices) && is_numeric($hourPrices[$hourKey])) {
            return $hourKey;
        }
    }

    return null;
}

/**
 * @param array<string, float>|null $hourPrices
 * @return array<string, float>|null
 */
function normalizeSpringDstHourMap(?array $hourPrices, string $selectedDateYmd): ?array {
    if (!is_array($hourPrices) || count($hourPrices) !== 23 || !isSpringDstTransitionDay($selectedDateYmd)) {
        return $hourPrices;
    }

    if (array_key_exists('02', $hourPrices)) {
        return $hourPrices;
    }

    $sourceHourKey = null;
    if (array_key_exists('01', $hourPrices) && is_numeric($hourPrices['01'])) {
        $sourceHourKey = '01';
    } else {
        $sourceHourKey = findNearestEarlierHourKey($hourPrices, '02');
    }

    if ($sourceHourKey === null) {
        return $hourPrices;
    }

    $hourPrices['02'] = (float)$hourPrices[$sourceHourKey];
    ksort($hourPrices);
    return $hourPrices;
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
            error_log('get_prices_v6: Failed to create directory ' . $dir);
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
        error_log('get_prices_v6: Failed to write ' . $filePath);
        return false;
    }
    return $filePath;
}

/**
 * Fetch ENTSO-E for one NL date, save to price file, return hour map.
 *
 * @return array<string, float>|null Hour "00"-"23" => consumer price, or null on failure
 */
function fetchEntsoeForDate(string $dateStr): ?array {
    $url = buildEntsoeUrlForDate($dateStr);
    if ($url === null) {
        return null;
    }
    $xmlContent = fetchXml($url);
    if ($xmlContent === null) {
        return null;
    }
    $hours = parseHourlyPrices($xmlContent);
    if (empty($hours)) {
        return null;
    }
    $byDate = buildPriceFilesByDate($hours);
    $dateYmd = substr($dateStr, 0, 4) . '-' . substr($dateStr, 4, 2) . '-' . substr($dateStr, 6, 2);
    $hourPrices = $byDate[$dateYmd] ?? null;
    $hourPrices = normalizeSpringDstHourMap($hourPrices, $dateYmd);
    if ($hourPrices === null || count($hourPrices) < 24) {
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
        $todayData = fetchEntsoeForDate($todayStr);
        $updateToday = $todayData !== null;
    }

    if (priceFileExists($tomorrowStr)) {
        $tomorrowData = loadPriceFile($tomorrowStr);
    } elseif ($hourNl >= TOMORROW_FETCH_HOUR) {
        $tomorrowData = fetchEntsoeForDate($tomorrowStr);
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

$result = getPriceData();
sendJsonResponse($result, 200);
