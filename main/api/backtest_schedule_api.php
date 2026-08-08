<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/common/php/system_config.php';
require_once __DIR__ . '/charge_schedule_functions.php';
require_once dirname(__DIR__) . '/data/target_battery_planner.php';
require_once dirname(__DIR__) . '/data/resolve_schedule_conditions.php';
require_once dirname(__DIR__) . '/prices/price_ticks_common.php';

const BACKTEST_SCHEDULE_FILE = __DIR__ . '/../data/charge_schedule.json';

function backtestNormalizeDate(string $value, DateTimeZone $timezone): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Ymd', $value, $timezone);
    if (!$date instanceof DateTimeImmutable || $date->format('Ymd') !== $value) {
        throw new InvalidArgumentException('Invalid date. Expected YYYYMMDD.');
    }
    $today = new DateTimeImmutable('today', $timezone);
    if ($date >= $today) {
        throw new InvalidArgumentException('Choose a completed historical date.');
    }
    return $date;
}

function backtestNormalizeStartingSoc($value): float
{
    if (!is_numeric($value)) {
        throw new InvalidArgumentException('Starting battery level must be a number from 0 to 100.');
    }
    $percent = (float) $value;
    if ($percent < 0 || $percent > 100) {
        throw new InvalidArgumentException('Starting battery level must be between 0 and 100.');
    }
    return round($percent, 1);
}

/** @return array{prices:?array,source:?string} */
function backtestLoadPrices(string $dateYmd, ?PDO $pdo = null): array
{
    $date = priceTicksYmdToDate($dateYmd);
    if ($pdo instanceof PDO) {
        $prices = priceTicksLoadHourMapFromDb($pdo, $date);
        if (priceTicksIsComplete($prices)) {
            return ['prices' => $prices, 'source' => 'price_ticks'];
        }
    }

    $prices = priceTicksLoadJsonPriceFile($date);
    if (is_array($prices) && priceTicksIsComplete($prices)) {
        return ['prices' => $prices, 'source' => 'json'];
    }
    return ['prices' => null, 'source' => null];
}

function backtestMergeConditionalItems(array $resolved, array $conditionalItems): array
{
    $byTime = [];
    foreach ($conditionalItems as $item) {
        if (is_array($item) && isset($item['time']) && array_key_exists('value', $item)) {
            $byTime[str_pad((string) $item['time'], 4, '0', STR_PAD_LEFT)] = $item;
        }
    }

    foreach ($resolved as &$slot) {
        $time = str_pad((string) ($slot['time'] ?? ''), 4, '0', STR_PAD_LEFT);
        if (!isset($byTime[$time])) {
            continue;
        }
        $slotKey = (string) ($slot['key'] ?? '');
        if ($slotKey !== '' && strpos($slotKey, '*') === false) {
            continue;
        }

        $condition = $byTime[$time];
        $slot['value'] = $condition['value'];
        $slot['source'] = 'condition';
        foreach ([
            'runtime_conditions',
            'fallback_value',
            'rule_name',
            'rule_index',
            'rule_id',
            'min_power',
            'max_power',
            'target_soc_percent',
            'target_anchor',
            'max_discharge_power',
        ] as $field) {
            if (array_key_exists($field, $condition) && $condition[$field] !== null) {
                $slot[$field] = $condition[$field];
            } else {
                unset($slot[$field]);
            }
        }
    }
    unset($slot);
    return $resolved;
}

function backtestSolarEvents(array $groups): array
{
    $events = [];
    foreach ($groups as $group) {
        $date = (string) ($group['date'] ?? '');
        if ($date === '') {
            continue;
        }
        foreach (['sunrise', 'sunset'] as $eventName) {
            $time = $group[$eventName . '_time'] ?? null;
            if (!is_string($time) || preg_match('/^(\d{2}):(\d{2})/', $time, $matches) !== 1) {
                continue;
            }
            $events[$date][$eventName] = [
                'time' => substr($time, 0, 5),
                'minuteOfDay' => ((int) $matches[1] * 60) + (int) $matches[2],
            ];
        }
    }
    return $events;
}

