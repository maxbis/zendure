<?php
declare(strict_types=1);

require_once __DIR__ . '/report_api_common.php';

/**
 * @return array<string, mixed>
 */
function dailyReportDbConfig(): array
{
    return [
        'host' => getenv('MARIADB_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('MARIADB_PORT') ?: '3306'),
        'database' => getenv('MARIADB_DATABASE') ?: 'sqlite_replication',
        'user' => getenv('MARIADB_USER') ?: 'root',
        'password' => getenv('MARIADB_PASSWORD') ?: '',
    ];
}

function dailyReportCreatePdo(): PDO
{
    $config = dailyReportDbConfig();
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

/**
 * @return array{
 *   generated: bool,
 *   source: string,
 *   path: string|null,
 *   savedAt: string|null,
 *   report: array<string, mixed>
 * }
 */
function dailyReportLoadSmart(string $date, bool $forceRegenerate = false): array
{
    $tz = dailyReportTimezone();
    dailyReportValidateDate($date, $tz);
    $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');

    $fixtureRoot = getenv('DAILY_REPORT_SMART_FIXTURE_DIR');
    if (!$forceRegenerate && $date !== $today && is_string($fixtureRoot) && trim($fixtureRoot) !== '') {
        return dailyReportLoadSmartFixture($date, trim($fixtureRoot));
    }

    if ($date === $today) {
        $loaded = dailyReportGenerateLive($date);
        $loaded['source'] = $forceRegenerate ? 'live_today_regenerated' : 'live_today';
        return $loaded;
    }

    if ($forceRegenerate) {
        dailyReportRegenerateAggregate($date);
    }

    $loaded = dailyReportLoadFromAggregate(dailyReportCreatePdo(), $date, $tz);
    $loaded['generated'] = $forceRegenerate;
    $loaded['source'] = $forceRegenerate ? 'aggregate_regenerated_manual' : 'aggregate_saved';
    return $loaded;
}

/**
 * Test helper path only: production code should leave DAILY_REPORT_SMART_FIXTURE_DIR unset.
 *
 * @return array{
 *   generated: bool,
 *   source: string,
 *   path: string,
 *   savedAt: string,
 *   report: array<string, mixed>
 * }
 */
function dailyReportLoadSmartFixture(string $date, string $root): array
{
    $yyyymm = str_replace('-', '', substr($date, 0, 7));
    $yyyymmdd = str_replace('-', '', $date);
    $path = rtrim(str_replace('\\', DIRECTORY_SEPARATOR, $root), '/\\')
        . DIRECTORY_SEPARATOR . $yyyymm
        . DIRECTORY_SEPARATOR . 'daily_report_' . $yyyymmdd . '.json';

    $report = dailyReportLoadJsonFile($path, 'smart report fixture');
    $savedAt = (new DateTimeImmutable('@' . filemtime($path)))
        ->setTimezone(dailyReportTimezone())
        ->format(DATE_ATOM);

    return [
        'generated' => false,
        'source' => 'aggregate_saved',
        'path' => $path,
        'savedAt' => $savedAt,
        'report' => $report,
    ];
}

/**
 * @return array{
 *   generated: bool,
 *   source: string,
 *   path: string|null,
 *   savedAt: string,
 *   report: array<string, mixed>
 * }
 */
function dailyReportGenerateLive(string $date): array
{
    $scriptPath = DAILY_REPORT_GENERATOR_SCRIPT;
    if (!file_exists($scriptPath)) {
        throw new RuntimeException('Report generator script not found.');
    }

    $output = dailyReportRunPythonCommand(
        $scriptPath,
        ['--date', $date, '--timezone', dailyReportTimezone()->getName()],
        'Report generator'
    );
    $report = json_decode($output, true);
    if (!is_array($report)) {
        throw new RuntimeException('Report generator returned invalid JSON.');
    }

    return [
        'generated' => true,
        'source' => 'live_today',
        'path' => null,
        'savedAt' => (new DateTimeImmutable('now', dailyReportTimezone()))->format(DATE_ATOM),
        'report' => $report,
    ];
}

/**
 * @return array{
 *   generated: bool,
 *   source: string,
 *   path: string|null,
 *   savedAt: string|null,
 *   report: array<string, mixed>
 * }
 */
function dailyReportLoadFromAggregate(PDO $pdo, string $date, DateTimeZone $tz): array
{
    $dayStart = new DateTimeImmutable($date . ' 00:00:00', $tz);
    $dayEnd = $dayStart->modify('+1 day');
    $dayStartTs = $dayStart->getTimestamp();
    $dayEndTs = $dayEnd->getTimestamp();
    $nowTs = (new DateTimeImmutable('now', $tz))->getTimestamp();
    $effectiveEndTs = max($dayStartTs, min($dayEndTs, $nowTs));

    $stmt = $pdo->prepare(
        'SELECT local_hour, hour_start_ts, hour_end_ts, charged_wh, discharged_wh,
            battery_pct_start, battery_pct_end, battery_pct_delta, grid_from_wh, grid_to_wh,
            battery_charge_grid_wh, battery_charge_surplus_wh,
            battery_discharge_home_wh, battery_discharge_export_wh,
            consumer_eur_per_kwh, spot_eur_per_kwh, price_source,
            battery_charge_cost_milli_eur, battery_home_savings_milli_eur,
            battery_export_revenue_milli_eur, battery_flow_pnl_milli_eur,
            battery_pnl_status, battery_pnl_method_version,
            source_rows, computed_at, updated_at
         FROM hourly_report_inputs
         WHERE local_date = ?
         ORDER BY local_hour ASC'
    );
    $stmt->execute([$date]);

    $rowsByHour = [];
    $savedCandidates = [];
    foreach ($stmt->fetchAll() as $row) {
        $hour = (int)$row['local_hour'];
        if ($hour < 0 || $hour > 23) {
            continue;
        }
        $rowsByHour[$hour] = $row;
        if (!empty($row['updated_at'])) {
            $savedCandidates[] = (string)$row['updated_at'];
        } elseif (!empty($row['computed_at'])) {
            $savedCandidates[] = (string)$row['computed_at'];
        }
    }

    $hours = [];
    $totalCharged = 0.0;
    $totalDischarged = 0.0;
    $totalGridFrom = 0.0;
    $totalGridTo = 0.0;
    $totalGridFromCost = 0.0;
    $totalGridToCost = 0.0;
    $totalNetCost = 0.0;
    $totalSavings = 0.0;
    $totalChargeCost = 0.0;
    $pnlTotalKeys = [
        'battery_charge_grid_wh',
        'battery_charge_surplus_wh',
        'battery_discharge_home_wh',
        'battery_discharge_export_wh',
        'battery_charge_cost_milli_eur',
        'battery_home_savings_milli_eur',
        'battery_export_revenue_milli_eur',
        'battery_flow_pnl_milli_eur',
    ];
    $pnlTotals = array_fill_keys($pnlTotalKeys, 0);
    $pnlAllComplete = true;
    $pnlMethodVersion = null;
    $priceHoursAvailable = 0;

    for ($hour = 0; $hour < 24; $hour++) {
        $row = $rowsByHour[$hour] ?? null;
        $hourKey = str_pad((string)$hour, 2, '0', STR_PAD_LEFT);
        $bucketStartTs = $row !== null ? (int)$row['hour_start_ts'] : $dayStartTs + ($hour * 3600);
        $nominalEndTs = $dayStartTs + (($hour + 1) * 3600);
        $bucketEndTs = $row !== null ? (int)$row['hour_end_ts'] : $nominalEndTs;
        $isPartialHour = $bucketStartTs < $effectiveEndTs && $bucketEndTs < $nominalEndTs;

        $chargedWh = dailyReportFloatValue($row['charged_wh'] ?? null) ?? 0.0;
        $dischargedWh = dailyReportFloatValue($row['discharged_wh'] ?? null) ?? 0.0;
        $batteryStart = dailyReportFloatValue($row['battery_pct_start'] ?? null);
        $batteryEnd = dailyReportFloatValue($row['battery_pct_end'] ?? null);
        $batteryDelta = dailyReportFloatValue($row['battery_pct_delta'] ?? null);
        $gridFromWh = dailyReportFloatValue($row['grid_from_wh'] ?? null);
        $gridToWh = dailyReportFloatValue($row['grid_to_wh'] ?? null);
        $price = dailyReportFloatValue($row['consumer_eur_per_kwh'] ?? null);
        $spotPrice = dailyReportFloatValue($row['spot_eur_per_kwh'] ?? null);
        $pnlStatus = is_string($row['battery_pnl_status'] ?? null)
            ? (string)$row['battery_pnl_status']
            : null;
        $rowPnlMethodVersion = dailyReportIntValue($row['battery_pnl_method_version'] ?? null);
        $pnlValues = [];
        foreach ($pnlTotalKeys as $key) {
            $pnlValues[$key] = dailyReportIntValue($row[$key] ?? null);
        }
        if (
            $pnlStatus === 'complete'
            && $rowPnlMethodVersion !== null
            && ($pnlMethodVersion === null || $pnlMethodVersion === $rowPnlMethodVersion)
            && !in_array(null, $pnlValues, true)
        ) {
            $pnlMethodVersion = $rowPnlMethodVersion;
            foreach ($pnlTotalKeys as $key) {
                $pnlTotals[$key] += (int)$pnlValues[$key];
            }
        } else {
            $pnlAllComplete = false;
        }

        $gridFromCost = null;
        $gridToCost = null;
        $netCost = null;
        $savingsEur = null;
        $chargeCostEur = null;

        $totalCharged += $chargedWh;
        $totalDischarged += $dischargedWh;
        if ($gridFromWh !== null) {
            $totalGridFrom += $gridFromWh;
        }
        if ($gridToWh !== null) {
            $totalGridTo += $gridToWh;
        }

        if ($price !== null) {
            $priceHoursAvailable++;
            $savingsEur = ($dischargedWh / 1000.0) * $price;
            $chargeCostEur = ($chargedWh / 1000.0) * $price;
            $totalSavings += $savingsEur;
            $totalChargeCost += $chargeCostEur;
            if ($gridFromWh !== null) {
                $gridFromCost = ($gridFromWh / 1000.0) * $price;
                $totalGridFromCost += $gridFromCost;
            }
            if ($gridToWh !== null) {
                $gridToCost = -1.0 * (($gridToWh / 1000.0) * $price);
                $totalGridToCost += $gridToCost;
            }
            if ($gridFromCost !== null || $gridToCost !== null) {
                $netCost = ($gridFromCost ?? 0.0) + ($gridToCost ?? 0.0);
                $totalNetCost += $netCost;
            }
        }

        $hours[] = [
            'hour' => $hourKey,
            'charged_wh' => round($chargedWh, 2),
            'discharged_wh' => round($dischargedWh, 2),
            'battery_pct_start' => dailyReportRoundValue($batteryStart, 2),
            'battery_pct_end' => dailyReportRoundValue($batteryEnd, 2),
            'battery_pct_delta' => dailyReportRoundValue($batteryDelta, 2),
            'grid_from_wh' => dailyReportRoundValue($gridFromWh, 2),
            'grid_to_wh' => dailyReportRoundValue($gridToWh, 2),
            'price_eur_per_kwh' => dailyReportRoundValue($price, 4),
            'spot_eur_per_kwh' => dailyReportRoundValue($spotPrice, 6),
            'battery_charge_grid_wh' => $pnlValues['battery_charge_grid_wh'],
            'battery_charge_surplus_wh' => $pnlValues['battery_charge_surplus_wh'],
            'battery_discharge_home_wh' => $pnlValues['battery_discharge_home_wh'],
            'battery_discharge_export_wh' => $pnlValues['battery_discharge_export_wh'],
            'battery_charge_cost_milli_eur' => $pnlValues['battery_charge_cost_milli_eur'],
            'battery_home_savings_milli_eur' => $pnlValues['battery_home_savings_milli_eur'],
            'battery_export_revenue_milli_eur' => $pnlValues['battery_export_revenue_milli_eur'],
            'battery_flow_pnl_milli_eur' => $pnlValues['battery_flow_pnl_milli_eur'],
            'battery_pnl_status' => $pnlStatus,
            'battery_pnl_method_version' => $rowPnlMethodVersion,
            'grid_from_cost' => dailyReportRoundValue($gridFromCost, 4),
            'grid_to_cost' => dailyReportRoundValue($gridToCost, 4),
            'net_cost' => dailyReportRoundValue($netCost, 4),
            'savings_eur' => dailyReportRoundValue($savingsEur, 4),
            'charge_cost_eur' => dailyReportRoundValue($chargeCostEur, 4),
            'is_partial_hour' => $isPartialHour,
        ];
    }

    $batteryStart = dailyReportFirstFinite($hours, 'battery_pct_start');
    $batteryEnd = dailyReportLastFinite($hours, 'battery_pct_end') ?? dailyReportLastFinite($hours, 'battery_pct_start');
    $batteryDeltaTotal = $batteryStart !== null && $batteryEnd !== null ? $batteryEnd - $batteryStart : null;
    $hasPrices = $priceHoursAvailable > 0;
    $savedAt = dailyReportLatestAtom($savedCandidates, $tz);

    return [
        'generated' => false,
        'source' => 'aggregate_saved',
        'path' => null,
        'savedAt' => $savedAt,
        'report' => [
            'date' => $date,
            'timezone' => $tz->getName(),
            'day_start_ts' => $dayStartTs,
            'day_end_ts' => $dayEndTs,
            'analysis_end_ts' => $effectiveEndTs,
            'is_partial_day' => $effectiveEndTs < $dayEndTs,
            'price_file_found' => $hasPrices,
            'price_file_path' => $hasPrices ? 'db:hourly_report_inputs' : null,
            'price_source' => 'db:hourly_report_inputs',
            'price_hours_available' => $priceHoursAvailable,
            'generated_at' => $savedAt,
            'hours' => $hours,
            'totals' => [
                'charged_wh' => round($totalCharged, 2),
                'discharged_wh' => round($totalDischarged, 2),
                'battery_pct_delta_total' => dailyReportRoundValue($batteryDeltaTotal, 2),
                'grid_from_wh' => round($totalGridFrom, 2),
                'grid_to_wh' => round($totalGridTo, 2),
                'grid_from_cost' => $hasPrices ? dailyReportRoundValue($totalGridFromCost, 4) : null,
                'grid_to_cost' => $hasPrices ? dailyReportRoundValue($totalGridToCost, 4) : null,
                'net_cost' => $hasPrices ? dailyReportRoundValue($totalNetCost, 4) : null,
                'savings_eur' => $hasPrices ? dailyReportRoundValue($totalSavings, 4) : null,
                'charge_cost_eur' => $hasPrices ? dailyReportRoundValue($totalChargeCost, 4) : null,
                ...($pnlAllComplete
                    ? $pnlTotals
                    : array_fill_keys($pnlTotalKeys, null)),
                'battery_pnl_status' => $pnlAllComplete ? 'complete' : 'incomplete',
                'battery_pnl_method_version' => $pnlAllComplete ? $pnlMethodVersion : null,
            ],
        ],
    ];
}

function dailyReportRegenerateAggregate(string $date): void
{
    $scriptPath = DAILY_REPORT_ROOT . '/tools/update_hourly_report_inputs.py';
    if (!file_exists($scriptPath)) {
        throw new RuntimeException('Aggregate updater script not found.');
    }

    dailyReportRunPythonCommand(
        $scriptPath,
        ['--date', $date, '--timezone', dailyReportTimezone()->getName()],
        'Aggregate updater'
    );
}

/**
 * @param array<int, string> $arguments
 */
function dailyReportRunPythonCommand(string $scriptPath, array $arguments, string $label): string
{
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (in_array('exec', $disabled, true)) {
        throw new RuntimeException(
            'PHP exec() is disabled (php.ini disable_functions). Run the report updater via cron/CLI.'
        );
    }

    $pythonBin = getenv('PYTHON_BIN');
    if (!is_string($pythonBin) || trim($pythonBin) === '') {
        $pythonBin = PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
    }

    $command = escapeshellarg($pythonBin) . ' ' . escapeshellarg($scriptPath);
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        $command .= ' 2>&1';
    }

    $outputLines = [];
    $exitCode = 0;
    exec($command, $outputLines, $exitCode);
    $output = trim(implode("\n", $outputLines));

    if ($exitCode !== 0) {
        if ($output === '') {
            $output = $label . ' exited with code ' . $exitCode . ' and no output.';
        }
        throw new RuntimeException($output);
    }

    return $output;
}

function dailyReportValidateDate(string $date, DateTimeZone $tz): void
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date, $tz);
    if ($dt === false || $dt->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid date. Expected YYYY-MM-DD.');
    }
}

