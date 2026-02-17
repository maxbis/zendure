<?php
/**
 * Electricity Price Fetcher v3 - Frank Energie GraphQL
 *
 * Features:
 * - Fetches prices from Frank Energie GraphQL endpoint
 * - Stores prices in: data/price/YYYYMM/priceYYYYMMDD.json
 * - Uses priceIncludingMarkup
 * - Fetches today and tomorrow (when available)
 */

define('CONFIG_FILE', __DIR__ . '/../config/config.json');
define('DATA_BASE_DIR', __DIR__ . '/../data');
define('PRICE_DIR', DATA_BASE_DIR . '/price');
// Primary endpoint observed in recent community examples.
define('FRANK_GRAPHQL_URL', 'https://frank-graphql-prod.graphcdn.app/');
// Legacy endpoint fallback.
define('FRANK_GRAPHQL_URL_FALLBACK', 'https://graphcdn.frankenergie.nl/');
define('ENEVER_SUPPLIER_KEY', 'prijsFR');

/**
 * Loads configuration from config.json.
 * 
 * @return array Configuration values with defaults
 */
function loadConfig() {
    $tomorrowFetchHour = 15;
    $urlToday = null;
    $urlTomorrow = null;

    if (!file_exists(CONFIG_FILE)) {
        return [
            'tomorrowFetchHour' => $tomorrowFetchHour,
            'urlToday' => $urlToday,
            'urlTomorrow' => $urlTomorrow
        ];
    }

    $configContent = file_get_contents(CONFIG_FILE);
    if ($configContent === false) {
        return [
            'tomorrowFetchHour' => $tomorrowFetchHour,
            'urlToday' => $urlToday,
            'urlTomorrow' => $urlTomorrow
        ];
    }

    $config = json_decode($configContent, true);
    if (!is_array($config)) {
        return [
            'tomorrowFetchHour' => $tomorrowFetchHour,
            'urlToday' => $urlToday,
            'urlTomorrow' => $urlTomorrow
        ];
    }

    if (isset($config['tomorrowFetchHour'])) {
        $tomorrowFetchHour = (int)$config['tomorrowFetchHour'];
    }

    if (isset($config['priceUrls']['today'])) {
        $urlToday = $config['priceUrls']['today'];
    }
    if (isset($config['priceUrls']['tomorrow'])) {
        $urlTomorrow = $config['priceUrls']['tomorrow'];
    }

    return [
        'tomorrowFetchHour' => $tomorrowFetchHour,
        'urlToday' => $urlToday,
        'urlTomorrow' => $urlTomorrow
    ];
}

/**
 * Executes a GraphQL query and returns decoded data.
 * 
 * @param string $query GraphQL query string
 * @param array $variables GraphQL variables
 * @return array|null Decoded JSON response or null on error
 */
function isDebugEnabled() {
    return getenv('DEBUG') === '1';
}

function fetchGraphQl($query, $variables = null, $operationName = null) {
    $payloadArray = ['query' => $query];
    if ($variables !== null) {
        $payloadArray['variables'] = $variables;
    }
    if ($operationName !== null) {
        $payloadArray['operationName'] = $operationName;
    }

    $payload = json_encode($payloadArray);

    if ($payload === false) {
        error_log("❌ Error encoding GraphQL payload");
        return null;
    }

    $ch = curl_init(FRANK_GRAPHQL_URL);
    if ($ch === false) {
        error_log("❌ Error initializing cURL for GraphQL");
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: Mozilla/5.0'
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || !empty($curlError)) {
        error_log("❌ GraphQL request failed: $curlError");
        return null;
    }

    if ($httpCode !== 200) {
        // Try legacy endpoint once if primary fails.
        $fallback = fetchGraphQlFallback($payload);
        if ($fallback !== null) {
            return $fallback;
        }
        if (isDebugEnabled()) {
            error_log("❌ GraphQL HTTP $httpCode response: " . trim($response));
        } else {
            error_log("❌ GraphQL returned HTTP status: $httpCode");
        }
        return null;
    }

    $data = json_decode($response, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        error_log("❌ Error parsing GraphQL response: " . json_last_error_msg());
        return null;
    }

    if (isset($data['errors'])) {
        if (isDebugEnabled()) {
            error_log("❌ GraphQL returned errors: " . json_encode($data['errors'], JSON_UNESCAPED_UNICODE));
            error_log("❌ GraphQL full response: " . json_encode($data, JSON_UNESCAPED_UNICODE));
        } else {
            error_log("❌ GraphQL returned errors");
        }
        return null;
    }

    return $data;
}

