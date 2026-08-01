<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/daily_report/load_env.php';

daily_report_bootstrap_env();

const APP_ENERGY_HISTORY_TIMEZONE = 'Europe/Amsterdam';
const APP_ENERGY_HISTORY_DAYS_DEFAULT = 3;
const APP_ENERGY_HISTORY_DAYS_MAX = 30;

/** @return array{host:string,port:int,database:string,user:string,password:string} */
function appEnergyHistoryDbConfig(): array
{
    return [
        'host' => getenv('MARIADB_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('MARIADB_PORT') ?: '3306'),
        'database' => getenv('MARIADB_DATABASE') ?: 'sqlite_replication',
        'user' => getenv('MARIADB_USER') ?: 'root',
        'password' => getenv('MARIADB_PASSWORD') ?: '',
    ];
}

function appEnergyHistoryCreatePdo(): PDO
{
    $config = appEnergyHistoryDbConfig();
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['port'],
        $config['database']
    );

    return new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function appEnergyHistoryResolveDays(mixed $rawValue): int
{
    if ($rawValue === null || $rawValue === '') {
        return APP_ENERGY_HISTORY_DAYS_DEFAULT;
    }
    if ((!is_string($rawValue) && !is_int($rawValue)) || !preg_match('/^\d+$/', (string)$rawValue)) {
        return APP_ENERGY_HISTORY_DAYS_DEFAULT;
    }

    return min((int)$rawValue, APP_ENERGY_HISTORY_DAYS_MAX);
}

/** @return array<int, array<string, mixed>> */
function appEnergyHistoryFetchRows(PDO $pdo, string $startDate, string $endDate): array
{
    $stmt = $pdo->prepare(
        'SELECT local_date, local_hour, charged_wh, discharged_wh,
                battery_pct_start, battery_pct_end,
                consumer_eur_per_kwh, spot_eur_per_kwh
         FROM hourly_report_inputs
         WHERE local_date BETWEEN :start_date AND :end_date
         ORDER BY local_date ASC, local_hour ASC'
    );
    $stmt->execute(['start_date' => $startDate, 'end_date' => $endDate]);
    return $stmt->fetchAll();
}

/** @return array<int, array<string, mixed>> */
function appEnergyHistoryFetchPriceRows(PDO $pdo, string $date): array
{
    $stmt = $pdo->prepare(
        'SELECT local_hour, consumer_eur_per_kwh, spot_eur_per_kwh
         FROM price_ticks
         WHERE local_date = :local_date
         ORDER BY local_hour ASC'
    );
    $stmt->execute(['local_date' => $date]);
    return $stmt->fetchAll();
}

/**
 * Convert the canonical live daily-report output to the same row shape as
 * hourly_report_inputs so current-day and historical rows share aggregation.
 *
 * @param array<string, mixed> $report
 * @param array<int, array<string, mixed>> $priceRows
 * @return array<int, array<string, mixed>>
 */
function appEnergyHistoryMapLiveReportRows(array $report, array $priceRows, string $date): array
{
    $pricesByHour = [];
    foreach ($priceRows as $priceRow) {
        $hour = (int)($priceRow['local_hour'] ?? -1);
        if ($hour < 0 || $hour > 23) {
            continue;
        }
        $pricesByHour[$hour] = [
            'consumer_eur_per_kwh' => appEnergyHistoryFloat($priceRow['consumer_eur_per_kwh'] ?? null),
            'spot_eur_per_kwh' => appEnergyHistoryFloat($priceRow['spot_eur_per_kwh'] ?? null),
        ];
    }

    $mapped = [];
    $hours = is_array($report['hours'] ?? null) ? $report['hours'] : [];
    foreach ($hours as $hourRow) {
        if (!is_array($hourRow)) {
            continue;
        }
        $hourValue = $hourRow['hour'] ?? null;
        if ((!is_string($hourValue) && !is_int($hourValue)) || !preg_match('/^\d{1,2}$/', (string)$hourValue)) {
            continue;
        }
        $hour = (int)$hourValue;
        if ($hour < 0 || $hour > 23) {
            continue;
        }
        $price = $pricesByHour[$hour] ?? [];
        $mapped[] = [
            'local_date' => $date,
            'local_hour' => $hour,
            'charged_wh' => $hourRow['charged_wh'] ?? 0,
            'discharged_wh' => $hourRow['discharged_wh'] ?? 0,
            'battery_pct_start' => $hourRow['battery_pct_start'] ?? null,
            'battery_pct_end' => $hourRow['battery_pct_end'] ?? null,
            'consumer_eur_per_kwh' => $price['consumer_eur_per_kwh'] ?? null,
            'spot_eur_per_kwh' => $price['spot_eur_per_kwh'] ?? null,
        ];
    }
    return $mapped;
}

function appEnergyHistoryFloat(mixed $value): ?float
{
    if ($value === null || is_bool($value) || !is_numeric($value)) {
        return null;
    }
    $number = (float)$value;
    return is_finite($number) ? $number : null;
}

/** @return array{sum:float,missingHours:array<int,string>} */
function appEnergyHistoryEmptyMoneyMetric(): array
{
    return ['sum' => 0.0, 'missingHours' => []];
}

/** @param array{sum:float,missingHours:array<int,string>} $metric */
function appEnergyHistoryFinishMoneyMetric(array $metric): array
{
    $complete = $metric['missingHours'] === [];
    return [
        'eur' => $complete ? round($metric['sum'], 6) : null,
        'complete' => $complete,
        'missingHours' => $metric['missingHours'],
    ];
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<string, mixed>
 */
function appEnergyHistoryBuildPayload(
    array $rows,
    int $requestedDays,
    string $todaySource = 'sqlite_replication.status_updates',
    bool $isStale = false
): array
{
    $whPerHour = [];
    $days = [];

    foreach ($rows as $row) {
        $date = (string)($row['local_date'] ?? '');
        $hourNumber = (int)($row['local_hour'] ?? 0);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $hourNumber < 0 || $hourNumber > 23) {
            continue;
        }

        $hour = str_pad((string)$hourNumber, 2, '0', STR_PAD_LEFT);
        $hourLabel = $date . ' ' . $hour . ':00';
        $chargedWh = max(0.0, appEnergyHistoryFloat($row['charged_wh'] ?? null) ?? 0.0);
        $dischargedWh = max(0.0, appEnergyHistoryFloat($row['discharged_wh'] ?? null) ?? 0.0);
        $consumerPrice = appEnergyHistoryFloat($row['consumer_eur_per_kwh'] ?? null);
        $spotPrice = appEnergyHistoryFloat($row['spot_eur_per_kwh'] ?? null);
        $battery = appEnergyHistoryFloat($row['battery_pct_end'] ?? null)
            ?? appEnergyHistoryFloat($row['battery_pct_start'] ?? null);
        if ($battery !== null) {
            $battery = max(0.0, min(100.0, $battery));
        }

        if (!isset($days[$date])) {
            $days[$date] = [
                'chargedWh' => 0.0,
                'dischargedWh' => 0.0,
                'money' => [
                    'consumer' => [
                        'charged' => appEnergyHistoryEmptyMoneyMetric(),
                        'discharged' => appEnergyHistoryEmptyMoneyMetric(),
                    ],
                    'spot' => [
                        'charged' => appEnergyHistoryEmptyMoneyMetric(),
                        'discharged' => appEnergyHistoryEmptyMoneyMetric(),
                    ],
                ],
            ];
        }

        $days[$date]['chargedWh'] += $chargedWh;
        $days[$date]['dischargedWh'] += $dischargedWh;

        foreach (['consumer' => $consumerPrice, 'spot' => $spotPrice] as $priceType => $price) {
            foreach (['charged' => $chargedWh, 'discharged' => $dischargedWh] as $direction => $energyWh) {
                if ($energyWh <= 0.0) {
                    continue;
                }
                if ($price === null) {
                    $days[$date]['money'][$priceType][$direction]['missingHours'][] = $hourLabel;
                    continue;
                }
                $days[$date]['money'][$priceType][$direction]['sum'] += ($energyWh / 1000.0) * $price;
            }
        }

        $whPerHour[] = [
            'hourLabel' => $hourLabel,
            'wh' => round($chargedWh - $dischargedWh, 2),
            'chargedWh' => round($chargedWh, 3),
            'dischargedWh' => round($dischargedWh, 3),
            'electricLevel' => $battery,
            'consumerEurPerKwh' => $consumerPrice,
            'spotEurPerKwh' => $spotPrice,
        ];
    }

    $whPerDay = [];
    foreach ($days as $date => $day) {
        $priceTotals = [];
        foreach (['consumer', 'spot'] as $priceType) {
            $charged = appEnergyHistoryFinishMoneyMetric($day['money'][$priceType]['charged']);
            $discharged = appEnergyHistoryFinishMoneyMetric($day['money'][$priceType]['discharged']);
            $netComplete = $charged['complete'] && $discharged['complete'];
            $priceTotals[$priceType] = [
                'charged' => $charged,
                'discharged' => $discharged,
                'net' => [
                    'eur' => $netComplete ? round((float)$charged['eur'] - (float)$discharged['eur'], 6) : null,
                    'complete' => $netComplete,
                    'missingHours' => array_values(array_unique(array_merge(
                        $charged['missingHours'],
                        $discharged['missingHours']
                    ))),
                ],
            ];
        }

        $whPerDay[$date] = [
            'pos' => round($day['chargedWh'], 2),
            'neg' => round(-$day['dischargedWh'], 2),
            'priceTotals' => $priceTotals,
        ];
    }
    krsort($whPerDay, SORT_STRING);

    return [
        'whPerHour' => $whPerHour,
        'whPerDay' => $whPerDay,
        'cacheInfo' => [
            'source' => 'hybrid',
            'todaySource' => $todaySource,
            'historySource' => 'sqlite_replication.hourly_report_inputs',
            'priceSource' => 'sqlite_replication.price_ticks',
            'days' => $requestedDays,
            'isStale' => $isStale,
        ],
    ];
}
