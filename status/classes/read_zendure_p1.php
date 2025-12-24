<?php
/**
 * Zendure P1 Meter Reader - PHP Version
 * Fetches grid power readings from Zendure P1 Meter and saves to JSON file
 * 
 * Usage:
 *   php read_zendure_p1.php [ip_address]
 *   or use as a class in other scripts
 */

require_once __DIR__ . '/../includes/config_loader.php';

class ZendureP1Meter {
    private $url;
    private $ip;
    private $dataDir;
    private $dataFile;
    
    // Meter properties
    private $deviceId;
    private $totalPower;
    private $phaseA;
    private $phaseB;
    private $phaseC;
    private $timestamp;
    
    /**
     * Initialize the ZendureP1Meter reader
     * 
     * @param string $ipAddress IP address of the P1 meter device
     */
    public function __construct($ipAddress) {
        $this->ip = $ipAddress;
        $this->url = "http://{$ipAddress}/properties/report";
        
        // Load config to get data directory
        $config = require __DIR__ . '/../includes/config_loader.php';
        // Resolve relative path (config uses '../data/' relative to zendure folder)
        // Since we're in zendure/classes/, we need to go up to root (dirname(dirname(__DIR__)))
        $dataDirPath = $config['dataDir'];
        if (strpos($dataDirPath, '../') === 0) {
            // Relative path - resolve from root directory (go up two levels from zendure/classes/)
            $rootDir = dirname(dirname(__DIR__));
            $this->dataDir = $rootDir . '/' . trim(str_replace('../', '', $dataDirPath), '/');
        } else {
            // Absolute or relative to current directory
            $this->dataDir = $dataDirPath;
        }
        $this->dataFile = rtrim($this->dataDir, '/') . '/zendure_p1_data.json';
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
     * Fetch the latest grid readings from the Zendure P1 Meter
     * 
     * @param bool $verbose If true, print detailed output
     * @return array|false Returns data array on success, false on failure
     */
    public function update($verbose = true) {
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
            
            // Map the exact keys from the response
            $this->deviceId = $data['deviceId'] ?? null;
            $this->totalPower = $data['total_power'] ?? null;
            $this->phaseA = $data['a_aprt_power'] ?? null;
            $this->phaseB = $data['b_aprt_power'] ?? null;
            $this->phaseC = $data['c_aprt_power'] ?? null;
            $this->timestamp = $data['timestamp'] ?? null;
            
            if ($verbose) {
                $this->printReadings();
            }
            
            // Prepare reading data with timestamp
            $readingData = [
                'timestamp' => date('c'), // ISO 8601 format
                'deviceId' => $this->deviceId,
                'total_power' => $this->totalPower,
                'a_aprt_power' => $this->phaseA,
                'b_aprt_power' => $this->phaseB,
                'c_aprt_power' => $this->phaseC,
                'meter_timestamp' => $this->timestamp
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
                echo "Error connecting to Zendure P1 at {$this->ip}: {$e->getMessage()}\n";
            }
            return false;
        }
    }
    
    /**
     * Prints the grid power data in a readable format
     */
    private function printReadings() {
        echo "API URL: {$this->url}\n\n";
        
        // Detect if you are importing or exporting based on the total_power sign
        $status = ($this->totalPower < 0) ? "EXPORTING ☀️" : "IMPORTING ⚡";
        
        echo "\n--- Zendure P1 Grid Report [{$this->deviceId}] ---\n";
        echo "Status:        {$status}\n";
        echo "Total Power:   {$this->totalPower} W\n";
        echo "Phase L1:      {$this->phaseA} W\n";
        echo "Phase L2:      {$this->phaseB} W\n";
        echo "Phase L3:      {$this->phaseC} W\n";
        echo "Timestamp:     {$this->timestamp}\n";
        echo "---------------------------------------------\n";
    }
    
    /**
     * Get the calculated status based on total_power
     * 
     * @return string "EXPORTING ☀️" if total_power < 0, else "IMPORTING ⚡"
     */
    public function getStatus() {
        return ($this->totalPower < 0) ? "EXPORTING ☀️" : "IMPORTING ⚡";
    }
    
    /**
     * Get total power value
     * 
     * @return int|null Total power in watts
     */
    public function getTotalPower() {
        return $this->totalPower;
    }
}

// CLI usage
if (php_sapi_name() === 'cli') {
    // Load config to get default IP
    $config = require __DIR__ . '/../includes/config_loader.php';
    
    // Get IP address from command line argument or use config default
    $ipAddress = $argv[1] ?? ($config['p1MeterIp'] ?? null);
    
    if (empty($ipAddress)) {
        echo "Error: No IP address specified. Please set 'p1MeterIp' in config.php or provide as command line argument.\n";
        exit(1);
    }
    
    $meter = new ZendureP1Meter($ipAddress);
    $result = $meter->update(true);
    
    exit($result === false ? 1 : 0);
}
?>

