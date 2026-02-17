<?php
/**
 * Electricity Price Fetcher v3 - jeroen.nl API (v2-style)
 *
 * Features:
 * - Reads API endpoints from config (priceUrls-alta or priceUrls)
 * - Fetches from jeroen.nl-style API: bare JSON array with datum_nl, prijs_excl_belastingen (15-min)
 * - Stores prices in: data/price/YYYYMM/priceYYYYMMDD.json (hour keys "00"-"23", same format as v2)
 * - Same algorithm as get_prices_v2: fetch today/tomorrow when needed, return last two available dates
 */

define('CONFIG_FILE', __DIR__ . '/../config/config.json');
define('DATA_BASE_DIR', __DIR__ . '/../data');
define('PRICE_DIR', DATA_BASE_DIR . '/price');

/**
 * Loads configuration from config.json.
 * Prefers priceUrls-alta.today / priceUrls-alta.tomorrow when present, else priceUrls.
 *
 * @return array|null Array with 'urlToday', 'urlTomorrow', 'tomorrowFetchHour' or null on error
 */
function loadConfig() {
    if (!file_exists(CONFIG_FILE)) {
        error_log("ERROR: Config file " . CONFIG_FILE . " not found");
        return null;
    }

    $configContent = file_get_contents(CONFIG_FILE);
    if ($configContent === false) {
        error_log("ERROR: Could not read config file " . CONFIG_FILE);
        return null;
    }

    $config = json_decode($configContent, true);
    if ($config === null) {
        error_log("ERROR: Could not parse config file " . CONFIG_FILE);
        return null;
    }

    // v3 uses priceUrls-alta only (jeroen.nl); do not fall back to priceUrls (enever) to avoid wrong API.
    $alta = isset($config['priceUrls-alta']) && is_array($config['priceUrls-alta'])
        ? $config['priceUrls-alta']
        : null;
    $urlToday = $alta['today'] ?? null;
    $urlTomorrow = $alta['tomorrow'] ?? null;

    if (empty($urlToday) || empty($urlTomorrow)) {
        error_log("ERROR: priceUrls-alta.today and priceUrls-alta.tomorrow required in config for get_prices_v3 (jeroen.nl)");
        return null;
    }

    $tomorrowFetchHour = isset($config['tomorrowFetchHour']) ? (int)$config['tomorrowFetchHour'] : 15;

    return [
        'urlToday' => $urlToday,
        'urlTomorrow' => $urlTomorrow,
        'tomorrowFetchHour' => $tomorrowFetchHour
    ];
}

/**
 * Fetches price data from API endpoint (GET).
 * Expects jeroen.nl-style response: bare JSON array of entries.
 *
 * @param string $url API endpoint URL
 * @return array|null Decoded array or null on error
 */
function fetchPricesFromApi($url) {
    $ch = curl_init($url);

    if ($ch === false) {
        error_log("❌ Error initializing cURL for URL: $url");
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || !empty($curlError)) {
        error_log("❌ Error fetching prices from API: $curlError");
        return null;
    }

    if ($httpCode !== 200) {
        error_log("❌ API returned HTTP status: $httpCode");
        return null;
    }

    $data = json_decode($response, true);

    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        $preview = trim(substr($response, 0, 300));
        if (stripos($response, 'limiet') !== false || stripos($response, 'query') !== false) {
            error_log("❌ API may have returned rate-limit or error page (not JSON). Preview: " . $preview);
        } else {
            error_log("❌ Error parsing JSON response: " . json_last_error_msg() . ". Preview: " . $preview);
        }
        return null;
    }

    if (!is_array($data) || empty($data)) {
        error_log("❌ API did not return a non-empty array");
        return null;
    }

    return $data;
}

/**
 * Extracts date from jeroen.nl API response (first entry datum_nl).
 *
 * @param array $data Raw array of entries with datum_nl
 * @return string|null Date string in format Ymd or null
 */
function extractDateFromApiData($data) {
    if (!is_array($data) || empty($data)) {
        return null;
    }

    try {
        $firstEntry = $data[0];
        if (!isset($firstEntry['datum_nl']) || empty($firstEntry['datum_nl'])) {
            return null;
        }
        $dt = new DateTime($firstEntry['datum_nl']);
        return $dt->format('Ymd');
    } catch (Exception $e) {
        error_log("❌ Error extracting date from data: " . $e->getMessage());
        return null;
    }
}

