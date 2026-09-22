<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/daily_report/load_env.php';

daily_report_bootstrap_env();

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
        'SELECT local_date, local_hour, charged_wh, discharged_wh, grid_from_wh, grid_to_wh,
                battery_pct_start, battery_pct_end,
                consumer_eur_per_kwh, spot_eur_per_kwh,
                battery_charge_grid_wh, battery_charge_surplus_wh,
                battery_discharge_home_wh, battery_discharge_export_wh,
                battery_charge_cost_milli_eur, battery_home_savings_milli_eur,
                battery_export_revenue_milli_eur, battery_flow_pnl_milli_eur,
                battery_pnl_status, battery_pnl_method_version
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
            'grid_from_wh' => $hourRow['grid_from_wh'] ?? null,
            'grid_to_wh' => $hourRow['grid_to_wh'] ?? null,
            'battery_pct_start' => $hourRow['battery_pct_start'] ?? null,
            'battery_pct_end' => $hourRow['battery_pct_end'] ?? null,
            'consumer_eur_per_kwh' => $price['consumer_eur_per_kwh'] ?? null,
            'spot_eur_per_kwh' => $price['spot_eur_per_kwh'] ?? null,
            'battery_charge_grid_wh' => $hourRow['battery_charge_grid_wh'] ?? null,
            'battery_charge_surplus_wh' => $hourRow['battery_charge_surplus_wh'] ?? null,
            'battery_discharge_home_wh' => $hourRow['battery_discharge_home_wh'] ?? null,
            'battery_discharge_export_wh' => $hourRow['battery_discharge_export_wh'] ?? null,
            'battery_charge_cost_milli_eur' => $hourRow['battery_charge_cost_milli_eur'] ?? null,
            'battery_home_savings_milli_eur' => $hourRow['battery_home_savings_milli_eur'] ?? null,
            'battery_export_revenue_milli_eur' => $hourRow['battery_export_revenue_milli_eur'] ?? null,
            'battery_flow_pnl_milli_eur' => $hourRow['battery_flow_pnl_milli_eur'] ?? null,
            'battery_pnl_status' => $hourRow['battery_pnl_status'] ?? null,
            'battery_pnl_method_version' => $hourRow['battery_pnl_method_version'] ?? null,
        ];
    }
    return $mapped;
}

