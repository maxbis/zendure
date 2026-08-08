<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/main/api/backtest_schedule_api.php';

$failures = [];

function backtestAssert(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$timezone = new DateTimeZone('Europe/Amsterdam');
$historical = backtestNormalizeDate('20240115', $timezone);
backtestAssert($historical->format('Ymd') === '20240115', 'Historical dates should normalize.');

try {
    backtestNormalizeDate('not-a-date', $timezone);
    backtestAssert(false, 'Invalid dates should be rejected.');
} catch (InvalidArgumentException $error) {
    backtestAssert(true, 'Invalid date rejected.');
}

backtestAssert(backtestNormalizeStartingSoc('42.25') === 42.3, 'Starting SoC should normalize to one decimal.');
foreach ([-1, 101, 'invalid'] as $invalidSoc) {
    try {
        backtestNormalizeStartingSoc($invalidSoc);
        backtestAssert(false, 'Invalid starting SoC should be rejected.');
    } catch (InvalidArgumentException $error) {
        backtestAssert(true, 'Invalid SoC rejected.');
    }
}

$prices = [];
for ($hour = 0; $hour < 24; $hour++) {
    $prices[str_pad((string) $hour, 2, '0', STR_PAD_LEFT)] = $hour === 2 ? 0.35 : 0.10;
}
$rawRules = [[
    'key' => '************',
    'name' => 'Expensive hour',
    'value' => -500,
    'conditions' => [['field' => 'price', 'op' => '>', 'value' => 20]],
]];
$groups = resolveRuleGroupsForDates(
    ['20240115'],
    ['20240115' => $prices],
    $rawRules,
    ['active_profile_id' => 'show_all', 'profiles' => []],
    [
        'timezone' => 'Europe/Amsterdam',
        'latitude' => 52.37,
        'longitude' => 4.90,
    ]
);
backtestAssert(count($groups) === 1, 'Explicit historical date should resolve one group.');
backtestAssert(count($groups[0]['items'] ?? []) === 1, 'Only the matching historical price hour should resolve.');
backtestAssert(($groups[0]['items'][0]['time'] ?? null) === '0200', 'The matching hour should be 02:00.');
backtestAssert(($groups[0]['items'][0]['value'] ?? null) === -500, 'The current rule action should be retained.');

$base = [
    ['time' => '0100', 'value' => 'netzero', 'key' => '********0100'],
    ['time' => '0200', 'value' => 100, 'key' => '202401150200'],
];
$conditions = [
    ['time' => '0100', 'value' => -500, 'rule_name' => 'Historical condition'],
    ['time' => '0200', 'value' => -700, 'rule_name' => 'Must not override manual'],
];
$merged = backtestMergeConditionalItems($base, $conditions);
backtestAssert($merged[0]['value'] === -500, 'Conditional rules should replace wildcard base slots.');
backtestAssert(($merged[0]['rule_name'] ?? null) === 'Historical condition', 'Rule metadata should be copied.');
backtestAssert($merged[1]['value'] === 100, 'Exact manual schedule entries should retain priority.');

$methodResponse = handleBacktestScheduleRequest('POST', []);
backtestAssert($methodResponse['status'] === 405, 'Non-GET backtest requests should be rejected.');
$validationResponse = handleBacktestScheduleRequest('GET', ['date' => 'invalid', 'soc' => 50]);
backtestAssert($validationResponse['status'] === 400, 'Invalid request input should return a validation response.');

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec(
        'CREATE TABLE price_ticks (
            local_date TEXT NOT NULL,
            local_hour INTEGER NOT NULL,
            consumer_eur_per_kwh REAL NOT NULL
        )'
    );
    $statement = $pdo->prepare(
        'INSERT INTO price_ticks (local_date, local_hour, consumer_eur_per_kwh) VALUES (?, ?, ?)'
    );
    foreach (['2024-01-15', '2024-01-16'] as $date) {
        for ($hour = 0; $hour < 24; $hour++) {
            $statement->execute([$date, $hour, $date === '2024-01-15' && $hour === 0 ? 0.123456 : 0.10]);
        }
    }
    $loaded = backtestLoadPrices('20240115', $pdo);
    backtestAssert($loaded['source'] === 'price_ticks', 'MariaDB-compatible price rows should be the primary source.');
    backtestAssert(abs(($loaded['prices']['00'] ?? 0) - 0.123456) < 0.000001, 'Stored historical price should load.');

    $scenarioResponse = handleBacktestScheduleRequest('GET', ['date' => '20240115', 'soc' => '42.5'], $pdo);
    backtestAssert($scenarioResponse['status'] === 200, 'A complete stored two-day scenario should resolve.');
    $scenario = $scenarioResponse['payload'];
    backtestAssert(($scenario['mode'] ?? null) === 'simulation', 'Scenario should identify simulation mode.');
    backtestAssert(($scenario['startingBatteryPercent'] ?? null) === 42.5, 'Scenario should retain starting SoC.');
    backtestAssert(count($scenario['schedules']['today'] ?? []) >= 24, 'Scenario should return the selected day schedule.');
    backtestAssert(count($scenario['schedules']['tomorrow'] ?? []) >= 24, 'Scenario should return the following day schedule.');
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Historical backtest tests passed.\n";
