<?php
// data/api/data_functions.php

// Helper Functions for Data API

/**
 * Gets the file path for a given type and parameters
 * 
 * @param string $type Type of data (price, zendure, zendure_p1, schedule, automation_status, file)
 * @param array $params Parameters (date, name, etc.)
 * @return string|null Full file path or null on error
 */
function getDataFilePath($type, $params) {
    $dataDir = __DIR__ . '/..';
    
    switch ($type) {
        case 'price':
            if (!isset($params['date'])) {
                return null;
            }
            $date = sanitizeFileName($params['date']);
            if (!$date || !preg_match('/^\d{8}$/', $date)) {
                return null;
            }
            return $dataDir . '/price' . $date . '.json';
            
        case 'zendure':
            return $dataDir . '/zendure_data.json';
            
        case 'zendure_p1':
            return $dataDir . '/zendure_p1_data.json';
            
        case 'schedule':
            return $dataDir . '/charge_schedule.json';
            
        case 'automation_status':
            return $dataDir . '/automation_status.json';
            
        case 'file':
            if (!isset($params['name'])) {
                return null;
            }
            $name = sanitizeFileName($params['name']);
            if (!$name || !preg_match('/\.json$/', $name)) {
                return null;
            }
            return $dataDir . '/' . $name;
            
        default:
            return null;
    }
}

/**
 * Reads a JSON data file safely
 * 
 * @param string $filePath Full path to the file
 * @return array|null Decoded data or null on error
 */
function readDataFile($filePath) {
    if (!file_exists($filePath)) {
        return null;
    }
    
    $json = file_get_contents($filePath);
    if ($json === false) {
        error_log("Failed to read file: $filePath");
        return null;
    }
    
    $data = json_decode($json, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        error_log("Failed to decode JSON from file: $filePath - " . json_last_error_msg());
        return null;
    }
    
    return $data;
}

/**
 * Writes JSON data to file atomically
 * 
 * @param string $filePath Full path to the file
 * @param mixed $data Data to write (will be JSON encoded)
 * @return bool True on success, false on error
 */
function writeDataFileAtomic($filePath, $data) {
    // Ensure directory exists
    $dir = dirname($filePath);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            error_log("Failed to create directory: $dir");
            return false;
        }
    }
    
    // Check if directory is writable
    if (!is_writable($dir)) {
        error_log("Directory is not writable: $dir");
        return false;
    }
    
    // Encode to JSON
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        error_log("Failed to encode JSON data");
        return false;
    }
    
    // Write to temporary file first
    $tempFile = $filePath . '.tmp';
    if (file_put_contents($tempFile, $json) === false) {
        error_log("Failed to write temp file: $tempFile");
        return false;
    }
    
    // Atomically replace the target file
    if (!rename($tempFile, $filePath)) {
        error_log("Failed to rename temp file to: $filePath");
        // Clean up temp file if rename failed
        @unlink($tempFile);
        return false;
    }
    
    return true;
}

/**
 * Validates price data structure
 * 
 * @param mixed $data Data to validate
 * @return array ['valid' => bool, 'error' => string|null]
 */
function validatePriceData($data) {
    if (!is_array($data)) {
        return ['valid' => false, 'error' => 'Data must be an array'];
    }
    
    // Check for hour keys 00-23
    $validHours = [];
    for ($h = 0; $h < 24; $h++) {
        $validHours[sprintf('%02d', $h)] = true;
    }
    
    foreach ($data as $hour => $price) {
        $hourStr = (string)$hour;
        if (!isset($validHours[$hourStr])) {
            return ['valid' => false, 'error' => "Invalid hour key: $hour (must be 00-23)"];
        }
        if (!is_numeric($price)) {
            return ['valid' => false, 'error' => "Price for hour $hour must be numeric"];
        }
    }
    
    return ['valid' => true, 'error' => null];
}

/**
 * Lists all JSON files in the data directory
 * 
 * @param string|null $pattern Optional pattern to filter (e.g., "price*.json")
 * @return array Array of filenames
 */