/**
 * Live reports contain placeholder rows for all 24 hours. Only elapsed hours
 * can contribute to today's totals; retain missing readings in elapsed hours.
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function appEnergyHistoryFilterFutureRows(array $rows, string $today, int $currentHour): array
{
    return array_values(array_filter($rows, static function (array $row) use ($today, $currentHour): bool {
        return (string)($row['local_date'] ?? '') !== $today
            || (int)($row['local_hour'] ?? -1) <= $currentHour;
    }));
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
        $gridFromWh = appEnergyHistoryFloat($row['grid_from_wh'] ?? null);
        $gridToWh = appEnergyHistoryFloat($row['grid_to_wh'] ?? null);
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
                'gridMoney' => [
                    'import' => appEnergyHistoryEmptyMoneyMetric(),
                    'export' => appEnergyHistoryEmptyMoneyMetric(),
                ],
                'batteryFlow' => [
                    'complete' => true,
                    'partial' => false,
                    'elapsedHours' => 0,
                    'valuedHours' => 0,
                    'missingHours' => [],
                    'reasons' => [],
                    'chargeGridWh' => 0,
                    'chargeSurplusWh' => 0,
                    'dischargeHomeWh' => 0,
                    'dischargeExportWh' => 0,
                    'chargeGridMilliEur' => 0,
                    'chargeSurplusMilliEur' => 0,
                    'chargeCostMilliEur' => 0,
                    'homeSavingsMilliEur' => 0,
                    'exportRevenueMilliEur' => 0,
                    'pnlMilliEur' => 0,
                    'conservative' => [
                        'chargeGridWh' => 0,
                        'chargeSurplusWh' => 0,
                        'chargeGridMilliEur' => 0,
                        'chargeSurplusMilliEur' => 0,
                        'chargeCostMilliEur' => 0,
                        'unclassifiedWh' => 0,
                        'unclassifiedValueMilliEur' => 0,
                        'dischargeValueMilliEur' => 0,
                        'pnlMilliEur' => 0,
                        'missingChargeHours' => [],
                        'missingDischargeHours' => [],
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

        foreach ([
            'import' => [$gridFromWh, $consumerPrice],
            'export' => [$gridToWh, $spotPrice],
        ] as $direction => [$energyWh, $price]) {
            if ($energyWh === null || $energyWh < 0 || ($energyWh > 0 && $price === null)) {
                $days[$date]['gridMoney'][$direction]['missingHours'][] = $hourLabel;
                continue;
            }
            if ($energyWh > 0) {
                $days[$date]['gridMoney'][$direction]['sum'] += ($energyWh / 1000.0) * $price;
            }
        }

        $batteryWhFields = [
            'battery_charge_grid_wh', 'battery_charge_surplus_wh',
            'battery_discharge_home_wh', 'battery_discharge_export_wh',
        ];
        $batteryMoneyFields = [
            'battery_charge_cost_milli_eur', 'battery_home_savings_milli_eur',
            'battery_export_revenue_milli_eur', 'battery_flow_pnl_milli_eur',
        ];
        $batteryValues = [];
        foreach (array_merge($batteryWhFields, $batteryMoneyFields) as $field) {
            $batteryValues[$field] = appEnergyHistoryFloat($row[$field] ?? null);
        }
        $batteryStatus = (string)($row['battery_pnl_status'] ?? 'unavailable');
        if ($batteryStatus === '') {
            $batteryStatus = 'unavailable';
        }
        $days[$date]['batteryFlow']['elapsedHours']++;
        $batteryComplete = $batteryStatus === 'complete'
            && (int)($row['battery_pnl_method_version'] ?? 0) === 2
            && !in_array(null, $batteryValues, true)
            && (
                $batteryValues['battery_charge_grid_wh'] + $batteryValues['battery_discharge_home_wh'] === 0.0
                || $consumerPrice !== null
            )
            && (
                $batteryValues['battery_charge_surplus_wh'] + $batteryValues['battery_discharge_export_wh'] === 0.0
                || $spotPrice !== null
            );
        if (!$batteryComplete) {
            $days[$date]['batteryFlow']['complete'] = false;
            $days[$date]['batteryFlow']['missingHours'][] = $hourLabel;
            $days[$date]['batteryFlow']['reasons'][] = $batteryStatus === 'complete' ? 'unavailable' : $batteryStatus;

            // Charging can still be valued when only the discharge destination is unknown.
            $chargeGridWh = $batteryValues['battery_charge_grid_wh'];
            $chargeSurplusWh = $batteryValues['battery_charge_surplus_wh'];
            $chargeKnown = $chargedWh <= 0.0 || (
                $chargeGridWh !== null && $chargeSurplusWh !== null
                && $chargeGridWh >= 0 && $chargeSurplusWh >= 0
                && abs($chargeGridWh + $chargeSurplusWh - $chargedWh) <= 1.0
            );
            if (!$chargeKnown || ($chargeGridWh > 0 && $consumerPrice === null)
                || ($chargeSurplusWh > 0 && $spotPrice === null)) {
                $days[$date]['batteryFlow']['conservative']['missingChargeHours'][] = $hourLabel;
            } elseif ($chargedWh > 0.0) {
                $conservative =& $days[$date]['batteryFlow']['conservative'];
                $gridWh = (int)round($chargeGridWh);
                $surplusWh = (int)round($chargeSurplusWh);
                $gridCost = (int)round($gridWh * ($consumerPrice ?? 0.0));
                $chargeCost = (int)round($gridWh * ($consumerPrice ?? 0.0) + $surplusWh * ($spotPrice ?? 0.0));
                $conservative['chargeGridWh'] += $gridWh;
                $conservative['chargeSurplusWh'] += $surplusWh;
                $conservative['chargeGridMilliEur'] += $gridCost;
                $conservative['chargeSurplusMilliEur'] += $chargeCost - $gridCost;
                $conservative['chargeCostMilliEur'] += $chargeCost;
                unset($conservative);
            }

            // Without a destination split, use the lower hourly price, not "export revenue".
            if ($dischargedWh > 0.0) {
                $conservative =& $days[$date]['batteryFlow']['conservative'];
                $unclassifiedWh = (int)round($dischargedWh);
                $conservative['unclassifiedWh'] += $unclassifiedWh;
                if ($consumerPrice === null || $spotPrice === null) {
                    $conservative['missingDischargeHours'][] = $hourLabel;
                } else {
                    $value = (int)round($unclassifiedWh * min($consumerPrice, $spotPrice));
                    $conservative['unclassifiedValueMilliEur'] += $value;
                    $conservative['dischargeValueMilliEur'] += $value;
                }
                unset($conservative);
            }
        } else {
            $flow =& $days[$date]['batteryFlow'];
            $flow['valuedHours']++;
            $flow['chargeGridWh'] += (int)$batteryValues['battery_charge_grid_wh'];
            $flow['chargeSurplusWh'] += (int)$batteryValues['battery_charge_surplus_wh'];
            $flow['dischargeHomeWh'] += (int)$batteryValues['battery_discharge_home_wh'];
            $flow['dischargeExportWh'] += (int)$batteryValues['battery_discharge_export_wh'];
            $gridChargeMilliEur = (int)round($batteryValues['battery_charge_grid_wh'] * ($consumerPrice ?? 0.0));
            $chargeMilliEur = (int)$batteryValues['battery_charge_cost_milli_eur'];
            $flow['chargeGridMilliEur'] += $gridChargeMilliEur;
            $flow['chargeSurplusMilliEur'] += $chargeMilliEur - $gridChargeMilliEur;
            $flow['chargeCostMilliEur'] += $chargeMilliEur;
            $flow['homeSavingsMilliEur'] += (int)$batteryValues['battery_home_savings_milli_eur'];
            $flow['exportRevenueMilliEur'] += (int)$batteryValues['battery_export_revenue_milli_eur'];
            $flow['pnlMilliEur'] += (int)$batteryValues['battery_flow_pnl_milli_eur'];
            $conservative =& $flow['conservative'];
            $conservative['chargeGridWh'] += (int)$batteryValues['battery_charge_grid_wh'];
            $conservative['chargeSurplusWh'] += (int)$batteryValues['battery_charge_surplus_wh'];
            $conservative['chargeGridMilliEur'] += $gridChargeMilliEur;
            $conservative['chargeSurplusMilliEur'] += $chargeMilliEur - $gridChargeMilliEur;
            $conservative['chargeCostMilliEur'] += $chargeMilliEur;
            $conservative['dischargeValueMilliEur'] += (int)$batteryValues['battery_home_savings_milli_eur']
                + (int)$batteryValues['battery_export_revenue_milli_eur'];
            unset($conservative);
            unset($flow);
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
            $pnlComplete = $charged['complete'] && $discharged['complete'];
            $priceTotals[$priceType] = [
                'charged' => $charged,
                'discharged' => $discharged,
                // PnL: discharge value minus charge cost (negative spot charge is a benefit).
                'pnl' => [
                    'eur' => $pnlComplete ? round((float)$discharged['eur'] - (float)$charged['eur'], 6) : null,
                    'complete' => $pnlComplete,
                    'missingHours' => array_values(array_unique(array_merge(
                        $charged['missingHours'],
                        $discharged['missingHours']
                    ))),
                ],
            ];
        }

        $batteryFlow = $day['batteryFlow'];
        $batteryFlow['reasons'] = array_values(array_unique($batteryFlow['reasons']));
        $batteryFlow['partial'] = !$batteryFlow['complete'] && $batteryFlow['valuedHours'] > 0;
        $conservative =& $batteryFlow['conservative'];
        $conservative['chargeComplete'] = $conservative['missingChargeHours'] === [];
        $conservative['dischargeComplete'] = $conservative['missingDischargeHours'] === [];
        $conservative['complete'] = $conservative['chargeComplete'] && $conservative['dischargeComplete'];
        if (!$conservative['chargeComplete']) {
            foreach (['chargeGridWh', 'chargeSurplusWh', 'chargeGridMilliEur',
                'chargeSurplusMilliEur', 'chargeCostMilliEur'] as $field) {
                $conservative[$field] = null;
            }
        }
        if (!$conservative['dischargeComplete']) {
            $conservative['unclassifiedValueMilliEur'] = null;
            $conservative['dischargeValueMilliEur'] = null;
        }
        $conservative['pnlMilliEur'] = $conservative['complete']
            ? $conservative['dischargeValueMilliEur'] - $conservative['chargeCostMilliEur']
            : null;
        unset($conservative);
        if (!$batteryFlow['complete'] && !$batteryFlow['partial']) {
            foreach ([
                'chargeGridWh', 'chargeSurplusWh', 'dischargeHomeWh', 'dischargeExportWh',
                'chargeGridMilliEur', 'chargeSurplusMilliEur', 'chargeCostMilliEur',
                'homeSavingsMilliEur', 'exportRevenueMilliEur', 'pnlMilliEur',
            ] as $field) {
                $batteryFlow[$field] = null;
            }
        }

        $whPerDay[$date] = [
            'pos' => round($day['chargedWh'], 2),
            'neg' => round(-$day['dischargedWh'], 2),
            'priceTotals' => $priceTotals,
            'gridPriceTotals' => [
                'import' => appEnergyHistoryFinishMoneyMetric($day['gridMoney']['import']),
                'export' => appEnergyHistoryFinishMoneyMetric($day['gridMoney']['export']),
            ],
            'batteryFlowTotals' => $batteryFlow,
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
