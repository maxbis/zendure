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
    private static $loadError = null;

    /**
     * Human-friendly labels for known config file paths.
     * @var array<string, string>
     */
    private static $configLabels = [
        __DIR__ . '/../config/config.json' => 'main config.json',
        __DIR__ . '/../run_schedule/config/config.json' => 'run_schedule config.json'
    ];
    
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
        self::ensureLoaded();
        
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
        self::ensureLoaded();
        
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
        self::ensureLoaded();
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
     * Get a friendly label for the loaded config path.
     * @return string|null
     */
    public static function getConfigLabel() {
        self::ensureLoaded();
        if (self::$configPath === null) {
            return null;
        }

        return self::$configLabels[self::$configPath] ?? basename((string) self::$configPath);
    }

    /**
     * Get the most recent configuration load error, if any.
     * @return string|null Error message or null when config loaded successfully
     */
    public static function getLoadError() {
        self::ensureLoaded();
        return self::$loadError;
    }

    /**
     * Check whether configuration loading failed.
     * @return bool True when an invalid/unreadable config file was detected
     */
    public static function hasLoadError() {
        self::ensureLoaded();
        return self::$loadError !== null;
    }
    
    /**
     * Load configuration from files
     * Tries multiple paths in order of priority
     * @return array Configuration array
     */
    private static function load() {
        self::$loadError = null;

        // Define config paths in order of priority
        $paths = [
            __DIR__ . '/../config/config.json',
            __DIR__ . '/../run_schedule/config/config.json'
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                self::$configPath = $path;

                $json = @file_get_contents($path);
                if ($json === false) {
                    self::$loadError = 'Unable to read ' . self::describeConfigPath($path) . '.';
                    error_log('ConfigLoader: ' . self::$loadError);
                    return [];
                }

                $config = json_decode($json, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    self::$loadError = 'Invalid JSON in ' . self::describeConfigPath($path) . ': ' . json_last_error_msg();
                    error_log('ConfigLoader: ' . self::$loadError);
                    return [];
                }

                if (!is_array($config)) {
                    self::$loadError = self::describeConfigPath($path) . ' must decode to a JSON object.';
                    error_log('ConfigLoader: ' . self::$loadError);
                    return [];
                }

                return $config;
            }
        }

        // Return empty array if no config found
        self::$configPath = null;
        return [];
    }

    /**
     * Initialize config on first use.
     */
    private static function ensureLoaded() {
        if (self::$config === null) {
            self::$config = self::load();
        }
    }

    /**
     * Convert a config path into a user-facing label.
     * @param string $path
     * @return string
     */
    private static function describeConfigPath($path) {
        return self::$configLabels[$path] ?? basename((string) $path);
    }
    
    /**
     * Reset configuration (useful for testing)
     */
    public static function reset() {
        self::$config = null;
        self::$configPath = null;
        self::$loadError = null;
    }
}
