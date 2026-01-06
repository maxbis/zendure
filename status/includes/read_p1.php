<?php
/**
 * Read P1 Meter Data from Device
 * Fetches grid power readings from Zendure P1 Meter device
 * 
 * @param string $deviceIp IP address of the P1 meter device
 * @return array|false Returns data array on success, false on failure
 */
function readP1Data($deviceIp) {
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
    
    // Extract P1 meter data
    $deviceId = $data['deviceId'] ?? null;
    $totalPower = $data['total_power'] ?? null;
    $phaseA = $data['a_aprt_power'] ?? null;
    $phaseB = $data['b_aprt_power'] ?? null;
    $phaseC = $data['c_aprt_power'] ?? null;
    $meterTimestamp = $data['timestamp'] ?? null;
    
    // Prepare reading data with timestamp
    $readingData = [
        'timestamp' => date('c'), // ISO 8601 format
        'deviceId' => $deviceId,
        'total_power' => $totalPower,
        'a_aprt_power' => $phaseA,
        'b_aprt_power' => $phaseB,
        'c_aprt_power' => $phaseC,
        'meter_timestamp' => $meterTimestamp
    ];
    
    return $readingData;
}
