<?php
/**
 * Zendure SolarFlow Status Viewer - Configuration
 * Central configuration file for paths and settings
 */

// Data directory path (relative to this file)
$config = [
    'dataDir' => '../data/',
    'dataFile' => null, // Will be set below
    'deviceIp' => '192.168.2.93', // IP address of the Zendure device
    'deviceSn' => 'HOA1NAN9N385989', // Serial number of the Zendure device (required for write/control operations)
    'p1MeterIp' => '192.168.2.94', // IP address of the P1 meter device
    'p1DeviceId' => 'ZEE1NBN9N420947', // P1 meter device ID for MQTT filtering
    // MQTT Configuration (for zendure_mqtt_p1.py)
    // Note: Python script uses environment variables by default for security
    // These values are for reference - set ZENDURE_APP_KEY, ZENDURE_APP_SECRET as env vars
    'mqtt' => [
        'broker' => 'mqtt-eu.zen-iot.com', // Use "mqtt-eu.zen-iot.com" for Europe or "mqtt.zen-iot.com" for Global
        'port' => 1883,
        'appKey' => 'aHR0cHM6Ly9hcHAuemVuZHVyZS50ZWNoL2V1LjYydWgwQzIwdg', // From Zendure Developer API
        'appSecret' => 'ZEE1NBN9N420947', // From Zendure Developer API
    ],
];

// Construct full data file path
$config['dataFile'] = $config['dataDir'] . 'zendure_data.json';

// Return the config array
return $config;
