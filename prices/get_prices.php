<?php
/**
 * Electricity Price Fetcher - PHP Version
 * 
 * Fetches electricity prices from API endpoints and saves them to JSON files.
 * Can be used as a CLI script or as a library.
 * 
 * CLI Usage:
 *   php get_prices.php
 * 
 * Library Usage:
 *   require_once 'get_prices.php';
 *   $result = fetchPrices(); // Returns ['today' => true/false, 'tomorrow' => true/false]
 */

// Configuration
define('CONFIG_FILE', __DIR__ . '/../config/price_urls.txt');
define('DATA_DIR', __DIR__ . '/../data');

/**
 * Loads API URLs from config file.
 * Finds the first two non-comment lines (first for today, second for tomorrow).
 * 
 * @return array Array with 'urlToday' and 'urlTomorrow', or null on error
 */
function loadUrlsFromConfig() {
    if (!file_exists(CONFIG_FILE)) {
        error_log("ERROR: Config file " . CONFIG_FILE . " not found");
        return null;
    }
    
    $lines = file(CONFIG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    // Filter out comment lines (lines starting with #)
    $nonCommentLines = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (!empty($trimmed) && strpos($trimmed, '#') !== 0) {
            $nonCommentLines[] = $trimmed;
        }
    }
    
    if (count($nonCommentLines) < 2) {
        error_log("ERROR: Config file " . CONFIG_FILE . " must have at least 2 non-comment lines");
        return null;
    }
    
    // First non-comment line is today's URL, second is tomorrow's URL
    $urlToday = $nonCommentLines[0];
    $urlTomorrow = $nonCommentLines[1];
    
    if (empty($urlToday) || empty($urlTomorrow)) {
        error_log("ERROR: URLs in config file " . CONFIG_FILE . " cannot be empty");
        return null;
    }
    
    return [
        'urlToday' => $urlToday,
        'urlTomorrow' => $urlTomorrow
    ];
}

/**
 * Fetches price data from API endpoint.
 * 
 * @param string $url API endpoint URL
 * @return array|null JSON response data or null on error
 */
function fetchPrices($url) {
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
function getDateFromData($data) {
    if (!$data || !isset($data['data']) || !is_array($data['data']) || empty($data['data'])) {
        return null;
    }
    
    try {
        // Get first entry's datum field
        $firstEntry = $data['data'][0];
        
        if (!isset($firstEntry['datum'])) {
            return null;
        }
        
        $datum = $firstEntry['datum'];
        
        if (empty($datum)) {
            return null;
        }
        
        // Parse ISO datetime string (e.g., "2025-12-20T00:00:00+01:00")
        // PHP's DateTime can handle ISO format directly
        $dt = new DateTime($datum);
        
        // Format as yyyymmdd
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
function extractPrijsne($data) {
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
            
            // Extract hour from datetime string
            $dt = new DateTime($datum);
            $hour = $dt->format('H');
            
            // Store price for this hour
            $prices[$hour] = (float)$prijsne;
        }
        
        return !empty($prices) ? $prices : null;
    } catch (Exception $e) {
        error_log("❌ Error extracting prices: " . $e->getMessage());
        return null;
    }
}

/**
 * Generates filename for price data.
 * 
 * @param string $dateStr Date string in format yyyymmdd
 * @return string Full file path
 */
function getFilename($dateStr) {
    $dataDir = rtrim(DATA_DIR, '/');
    return $dataDir . '/price' . $dateStr . '.json';
}

/**
 * Checks if price file already exists.
 * 
 * @param string $dateStr Date string in format yyyymmdd
 * @return bool True if file exists, False otherwise
 */
function fileExists($dateStr) {
    $filename = getFilename($dateStr);
    return file_exists($filename);
}

/**
 * Saves price data to JSON file.
 * 
 * @param string $dateStr Date string in format yyyymmdd
 * @param array $prices Dictionary with hour keys and price values
 * @return bool True if successful, False otherwise
 */
function savePrices($dateStr, $prices) {
    // Ensure data directory exists
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0755, true)) {
            error_log("❌ Error creating data directory: " . DATA_DIR);
            return false;
        }
    }
    
    $filename = getFilename($dateStr);
    
    $jsonContent = json_encode($prices, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    if ($jsonContent === false) {
        error_log("❌ Error encoding JSON for file: $filename");
        return false;
    }
    
    $result = file_put_contents($filename, $jsonContent, LOCK_EX);
    
    if ($result === false) {
        error_log("❌ Error saving file: $filename");
        return false;
    }
    
    if (isRunningInCLI()) {
        echo "✅ Saved prices to $filename\n";
    }
    
    return true;
}

