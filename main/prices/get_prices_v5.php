<?php
/**
 * Electricity Price Fetcher v5 - alta API with consumer price conversion.
 *
 * Reads URLs from config key "priceUrls-alta", converts kwartierprijzen to
 * hourly consumer prices, stores files in /data/price/YYYYMM/priceYYYYMMDD.json,
 * and returns v2-style output.
 */

declare(strict_types=1);

const CONFIG_FILE = __DIR__ . '/../config/config.json';
const DATA_BASE_DIR = __DIR__ . '/../data';
const PRICE_DIR = DATA_BASE_DIR . '/price';

const CONFIG_PRICE_URLS_KEY = 'priceUrls-alta';
const CONFIG_TODAY_KEY = 'today';
const CONFIG_TOMORROW_KEY = 'tomorrow';

const INKOOPVERGOEDING = 0.0219;
const BELASTING = 0.0917;
const BTW = 1.21;

const TOMORROW_FETCH_HOUR = 15;

/**
 * @return array{urlToday: string, urlTomorrow: string}|null
 */
function loadConfig(): ?array {
    if (!file_exists(CONFIG_FILE)) {
        error_log('get_prices_v5: Config file not found: ' . CONFIG_FILE);
        return null;
    }

    $content = file_get_contents(CONFIG_FILE);
    if ($content === false) {
        error_log('get_prices_v5: Could not read config file');
        return null;
    }

    $config = json_decode($content, true);
    if (!is_array($config)) {
        error_log('get_prices_v5: Invalid JSON in config file');
        return null;
    }

    $priceUrls = $config[CONFIG_PRICE_URLS_KEY] ?? null;
    if (!is_array($priceUrls)) {
        error_log('get_prices_v5: Missing config key: ' . CONFIG_PRICE_URLS_KEY);
        return null;
    }

    $urlToday = $priceUrls[CONFIG_TODAY_KEY] ?? null;
    $urlTomorrow = $priceUrls[CONFIG_TOMORROW_KEY] ?? null;

    if (!is_string($urlToday) || $urlToday === '' || !is_string($urlTomorrow) || $urlTomorrow === '') {
        error_log('get_prices_v5: Missing ' . CONFIG_PRICE_URLS_KEY . '.today or .tomorrow');
        return null;
    }

    return [
        'urlToday' => $urlToday,
        'urlTomorrow' => $urlTomorrow,
    ];
}

/**
 * @return array<int, array<string, mixed>>|null
 */
function fetchPricesFromApi(string $url): ?array {
    $ch = curl_init($url);
    if ($ch === false) {
        error_log('get_prices_v5: cURL init failed for URL: ' . $url);
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        error_log('get_prices_v5: API request failed: ' . $curlError);
        return null;
    }

    if ($httpCode !== 200) {
        error_log('get_prices_v5: API returned HTTP ' . $httpCode);
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data)) {
        error_log('get_prices_v5: API did not return a non-empty JSON array');
        return null;
    }

    return $data;
}

function toFloatPrice(mixed $value): ?float {
    if (is_float($value) || is_int($value)) {
        return (float)$value;
    }

    if (!is_string($value) || $value === '') {
        return null;
    }

    $normalized = str_replace(',', '.', $value);
    if (!is_numeric($normalized)) {
        return null;
    }

    return (float)$normalized;
}

function toConsumerPrice(float $prijsExclBelastingen): float {
    return ($prijsExclBelastingen + INKOOPVERGOEDING + BELASTING) * BTW;
}

/**
 * Builds hourly consumer prices for a target date (Ymd) from quarter-hour rows.
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<string, float>|null
 */
function extractHourlyConsumerPricesForDate(array $rows, string $targetDateYmd): ?array {
    $sumByHour = [];
    $countByHour = [];

    foreach ($rows as $entry) {
        $datumNl = $entry['datum_nl'] ?? null;
        if (!is_string($datumNl) || $datumNl === '') {
            continue;
        }

        try {
            $dt = new DateTime($datumNl);
        } catch (Exception $e) {
            continue;
        }

        if ($dt->format('Ymd') !== $targetDateYmd) {
            continue;
        }

        $rawPrice = toFloatPrice($entry['prijs_excl_belastingen'] ?? null);
        if ($rawPrice === null) {
            continue;
        }

        $hour = $dt->format('H');
        $consumerPrice = toConsumerPrice($rawPrice);

        if (!isset($sumByHour[$hour])) {
            $sumByHour[$hour] = 0.0;
            $countByHour[$hour] = 0;
        }

        $sumByHour[$hour] += $consumerPrice;
        $countByHour[$hour] += 1;
    }

    if (empty($sumByHour)) {
        return null;
    }

    $hourly = [];
    for ($h = 0; $h < 24; $h++) {
        $hourKey = str_pad((string)$h, 2, '0', STR_PAD_LEFT);
        if (!isset($sumByHour[$hourKey]) || (int)$countByHour[$hourKey] === 0) {
            return null;
        }
        $hourly[$hourKey] = round($sumByHour[$hourKey] / $countByHour[$hourKey], 6);
    }

    return $hourly;
}

function getPriceDirectory(string $dateStr): ?string {
    if (!preg_match('/^\d{8}$/', $dateStr)) {
        return null;
    }

    return PRICE_DIR . '/' . substr($dateStr, 0, 6);
}

function getPriceFilePath(string $dateStr): ?string {
    $dir = getPriceDirectory($dateStr);
    if ($dir === null) {
        return null;
    }

    return $dir . '/price' . $dateStr . '.json';
}

function priceFileExists(string $dateStr): bool {
    $filePath = getPriceFilePath($dateStr);
    return $filePath !== null && file_exists($filePath);
}

