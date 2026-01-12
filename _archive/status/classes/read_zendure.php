<?php
/**
 * Zendure SolarFlow Status Reader - PHP Version
 * Fetches status from Zendure SolarFlow device and saves to JSON file
 * 
 * Usage:
 *   php read_zendure.php [ip_address]
 *   or use as a class in other scripts
 */

require_once __DIR__ . '/../includes/config_loader.php';

class SolarFlow2400 {
    private $url;
    private $ip;
    private $dataDir;
    private $dataFile;
    
    /**
     * Initialize the SolarFlow2400 reader
     * 
     * @param string $ipAddress IP address of the Zendure device
     */
    public function __construct($ipAddress) {
        $this->ip = $ipAddress;
        $this->url = "http://{$ipAddress}/properties/report";
        
        // Load config to get data directory
        $config = require __DIR__ . '/../includes/config_loader.php';
        // Resolve relative path (config uses '../data/' relative to zendure folder)
        $dataDirPath = $config['dataDir'];
        if (strpos($dataDirPath, '../') === 0) {
            // Relative path - resolve from zendure folder (go up one more level from classes)
            // __DIR__ is zendure/classes, dirname(__DIR__) is zendure, dirname(dirname(__DIR__)) is Energy
            $baseDir = dirname(dirname(__DIR__));
            $this->dataDir = $baseDir . '/' . trim(str_replace('../', '', $dataDirPath), '/');
        } else {
            // Absolute or relative to current directory
            $this->dataDir = $dataDirPath;
        }
        $this->dataFile = rtrim($this->dataDir, '/') . '/zendure_data.json';
    }
    
    /**
     * Write JSON data atomically to avoid concurrency issues.
     * Writes to a temporary file first, then atomically renames it.
     * 
     * @param array $data Data to write as JSON
     * @throws Exception If writing fails
     */
    private function writeJsonAtomic($data) {
        // Ensure data directory exists
        if (!is_dir($this->dataDir)) {
            if (!mkdir($this->dataDir, 0755, true)) {
                throw new Exception("Failed to create data directory: {$this->dataDir}");
            }
        }
        
        // Write to temporary file first
        $tempFile = $this->dataFile . '.tmp';
        $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        try {
            if (file_put_contents($tempFile, $jsonContent) === false) {
                throw new Exception("Failed to write temporary file: {$tempFile}");
            }
            
            // Atomically replace the target file with the temp file
            if (!rename($tempFile, $this->dataFile)) {
                throw new Exception("Failed to rename temporary file to target file");
            }
        } catch (Exception $e) {
            // Clean up temp file if something goes wrong
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            throw $e;
        }
    }
    
    /**
     * Fetch status from the Zendure device
     * 
     * @param bool $verbose If true, print detailed output
     * @return array|false Returns data array on success, false on failure
     */
    public function getStatus($verbose = true) {
        try {
            // Use cURL for HTTP request (more reliable than file_get_contents)
            $ch = curl_init($this->url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($response === false || !empty($curlError)) {
                throw new Exception("cURL error: {$curlError}");
            }
            
            if ($httpCode !== 200) {
                throw new Exception("HTTP error: {$httpCode}");
            }
            
            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("JSON decode error: " . json_last_error_msg());
            }
            
            // Extract properties and pack data
            $props = $data['properties'] ?? [];
            $packs = $data['packData'] ?? [];
            
            if ($verbose) {
                echo "API URL: {$this->url}\n\n";
                
                // Print all properties
                foreach ($props as $key => $value) {
                    echo "{$key}: {$value}\n";
                }
                
                echo "\n--- Zendure SolarFlow 2400 AC ({$this->ip}) ---\n";
                echo "System SoC:      " . ($props['electricLevel'] ?? 'N/A') . "%\n";
                echo "Solar Input:     " . ($props['solarInputPower'] ?? 0) . " W\n";
                echo "Home Output:     " . ($props['outputHomePower'] ?? 0) . " W\n";
                
                if (isset($props['BatVolt'])) {
                    echo "Battery Voltage: " . number_format($props['BatVolt'] / 100, 2) . " V\n";
                }
                
                if (isset($props['hyperTmp'])) {
                    echo "Unit Temp:       " . number_format($props['hyperTmp'] / 100, 1) . "°C\n";
                }
                
                echo "Grid Charge:     " . ($props['gridInputPower'] ?? 0) . " W\n";
                echo "Pack Input:      " . ($props['packInputPower'] ?? 0) . " W\n";
                
                echo "\n--- Battery Pack Details ---\n";
                foreach ($packs as $i => $pack) {
                    $packNum = $i + 1;
                    $sn = $pack['sn'] ?? 'N/A';
                    $level = $pack['socLevel'] ?? 'N/A';
                    $temp = isset($pack['maxTemp']) ? number_format($pack['maxTemp'] / 100, 1) : 'N/A';
                    $state = $pack['state'] ?? 'N/A';
                    echo "Pack {$packNum} [{$sn}]:\n";
                    echo "  Level: {$level}% | Temp: {$temp}°C | State: {$state}\n";
                }
                
                echo "--------------------------------------------\n";
            }
            
            // Prepare reading data with timestamp
            $readingData = [
                'timestamp' => date('c'), // ISO 8601 format
                'properties' => $props,
                'packData' => $packs
            ];
            
            // Save to JSON file atomically
            try {
                $this->writeJsonAtomic($readingData);
                if ($verbose) {
                    echo "✅ Reading saved to {$this->dataFile}\n";
                }
            } catch (Exception $e) {
                if ($verbose) {
                    echo "⚠️  Warning: Failed to save reading to file: {$e->getMessage()}\n";
                }
            }
            
            return $readingData;
            
        } catch (Exception $e) {
            if ($verbose) {
                echo "Error fetching data: {$e->getMessage()}\n";
            }
            return false;
        }
    }
}

// CLI usage
if (php_sapi_name() === 'cli') {
    // Load config to get default IP
    $config = require __DIR__ . '/../includes/config_loader.php';
    
    // Get IP address from command line argument or use config default
    $ipAddress = $argv[1] ?? $config['deviceIp'];
    
    if (empty($ipAddress)) {
        echo "Error: No IP address specified. Please set 'deviceIp' in config.php or provide as command line argument.\n";
        exit(1);
    }
    
    $solarflow = new SolarFlow2400($ipAddress);
    $result = $solarflow->getStatus(true);
    
    exit($result === false ? 1 : 0);
}
?>
