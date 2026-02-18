<?php

declare(strict_types=1);

/**
 * ENTSO-E quarter-hour market price test endpoint.
 *
 * Fetches A44 day-ahead XML from ENTSO-E and outputs JSON grouped per NL hour:
 * - four quarter prices (EUR/MWh)
 * - average hour price over those quarters (EUR/MWh)
 */

const ENTSOE_TEST_URL = 'https://web-api.tp.entsoe.eu/api?documentType=A44&in_Domain=10YNL----------L&out_Domain=10YNL----------L&periodStart=202602180000&periodEnd=202602190000&securityToken=4b813a09-87ad-4ae8-8f3d-d15b635d7f96';
const TIMEZONE_NL = 'Europe/Amsterdam';
const INKOOPVERGOEDING = 0.0219;
const BELASTING = 0.08980;
const BTW = 1.21;

function isRunningInCLI(): bool {
    return php_sapi_name() === 'cli' || php_sapi_name() === 'phpdbg';
}

function sendJsonResponse(array $data, int $statusCode = 200): void {
    if (!isRunningInCLI()) {
        http_response_code($statusCode);
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Content-Type: application/json');
    }

    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

function fetchXml(string $url): ?string {
    $ch = curl_init($url);
    if ($ch === false) {
        error_log('entsoe_test: Failed to initialize cURL');
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
        error_log('entsoe_test: cURL error: ' . $curlError);
        return null;
    }

    if ($httpCode !== 200) {
        error_log('entsoe_test: HTTP error code ' . $httpCode);
        return null;
    }

    return $response;
}

/**
 * @return array<int, array<string, mixed>>
 */
function parseHourlyPrices(string $xmlContent): array {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlContent);
    if ($xml === false) {
        return [];
    }

    $namespaces = $xml->getNamespaces(true);
    $defaultNs = $namespaces[''] ?? null;
    if ($defaultNs === null) {
        return [];
    }

    $xml->registerXPathNamespace('ns', $defaultNs);
    $periods = $xml->xpath('//ns:TimeSeries/ns:Period');
    if ($periods === false || empty($periods)) {
        return [];
    }

    $tzUtc = new DateTimeZone('UTC');
    $tzNl = new DateTimeZone(TIMEZONE_NL);

    $groups = [];

    foreach ($periods as $period) {
        $resolution = trim((string)$period->resolution);
        if ($resolution !== 'PT15M') {
            continue;
        }

        $startStr = trim((string)$period->timeInterval->start);
        if ($startStr === '') {
            continue;
        }

        try {
            $periodStartUtc = new DateTimeImmutable($startStr, $tzUtc);
        } catch (Exception $e) {
            continue;
        }

        foreach ($period->Point as $point) {
            $position = (int)$point->position;
            $price = (float)$point->{'price.amount'};
            if ($position <= 0) {
                continue;
            }

            $offsetMinutes = ($position - 1) * 15;
            $quarterStartUtc = $periodStartUtc->modify('+' . $offsetMinutes . ' minutes');
            $quarterStartNl = $quarterStartUtc->setTimezone($tzNl);

            $hourKey = $quarterStartNl->format('Y-m-d H');
            if (!isset($groups[$hourKey])) {
                $groups[$hourKey] = [
                    'date_nl' => $quarterStartNl->format('Y-m-d'),
                    'hour_nl' => $quarterStartNl->format('H'),
                    'hour_start_nl' => $quarterStartNl->format('Y-m-d H:00:00'),
                    'quarter_prices_eur_mwh' => [],
                ];
            }

            $groups[$hourKey]['quarter_prices_eur_mwh'][] = round($price, 2);
        }
    }

    ksort($groups);

    $result = [];
    foreach ($groups as $group) {
        $quarterPrices = $group['quarter_prices_eur_mwh'];
        $count = count($quarterPrices);
        $average = $count > 0 ? round(array_sum($quarterPrices) / $count, 4) : null;
        $kwhPrice = $average !== null ? round($average / 1000, 4) : null;
        $consumerPrice = $kwhPrice !== null
            ? round(($kwhPrice + INKOOPVERGOEDING + BELASTING) * BTW, 4)
            : null;

        $result[] = [
            'date_nl' => $group['date_nl'],
            'hour_nl' => $group['hour_nl'],
            'hour_start_nl' => $group['hour_start_nl'],
            'quarter_prices_eur_mwh' => $quarterPrices,
            'quarters_found' => $count,
            'average_price_eur_mwh' => $average,
            'kwh_price' => $kwhPrice,
            'consumer_price' => $consumerPrice,
        ];
    }

    return $result;
}

function run(): array {
    $xmlContent = fetchXml(ENTSOE_TEST_URL);
    if ($xmlContent === null) {
        return [
            'ok' => false,
            'error' => 'Failed to fetch ENTSO-E XML data',
            'source_url' => ENTSOE_TEST_URL,
        ];
    }

    $hours = parseHourlyPrices($xmlContent);
    if (empty($hours)) {
        return [
            'ok' => false,
            'error' => 'No PT15M points found in response',
            'source_url' => ENTSOE_TEST_URL,
        ];
    }

    return [
        'ok' => true,
        'source_url' => ENTSOE_TEST_URL,
        'timezone' => TIMEZONE_NL,
        'generated_at_utc' => gmdate('Y-m-d H:i:s'),
        'hours' => $hours,
    ];
}

$result = run();
$statusCode = ($result['ok'] ?? false) ? 200 : 500;
sendJsonResponse($result, $statusCode);