/**
 * @param array<string, float> $prices
 */
function savePriceData(string $dateStr, array $prices): bool {
    $dir = getPriceDirectory($dateStr);
    if ($dir === null) {
        error_log('get_prices_v5: Invalid date format: ' . $dateStr);
        return false;
    }

    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        error_log('get_prices_v5: Failed creating directory: ' . $dir);
        return false;
    }

    $filePath = getPriceFilePath($dateStr);
    if ($filePath === null) {
        return false;
    }

    $json = json_encode($prices, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        error_log('get_prices_v5: JSON encode failed for date: ' . $dateStr);
        return false;
    }

    if (file_put_contents($filePath, $json, LOCK_EX) === false) {
        error_log('get_prices_v5: Failed writing file: ' . $filePath);
        return false;
    }

    if (isRunningInCLI()) {
        echo "Saved prices to {$filePath}\n";
    }

    return true;
}

function fetchAndSavePricesForDate(string $url, string $targetDateYmd, string $label): bool {
    if (priceFileExists($targetDateYmd)) {
        if (isRunningInCLI()) {
            echo "File for {$label} ({$targetDateYmd}) already exists, skipping\n";
        }
        return true;
    }

    if (isRunningInCLI()) {
        echo "Fetching {$label} prices for {$targetDateYmd}...\n";
    }

    $rows = fetchPricesFromApi($url);
    if ($rows === null) {
        return false;
    }

    $hourlyPrices = extractHourlyConsumerPricesForDate($rows, $targetDateYmd);
    if ($hourlyPrices === null) {
        error_log('get_prices_v5: Could not build full 24-hour prices for ' . $targetDateYmd);
        return false;
    }

    return savePriceData($targetDateYmd, $hourlyPrices);
}

/**
 * @return array{today: bool, tomorrow: bool}
 */
function checkAndUpdatePrices(array $config): array {
    $todayYmd = date('Ymd');
    $tomorrowYmd = date('Ymd', strtotime('+1 day'));
    $currentHour = (int)date('H');

    $result = ['today' => false, 'tomorrow' => false];

    if (priceFileExists($todayYmd)) {
        $result['today'] = true;
    } else {
        $result['today'] = fetchAndSavePricesForDate($config['urlToday'], $todayYmd, 'today');
    }

    if ($currentHour >= TOMORROW_FETCH_HOUR) {
        if (priceFileExists($tomorrowYmd)) {
            $result['tomorrow'] = true;
        } else {
            $result['tomorrow'] = fetchAndSavePricesForDate($config['urlTomorrow'], $tomorrowYmd, 'tomorrow');
        }
    }

    return $result;
}

/**
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
            if (preg_match('/price(\d{8})\.json$/', basename($file), $m)) {
                $dates[] = $m[1];
            }
        }
    }

    rsort($dates);
    return $dates;
}

/**
 * @return array<string, float>|null
 */
function loadPriceData(string $dateStr): ?array {
    $filePath = getPriceFilePath($dateStr);
    if ($filePath === null || !file_exists($filePath)) {
        return null;
    }

    $json = file_get_contents($filePath);
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
 * @return array{
 *   today: array<string, float>|null,
 *   tomorrow: array<string, float>|null,
 *   dates: array{today: string|null, tomorrow: string|null}
 * }
 */
function getLastTwoAvailableDates(): array {
    $availableDates = findAllAvailableDates();
    $todayYmd = date('Ymd');
    $tomorrowYmd = date('Ymd', strtotime('+1 day'));
    $yesterdayYmd = date('Ymd', strtotime('-1 day'));

    $result = [
        'today' => null,
        'tomorrow' => null,
        'dates' => [
            'today' => null,
            'tomorrow' => null,
        ],
    ];

    if (in_array($todayYmd, $availableDates, true)) {
        $result['today'] = loadPriceData($todayYmd);
        $result['dates']['today'] = $todayYmd;
    } elseif (in_array($yesterdayYmd, $availableDates, true)) {
        $result['today'] = loadPriceData($yesterdayYmd);
        $result['dates']['today'] = $yesterdayYmd;
    }

    if (in_array($tomorrowYmd, $availableDates, true)) {
        $result['tomorrow'] = loadPriceData($tomorrowYmd);
        $result['dates']['tomorrow'] = $tomorrowYmd;
    }

    return $result;
}

function getPriceData(): array {
    $config = loadConfig();
    if ($config === null) {
        return [
            'error' => 'Failed to load configuration',
            'today' => null,
            'tomorrow' => null,
            'dates' => ['today' => null, 'tomorrow' => null],
            'updateResults' => ['today' => false, 'tomorrow' => false],
        ];
    }

    $updateResults = checkAndUpdatePrices($config);
    $priceData = getLastTwoAvailableDates();
    $priceData['updateResults'] = $updateResults;

    return $priceData;
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

if (!isRunningInCLI()) {
    sendJsonResponse(getPriceData());
    exit;
}

if (isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) === realpath(__FILE__)) {
    echo "Electricity Price Fetcher v5 (alta)\n";
    echo str_repeat('=', 50) . "\n";

    $result = getPriceData();

    echo "\nUpdate Results:\n";
    echo '  Today: ' . (($result['updateResults']['today'] ?? false) ? 'OK' : 'FAIL') . "\n";
    echo '  Tomorrow: ' . (($result['updateResults']['tomorrow'] ?? false) ? 'OK' : 'FAIL') . "\n";

    echo "\nAvailable Data:\n";
    echo '  Today: ' . (($result['dates']['today'] ?? null) ?: 'Not available') . "\n";
    echo '  Tomorrow: ' . (($result['dates']['tomorrow'] ?? null) ?: 'Not available') . "\n";
}
