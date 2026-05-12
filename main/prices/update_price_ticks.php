<?php

declare(strict_types=1);

require_once __DIR__ . '/get_prices_v6.php';
require_once __DIR__ . '/price_ticks_common.php';

if (!isRunningInCLI()) {
    http_response_code(403);
    echo "This script must be run from the command line.\n";
    exit(1);
}

function updatePriceTicksFetchEntsoe(string $date): ?array {
    return fetchEntsoeHourPricesForDate(priceTicksDateToYmd($date), false, false);
}

try {
    $pdo = priceTicksCreatePdo();
    priceTicksEnsureTables($pdo);

    $tz = new DateTimeZone(PRICE_TICKS_TIMEZONE);
    $today = new DateTimeImmutable('today', $tz);
    $dates = [
        $today->modify('-1 day')->format('Y-m-d'),
        $today->format('Y-m-d'),
        $today->modify('+1 day')->format('Y-m-d'),
    ];

    $results = [];
    foreach ($dates as $date) {
        $results[] = priceTicksReconcileDate($pdo, $date, 'daily', 'updatePriceTicksFetchEntsoe');
    }

    priceTicksPrintSummary($results);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'update_price_ticks failed: ' . $e->getMessage() . "\n");
    exit(1);
}
