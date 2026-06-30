<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/price_conversion.php';

const ENERGYZERO_HOUR_PRICES_BASE_URL = 'https://public.api.energyzero.nl/public/v1/prices';
const ENERGYZERO_HOUR_PRICES_TIMEZONE = 'Europe/Amsterdam';

function energyzeroHourPricesParseDecimal(mixed $value): ?float {
    if (is_int($value) || is_float($value)) {
        return (float)$value;
    }
    if (!is_string($value)) {
        return null;
    }
    $normalized = str_replace(',', '.', trim($value));
    if ($normalized === '' || !is_numeric($normalized)) {
        return null;
    }
    return (float)$normalized;
}

function energyzeroHourPricesBuildUrl(string $dateStr): ?string {
    if (strlen($dateStr) !== 8) {
        return null;
    }

    $dateFormatted = substr($dateStr, 6, 2) . '-' . substr($dateStr, 4, 2) . '-' . substr($dateStr, 0, 4);

    return ENERGYZERO_HOUR_PRICES_BASE_URL
        . '?energyType=ENERGY_TYPE_ELECTRICITY'
        . '&date=' . rawurlencode($dateFormatted)
        . '&interval=INTERVAL_HOUR';
}

/**
 * @return array<string, mixed>|null
 */
function energyzeroHourPricesFetchJson(string $url): ?array {
    $ch = curl_init($url);
    if ($ch === false) {
        error_log('energyzero_hour_prices: Failed to initialize cURL');
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($response === false || $curlError !== '') {
        error_log('energyzero_hour_prices: cURL error: ' . $curlError);
        return null;
    }
    if ($httpCode !== 200) {
        error_log('energyzero_hour_prices: HTTP ' . $httpCode);
        return null;
    }
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string, mixed> $payload
 * @return array<int, array<string, mixed>>
 */
function energyzeroHourPricesParsePayload(array $payload): array {
    $rows = $payload['base'] ?? null;
    if (!is_array($rows)) {
        return [];
    }

    $tzUtc = new DateTimeZone('UTC');
    $tzNl = new DateTimeZone(ENERGYZERO_HOUR_PRICES_TIMEZONE);
    $groups = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $start = $row['start'] ?? null;
        $priceValue = is_array($row['price'] ?? null) ? (($row['price']['value'] ?? null)) : null;
        if (!is_string($start)) {
            continue;
        }

        $sourcePrice = energyzeroHourPricesParseDecimal($priceValue);
        if ($sourcePrice === null) {
            continue;
        }

        try {
            $dtUtc = new DateTimeImmutable($start, $tzUtc);
        } catch (Exception $e) {
            continue;
        }

        $dtNl = $dtUtc->setTimezone($tzNl);
        $dateNl = $dtNl->format('Y-m-d');
        $hourNl = $dtNl->format('H');
        $hourKey = $dateNl . ' ' . $hourNl;

        if (!isset($groups[$hourKey])) {
            $groups[$hourKey] = [
                'date_nl' => $dateNl,
                'hour_nl' => $hourNl,
                'source_prices' => [],
            ];
        }

        $groups[$hourKey]['source_prices'][] = round($sourcePrice, 6);
    }

    ksort($groups);
    $result = [];
    foreach ($groups as $group) {
        $sourcePrices = $group['source_prices'];
        $count = count($sourcePrices);
        $averageSource = $count > 0 ? round(array_sum($sourcePrices) / $count, 6) : null;
        $consumerPrice = convertSpotToConsumerPrice($averageSource);

        $result[] = [
            'date_nl' => $group['date_nl'],
            'hour_nl' => $group['hour_nl'],
            'consumer_price' => $consumerPrice,
        ];
    }

    return $result;
}

/**
 * @param array<int, array<string, mixed>> $hours
 * @return array<string, array<string, float>>
 */
function energyzeroHourPricesBuildByDate(array $hours): array {
    $byDate = [];
    foreach ($hours as $row) {
        $date = $row['date_nl'];
        $hour = str_pad((string)(int)$row['hour_nl'], 2, '0', STR_PAD_LEFT);
        $price = $row['consumer_price'];
        if ($price === null) {
            continue;
        }
        if (!isset($byDate[$date])) {
            $byDate[$date] = [];
        }
        $byDate[$date][$hour] = $price;
    }
    return $byDate;
}

/**
 * @return array<string, float>|null Hour "00"-"23" => consumer price, or null on failure
 */
function fetchEnergyzeroHourPricesForDate(string $dateStr, bool $requireComplete = true): ?array {
    $url = energyzeroHourPricesBuildUrl($dateStr);
    if ($url === null) {
        return null;
    }
    $payload = energyzeroHourPricesFetchJson($url);
    if ($payload === null) {
        return null;
    }

    $hours = energyzeroHourPricesParsePayload($payload);
    if ($hours === []) {
        return null;
    }

    $byDate = energyzeroHourPricesBuildByDate($hours);
    $expectedDateYmd = substr($dateStr, 0, 4) . '-' . substr($dateStr, 4, 2) . '-' . substr($dateStr, 6, 2);
    $selectedDateYmd = $expectedDateYmd;

    if (!isset($byDate[$selectedDateYmd])) {
        $dates = array_keys($byDate);
        sort($dates);
        $selectedDateYmd = $dates[0] ?? '';
        if ($selectedDateYmd === '') {
            return null;
        }
    }

    $hourPrices = $byDate[$selectedDateYmd] ?? null;
    if ($hourPrices === null || $hourPrices === []) {
        return null;
    }
    if ($requireComplete && count($hourPrices) < 24) {
        return null;
    }

    return $hourPrices;
}
