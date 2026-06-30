<?php

declare(strict_types=1);

require_once __DIR__ . '/get_prices_v6.php';
require_once __DIR__ . '/price_ticks_common.php';

if (!isRunningInCLI()) {
    http_response_code(403);
    echo "This script must be run from the command line.\n";
    exit(1);
}

function backfillPriceTicksUsage(): string {
    return "Usage: php backfill_price_ticks.php [--start-date YYYY-MM-DD] [--end-date YYYY-MM-DD] [--dry-run]\n"
        . "When --start-date is omitted, the script defaults to yesterday in Europe/Amsterdam.\n";
}

try {
    $options = getopt('', ['start-date:', 'end-date::', 'dry-run']);
    $tz = new DateTimeZone(PRICE_TICKS_TIMEZONE);
    $startDateRaw = $options['start-date'] ?? (new DateTimeImmutable('today', $tz))->modify('-1 day')->format('Y-m-d');
    if (!is_string($startDateRaw) || $startDateRaw === '') {
        fwrite(STDERR, backfillPriceTicksUsage());
        exit(2);
    }

    $startDate = priceTicksNormalizeDate($startDateRaw);
    $endDateRaw = $options['end-date'] ?? (new DateTimeImmutable('today', $tz))->format('Y-m-d');
    if (!is_string($endDateRaw) || $endDateRaw === '') {
        fwrite(STDERR, backfillPriceTicksUsage());
        exit(2);
    }
    $endDate = priceTicksNormalizeDate($endDateRaw);
    $dryRun = array_key_exists('dry-run', $options);

    $cursor = new DateTimeImmutable($startDate, $tz);
    $end = new DateTimeImmutable($endDate, $tz);
    if ($cursor > $end) {
        throw new InvalidArgumentException('--start-date must be before or equal to --end-date');
    }

    $pdo = priceTicksCreatePdo();
    priceTicksEnsureTables($pdo);

    $results = [];
    $totals = [
        'json_imported' => 0,
        'provider_fetched' => 0,
        'already_complete' => 0,
        'incomplete_or_failed' => 0,
    ];

    while ($cursor <= $end) {
        $date = $cursor->format('Y-m-d');
        $result = priceTicksFillDate($pdo, $date, 'backfill', $dryRun);
        $results[] = $result;

        $status = (string)($result['status'] ?? '');
        if ($status === 'already_complete') {
            $totals['already_complete']++;
        } elseif ($status === 'json_imported') {
            $totals['json_imported']++;
        } elseif (($result['success'] ?? false) === true) {
            $totals['provider_fetched']++;
        } else {
            $totals['incomplete_or_failed']++;
        }

        $cursor = $cursor->modify('+1 day');
    }

    priceTicksPrintSummary($results);
    echo sprintf(
        "Totals: json_imported=%d provider_fetched=%d already_complete=%d incomplete_or_failed=%d%s\n",
        $totals['json_imported'],
        $totals['provider_fetched'],
        $totals['already_complete'],
        $totals['incomplete_or_failed'],
        $dryRun ? ' dry_run=1' : ''
    );
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'backfill_price_ticks failed: ' . $e->getMessage() . "\n");
    exit(1);
}
