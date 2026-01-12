<?php
/**
 * Read Zendure Data from Device
 * Fetches status from Zendure SolarFlow device
 * 
 * @param string $deviceIp IP address of the Zendure device
 * @return array|false Returns data array on success, false on failure
 */
function readZendureData($deviceIp) {
    $url = "http://{$deviceIp}/properties/report";
    
    // Use cURL for HTTP request
    $ch = curl_init($url);
    if ($ch === false) {
        return false;
    }
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($response === false || !empty($curlError)) {
        return false;
    }
    
    if ($httpCode !== 200) {
        return false;
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return false;
    }
    
    // Extract properties and pack data
    $props = $data['properties'] ?? [];
    $packs = $data['packData'] ?? [];
    
    // Prepare reading data with timestamp
    $readingData = [
        'timestamp' => date('c'), // ISO 8601 format
        'properties' => $props,
        'packData' => $packs
    ];
    
    return $readingData;
}
