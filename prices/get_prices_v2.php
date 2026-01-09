<?php
/**
 * Electricity Price Fetcher v2 - Self-Contained
 * 
 * Features:
 * - Reads API endpoints from config/config.json
 * - Stores prices in organized structure: data/price/YYYYMM/priceYYYYMMDD.json
 * - Smart update logic: only fetches when needed
 * - Returns last two available dates of price data
 * 
 * Usage as library:
 *   require_once 'get_prices2.php';
 *   $result = getPriceData(); // Returns JSON with last two days
 * 
 * Usage as API endpoint:
 *   GET prices/get_prices2.php
 *   Returns JSON: {"today": {...}, "tomorrow": {...}, "dates": {"today": "20260108", "tomorrow": "20260109"}}
 */

// Configuration
define('CONFIG_FILE', __DIR__ . '/../config/config.json');
define('DATA_BASE_DIR', __DIR__ . '/../data');
define('PRICE_DIR', DATA_BASE_DIR . '/price');

/**
 * Loads configuration from config.json
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
    
    if (!isset($config['priceUrls']['today']) || !isset($config['priceUrls']['tomorrow'])) {
        error_log("ERROR: priceUrls.today or priceUrls.tomorrow not found in config");
        return null;
    }
    
    $tomorrowFetchHour = isset($config['tomorrowFetchHour']) ? (int)$config['tomorrowFetchHour'] : 15;
    
    return [
        'urlToday' => $config['priceUrls']['today'],
        'urlTomorrow' => $config['priceUrls']['tomorrow'],
        'tomorrowFetchHour' => $tomorrowFetchHour
    ];
}

/**
 * Fetches price data from API endpoint.
 * 
 * @param string $url API endpoint URL
 * @return array|null JSON response data or null on error
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
        error_log("❌ Error parsing JSON response: " . json_last_error_msg());
        return null;
    }
    
    if (!isset($data['status']) || $data['status'] !== 'true') {
        $status = isset($data['status']) ? $data['status'] : 'unknown';
        error_log("❌ API returned status: $status");
        return null;
    }
    
    return $data;
}

/**
 * Extracts date from API response to determine filename.
 * 
 * @param array $data API response JSON data
 * @return string|null Date string in format yyyymmdd or null
 */
function extractDateFromApiData($data) {
    if (!$data || !isset($data['data']) || !is_array($data['data']) || empty($data['data'])) {
        return null;
    }
    
    try {
        $firstEntry = $data['data'][0];
        
        if (!isset($firstEntry['datum'])) {
            return null;
        }
        
        $datum = $firstEntry['datum'];
        
        if (empty($datum)) {
            return null;
        }
        
        $dt = new DateTime($datum);
        return $dt->format('Ymd');
    } catch (Exception $e) {
        error_log("❌ Error extracting date from data: " . $e->getMessage());
        return null;
    }
}

/**
 * Extracts prijsNE values and organizes by hour.
 * 
 * @param array $data API response JSON data
 * @return array|null Dictionary with hour keys (00-23) and price values, or null on error
 */
function extractPricesFromApiData($data) {
    if (!$data || !isset($data['data']) || !is_array($data['data'])) {
        return null;
    }
    
    $prices = [];
    
    try {
        foreach ($data['data'] as $entry) {
            if (!isset($entry['datum']) || !isset($entry['prijsNE'])) {
                continue;
            }
            
            $datum = $entry['datum'];
            $prijsne = $entry['prijsNE'];
            
            if (empty($datum) || $prijsne === null) {
                continue;
            }
            
            $dt = new DateTime($datum);
            $hour = $dt->format('H');
            $prices[$hour] = (float)$prijsne;
        }
        
        return !empty($prices) ? $prices : null;
    } catch (Exception $e) {
        error_log("❌ Error extracting prices: " . $e->getMessage());
        return null;
    }
}

/**
 * Gets the directory path for a given date (YYYYMMDD format).
 * 
 * @param string $dateStr Date string in format yyyymmdd
 * @return string Directory path (e.g., data/price/202601/)
 */
function getPriceDirectory($dateStr) {
    if (strlen($dateStr) !== 8) {
        return null;
    }
    
    $yearMonth = substr($dateStr, 0, 6); // YYYYMM
    return PRICE_DIR . '/' . $yearMonth;
}

/**
 * Gets the full file path for a given date.
 * 
 * @param string $dateStr Date string in format yyyymmdd
 * @return string Full file path
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
 * 
 * @param string $dateStr Date string in format yyyymmdd
 * @return bool True if file exists, False otherwise
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
 * 
 * @param string $dateStr Date string in format yyyymmdd
 * @param array $prices Dictionary with hour keys and price values
 * @return bool True if successful, False otherwise
 */
