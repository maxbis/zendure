<?php

/**
 * ENTSO-E Day Ahead Prices (Netherlands) v4
 *
 * Features:
 * - Fetches from ENTSO-E API (no config URLs)
 * - Stores prices in data/price/YYYYMM/priceYYYYMMDD.json (same as v2)
 * - File system cache: skips API when file exists
 * - Returns v2-style API: { today, tomorrow, dates, updateResults }
 * - Stores consumer_price per hour: {"00": 0.24, "01": 0.23, ...}
 */

declare(strict_types=1);

const ENTSOE_TOKEN = '4b813a09-87ad-4ae8-8f3d-d15b635d7f96';
const ENTSOE_ENDPOINT = 'https://web-api.tp.entsoe.eu/api';
const NL_DOMAIN = '10YNL----------L';
const DOCUMENT_TYPE = 'A44'; // Day-ahead prices

// Price components (EUR/kWh) and VAT multiplier
const INKOOPVERGOEDING = 0.0219;
const BELASTING = 0.0917;
const BTW = 1.21;

// When true, skip all ENTSO-E API calls; only load from files
const PRICE_API_OFFLINE_MODE = false;

const TOMORROW_FETCH_HOUR = 15;

define('DATA_BASE_DIR', __DIR__ . '/../data');
define('PRICE_DIR', DATA_BASE_DIR . '/price');

/**
 * Gets the directory path for a given date (YYYYMMDD format).
 */
function getPriceDirectory(string $dateStr): ?string {
    if (strlen($dateStr) !== 8) {
        return null;
    }
    $yearMonth = substr($dateStr, 0, 6);
    return PRICE_DIR . '/' . $yearMonth;
}

/**
 * Gets the full file path for a given date.
 */
function getPriceFilePath(string $dateStr): ?string {
    $dir = getPriceDirectory($dateStr);
    if (!$dir) {
        return null;
    }
    return $dir . '/price' . $dateStr . '.json';
}

/**
 * Checks if price file exists for a given date.
 */
function priceFileExists(string $dateStr): bool {
    $filePath = getPriceFilePath($dateStr);
    return $filePath !== null && file_exists($filePath);
}

/**
 * Saves price data to JSON file.
 *
 * @param array<string, float> $prices Hour keys "00"-"23" with consumer_price values
 */
function savePriceData(string $dateStr, array $prices): bool {
    $dir = getPriceDirectory($dateStr);
    if (!$dir) {
        error_log("get_prices_v4: Invalid date format: $dateStr");
        return false;
    }
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            error_log("get_prices_v4: Error creating directory: $dir");
            return false;
        }
    }
    $filePath = getPriceFilePath($dateStr);
    $jsonContent = json_encode($prices, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($jsonContent === false) {
        error_log("get_prices_v4: Error encoding JSON for file: $filePath");
        return false;
    }
    if (file_put_contents($filePath, $jsonContent, LOCK_EX) === false) {
        error_log("get_prices_v4: Error saving file: $filePath");
        return false;
    }
    if (isRunningInCLI()) {
        echo "Saved prices to $filePath\n";
    }
    return true;
}

/**
 * Loads price data from file.
 *
 * @return array<string, float>|null Hour keys "00"-"23" with price values, or null
 */
function loadPriceData(string $dateStr): ?array {
    $filePath = getPriceFilePath($dateStr);
    if (!$filePath || !file_exists($filePath)) {
        return null;
    }
    $jsonContent = file_get_contents($filePath);
    if ($jsonContent === false) {
        return null;
    }
    $data = json_decode($jsonContent, true);
    if ($data === null || !is_array($data)) {
        return null;
    }
    return $data;
}

/**
 * Fetches ENTSO-E day-ahead prices for a specific date.
 * Returns consumer_price per hour: (price_eur_kwh + BELASTING + INKOOPVERGOEDING) * BTW
 *
 * @return array<string, float>|null ["00" => consumer_price, "01" => ..., ...] or null on error
 */
function fetchEntsoePrices(string $dateYmd): ?array {
    $tz = date_default_timezone_get();
    date_default_timezone_set('UTC');

    $start = new DateTime($dateYmd . ' 00:00:00');
    $end = (clone $start)->modify('+1 day');
    $periodStart = $start->format('YmdHi');
    $periodEnd = $end->format('YmdHi');

    date_default_timezone_set($tz);

    $params = http_build_query([
        'securityToken' => ENTSOE_TOKEN,
        'documentType' => DOCUMENT_TYPE,
        'in_Domain' => NL_DOMAIN,
        'out_Domain' => NL_DOMAIN,
        'periodStart' => $periodStart,
        'periodEnd' => $periodEnd,
    ]);
    $url = ENTSOE_ENDPOINT . '?' . $params;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError) {
        error_log("get_prices_v4: Curl error for $dateYmd: $curlError");
        return null;
    }
    if ($httpCode !== 200) {
        error_log("get_prices_v4: ENTSO-E returned HTTP $httpCode for $dateYmd");
        return null;
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($response);
    if ($xml === false) {
        error_log("get_prices_v4: Invalid XML response for $dateYmd");
        return null;
    }

    $namespaces = $xml->getNamespaces(true);
    $ns = $namespaces[''] ?? null;
    $xml->registerXPathNamespace('ns', $ns);
    $points = $xml->xpath('//ns:Point');

    if ($points === false || count($points) < 24) {
        error_log("get_prices_v4: Unexpected point count for $dateYmd");
        return null;
    }

    $prices = [];
    foreach ($points as $point) {
        $position = (int) $point->position;
        $priceMwh = (float) $point->{'price.amount'};
        $priceKwh = $priceMwh / 1000;
        $consumerPrice = ($priceKwh + BELASTING + INKOOPVERGOEDING) * BTW;

        $timestamp = (clone $start)->modify('+' . ($position - 1) . ' hour');
        $hour = $timestamp->format('H');
        $prices[$hour] = round($consumerPrice, 6);
    }
    ksort($prices);
    return count($prices) === 24 ? $prices : null;
}

