<?php
/**
 * Data Loading Functions
 * Functions for loading and parsing data files
 */

/**
 * Load and parse Zendure data from JSON file
 * Returns array with 'data', 'error', and 'message' keys
 */
function loadZendureData($dataFile) {
    if ($dataFile === null || $dataFile === '') {
        return [
            'data' => null,
            'error' => true,
            'message' => 'Data file path not configured.'
        ];
    }
    
    if (!file_exists($dataFile)) {
        return [
            'data' => null,
            'error' => true,
            'message' => 'Data file not found. Please run read_zendure.py to fetch data.'
        ];
    }

    $jsonContent = file_get_contents($dataFile);
    $data = json_decode($jsonContent, true);
    
    if (!$data) {
        return [
            'data' => null,
            'error' => true,
            'message' => 'Failed to parse JSON data.'
        ];
    }

    return [
        'data' => $data,
        'error' => false,
        'message' => null
    ];
}