function savePriceData($dateStr, $prices) {
    $dir = getPriceDirectory($dateStr);
    if (!$dir) {
        error_log("❌ Invalid date format: $dateStr");
        return false;
    }
    
    // Create directory if it doesn't exist
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
 * Loads price data from file.
 * 
 * @param string $dateStr Date string in format yyyymmdd
 * @return array|null Price data array or null on error
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
 * Fetches and saves prices for a specific date.
 * 
 * @param string $url API endpoint URL
 * @param string $dateLabel Label for logging (e.g., "today", "tomorrow")
 * @return bool True if successful, False otherwise
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
    
    // Check if file already exists
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
 * Checks if updates are needed and performs them.
 * 
 * @param array $config Configuration array
 * @return array Array with 'today' and 'tomorrow' keys indicating success (true/false)
 */
function checkAndUpdatePrices($config) {
    $results = ['today' => false, 'tomorrow' => false];
    $currentDate = date('Ymd');
    $currentHour = (int)date('H');
    $tomorrowDate = date('Ymd', strtotime('+1 day'));
    
    // Update today if not present
    if (!priceFileExists($currentDate)) {
        $results['today'] = fetchAndSavePrices($config['urlToday'], 'today');
    } else {
        $results['today'] = true; // Already exists
    }
    
    // Update tomorrow only if:
    // 1. Current hour >= tomorrowFetchHour (default 15:00)
    // 2. Tomorrow's file doesn't exist
    if ($currentHour >= $config['tomorrowFetchHour']) {
        if (!priceFileExists($tomorrowDate)) {
            $results['tomorrow'] = fetchAndSavePrices($config['urlTomorrow'], 'tomorrow');
        } else {
            $results['tomorrow'] = true; // Already exists
        }
    } else {
        $results['tomorrow'] = false; // Too early to fetch tomorrow
    }
    
    return $results;
}

/**
 * Finds all available price files and returns sorted dates.
 * 
 * @return array Array of date strings (YYYYMMDD format) sorted descending
 */
function findAllAvailableDates() {
    $dates = [];
    
    if (!is_dir(PRICE_DIR)) {
        return $dates;
    }
    
    // Scan all year/month directories
    $yearMonthDirs = glob(PRICE_DIR . '/*', GLOB_ONLYDIR);
    
    foreach ($yearMonthDirs as $dir) {
        $files = glob($dir . '/price*.json');
        foreach ($files as $file) {
            $filename = basename($file);
            // Extract date from filename: priceYYYYMMDD.json
            if (preg_match('/price(\d{8})\.json$/', $filename, $matches)) {
                $dates[] = $matches[1];
            }
        }
    }
    
    // Sort descending (newest first)
    rsort($dates);
    
    return $dates;
}

/**
 * Gets the last two available dates of price data.
 * Returns:
 * 1. yesterday and tomorrow (if today not available)
 * 2. today and tomorrow (if both available)
 * 3. today and null (if tomorrow not available)
 * 
 * @return array Array with 'today', 'tomorrow', and 'dates' keys
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
    
    // Case 1: If today exists, use it; otherwise use yesterday
    if (in_array($currentDate, $availableDates)) {
        $result['today'] = loadPriceData($currentDate);
        $result['dates']['today'] = $currentDate;
    } elseif (in_array($yesterdayDate, $availableDates)) {
        // Today not available, use yesterday as "today"
        $result['today'] = loadPriceData($yesterdayDate);
        $result['dates']['today'] = $yesterdayDate;
    }
    
    // Case 2 & 3: Try to get tomorrow
    if (in_array($tomorrowDate, $availableDates)) {
        $result['tomorrow'] = loadPriceData($tomorrowDate);
        $result['dates']['tomorrow'] = $tomorrowDate;
    }
    // If tomorrow not available, it remains null (Case 3)
    
    return $result;
}

/**
 * Main function: checks for updates, performs them if needed, and returns price data.
 * 
 * @return array Array with 'today', 'tomorrow', 'dates', and 'updateResults' keys
 */
function getPriceData() {
    // Load configuration
    $config = loadConfig();
    if (!$config) {
        return [
            'error' => 'Failed to load configuration',
            'today' => null,
            'tomorrow' => null,
            'dates' => ['today' => null, 'tomorrow' => null]
        ];
    }
    
    // Check and update prices if needed
    $updateResults = checkAndUpdatePrices($config);
    
    // Get last two available dates
    $priceData = getLastTwoAvailableDates();
    $priceData['updateResults'] = $updateResults;
    
    return $priceData;
}

/**
 * Checks if the script is running from CLI.
 * 
 * @return bool True if running from CLI, False otherwise
 */
function isRunningInCLI() {
    return php_sapi_name() === 'cli' || php_sapi_name() === 'phpdbg';
}

/**
 * Sends JSON response with proper headers.
 * 
 * @param array $data Data to send as JSON
 */
function sendJsonResponse($data) {
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// Handle API endpoint request
if (!isRunningInCLI()) {
    // Running as web request - return JSON API response
    $result = getPriceData();
    sendJsonResponse($result);
    exit;
}

// Handle CLI execution
if (isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) === realpath(__FILE__)) {
    // Running from CLI directly
    echo "🔌 Electricity Price Fetcher v2\n";
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