/**
 * Fetches and saves prices for a date. Skips API if file exists (cache).
 */
function fetchAndSavePricesForDate(string $dateYmd, string $label): bool {
    if (priceFileExists($dateYmd)) {
        if (isRunningInCLI()) {
            echo "File for $label ($dateYmd) already exists, skipping API call\n";
        }
        return true;
    }

    if (isRunningInCLI()) {
        echo "Fetching $label prices for $dateYmd...\n";
    }

    $prices = fetchEntsoePrices($dateYmd);
    if (!$prices) {
        if (isRunningInCLI()) {
            echo "Could not fetch prices for $label ($dateYmd)\n";
        } else {
            error_log("get_prices_v4: Could not fetch prices for $dateYmd");
        }
        return false;
    }

    return savePriceData($dateYmd, $prices);
}

/**
 * Checks and updates today/tomorrow prices. Returns [today => bool, tomorrow => bool].
 */
function checkAndUpdatePrices(): array {
    $currentDate = date('Ymd');
    $tomorrowDate = date('Ymd', strtotime('+1 day'));
    $currentHour = (int) date('H');

    $results = ['today' => false, 'tomorrow' => false];

    if (!priceFileExists($currentDate)) {
        $results['today'] = fetchAndSavePricesForDate($currentDate, 'today');
    } else {
        $results['today'] = true;
    }

    if ($currentHour >= TOMORROW_FETCH_HOUR) {
        if (!priceFileExists($tomorrowDate)) {
            $results['tomorrow'] = fetchAndSavePricesForDate($tomorrowDate, 'tomorrow');
        } else {
            $results['tomorrow'] = true;
        }
    }

    return $results;
}

/**
 * Finds all available price file dates, sorted descending.
 *
 * @return array<int, string>
 */
function findAllAvailableDates(): array {
    $dates = [];
    if (!is_dir(PRICE_DIR)) {
        return $dates;
    }
    $yearMonthDirs = glob(PRICE_DIR . '/*', GLOB_ONLYDIR);
    foreach ($yearMonthDirs ?: [] as $dir) {
        $files = glob($dir . '/price*.json');
        foreach ($files ?: [] as $file) {
            if (preg_match('/price(\d{8})\.json$/', basename($file), $matches)) {
                $dates[] = $matches[1];
            }
        }
    }
    rsort($dates);
    return $dates;
}

/**
 * Returns last two available dates (today, tomorrow) with loaded data.
 */
function getLastTwoAvailableDates(): array {
    $availableDates = findAllAvailableDates();
    $currentDate = date('Ymd');
    $tomorrowDate = date('Ymd', strtotime('+1 day'));
    $yesterdayDate = date('Ymd', strtotime('-1 day'));

    $result = [
        'today' => null,
        'tomorrow' => null,
        'dates' => ['today' => null, 'tomorrow' => null],
    ];

    if (in_array($currentDate, $availableDates)) {
        $result['today'] = loadPriceData($currentDate);
        $result['dates']['today'] = $currentDate;
    } elseif (in_array($yesterdayDate, $availableDates)) {
        $result['today'] = loadPriceData($yesterdayDate);
        $result['dates']['today'] = $yesterdayDate;
    }

    if (in_array($tomorrowDate, $availableDates)) {
        $result['tomorrow'] = loadPriceData($tomorrowDate);
        $result['dates']['tomorrow'] = $tomorrowDate;
    }

    return $result;
}

function isRunningInCLI(): bool {
    return php_sapi_name() === 'cli' || php_sapi_name() === 'phpdbg';
}

function sendJsonResponse(array $data): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * Main: check updates, load files, return v2-style output.
 */
function getPriceData(): array {
    if (PRICE_API_OFFLINE_MODE) {
        $priceData = getLastTwoAvailableDates();
        $priceData['updateResults'] = [
            'today' => priceFileExists(date('Ymd')),
            'tomorrow' => priceFileExists(date('Ymd', strtotime('+1 day'))),
            'skipped' => true,
        ];
        return $priceData;
    }

    $updateResults = checkAndUpdatePrices();
    $priceData = getLastTwoAvailableDates();
    $priceData['updateResults'] = $updateResults;
    return $priceData;
}

if (!isRunningInCLI()) {
    sendJsonResponse(getPriceData());
    exit;
}

if (isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) === realpath(__FILE__)) {
    echo "ENTSO-E Electricity Price Fetcher v4\n";
    echo str_repeat('=', 50) . "\n";

    $result = getPriceData();

    echo "\nUpdate Results:\n";
    echo "  Today: " . ($result['updateResults']['today'] ? 'OK' : 'FAIL') . "\n";
    echo "  Tomorrow: " . ($result['updateResults']['tomorrow'] ? 'OK' : 'FAIL') . "\n";

    echo "\nAvailable Data:\n";
    echo "  Today: " . ($result['dates']['today'] ?? 'Not available') . "\n";
    echo "  Tomorrow: " . ($result['dates']['tomorrow'] ?? 'Not available') . "\n";
}
