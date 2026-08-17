<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/main/includes/rule_profile_auto.php';
require_once dirname(__DIR__, 2) . '/main/data/resolve_schedule_conditions.php';

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$config = [
    'selection_mode' => 'auto',
    'active_profile_id' => 'profile_a',
    'profiles' => [
        'profile_a' => ['id' => 'profile_a'],
        'profile_b' => ['id' => 'profile_b'],
    ],
];
$state = [
    'forecast_status' => 'stale',
    'days' => [
        '2026-08-17' => [
            'profile_id' => 'profile_b',
            'swr_wh_m2' => 3200,
            'reason' => 'stale_forecast_matched',
            'forecast_cached_at' => 123,
            'evaluated_at' => '2026-08-16T23:55:00+02:00',
        ],
    ],
];

$automatic = effectiveRuleProfileForDate($config, '20260817', $state);
assertSameValue('profile_b', $automatic['effective_profile_id'], 'Automatic date selection');
assertSameValue(3200, $automatic['swr_wh_m2'], 'SWR metadata');
assertSameValue('stale', $automatic['forecast_status'], 'Forecast status metadata');

$fallback = effectiveRuleProfileForDate($config, '20260818', $state);
assertSameValue('profile_a', $fallback['effective_profile_id'], 'Missing date falls back to manual profile');
assertSameValue('manual_fallback', $fallback['reason'], 'Fallback reason');

$config['selection_mode'] = 'manual';
$manual = effectiveRuleProfileForDate($config, '20260817', $state);
assertSameValue('profile_a', $manual['effective_profile_id'], 'Manual mode ignores automatic state');

$rules = normalizeRules([
    ['rule_id' => 'rule_a', 'key' => '************', 'value' => 100],
    ['rule_id' => 'rule_b', 'key' => '************', 'value' => 200],
]);
$profileConfig = normalizeProfileConfig([
    'selection_mode' => 'auto',
    'active_profile_id' => 'profile_a',
    'profiles' => [
        ['id' => 'profile_a', 'rule_ids' => ['rule_a']],
        ['id' => 'profile_b', 'rule_ids' => ['rule_b']],
    ],
], $rules);
$automatic = effectiveRuleProfileForDate($profileConfig, '20260817', $state);
$profileConfig['active_profile_id'] = $automatic['effective_profile_id'];
$prices = array_fill(0, 24, 10.0);
$resolved = resolveForDate('20260817', $rules, $prices, $profileConfig);
assertSameValue(200, $resolved[0]['value'], 'Date-specific profile filters resolved rules');

echo "rule_profile_auto_test: ok\n";
