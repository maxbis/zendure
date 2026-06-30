<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/price_conversion.php';
$dailyReportEnvLoader = dirname(__DIR__, 2) . '/daily_report/load_env.php';
if (is_readable($dailyReportEnvLoader)) {
    require_once $dailyReportEnvLoader;
    if (function_exists('daily_report_bootstrap_env')) {
        daily_report_bootstrap_env();
    }
}

const PRICE_TICKS_SOURCE_ENTSOE_V6 = 'entsoe_v6';
const PRICE_TICKS_SOURCE_ENERGYZERO_V7 = 'energyzero_v7';
const PRICE_TICKS_SOURCE_JSON = 'json';
const PRICE_TICKS_TIMEZONE = 'Europe/Amsterdam';
const PRICE_TICKS_EXPECTED_ROWS = 24;

function priceTicksDbConfig(): array {
    return [
        'host' => getenv('MARIADB_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('MARIADB_PORT') ?: '3306'),
        'user' => getenv('MARIADB_USER') ?: 'root',
        'password' => getenv('MARIADB_PASSWORD') ?: '',
        'database' => getenv('MARIADB_DATABASE') ?: 'sqlite_replication',
    ];
}

function priceTicksCreatePdo(?array $config = null): PDO {
    $config = $config ?? priceTicksDbConfig();
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['host'],
        (int)$config['port'],
        $config['database']
    );

    return new PDO($dsn, (string)$config['user'], (string)$config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function priceTicksEnsureTables(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS price_ticks (
            local_date DATE NOT NULL,
            local_hour TINYINT UNSIGNED NOT NULL,
            price_at_utc DATETIME NULL,
            consumer_eur_per_kwh DECIMAL(10,6) NOT NULL,
            spot_eur_per_kwh DECIMAL(10,6) NULL,
            source VARCHAR(32) NOT NULL DEFAULT 'entsoe_v6',
            samples_found TINYINT UNSIGNED NULL,
            fetched_at DATETIME NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_price_ticks_local_hour (local_date, local_hour)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS price_fetch_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            target_date DATE NOT NULL,
            run_type VARCHAR(16) NOT NULL,
            source VARCHAR(32) NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            rows_expected TINYINT UNSIGNED NOT NULL DEFAULT 24,
            rows_upserted TINYINT UNSIGNED NOT NULL DEFAULT 0,
            missing_hours TEXT NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NOT NULL,
            error_text TEXT NULL,
            PRIMARY KEY (id),
            KEY idx_price_fetch_log_target_date (target_date),
            KEY idx_price_fetch_log_run_type (run_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function priceTicksNormalizeDate(string $date): string {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date, new DateTimeZone(PRICE_TICKS_TIMEZONE));
    if ($dt === false || $dt->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid date, expected YYYY-MM-DD: ' . $date);
    }
    return $date;
}

function priceTicksDateToYmd(string $date): string {
    return str_replace('-', '', priceTicksNormalizeDate($date));
}

function priceTicksYmdToDate(string $dateYmd): string {
    if (!preg_match('/^\d{8}$/', $dateYmd)) {
        throw new InvalidArgumentException('Invalid date, expected YYYYMMDD: ' . $dateYmd);
    }
    return substr($dateYmd, 0, 4) . '-' . substr($dateYmd, 4, 2) . '-' . substr($dateYmd, 6, 2);
}

function priceTicksExpectedHours(): array {
    return array_map(static fn (int $hour): string => str_pad((string)$hour, 2, '0', STR_PAD_LEFT), range(0, 23));
}

function priceTicksMissingHours(array $hourMap): array {
    $missing = [];
    foreach (priceTicksExpectedHours() as $hourKey) {
        if (!array_key_exists($hourKey, $hourMap) || !is_numeric($hourMap[$hourKey])) {
            $missing[] = $hourKey;
        }
    }
    return $missing;
}

function priceTicksIsComplete(array $hourMap): bool {
    return priceTicksMissingHours($hourMap) === [];
}

function priceTicksLocalHourToUtc(string $date, string $hourKey): string {
    $tzNl = new DateTimeZone(PRICE_TICKS_TIMEZONE);
    $local = new DateTimeImmutable($date . ' ' . $hourKey . ':00:00', $tzNl);
    return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function priceTicksLoadHourMapFromDb(PDO $pdo, string $date): array {
    $date = priceTicksNormalizeDate($date);
    $stmt = $pdo->prepare(
        'SELECT local_hour, consumer_eur_per_kwh FROM price_ticks WHERE local_date = ? ORDER BY local_hour ASC'
    );
    $stmt->execute([$date]);

    $prices = [];
    foreach ($stmt->fetchAll() as $row) {
        $hourKey = str_pad((string)(int)$row['local_hour'], 2, '0', STR_PAD_LEFT);
        $prices[$hourKey] = (float)$row['consumer_eur_per_kwh'];
    }
    return $prices;
}

function priceTicksLoadJsonPriceFile(string $date, ?string $priceRoot = null): ?array {
    $date = priceTicksNormalizeDate($date);
    $yyyymm = str_replace('-', '', substr($date, 0, 7));
    $yyyymmdd = str_replace('-', '', $date);
    $root = $priceRoot ?? (__DIR__ . '/../data/price');
    $path = rtrim($root, '/\\') . '/' . $yyyymm . '/price' . $yyyymmdd . '.json';
    if (!is_file($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    $prices = [];
    foreach (priceTicksExpectedHours() as $hourKey) {
        if (array_key_exists($hourKey, $decoded) && is_numeric($decoded[$hourKey])) {
            $prices[$hourKey] = (float)$decoded[$hourKey];
        }
    }
    return $prices;
}

function priceTicksUpsertHourMap(
    PDO $pdo,
    string $date,
    array $hourMap,
    string $source,
    ?int $samplesFound = null,
    bool $dryRun = false
): int {
    $date = priceTicksNormalizeDate($date);
    $nowUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $rows = [];

    foreach (priceTicksExpectedHours() as $hourKey) {
        if (!array_key_exists($hourKey, $hourMap) || !is_numeric($hourMap[$hourKey])) {
            continue;
        }
        $consumerPrice = (float)$hourMap[$hourKey];
        $rows[] = [
            'local_date' => $date,
            'local_hour' => (int)$hourKey,
            'price_at_utc' => priceTicksLocalHourToUtc($date, $hourKey),
            'consumer_eur_per_kwh' => $consumerPrice,
            'spot_eur_per_kwh' => convertConsumerToSpotPrice($consumerPrice),
            'source' => $source,
            'samples_found' => $samplesFound,
            'fetched_at' => $nowUtc,
        ];
    }

    if ($dryRun || $rows === []) {
        return count($rows);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO price_ticks (
            local_date, local_hour, price_at_utc, consumer_eur_per_kwh, spot_eur_per_kwh,
            source, samples_found, fetched_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            price_at_utc = VALUES(price_at_utc),
            consumer_eur_per_kwh = VALUES(consumer_eur_per_kwh),
            spot_eur_per_kwh = VALUES(spot_eur_per_kwh),
            source = VALUES(source),
            samples_found = VALUES(samples_found),
            fetched_at = VALUES(fetched_at)'
    );

    foreach ($rows as $row) {
        $stmt->execute([
            $row['local_date'],
            $row['local_hour'],
            $row['price_at_utc'],
            $row['consumer_eur_per_kwh'],
            $row['spot_eur_per_kwh'],
            $row['source'],
            $row['samples_found'],
            $row['fetched_at'],
        ]);
    }

    return count($rows);
}

function priceTicksLogFetch(
    PDO $pdo,
    string $date,
    string $runType,
    string $source,
    bool $success,
    int $rowsUpserted,
    array $missingHours,
    string $startedAt,
    ?string $errorText,
    bool $dryRun = false
): void {
    if ($dryRun) {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO price_fetch_log (
            target_date, run_type, source, success, rows_expected, rows_upserted,
            missing_hours, started_at, finished_at, error_text
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $finishedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $stmt->execute([
        priceTicksNormalizeDate($date),
        $runType,
        $source,
        $success ? 1 : 0,
        PRICE_TICKS_EXPECTED_ROWS,
        $rowsUpserted,
        implode(',', $missingHours),
        $startedAt,
        $finishedAt,
        $errorText,
    ]);
}

function priceTicksReconcileDate(
    PDO $pdo,
    string $date,
    string $runType,
    callable $fetchHourMap,
    bool $dryRun = false,
    string $source = PRICE_TICKS_SOURCE_ENTSOE_V6
): array {
    $date = priceTicksNormalizeDate($date);
    $startedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $existing = priceTicksLoadHourMapFromDb($pdo, $date);
    $existingMissing = priceTicksMissingHours($existing);
    if ($existingMissing === []) {
        priceTicksLogFetch($pdo, $date, $runType, 'db', true, 0, [], $startedAt, null, $dryRun);
        return [
            'date' => $date,
            'status' => 'already_complete',
            'source' => 'db',
            'rows_upserted' => 0,
            'missing_hours' => [],
            'success' => true,
        ];
    }

    try {
        $fetched = $fetchHourMap($date);
        if (!is_array($fetched) || $fetched === []) {
            priceTicksLogFetch($pdo, $date, $runType, $source, false, 0, $existingMissing, $startedAt, 'No price rows returned', $dryRun);
            return [
                'date' => $date,
                'status' => 'incomplete',
                'source' => $source,
                'rows_upserted' => 0,
                'missing_hours' => $existingMissing,
                'success' => false,
            ];
        }

        $rowsUpserted = priceTicksUpsertHourMap($pdo, $date, $fetched, $source, 4, $dryRun);
        $combined = array_replace($existing, $fetched);
        $missing = priceTicksMissingHours($combined);
        $success = $missing === [];
        priceTicksLogFetch($pdo, $date, $runType, $source, $success, $rowsUpserted, $missing, $startedAt, $success ? null : 'Price rows still incomplete', $dryRun);
        return [
            'date' => $date,
            'status' => $success ? 'fetched_complete' : 'fetched_incomplete',
            'source' => $source,
            'rows_upserted' => $rowsUpserted,
            'missing_hours' => $missing,
            'success' => $success,
        ];
    } catch (Throwable $e) {
        priceTicksLogFetch($pdo, $date, $runType, $source, false, 0, $existingMissing, $startedAt, $e->getMessage(), $dryRun);
        return [
            'date' => $date,
            'status' => 'failed',
            'source' => $source,
            'rows_upserted' => 0,
            'missing_hours' => $existingMissing,
            'success' => false,
            'error' => $e->getMessage(),
        ];
    }
}

function priceTicksFetchEntsoe(string $date): ?array {
    return fetchEntsoeHourPricesForDate(priceTicksDateToYmd($date), false, false);
}

/**
 * Same EnergyZero source as get_prices_v7.php (via energyzero_hour_prices.php).
 */
function priceTicksFetchEnergyzero(string $date): ?array {
    static $loaded = false;
    if (!$loaded) {
        require_once __DIR__ . '/energyzero_hour_prices.php';
        $loaded = true;
    }

    return fetchEnergyzeroHourPricesForDate(priceTicksDateToYmd($date), false);
}

function priceTicksDateStillIncomplete(PDO $pdo, string $date): bool {
    return !priceTicksIsComplete(priceTicksLoadHourMapFromDb($pdo, $date));
}

/**
 * Fill one date in price_ticks using the most reliable source available.
 *
 * Order:
 * 1. Skip when DB already has 24 hours.
 * 2. Import complete JSON cache files (main/data/price, same as the UI).
 * 3. Fetch via get_prices_v6 / ENTSO-E (partial hours are kept).
 * 4. If still incomplete, fetch via get_prices_v7 / EnergyZero (partial hours are kept).
 */
function priceTicksFillDate(PDO $pdo, string $date, string $runType, bool $dryRun = false): array {
    $date = priceTicksNormalizeDate($date);
    $startedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $existing = priceTicksLoadHourMapFromDb($pdo, $date);
    if (priceTicksIsComplete($existing)) {
        priceTicksLogFetch($pdo, $date, $runType, 'db', true, 0, [], $startedAt, null, $dryRun);
        return [
            'date' => $date,
            'status' => 'already_complete',
            'source' => 'db',
            'rows_upserted' => 0,
            'missing_hours' => [],
            'success' => true,
        ];
    }

    $jsonPrices = priceTicksLoadJsonPriceFile($date);
    if (is_array($jsonPrices) && priceTicksIsComplete($jsonPrices)) {
        $rows = priceTicksUpsertHourMap($pdo, $date, $jsonPrices, PRICE_TICKS_SOURCE_JSON, 1, $dryRun);
        priceTicksLogFetch($pdo, $date, $runType, PRICE_TICKS_SOURCE_JSON, true, $rows, [], $startedAt, null, $dryRun);
        return [
            'date' => $date,
            'status' => 'json_imported',
            'source' => PRICE_TICKS_SOURCE_JSON,
            'rows_upserted' => $rows,
            'missing_hours' => [],
            'success' => true,
        ];
    }

    $result = priceTicksReconcileDate(
        $pdo,
        $date,
        $runType,
        'priceTicksFetchEntsoe',
        $dryRun,
        PRICE_TICKS_SOURCE_ENTSOE_V6
    );
    if (!priceTicksDateStillIncomplete($pdo, $date)) {
        return $result;
    }

    return priceTicksReconcileDate(
        $pdo,
        $date,
        $runType,
        'priceTicksFetchEnergyzero',
        $dryRun,
        PRICE_TICKS_SOURCE_ENERGYZERO_V7
    );
}

function priceTicksPrintSummary(array $results): void {
    foreach ($results as $result) {
        $missing = $result['missing_hours'] ?? [];
        $missingText = is_array($missing) && $missing !== [] ? ' missing=' . implode(',', $missing) : '';
        $errorText = isset($result['error']) ? ' error=' . $result['error'] : '';
        echo sprintf(
            "%s %s source=%s rows=%d%s%s\n",
            $result['date'] ?? '--',
            $result['status'] ?? 'unknown',
            $result['source'] ?? '--',
            (int)($result['rows_upserted'] ?? 0),
            $missingText,
            $errorText
        );
    }
}