function dailyReportFloatValue(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    return is_numeric($value) ? (float)$value : null;
}

function dailyReportIntValue(mixed $value): ?int
{
    if ($value === null || $value === '' || is_bool($value) || !is_numeric($value)) {
        return null;
    }
    return (int)$value;
}

function dailyReportRoundValue(?float $value, int $digits): ?float
{
    return $value === null ? null : round($value, $digits);
}

/**
 * @param array<int, array<string, mixed>> $hours
 */
function dailyReportFirstFinite(array $hours, string $key): ?float
{
    foreach ($hours as $row) {
        $value = dailyReportFloatValue($row[$key] ?? null);
        if ($value !== null) {
            return $value;
        }
    }
    return null;
}

/**
 * @param array<int, array<string, mixed>> $hours
 */
function dailyReportLastFinite(array $hours, string $key): ?float
{
    for ($index = count($hours) - 1; $index >= 0; $index--) {
        $value = dailyReportFloatValue($hours[$index][$key] ?? null);
        if ($value !== null) {
            return $value;
        }
    }
    return null;
}

/**
 * @param array<int, string> $values
 */
function dailyReportLatestAtom(array $values, DateTimeZone $tz): ?string
{
    if ($values === []) {
        return null;
    }
    rsort($values, SORT_STRING);
    try {
        return (new DateTimeImmutable($values[0], $tz))->format(DATE_ATOM);
    } catch (Throwable) {
        return $values[0];
    }
}
