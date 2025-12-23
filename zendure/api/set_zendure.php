<?php
/**
 * Zendure SolarFlow Power Control - PHP Version
 * Controls charge/discharge power of the Zendure device
 * 
 * Usage:
 *   Web: set_zendure.php?watts=400 (charge 400W)
 *        set_zendure.php?watts=-400 (discharge 400W)
 *        set_zendure.php?watts=0 (stop all)
 *   CLI: php set_zendure.php 400
 */

require_once __DIR__ . '/../includes/config_loader.php';

class ZendurePowerControl {
    private $ip;
    private $sn;
    private $writeUrl;
    
    /**
     * Initialize the Zendure Power Control
     * 
     * @param string $ipAddress IP address of the Zendure device
     * @param string $sn Serial number of the device
     */
    public function __construct($ipAddress, $sn) {
        $this->ip = $ipAddress;
        $this->sn = $sn;
        $this->writeUrl = "http://{$ipAddress}/properties/write";
    }
    
    /**
     * Internal helper to send the POST request to the device
     * 
     * @param array $properties Properties to set
     * @return array|false Response JSON array or false on error
     */
    private function sendCommand($properties) {
        $payload = [
            'sn' => $this->sn,
            'properties' => $properties
        ];
        
        try {
            $ch = curl_init($this->writeUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            
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
            
            return $data;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Set charge/discharge power
     * 
     * @param int $watts Power in watts
     *   - Positive: Charge from grid (acMode: 1, inputLimit: watts, outputLimit: 0)
     *   - Negative: Discharge to home (acMode: 2, outputLimit: abs(watts), inputLimit: 0)
     *   - Zero: Stop all charging and discharging (inputLimit: 0, outputLimit: 0)
     * 
     * @return array|false Response JSON array or false on error
     */
    public function setPower($watts) {
        if ($watts > 0) {
            // Charge mode: acMode 1 = Input
            return $this->sendCommand([
                'acMode' => 1,
                'inputLimit' => $watts,
                'outputLimit' => 0,
                'smartMode' => 1
            ]);
        } elseif ($watts < 0) {
            // Discharge mode: acMode 2 = Output
            $dischargeWatts = abs($watts);
            return $this->sendCommand([
                'acMode' => 2,
                'outputLimit' => $dischargeWatts,
                'inputLimit' => 0,
                'smartMode' => 1
            ]);
        } else {
            // Stop all
            return $this->sendCommand([
                'inputLimit' => 0,
                'outputLimit' => 0,
                'smartMode' => 1
            ]);
        }
    }
}

// Web usage - return JSON response
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    
    // Get watts parameter from GET or POST
    $watts = isset($_GET['watts']) ? $_GET['watts'] : (isset($_POST['watts']) ? $_POST['watts'] : null);
    
    if ($watts === null) {
        echo json_encode([
            'success' => false,
            'error' => 'Missing watts parameter'
        ]);
        exit(1);
    }
    
    try {
        $watts = intval($watts);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid watts value: must be an integer'
        ]);
        exit(1);
    }
    
    // Load config
    $config = require __DIR__ . '/../includes/config_loader.php';
    
    // Create controller and set power
    $controller = new ZendurePowerControl($config['deviceIp'], $config['deviceSn']);
    $result = $controller->setPower($watts);
    
    if ($result !== false) {
        echo json_encode([
            'success' => true,
            'watts' => $watts,
            'response' => $result
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to send command to device'
        ]);
    }
    exit(0);
}

// CLI usage
if (php_sapi_name() === 'cli') {
    // Load config
    $config = require __DIR__ . '/../includes/config_loader.php';
    
    // Get watts from command line argument
    if (!isset($argv[1])) {
        echo "Usage: php set_zendure.php <watts>\n";
        echo "  watts: Positive for charge, negative for discharge, 0 to stop\n";
        echo "  Examples:\n";
        echo "    php set_zendure.php 400      # Charge at 400W\n";
        echo "    php set_zendure.php -400     # Discharge at 400W\n";
        echo "    php set_zendure.php 0        # Stop all\n";
        exit(1);
    }
    
    try {
        $watts = intval($argv[1]);
    } catch (Exception $e) {
        echo "❌ Error: '{$argv[1]}' is not a valid integer\n";
        exit(1);
    }
    
    // Create controller and set power
    $controller = new ZendurePowerControl($config['deviceIp'], $config['deviceSn']);
    $result = $controller->setPower($watts);
    
    if ($result !== false) {
        echo "✅ Success: Set power to {$watts}W\n";
        print_r($result);
    } else {
        echo "❌ Failed to send command to device\n";
        exit(1);
    }
}
?>
