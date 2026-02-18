<?php

/**
 * ENTSO-E Day Ahead Prices (Netherlands)
 * Retrieves today's hourly prices
 */

declare(strict_types=1);

const ENTSOE_TOKEN = '4b813a09-87ad-4ae8-8f3d-d15b635d7f96';
const ENTSOE_ENDPOINT = 'https://web-api.tp.entsoe.eu/api';
const NL_DOMAIN = '10YNL----------L';
const DOCUMENT_TYPE = 'A44'; // Day-ahead prices

// Price components (EUR/kWh) and VAT multiplier
const INKOOPVERGOEDING = 0.0219;
const BELASTING = 0.0917;
const BTW = 1.21;

// Set timezone to UTC (ENTSO-E requires UTC)
date_default_timezone_set('UTC');

// Today 00:00 UTC
$start = new DateTime('today 00:00');
$end   = (clone $start)->modify('+1 day');

$periodStart = $start->format('YmdHi');
$periodEnd   = $end->format('YmdHi');

// Build query
$params = http_build_query([
    'securityToken' => ENTSOE_TOKEN,
    'documentType'  => DOCUMENT_TYPE,
    'in_Domain'     => NL_DOMAIN,
    'out_Domain'    => NL_DOMAIN,
    'periodStart'   => $periodStart,
    'periodEnd'     => $periodEnd,
]);

$url = ENTSOE_ENDPOINT . '?' . $params;

// Fetch data
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);

if ($response === false) {
    http_response_code(500);
    die(json_encode([
        'error' => 'Curl error: ' . curl_error($ch)
    ]));
}

curl_close($ch);

// Parse XML
libxml_use_internal_errors(true);
$xml = simplexml_load_string($response);

if ($xml === false) {
    http_response_code(500);
    die(json_encode([
        'error' => 'Invalid XML response'
    ]));
}

// Namespace handling
$namespaces = $xml->getNamespaces(true);
$ns = $namespaces[''] ?? null;

$xml->registerXPathNamespace('ns', $ns);

$points = $xml->xpath('//ns:Point');

$result = [];
$hour = 0;

foreach ($points as $point) {
    $position = (int)$point->position;
    $priceMwh = (float)$point->{'price.amount'};
    $priceKwh = $priceMwh / 1000;

    // Consumer price: (exchange price + tax + procurement fee) * VAT
    $consumerPrice = ($priceKwh + BELASTING + INKOOPVERGOEDING) * BTW;

    $timestamp = (clone $start)->modify('+' . ($position - 1) . ' hour');

    $result[] = [
        'datetime_utc' => $timestamp->format('Y-m-d H:i:s'),
        'price_eur_mwh' => $priceMwh,
        'price_eur_kwh' => round($priceKwh, 6),
        'consumer_price' => round($consumerPrice, 6),
    ];
}

// Output JSON
header('Content-Type: application/json');

echo json_encode([
    'date_utc' => $start->format('Y-m-d'),
    'prices' => $result
], JSON_PRETTY_PRINT);