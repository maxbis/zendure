<?php
/**
 * Zendure SolarFlow Status Viewer - Configuration Loader
 * Loads configuration from JSON file for cross-language compatibility
 */

// Path to JSON config file (relative to this file)
$configJsonPath = __DIR__ . '/../../zendure/config/config.json';

// Read and decode JSON config
if (!file_exists($configJsonPath)) {
    throw new Exception("Configuration file not found: {$configJsonPath}");
}

$jsonContent = file_get_contents($configJsonPath);
if ($jsonContent === false) {
    throw new Exception("Failed to read configuration file: {$configJsonPath}");
}

$config = json_decode($jsonContent, true);
if ($config === null && json_last_error() !== JSON_ERROR_NONE) {
    throw new Exception("Invalid JSON in configuration file: " . json_last_error_msg());
}

// Resolve data directory path (handle relative paths like "../data/")
$dataDirPath = $config['dataDir'] ?? '../data/';
if (strpos($dataDirPath, '../') === 0) {
    // Relative path - resolve from Energy root
    // __DIR__ is status/includes/, dirname(dirname(__DIR__)) is Energy/
    $baseDir = dirname(dirname(__DIR__));
    $dataDir = $baseDir . '/' . trim(str_replace('../', '', $dataDirPath), '/');
} else {
    // Absolute or relative to current directory
    $dataDir = $dataDirPath;
}

// Ensure trailing slash
$dataDir = rtrim($dataDir, '/') . '/';

// Construct full data file path
$config['dataDir'] = $dataDir;
$config['dataFile'] = $dataDir . 'zendure_data.json';

// Return the config array (same format as original config.php)
return $config;