function listDataFiles($pattern = null) {
    $dataDir = __DIR__ . '/..';
    
    if (!is_dir($dataDir)) {
        return [];
    }
    
    $files = [];
    $items = scandir($dataDir);
    
    if ($items === false) {
        return [];
    }
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $filePath = $dataDir . '/' . $item;
        if (!is_file($filePath)) {
            continue;
        }
        
        // Only include JSON files
        if (!preg_match('/\.json$/', $item)) {
            continue;
        }
        
        // Apply pattern filter if provided
        if ($pattern !== null) {
            $patternRegex = '/^' . str_replace(['*', '.'], ['.*', '\.'], $pattern) . '$/';
            if (!preg_match($patternRegex, $item)) {
                continue;
            }
        }
        
        $files[] = $item;
    }
    
    sort($files);
    return $files;
}

/**
 * Sanitizes a filename to prevent directory traversal attacks
 * 
 * @param string $name Filename to sanitize
 * @return string|null Sanitized filename or null if invalid
 */
function sanitizeFileName($name) {
    // Remove any path components
    $name = basename($name);
    
    // Remove null bytes
    $name = str_replace("\0", '', $name);
    
    // Check for directory traversal attempts
    if (strpos($name, '..') !== false || strpos($name, '/') !== false || strpos($name, '\\') !== false) {
        return null;
    }
    
    // Only allow alphanumeric, dots, dashes, underscores
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
        return null;
    }
    
    return $name;
}

/**
 * Cleans up old price files by moving them to archive directory
 * 
 * @param int $retentionDays Number of days to keep files before archiving (default: 4)
 * @param string $dataDir Data directory path (default: parent of current directory)
 * @param string $archiveDir Archive directory path (default: dataDir/price_archive)
 * @return array Statistics: ['moved' => int, 'errors' => array, 'skipped' => int]
 */
function cleanupOldPriceFiles($retentionDays = 4, $dataDir = null, $archiveDir = null) {
    if ($dataDir === null) {
        $dataDir = __DIR__ . '/..';
    }
    
    if ($archiveDir === null) {
        $archiveDir = $dataDir . '/price_archive';
    }
    
    $stats = [
        'moved' => 0,
        'errors' => [],
        'skipped' => 0
    ];
    
    // Ensure archive directory exists
    if (!is_dir($archiveDir)) {
        if (!mkdir($archiveDir, 0755, true)) {
            error_log("Failed to create archive directory: $archiveDir");
            $stats['errors'][] = "Failed to create archive directory";
            return $stats;
        }
    }
    
    // Check if archive directory is writable
    if (!is_writable($archiveDir)) {
        error_log("Archive directory is not writable: $archiveDir");
        $stats['errors'][] = "Archive directory is not writable";
        return $stats;
    }
    
    // Get all price files
    $priceFiles = listDataFiles('price*.json');
    
    if (empty($priceFiles)) {
        return $stats;
    }
    
    // Calculate cutoff date (retentionDays ago)
    $cutoffTime = time() - ($retentionDays * 24 * 60 * 60);
    $cutoffDate = date('Ymd', $cutoffTime);
    
    foreach ($priceFiles as $filename) {
        $filePath = $dataDir . '/' . $filename;
        
        // Skip if not a regular file
        if (!is_file($filePath)) {
            continue;
        }
        
        // Extract date from filename (priceYYYYMMDD.json)
        if (!preg_match('/^price(\d{8})\.json$/', $filename, $matches)) {
            // If filename doesn't match pattern, use file modification time
            $fileDate = date('Ymd', filemtime($filePath));
        } else {
            $fileDate = $matches[1];
        }
        
        // Compare dates (YYYYMMDD format allows string comparison)
        if ($fileDate >= $cutoffDate) {
            // File is still within retention period
            $stats['skipped']++;
            continue;
        }
        
        // Move file to archive
        $archivePath = $archiveDir . '/' . $filename;
        
        // If file already exists in archive, append timestamp to avoid overwrite
        if (file_exists($archivePath)) {
            $pathInfo = pathinfo($filename);
            $timestamp = date('YmdHis');
            $archivePath = $archiveDir . '/' . $pathInfo['filename'] . '_' . $timestamp . '.' . $pathInfo['extension'];
        }
        
        if (rename($filePath, $archivePath)) {
            $stats['moved']++;
            error_log("Archived price file: $filename -> " . basename($archivePath));
        } else {
            $errorMsg = "Failed to move file: $filename";
            error_log($errorMsg);
            $stats['errors'][] = $errorMsg;
        }
    }
    
    return $stats;
}

