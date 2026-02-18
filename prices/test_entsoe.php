<?php

/**
 * Simple ENTSO-E test script
 * Fetches day-ahead prices for a date and returns price per hour.
 *
 * Usage: php test_entsoe.php [YYYYMMDD]
 *   Default date: today (server timezone)
 */

declare(strict_types=1);

const ENTSOE_TOKEN = '4b813a09-87ad-4ae8-8f3d-d15b635d7f96';
const ENTSOE_ENDPOINT = 'https://web-api.tp.entsoe.eu/api';
const NL_DOMAIN = '10YNL----------L';
const DOCUMENT_TYPE = 'A44';

const INKOOPVERGOEDING = 0.0219;
const BELASTING = 0.0917;
const BTW = 1.21;

$dateYmd = $argv[1] ?? date('Ymd');

$tz = date_default_timezone_get();
date_default_timezone_set('UTC');

$start = new DateTime($dateYmd . ' 00:00:00');
$end = (clone $start)->modify('+1 day');
$periodStart = $start->format('YmdHi');
$periodEnd = $end->format('YmdHi');

date_default_timezone_set($tz);

$params = http_build_query([
    'securityToken' => ENTSOE_TOKEN,
    'documentType' => DOCUMENT_TYPE,
    'in_Domain' => NL_DOMAIN,
    'out_Domain' => NL_DOMAIN,
    'periodStart' => $periodStart,
    'periodEnd' => $periodEnd,
]);
$url = ENTSOE_ENDPOINT . '?' . $params;

echo "URL: $url\n\n";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false || $curlError) {
    die(json_encode(['error' => "Curl error: $curlError"], JSON_PRETTY_PRINT));
}
if ($httpCode !== 200) {
    die(json_encode(['error' => "HTTP $httpCode", 'response' => substr($response, 0, 500)], JSON_PRETTY_PRINT));
}

libxml_use_internal_errors(true);
$xml = simplexml_load_string($response);
if ($xml === false) {
    die(json_encode(['error' => 'Invalid XML', 'response' => substr($response, 0, 500)], JSON_PRETTY_PRINT));
}

$namespaces = $xml->getNamespaces(true);
$ns = $namespaces[''] ?? null;
$xml->registerXPathNamespace('ns', $ns);
// Get points from first Period only (API may return multiple periods)
$periodPoints = $xml->xpath('(//ns:Period)[1]//ns:Point');
$points = ($periodPoints !== false && !empty($periodPoints)) ? $periodPoints : $xml->xpath('//ns:Point');
$pointCount = $points === false ? 0 : count($points);

if ($pointCount < 24) {
    die(json_encode(['error' => 'Unexpected point count: ' . $pointCount], JSON_PRETTY_PRINT));
}

// Detect resolution: 24 = hourly (PT60M), 96 = 15-min (PT15M). Use first period only if multiple.
$is15Min = ($pointCount === 96);
$resolution = $is15Min ? 'PT15M' : 'PT60M';

$prices = [];

if ($is15Min) {
    // 15-min: group 4 points per hour, average price_eur_mwh, then convert to consumer_price
    $hourlyMwh = array_fill(0, 24, []);
    foreach ($points as $point) {
        $position = (int) $point->position;
        $priceMwh = (float) $point->{'price.amount'};
        $hourIndex = (int) floor(($position - 1) / 4);
        if ($hourIndex >= 0 && $hourIndex < 24) {
            $hourlyMwh[$hourIndex][] = $priceMwh;
        }
    }
    for ($h = 0; $h < 24; $h++) {
        if (count($hourlyMwh[$h]) !== 4) {
            die(json_encode(['error' => "Hour $h has " . count($hourlyMwh[$h]) . " points, expected 4"], JSON_PRETTY_PRINT));
        }
        $avgMwh = array_sum($hourlyMwh[$h]) / 4;
        $priceKwh = $avgMwh / 1000;
        $consumerPrice = ($priceKwh + BELASTING + INKOOPVERGOEDING) * BTW;
        $hourStr = sprintf('%02d', $h);
        $prices[$hourStr] = [
            'price_eur_kwh' => round($priceKwh, 6),
            'consumer_price' => round($consumerPrice, 6),
        ];
    }
} else {
    // Hourly: 1 point per hour
    foreach ($points as $point) {
        $position = (int) $point->position;
        $priceMwh = (float) $point->{'price.amount'};
        $priceKwh = $priceMwh / 1000;
        $consumerPrice = ($priceKwh + BELASTING + INKOOPVERGOEDING) * BTW;

        $timestamp = (clone $start)->modify('+' . ($position - 1) . ' hour');
        $hour = $timestamp->format('H');
        $prices[$hour] = [
            'price_eur_kwh' => round($priceKwh, 6),
            'consumer_price' => round($consumerPrice, 6),
        ];
    }
}
ksort($prices);

header('Content-Type: application/json');
echo json_encode([
    'date' => $dateYmd,
    'period' => ['start' => $periodStart, 'end' => $periodEnd],
    'resolution' => $resolution,
    'point_count' => $pointCount,
    'prices' => $prices,
], JSON_PRETTY_PRINT);
