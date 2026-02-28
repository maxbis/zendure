<?php

declare(strict_types=1);

const ENTSOE_TOKEN = '4b813a09-87ad-4ae8-8f3d-d15b635d7f96';
const ENTSOE_ENDPOINT = 'https://web-api.tp.entsoe.eu/api';
const BIDDING_ZONE_NL = '10YNL----------L';

/**
 * Get NL day-ahead prices for a given delivery date (Europe/Amsterdam date)
 */
function getDayAheadPrices(string $dateNL): array
{
    $startUTC = new DateTime($dateNL . ' 00:00:00', new DateTimeZone('UTC'));
    $endUTC = clone $startUTC;
    $endUTC->modify('+1 day');

    $periodStart = $startUTC->format('YmdHi');
    $periodEnd   = $endUTC->format('YmdHi');

    $endUTC = clone $startUTC;
    $endUTC->modify('+1 day');

    $periodStart = $startUTC->format('YmdHi');
    $periodEnd   = $endUTC->format('YmdHi');

    $query = http_build_query([
        'documentType' => 'A44',
        'in_Domain'    => BIDDING_ZONE_NL,
        'out_Domain'   => BIDDING_ZONE_NL,
        'periodStart'  => $periodStart,
        'periodEnd'    => $periodEnd,
        'securityToken'=> ENTSOE_TOKEN
    ]);

    $url = ENTSOE_ENDPOINT . '?' . $query;

    $response = file_get_contents($url);

    if ($response === false) {
        throw new RuntimeException('ENTSO-E request failed.');
    }

    $xml = simplexml_load_string($response);

    if ($xml === false) {
        throw new RuntimeException('Invalid XML returned.');
    }

    // Check if we received an acknowledgement (error)
    if ($xml->getName() === 'Acknowledgement_MarketDocument') {
        $reason = (string)$xml->Reason->text;
        throw new RuntimeException('ENTSO-E Error: ' . $reason);
    }

    // Parse price data
    $prices = [];

    foreach ($xml->TimeSeries->Period->Point as $point) {
        $position = (int)$point->position;
        $price = (float)$point->{'price.amount'};

        $prices[$position] = $price; // EUR/MWh
    }

    ksort($prices);

    return $prices;
}

try {
    $prices = getDayAheadPrices('2026-02-28');

    foreach ($prices as $hour => $priceMWh) {
        $priceKWh = $priceMWh / 1000;
        echo sprintf("Hour %02d: %.5f EUR/kWh\n", $hour - 1, $priceKWh);
    }

} catch (Exception $e) {
    echo $e->getMessage();
}