/**
 * Extracts prices from jeroen.nl API: 15-min entries → hourly average.
 * prijs_excl_belastingen uses comma as decimal separator.
 *
 * @param array $data Raw array of entries with datum_nl, prijs_excl_belastingen
 * @return array|null Dictionary with hour keys "00"-"23" and float values, or null
 */
function extractPricesFromApiData($data) {
    if (!is_array($data) || empty($data)) {
        return null;
    }

    $sum = [];
    $count = [];

    try {
        foreach ($data as $entry) {
            if (!isset($entry['datum_nl']) || !isset($entry['prijs_excl_belastingen'])) {
                continue;
            }
            $datumNl = $entry['datum_nl'];
            $prijsStr = $entry['prijs_excl_belastingen'];
            if (empty($datumNl) || $prijsStr === null || $prijsStr === '') {
                continue;
            }
            $dt = new DateTime($datumNl);
            $hour = $dt->format('H');
            $price = (float)str_replace(',', '.', $prijsStr);

            if (!isset($sum[$hour])) {
                $sum[$hour] = 0.0;
                $count[$hour] = 0;
            }
            $sum[$hour] += $price;
            $count[$hour] += 1;
        }
    } catch (Exception $e) {
        error_log("❌ Error extracting prices: " . $e->getMessage());
        return null;
    }

    if (empty($sum)) {
        return null;
    }

    $prices = [];
    foreach ($sum as $hour => $total) {
        $prices[$hour] = $total / max(1, $count[$hour]);
    }

    if (count($prices) < 24) {
        return null;
    }

    ksort($prices);
    return $prices;
}

/**
 * Gets the directory path for a given date (YYYYMMDD format).
 */
function getPriceDirectory($dateStr) {
    if (strlen($dateStr) !== 8) {
        return null;
    }
    $yearMonth = substr($dateStr, 0, 6);
    return PRICE_DIR . '/' . $yearMonth;
}

/**
 * Gets the full file path for a given date.
 */
function getPriceFilePath($dateStr) {
    $dir = getPriceDirectory($dateStr);
    if (!$dir) {
        return null;
    }
    return $dir . '/price' . $dateStr . '.json';
}

/**
 * Checks if price file exists for a given date.
 */
function priceFileExists($dateStr) {
    $filePath = getPriceFilePath($dateStr);
    if (!$filePath) {
        return false;
    }
    return file_exists($filePath);
}

/**
 * Saves price data to JSON file in organized directory structure.
 */
function savePriceData($dateStr, $prices) {
    $dir = getPriceDirectory($dateStr);
    if (!$dir) {
        error_log("❌ Invalid date format: $dateStr");
        return false;
    }

    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            error_log("❌ Error creating directory: $dir");
            return false;
        }
    }

    $filePath = getPriceFilePath($dateStr);
    $jsonContent = json_encode($prices, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    if ($jsonContent === false) {
        error_log("❌ Error encoding JSON for file: $filePath");
        return false;
    }

    $result = file_put_contents($filePath, $jsonContent, LOCK_EX);
    if ($result === false) {
        error_log("❌ Error saving file: $filePath");
        return false;
    }

    if (isRunningInCLI()) {
        echo "✅ Saved prices to $filePath\n";
    }

    return true;
}

/**
 * Fetches and saves prices for a given URL (date comes from response).
 * Same semantics as v2 fetchAndSavePrices.
 *
 * @param string $url API endpoint URL
 * @param string $dateLabel Label for logging (e.g. "today", "tomorrow")
 * @return bool True if successful
 */
function fetchAndSavePrices($url, $dateLabel) {
    if (isRunningInCLI()) {
        echo "\n📊 Fetching $dateLabel prices...\n";
    }

    $data = fetchPricesFromApi($url);
    if (!$data) {
        return false;
    }

    $dateStr = extractDateFromApiData($data);
    if (!$dateStr) {
        if (isRunningInCLI()) {
            echo "❌ Could not extract date from $dateLabel data\n";
        } else {
            error_log("❌ Could not extract date from $dateLabel data");
        }
        return false;
    }

    if (priceFileExists($dateStr)) {
        if (isRunningInCLI()) {
            echo "ℹ️  File for $dateLabel ($dateStr) already exists, skipping API call\n";
        }
        return true;
    }

    $prices = extractPricesFromApiData($data);
    if (!$prices) {
        if (isRunningInCLI()) {
            echo "❌ Could not extract prices from $dateLabel data\n";
        } else {
            error_log("❌ Could not extract prices from $dateLabel data");
        }
        return false;
    }

    return savePriceData($dateStr, $prices);
}