/**
 * Fallback GraphQL request to legacy endpoint.
 *
 * @param string $payload JSON payload
 * @return array|null
 */
function fetchGraphQlFallback($payload) {
    $ch = curl_init(FRANK_GRAPHQL_URL_FALLBACK);
    if ($ch === false) {
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: Mozilla/5.0'
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || !empty($curlError)) {
        return null;
    }

    if ($httpCode !== 200) {
        if (isDebugEnabled()) {
            error_log("❌ Fallback GraphQL HTTP $httpCode response: " . trim($response));
        }
        return null;
    }

    $data = json_decode($response, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    if (isset($data['errors'])) {
        if (isDebugEnabled()) {
            error_log("❌ Fallback GraphQL returned errors: " . json_encode($data['errors'], JSON_UNESCAPED_UNICODE));
        }
        return null;
    }

    return $data;
}

/**
 * Fetches price entries for a specific date.
 * 
 * @param string $dateYmd Date in YYYY-MM-DD
 * @return array|null List of entries or null
 */
function fetchPricesForDate($dateYmd) {
    $endDateYmd = date('Y-m-d', strtotime($dateYmd . ' +1 day'));

    $variants = [];

    $variants[] = [
        'label' => 'Date variables',
        'query' => <<<GQL
query MarketPrices(\$startDate: Date!, \$endDate: Date!) {
  marketPricesElectricity(startDate: \$startDate, endDate: \$endDate) {
    from
    till
    priceIncludingMarkup
  }
}
GQL,
        'variables' => ['startDate' => $dateYmd, 'endDate' => $endDateYmd],
        'operationName' => 'MarketPrices'
    ];

    $variants[] = [
        'label' => 'String variables',
        'query' => <<<GQL
query MarketPrices(\$startDate: String!, \$endDate: String!) {
  marketPricesElectricity(startDate: \$startDate, endDate: \$endDate) {
    from
    till
    priceIncludingMarkup
  }
}
GQL,
        'variables' => ['startDate' => $dateYmd, 'endDate' => $endDateYmd],
        'operationName' => 'MarketPrices'
    ];

    $startDateIso = $dateYmd . 'T00:00:00+01:00';
    $endDateIso = $endDateYmd . 'T00:00:00+01:00';
    $variants[] = [
        'label' => 'DateTime variables',
        'query' => <<<GQL
query MarketPrices(\$startDate: DateTime!, \$endDate: DateTime!) {
  marketPricesElectricity(startDate: \$startDate, endDate: \$endDate) {
    from
    till
    priceIncludingMarkup
  }
}
GQL,
        'variables' => ['startDate' => $startDateIso, 'endDate' => $endDateIso],
        'operationName' => 'MarketPrices'
    ];

    $variants[] = [
        'label' => 'Inline dates',
        'query' => <<<GQL
query MarketPrices {
  marketPricesElectricity(startDate: "{$dateYmd}", endDate: "{$endDateYmd}") {
    from
    till
    priceIncludingMarkup
  }
}
GQL,
        'variables' => null,
        'operationName' => 'MarketPrices'
    ];

    foreach ($variants as $variant) {
        if (isDebugEnabled()) {
            error_log("ℹ️ Trying GraphQL variant: " . $variant['label']);
        }

        $response = fetchGraphQl($variant['query'], $variant['variables'], $variant['operationName']);
        if (!$response || !isset($response['data']['marketPricesElectricity'])) {
            continue;
        }

        $items = $response['data']['marketPricesElectricity'];
        if (is_array($items) && !empty($items)) {
            return $items;
        }
    }

    return null;
}

/**
 * Fetches prices from Enever API and extracts supplier prices.
 *
 * @param string $url API URL
 * @param string $priceKey Supplier price key (e.g., prijsFR)
 * @return array|null Hourly prices array or null
 */
function fetchPricesFromEnever($url, $priceKey) {
    if (!$url) {
        return null;
    }

    if (strpos($url, 'price=') === false) {
        $separator = (strpos($url, '?') === false) ? '?' : '&';
        $url .= $separator . 'price=' . $priceKey;
    }

    $ch = curl_init($url);
    if ($ch === false) {
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
        return null;
    }

    if ($httpCode !== 200) {
        return null;
    }

    $data = json_decode($response, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    if (!isset($data['status']) || $data['status'] !== 'true') {
        return null;
    }

    if (!isset($data['data']) || !is_array($data['data'])) {
        return null;
    }

    $prices = [];
    try {
        foreach ($data['data'] as $entry) {
            if (!isset($entry['datum']) || !isset($entry[$priceKey])) {
                continue;
            }

            $dt = new DateTime($entry['datum']);
            $hour = $dt->format('H');
            $prices[$hour] = (float)$entry[$priceKey];
        }
    } catch (Exception $e) {
        return null;
    }

    if (count($prices) < 24) {
        return null;
    }

    ksort($prices);
    return $prices;
}

/**
 * Convert interval prices to hourly prices (averaging if 15-min data).
 * 
 * @param array $items GraphQL items
 * @return array|null Hourly prices array with keys "00"-"23"
 */
function normalizeToHourlyPrices($items, $targetDateYmd) {
    $sum = [];
    $count = [];

    try {
        foreach ($items as $entry) {
            if (!isset($entry['from']) || !isset($entry['priceIncludingMarkup'])) {
                continue;
            }

            $from = $entry['from'];
            $price = $entry['priceIncludingMarkup'];

            if ($from === null || $price === null) {
                continue;
            }

            $dt = new DateTime($from);
            if ($dt->format('Y-m-d') !== $targetDateYmd) {
                continue;
            }
            $hour = $dt->format('H');

            if (!isset($sum[$hour])) {
                $sum[$hour] = 0.0;
                $count[$hour] = 0;
            }

            $sum[$hour] += (float)$price;
            $count[$hour] += 1;
        }
    } catch (Exception $e) {
        error_log("❌ Error normalizing prices: " . $e->getMessage());
        return null;
    }

    if (empty($sum)) {
        return null;
    }

    $prices = [];
    foreach ($sum as $hour => $total) {
        $prices[$hour] = $total / max(1, $count[$hour]);
    }

    // Ensure we have a full day (24 hours)
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
 * Fetches and saves prices for a specific date.
 */
function fetchAndSavePricesForDate($dateStr, $label, $config) {
    if (isRunningInCLI()) {
        echo "\n📊 Fetching $label prices...\n";
    }

    if (priceFileExists($dateStr)) {
        if (isRunningInCLI()) {
            echo "ℹ️  File for $label ($dateStr) already exists, skipping\n";
        }
        return true;
    }

    $dt = DateTime::createFromFormat('Ymd', $dateStr);
    if ($dt === false) {
        if (isRunningInCLI()) {
            echo "❌ Invalid date format for $label: $dateStr\n";
        }
        return false;
    }
    $dateYmd = $dt->format('Y-m-d');
    $prices = null;

    $items = fetchPricesForDate($dateYmd);
    if ($items) {
        $prices = normalizeToHourlyPrices($items, $dateYmd);
    }

    if (!$prices) {
        $fallbackUrl = null;
        if ($label === 'today') {
            $fallbackUrl = $config['urlToday'] ?? null;
        } elseif ($label === 'tomorrow') {
            $fallbackUrl = $config['urlTomorrow'] ?? null;
        }

        $prices = fetchPricesFromEnever($fallbackUrl, ENEVER_SUPPLIER_KEY);
    }

    if (!$prices) {
        if (isRunningInCLI()) {
            echo "❌ No data available for $label ($dateStr)\n";
        }
        return false;
    }

    return savePriceData($dateStr, $prices);
}

/**
 * Checks if updates are needed and performs them.
 */
function checkAndUpdatePrices($config) {
    $results = ['today' => false, 'tomorrow' => false];
    $currentDate = date('Ymd');
    $tomorrowDate = date('Ymd', strtotime('+1 day'));
    $currentHour = (int)date('H');

    // Always try today
    $results['today'] = fetchAndSavePricesForDate($currentDate, 'today', $config);

    // Try tomorrow when likely available (or already exists)
    if (priceFileExists($tomorrowDate)) {
        $results['tomorrow'] = true;
    } elseif ($currentHour >= $config['tomorrowFetchHour']) {
        $results['tomorrow'] = fetchAndSavePricesForDate($tomorrowDate, 'tomorrow', $config);
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
 * Returns the last two available dates of price data.
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
 * Main function: checks for updates, performs them if needed, and returns price data.
 */
function getPriceData() {
    $config = loadConfig();
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
    echo "🔌 Electricity Price Fetcher v3 (Frank Energie)\n";
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
