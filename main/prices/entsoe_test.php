<?php

declare(strict_types=1);

/**
 * ENTSO-E quarter-hour market price test endpoint (v2).
 *
 * Same as entsoe_test.php: fetches A44 day-ahead XML from ENTSO-E and outputs JSON
 * grouped per NL hour. In addition, writes the retrieved prices to:
 *   main/data/price/YYYYMM/priceYYYYMMDD.json
 * Format: object with hour keys "00"-"23" and consumer price (EUR/kWh) per hour.
 */

require_once __DIR__ . '/entsoe_config.php';
require_once __DIR__ . '/../includes/price_conversion.php';

const ENTSOE_BASE_URL = 'https://web-api.tp.entsoe.eu/api?documentType=A44&in_Domain=10YNL----------L&out_Domain=10YNL----------L';
const TIMEZONE_NL = 'Europe/Amsterdam';

define('PRICE_DIR', __DIR__ . '/../data/price');

/**
 * Build ENTSO-E A44 URL for a period (start inclusive, end exclusive) in NL timezone.
 * periodStart/periodEnd are in UTC (YYYYMMDDHHmm).
 */
function buildEntsoeUrl(DateTimeImmutable $startNl, DateTimeImmutable $endNlExclusive): string {
    $tzUtc = new DateTimeZone('UTC');
    $startUtc = $startNl->setTimezone($tzUtc);
    $endUtc = $endNlExclusive->setTimezone($tzUtc);
    $periodStart = $startUtc->format('Ymd') . $startUtc->format('Hi');
    $periodEnd = $endUtc->format('Ymd') . $endUtc->format('Hi');
    return ENTSOE_BASE_URL . '&periodStart=' . $periodStart . '&periodEnd=' . $periodEnd . '&securityToken=' . getEntsoeSecurityToken();
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

function fetchXml(string $url): ?string {
    $ch = curl_init($url);
    if ($ch === false) {
        error_log('entsoe_test2: Failed to initialize cURL');
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
        error_log('entsoe_test2: cURL error: ' . $curlError);
        return null;
    }

    if ($httpCode !== 200) {
        error_log('entsoe_test2: HTTP error code ' . $httpCode);
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
        $resolution = trim((string)$period->resolution);
        if ($resolution !== 'PT15M') {
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
 * Build per-date hour->consumer_price map for file storage.
 * Keys "00"-"23", values consumer price (EUR/kWh).
 *
 * @param array<int, array<string, mixed>> $hours
 * @return array<string, array<string, float>> Map of date (Y-m-d) => hour "00"-"23" => price
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
 * Ensure directory exists and write price file.
 *
 * @param string $dateStr YYYYMMDD
 * @param array<string, float> $prices Hour "00"-"23" => consumer price
 * @return string|false Full path of written file on success, false on failure
 */
function savePriceFile(string $dateStr, array $prices): string|false {
    if (strlen($dateStr) !== 8) {
        return false;
    }
    $yearMonth = substr($dateStr, 0, 6);
    $dir = PRICE_DIR . '/' . $yearMonth;
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            error_log('entsoe_test2: Failed to create directory ' . $dir);
            return false;
        }
    }
    $filePath = $dir . '/price' . $dateStr . '.json';
    ksort($prices);
    $json = json_encode($prices, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    if (file_put_contents($filePath, $json, LOCK_EX) === false) {
        error_log('entsoe_test2: Failed to write ' . $filePath);
        return false;
    }
    return $filePath;
}

function run(): array {
    $tzNl = new DateTimeZone(TIMEZONE_NL);
    $nowNl = new DateTimeImmutable('now', $tzNl);
    $todayStart = $nowNl->setTime(0, 0, 0);
    $dayAfterTomorrowStart = $todayStart->modify('+2 days');

    $url = buildEntsoeUrl($todayStart, $dayAfterTomorrowStart);

    // Debug: print API endpoint (JSON response + error log)
    $debug = ['api_endpoint' => $url];
    error_log('entsoe_test: API endpoint: ' . $url);

    $xmlContent = fetchXml($url);
    if ($xmlContent === null) {
        return [
            'ok' => false,
            'error' => 'Failed to fetch ENTSO-E XML data',
            'source_url' => $url,
            'debug' => $debug,
        ];
    }

    $hours = parseHourlyPrices($xmlContent);
    if (empty($hours)) {
        return [
            'ok' => false,
            'error' => 'No PT15M points found in response',
            'source_url' => $url,
            'debug' => $debug,
        ];
    }

    $writtenFiles = [];
    $byDate = buildPriceFilesByDate($hours);
    foreach ($byDate as $dateYmd => $hourPrices) {
        $dateStr = str_replace('-', '', $dateYmd);
        $path = savePriceFile($dateStr, $hourPrices);
        if ($path !== false) {
            $writtenFiles[] = $path;
        }
    }

    return [
        'ok' => true,
        'source_url' => $url,
        'debug' => $debug,
        'timezone' => TIMEZONE_NL,
        'period_start_nl' => $todayStart->format('Y-m-d H:i:s'),
        'period_end_nl' => $dayAfterTomorrowStart->format('Y-m-d H:i:s'),
        'generated_at_utc' => gmdate('Y-m-d H:i:s'),
        'hours' => $hours,
        'written_files' => $writtenFiles,
    ];
}

$result = run();
$statusCode = ($result['ok'] ?? false) ? 200 : 500;
sendJsonResponse($result, $statusCode);

if (isRunningInCLI() && !empty($result['written_files'] ?? [])) {
    echo "\nFile(s) written:\n";
    foreach ($result['written_files'] as $path) {
        echo "  " . $path . "\n";
    }
}