/**
 * Checks if current hour >= 1:00 (local time).
 * 
 * @return bool True if current hour >= 1:00, False otherwise
 */
function shouldFetchTomorrow() {
    $currentHour = (int)date('G'); // 24-hour format without leading zeros (0-23)
    return $currentHour >= 1;
}

/**
 * Fetches prices from URL and saves to file if not already exists.
 * 
 * @param string $url API endpoint URL
 * @param string $dateLabel Label for logging (e.g., "today", "tomorrow")
 * @return bool True if successful, False otherwise
 */
function fetchAndSavePrices($url, $dateLabel) {
    if (isRunningInCLI()) {
        echo "\n📊 Fetching $dateLabel prices...\n";
    }
    
    // Fetch data from API
    $data = fetchPrices($url);
    if (!$data) {
        return false;
    }
    
    // Extract date
    $dateStr = getDateFromData($data);
    if (!$dateStr) {
        if (isRunningInCLI()) {
            echo "❌ Could not extract date from $dateLabel data\n";
        } else {
            error_log("❌ Could not extract date from $dateLabel data");
        }
        return false;
    }
    
    // Check if file already exists
    if (fileExists($dateStr)) {
        if (isRunningInCLI()) {
            echo "ℹ️  File for $dateLabel ($dateStr) already exists, skipping API call\n";
        }
        return true;
    }
    
    // Extract prices
    $prices = extractPrijsne($data);
    if (!$prices) {
        if (isRunningInCLI()) {
            echo "❌ Could not extract prices from $dateLabel data\n";
        } else {
            error_log("❌ Could not extract prices from $dateLabel data");
        }
        return false;
    }
    
    // Save to file
    return savePrices($dateStr, $prices);
}

/**
 * Main function to orchestrate fetching today's and tomorrow's prices.
 * Can be used as a library function or called from CLI.
 * 
 * @return array Array with 'today' and 'tomorrow' keys indicating success (true/false)
 */
function fetchPricesMain() {
    // Load URLs from config
    $urls = loadUrlsFromConfig();
    if (!$urls) {
        if (isRunningInCLI()) {
            echo "ERROR: Failed to load URLs from " . CONFIG_FILE . "\n";
        } else {
            error_log("ERROR: Failed to load URLs from " . CONFIG_FILE);
        }
        return ['today' => false, 'tomorrow' => false];
    }
    
    if (isRunningInCLI()) {
        echo "🔌 Electricity Price Fetcher\n";
        echo str_repeat("=", 50) . "\n";
    }
    
    $results = [];
    
    // Always fetch today's prices if file doesn't exist
    $results['today'] = fetchAndSavePrices($urls['urlToday'], 'today');
    
    // Fetch tomorrow's prices only if:
    // 1. Current hour >= 1:00 (local time)
    // 2. Tomorrow's price file doesn't exist
    if (shouldFetchTomorrow()) {
        $tomorrowDate = date('Ymd', strtotime('+1 day'));
        
        if (!fileExists($tomorrowDate)) {
            $results['tomorrow'] = fetchAndSavePrices($urls['urlTomorrow'], 'tomorrow');
        } else {
            if (isRunningInCLI()) {
                echo "\nℹ️  Tomorrow's prices ($tomorrowDate) already exist, skipping\n";
            }
            $results['tomorrow'] = true; // File exists, so we consider it successful
        }
    } else {
        $currentHour = date('H');
        if (isRunningInCLI()) {
            echo "\nℹ️  Current hour is {$currentHour}:00, skipping tomorrow's prices (only fetch after 01:00)\n";
        }
        $results['tomorrow'] = false; // Not fetched because it's too early
    }
    
    return $results;
}

/**
 * Checks if the script is running from CLI.
 * 
 * @return bool True if running from CLI, False otherwise
 */
function isRunningInCLI() {
    return php_sapi_name() === 'cli' || php_sapi_name() === 'phpdbg';
}

// Run main function if executed directly from CLI (not when included as library)
// Check if script is run directly by comparing file paths
if (isRunningInCLI()) {
    $scriptFile = isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : 
                  (isset($_SERVER['argv'][0]) ? $_SERVER['argv'][0] : '');
    if (realpath($scriptFile) === realpath(__FILE__)) {
        fetchPricesMain();
    }
}