/**
 * Checks if updates are needed and performs them (same algorithm as v2).
 *
 * @param array $config Configuration with urlToday, urlTomorrow, tomorrowFetchHour
 * @return array ['today' => bool, 'tomorrow' => bool]
 */
function checkAndUpdatePrices($config) {
    $results = ['today' => false, 'tomorrow' => false];
    $currentDate = date('Ymd');
    $currentHour = (int)date('H');
    $tomorrowDate = date('Ymd', strtotime('+1 day'));

    if (!priceFileExists($currentDate)) {
        $results['today'] = fetchAndSavePrices($config['urlToday'], 'today');
    } else {
        $results['today'] = true;
    }

    if ($currentHour >= $config['tomorrowFetchHour']) {
        if (!priceFileExists($tomorrowDate)) {
            $results['tomorrow'] = fetchAndSavePrices($config['urlTomorrow'], 'tomorrow');
        } else {
            $results['tomorrow'] = true;
        }
    } else {
        $results['tomorrow'] = false;
    }

    return $results;
}

/**
 * Finds all available price files and returns sorted dates.
 */
function findAllAvailableDates() {
    $dates = [];

    if (!is_dir(PRICE_DIR)) {
        return $dates;
    }

    $yearMonthDirs = glob(PRICE_DIR . '/*', GLOB_ONLYDIR);
    foreach ($yearMonthDirs as $dir) {
        $files = glob($dir . '/price*.json');
        foreach ($files as $file) {
            $filename = basename($file);
            if (preg_match('/price(\d{8})\.json$/', $filename, $matches)) {
                $dates[] = $matches[1];
            }
        }
    }

    rsort($dates);
    return $dates;
}

/**
 * Returns the last two available dates of price data (same as v2).
 */
function getLastTwoAvailableDates() {
    $availableDates = findAllAvailableDates();
    $currentDate = date('Ymd');
    $tomorrowDate = date('Ymd', strtotime('+1 day'));
    $yesterdayDate = date('Ymd', strtotime('-1 day'));

    $result = [
        'today' => null,
        'tomorrow' => null,
        'dates' => [
            'today' => null,
            'tomorrow' => null
        ]
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

/**
 * Loads price data from file.
 */
function loadPriceData($dateStr) {
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
 * Main function: load config, check and update prices, return last two available dates.
 */
function getPriceData() {
    $config = loadConfig();
    if (!$config) {
        return [
            'error' => 'Failed to load configuration',
            'today' => null,
            'tomorrow' => null,
            'dates' => ['today' => null, 'tomorrow' => null]
        ];
    }

    $updateResults = checkAndUpdatePrices($config);
    $priceData = getLastTwoAvailableDates();
    $priceData['updateResults'] = $updateResults;

    return $priceData;
}

/**
 * Checks if the script is running from CLI.
 */
function isRunningInCLI() {
    return php_sapi_name() === 'cli' || php_sapi_name() === 'phpdbg';
}

/**
 * Sends JSON response with proper headers.
 */
function sendJsonResponse($data) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// Handle API endpoint request
if (!isRunningInCLI()) {
    $result = getPriceData();
    sendJsonResponse($result);
    exit;
}

// Handle CLI execution
if (isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) === realpath(__FILE__)) {
    echo "🔌 Electricity Price Fetcher v3 (jeroen.nl / alta)\n";
    echo str_repeat("=", 50) . "\n";

    $result = getPriceData();

    echo "\n📊 Update Results:\n";
    echo "  Today: " . ($result['updateResults']['today'] ? '✅' : '❌') . "\n";
    echo "  Tomorrow: " . ($result['updateResults']['tomorrow'] ? '✅' : '❌') . "\n";

    echo "\n📅 Available Data:\n";
    if ($result['dates']['today']) {
        echo "  Today: " . $result['dates']['today'] . " ✅\n";
    } else {
        echo "  Today: Not available\n";
    }

    if ($result['dates']['tomorrow']) {
        echo "  Tomorrow: " . $result['dates']['tomorrow'] . " ✅\n";
    } else {
        echo "  Tomorrow: Not available\n";
    }
}
