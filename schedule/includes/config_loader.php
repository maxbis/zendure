<?php
/**
 * Centralized Configuration Loader
 * Provides a single source of truth for configuration management
 * 
 * Supports dot notation for nested keys (e.g., 'priceUrls.get_price')
 * Automatically handles fallback paths and location-based configuration
 */
class ConfigLoader {
    private static $config = null;
    private static $configPath = null;
    
    /**
     * Get a configuration value
     * @param string $key Configuration key (supports dot notation for nested keys)
     * @param mixed $default Default value if key not found
     * @return mixed Configuration value or default
     * 
     * @example
     * ConfigLoader::get('scheduleApiUrl', 'api/charge_schedule_api.php');
     * ConfigLoader::get('priceUrls.get_price');
     * ConfigLoader::get('location', 'remote');
     */
    public static function get($key, $default = null) {
        if (self::$config === null) {
            self::$config = self::load();
        }
        
        // Support dot notation for nested keys
        $keys = explode('.', $key);
        $value = self::$config;
        
        foreach ($keys as $k) {
            if (!is_array($value) || !isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value ?? $default;
    }
    
    /**
     * Get configuration with location-based fallback
     * For keys that have -local variants, automatically selects based on location setting
     * @param string $key Base configuration key
     * @param mixed $default Default value if key not found
     * @return mixed Configuration value or default
     * 
     * @example
     * ConfigLoader::getWithLocation('dataApiUrl'); // Returns dataApiUrl-local if location='local', else dataApiUrl
     */
    public static function getWithLocation($key, $default = null) {
        $location = self::get('location', 'remote');
        $localKey = $key . '-local';
        
        if ($location === 'local' && self::has($localKey)) {
            return self::get($localKey, $default);
        }
        
        return self::get($key, $default);
    }
    
    /**
     * Check if a configuration key exists
     * @param string $key Configuration key (supports dot notation)
     * @return bool True if key exists
     */
    public static function has($key) {
        if (self::$config === null) {
            self::$config = self::load();
        }
        
        $keys = explode('.', $key);
        $value = self::$config;
        
        foreach ($keys as $k) {
            if (!is_array($value) || !isset($value[$k])) {
                return false;
            }
            $value = $value[$k];
        }
        
        return true;
    }
    
    /**
     * Get all configuration
     * @return array Complete configuration array
     */
    public static function all() {
        if (self::$config === null) {
            self::$config = self::load();
        }
        return self::$config;
    }
    
    /**
     * Get the path of the loaded config file
     * @return string|null Config file path or null if not loaded
     */
    public static function getConfigPath() {
        return self::$configPath;
    }
    
    /**
     * Load configuration from files
     * Tries multiple paths in order of priority
     * @return array Configuration array
     */
    private static function load() {
        // Define config paths in order of priority
        $paths = [
            __DIR__ . '/../../config/config.json',
            __DIR__ . '/../../run_schedule/config/config.json'
        ];
        
        foreach ($paths as $path) {
            if (file_exists($path)) {
                $json = @file_get_contents($path);
                if ($json !== false) {
                    $config = json_decode($json, true);
                    if ($config !== null && is_array($config)) {
                        self::$configPath = $path;
                        return $config;
                    }
                }
            }
        }
        
        // Return empty array if no config found
        return [];
    }
    
    /**
     * Reset configuration (useful for testing)
     */
    public static function reset() {
        self::$config = null;
        self::$configPath = null;
    }
}
