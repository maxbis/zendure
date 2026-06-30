<?php

declare(strict_types=1);

/**
 * Daily price_ticks updater.
 *
 * For each target date: use JSON cache when complete, else ENTSO-E (v6),
 * else EnergyZero (v7) when the date is still incomplete in MariaDB.
 */
require_once __DIR__ . '/get_prices_v6.php';
require_once __DIR__ . '/price_ticks_common.php';

if (!isRunningInCLI()) {
    http_response_code(403);
    echo "This script must be run from the command line.\n";
    exit(1);
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
        $results[] = priceTicksFillDate($pdo, $date, 'daily', false);
    }

    priceTicksPrintSummary($results);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'update_price_ticks failed: ' . $e->getMessage() . "\n");
    exit(1);
}