function buildBacktestScenario(string $dateYmd, $startingSoc, ?PDO $pdo = null): array
{
    $systemConfig = loadSystemConfig();
    $timezone = new DateTimeZone($systemConfig['installation']['timezone']);
    $startDate = backtestNormalizeDate($dateYmd, $timezone);
    $startingPercent = backtestNormalizeStartingSoc($startingSoc);
    $dates = [$startDate->format('Ymd'), $startDate->modify('+1 day')->format('Ymd')];

    $ownsPdo = false;
    if ($pdo === null) {
        try {
            $pdo = priceTicksCreatePdo();
            $ownsPdo = true;
        } catch (Throwable $error) {
            $pdo = null;
        }
    }

    $priceMaps = [];
    $priceSources = [];
    foreach ($dates as $date) {
        $loaded = backtestLoadPrices($date, $pdo);
        if (!is_array($loaded['prices'])) {
            throw new RuntimeException('Historical prices are unavailable for ' . $date . '.');
        }
        $priceMaps[$date] = $loaded['prices'];
        $priceSources[$date] = $loaded['source'];
    }
    if ($ownsPdo) {
        $pdo = null;
    }

    $ruleGroups = resolveRuleGroupsForDates(
        $dates,
        $priceMaps,
        null,
        null,
        $systemConfig['installation']
    );
    $conditionsByDate = [];
    foreach ($ruleGroups as $group) {
        $conditionsByDate[(string) $group['date']] = $group['items'] ?? [];
    }

    $schedule = normalizeScheduleMap(loadSchedule(BACKTEST_SCHEDULE_FILE), 'historical backtest');
    $planningDays = [];
    foreach ($dates as $date) {
        $base = resolveScheduleForDate($schedule, $date);
        $planningDays[] = [
            'date' => $date,
            'items' => backtestMergeConditionalItems($base, $conditionsByDate[$date] ?? []),
        ];
    }

    $battery = [
        'percent' => $startingPercent,
        'capacity_wh' => (float) $systemConfig['battery']['capacityWh'],
        'minimum_percent' => (float) $systemConfig['battery']['minChargePercent'],
        'maximum_percent' => (float) $systemConfig['battery']['maxChargePercent'],
    ];
    $referenceTime = $startDate->setTime(0, 0);
    $planningDays = tbp_materialize_horizon($planningDays, $battery, $referenceTime, [
        'usage_w_by_hour' => $systemConfig['forecast']['defaultHouseholdUsageWByHour'],
        'efficiency' => $systemConfig['battery']['efficiency'],
        'max_discharge_power_w' => abs((int) $systemConfig['schedule']['minPowerW']),
        'max_charge_power_w' => (int) $systemConfig['schedule']['maxPowerW'],
        'charge_power_step_w' => (int) $systemConfig['schedule']['powerStepW'],
    ]);

    return [
        'success' => true,
        'mode' => 'simulation',
        'referenceTime' => $referenceTime->format(DateTimeInterface::ATOM),
        'startingBatteryPercent' => $startingPercent,
        'dates' => ['today' => $dates[0], 'tomorrow' => $dates[1]],
        'prices' => ['today' => $priceMaps[$dates[0]], 'tomorrow' => $priceMaps[$dates[1]]],
        'priceSources' => $priceSources,
        'schedules' => [
            'today' => $planningDays[0]['items'],
            'tomorrow' => $planningDays[1]['items'],
        ],
        'solarEvents' => backtestSolarEvents($ruleGroups),
        'entries' => ['today' => [], 'tomorrow' => []],
        'limitations' => [
            'Uses the current saved rules and active profile.',
            'Net-zero power is estimated without historical P1 replay.',
            'No live schedule or device state is changed.',
        ],
    ];
}

function shouldRunBacktestScheduleEntrypoint(): bool
{
    $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
    return is_string($script) && $script !== '' && realpath($script) === __FILE__;
}

/** @return array{status:int,payload:array} */
function handleBacktestScheduleRequest(string $method, array $query, ?PDO $pdo = null): array
{
    if ($method !== 'GET') {
        return [
            'status' => 405,
            'payload' => ['success' => false, 'error' => 'Method not allowed. Use GET.'],
        ];
    }

    try {
        $date = isset($query['date']) ? (string) $query['date'] : '';
        $soc = $query['soc'] ?? null;
        return ['status' => 200, 'payload' => buildBacktestScenario($date, $soc, $pdo)];
    } catch (InvalidArgumentException $error) {
        return ['status' => 400, 'payload' => ['success' => false, 'error' => $error->getMessage()]];
    } catch (RuntimeException $error) {
        return ['status' => 404, 'payload' => ['success' => false, 'error' => $error->getMessage()]];
    } catch (Throwable $error) {
        error_log('Backtest schedule API: ' . $error->getMessage());
        return [
            'status' => 500,
            'payload' => ['success' => false, 'error' => 'The historical scenario could not be built.'],
        ];
    }
}

if (shouldRunBacktestScheduleEntrypoint()) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('Allow: GET');

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $response = handleBacktestScheduleRequest($method, $_GET);
    http_response_code($response['status']);
    echo json_encode($response['payload'], JSON_UNESCAPED_SLASHES);
}
