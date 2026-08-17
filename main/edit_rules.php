<?php
// main/edit_rules.php
// Standalone rule editor for data/charge_schedule_conditions.json

require_once __DIR__ . '/includes/config_loader.php';
require_once __DIR__ . '/includes/sun_context.php';
require_once __DIR__ . '/includes/price_context.php';
require_once dirname(__DIR__) . '/common/php/system_config.php';
require_once __DIR__ . '/includes/rule_profile_auto.php';

$rulesFile = __DIR__ . '/data/charge_schedule_conditions.json';
$profilesFile = __DIR__ . '/data/rule_profiles.json';
const SHOW_ALL_PROFILE_ID = 'show_all';

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_PRETTY_PRINT);
    exit();
}

function readRulesFile(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON structure in rules file.');
    }
    return $data;
}

function writeRulesFileAtomic(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Failed to encode JSON.');
    }
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Failed to write temporary file.');
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Failed to replace target file.');
    }
}

function validateValue($value): bool
{
    return $value === 'netzero' || $value === 'netzero-' || $value === 'netzero+' || $value === 'empty_at_solar_charge' || $value === 'full_at_netzero_minus' || is_numeric($value);
}

function isRuntimeOnlyConditionField(string $field): bool
{
    return in_array($field, ['electricity_level', 'electric_level', 'electricLevel'], true);
}

function normalizeConditionRelationValue($value): string
{
    if (!is_string($value)) {
        return 'and';
    }
    $normalized = strtolower(trim($value));
    return $normalized === 'or' ? 'or' : 'and';
}

function resolveRuleEditorLimits(array $systemConfig): array
{
    return [
        'min' => (int) $systemConfig['schedule']['minPowerW'],
        'max' => (int) $systemConfig['schedule']['maxPowerW'],
    ];
}

/**
 * Today's sunrise/sunset and price anchors for condition help tooltips.
 *
 * @return array<string, mixed>|null
 */
function resolveEditorTodayContext(): ?array
{
    try {
        $systemConfig = loadSystemConfig();
        $installation = $systemConfig['installation'];
        $tz = new DateTimeZone((string) $installation['timezone']);
        $today = new DateTimeImmutable('now', $tz);
        $dateYmd = $today->format('Ymd');
        $context = [
            'date' => $dateYmd,
            'date_label' => $today->format('Y-m-d'),
        ];

        $sunCtx = getSunContextForDate(
            $dateYmd,
            (float) $installation['latitude'],
            (float) $installation['longitude'],
            $tz
        );
        if ($sunCtx !== []) {
            $context = array_merge($context, $sunCtx);
        }

        $yyyymm = substr($dateYmd, 0, 6);
        $pricePath = __DIR__ . '/data/price/' . $yyyymm . '/price' . $dateYmd . '.json';
        if (is_file($pricePath) && is_readable($pricePath)) {
            $raw = file_get_contents($pricePath);
            $priceData = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($priceData)) {
                $priceCtx = buildPriceContext($priceData);
                foreach ([
                    'min_price',
                    'max_price',
                    'min_price_hour',
                    'max_price_hour',
                    'max_price_hour_am',
                    'max_price_hour_pm',
                    'spread_price',
                ] as $key) {
                    if (array_key_exists($key, $priceCtx) && $priceCtx[$key] !== null) {
                        $context[$key] = is_float($priceCtx[$key])
                            ? round((float) $priceCtx[$key], 2)
                            : $priceCtx[$key];
                    }
                }
            }
        }

        return $context;
    } catch (Throwable $e) {
        return null;
    }
}

function generateRuleId(): string
{
    try {
        return 'rule_' . bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        return 'rule_' . str_replace('.', '', uniqid('', true));
    }
}

function normalizeRuleId($value, array &$usedIds): string
{
    $candidate = is_string($value) ? trim($value) : '';
    if ($candidate === '' || isset($usedIds[$candidate])) {
        do {
            $candidate = generateRuleId();
        } while (isset($usedIds[$candidate]));
    }
    $usedIds[$candidate] = true;
    return $candidate;
}

function defaultRuleProfilesConfig(): array
{
    return [
        'selection_mode' => 'manual',
        'active_profile_id' => SHOW_ALL_PROFILE_ID,
        'profiles' => [
            ['id' => 'profile_a', 'short_name' => 'A', 'description' => '', 'swr_min_wh_m2' => null, 'swr_max_wh_m2' => null, 'rule_ids' => []],
            ['id' => 'profile_b', 'short_name' => 'B', 'description' => '', 'swr_min_wh_m2' => null, 'swr_max_wh_m2' => null, 'rule_ids' => []],
            ['id' => 'profile_c', 'short_name' => 'C', 'description' => '', 'swr_min_wh_m2' => null, 'swr_max_wh_m2' => null, 'rule_ids' => []],
            ['id' => 'profile_d', 'short_name' => 'D', 'description' => '', 'swr_min_wh_m2' => null, 'swr_max_wh_m2' => null, 'rule_ids' => []],
            ['id' => 'profile_e', 'short_name' => 'E', 'description' => '', 'swr_min_wh_m2' => null, 'swr_max_wh_m2' => null, 'rule_ids' => []],
        ],
    ];
}

function normalizeProfileSwrBound($value, string $label): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_bool($value) || !is_numeric($value)) {
        throw new InvalidArgumentException($label . ' must be a non-negative number or empty.');
    }
    $number = (float) $value;
    if ($number < 0 || floor($number) !== $number) {
        throw new InvalidArgumentException($label . ' must be a non-negative whole number or empty.');
    }
    return (int) $number;
}

function normalizeProfileRuleIds($value, array $validRuleIds): array
{
    if (!is_array($value)) {
        return [];
    }
    $seen = [];
    $normalized = [];
    foreach ($value as $ruleId) {
        if (!is_string($ruleId)) {
            continue;
        }
        $trimmed = trim($ruleId);
        if ($trimmed === '' || !isset($validRuleIds[$trimmed]) || isset($seen[$trimmed])) {
            continue;
        }
        $seen[$trimmed] = true;
        $normalized[] = $trimmed;
    }
    return $normalized;
}

function normalizeProfiles(array $config, array $rules): array
{
    $default = defaultRuleProfilesConfig();
    $validRuleIds = [];
    foreach ($rules as $rule) {
        if (isset($rule['rule_id']) && is_string($rule['rule_id']) && $rule['rule_id'] !== '') {
            $validRuleIds[$rule['rule_id']] = true;
        }
    }

    $rawProfiles = isset($config['profiles']) && is_array($config['profiles']) ? $config['profiles'] : [];
    $profilesById = [];

    foreach ($rawProfiles as $profile) {
        if (!is_array($profile)) {
            continue;
        }
        $profileId = isset($profile['id']) && is_string($profile['id']) ? trim($profile['id']) : '';
        if ($profileId === '' || $profileId === SHOW_ALL_PROFILE_ID) {
            continue;
        }
        $shortName = isset($profile['short_name']) ? trim((string) $profile['short_name']) : '';
        $description = isset($profile['description']) ? trim((string) $profile['description']) : '';
        $swrMin = normalizeProfileSwrBound($profile['swr_min_wh_m2'] ?? null, 'SWR minimum for ' . $profileId);
        $swrMax = normalizeProfileSwrBound($profile['swr_max_wh_m2'] ?? null, 'SWR maximum for ' . $profileId);
        if ($swrMin !== null && $swrMax !== null && $swrMin >= $swrMax) {
            throw new InvalidArgumentException('SWR minimum must be lower than maximum for ' . $profileId . '.');
        }
        $profilesById[$profileId] = [
            'id' => $profileId,
            'short_name' => $shortName !== '' ? $shortName : strtoupper(substr($profileId, -1)),
            'description' => $description,
            'swr_min_wh_m2' => $swrMin,
            'swr_max_wh_m2' => $swrMax,
            'rule_ids' => normalizeProfileRuleIds($profile['rule_ids'] ?? [], $validRuleIds),
        ];
    }

    foreach ($default['profiles'] as $index => $profile) {
        $profileId = $profile['id'];
        if (!isset($profilesById[$profileId])) {
            $profilesById[$profileId] = $profile;
            continue;
        }
        if ($profilesById[$profileId]['short_name'] === '') {
            $profilesById[$profileId]['short_name'] = $profile['short_name'];
        }
    }

    // PHP arrays preserve insertion order, which is the automatic-selection priority.
    $orderedProfiles = array_values($profilesById);

    $activeProfileId = isset($config['active_profile_id']) && is_string($config['active_profile_id'])
        ? trim($config['active_profile_id'])
        : SHOW_ALL_PROFILE_ID;
    if ($activeProfileId !== SHOW_ALL_PROFILE_ID) {
        $knownIds = array_column($orderedProfiles, 'id');
        if (!in_array($activeProfileId, $knownIds, true)) {
            $activeProfileId = SHOW_ALL_PROFILE_ID;
        }
    }

    return [
        'selection_mode' => ($config['selection_mode'] ?? 'manual') === 'auto' ? 'auto' : 'manual',
        'active_profile_id' => $activeProfileId,
        'profiles' => array_values($orderedProfiles),
    ];
}

function normalizeOptionalRuleBound($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_int($value)) {
        return $value;
    }
    if (is_bool($value)) {
        return null;
    }
    if (is_string($value)) {
        $trimmed = trim($value);
        if (preg_match('/^-?\d+$/', $trimmed) !== 1) {
            return null;
        }
        return (int) $trimmed;
    }
    if (is_float($value)) {
        if ((float) ((int) $value) !== $value) {
            return null;
        }
        return (int) $value;
    }
    return null;
}

function validateCondition(array $condition): bool
{
    if (!isset($condition['field'], $condition['op'])) {
        return false;
    }
    $hasValue = array_key_exists('value', $condition);
    $hasValueRef = isset($condition['value_ref']) && $condition['value_ref'] !== '';
    if (!$hasValue && !$hasValueRef) {
        return false;
    }
    $field = (string) $condition['field'];
    $op = (string) $condition['op'];
    $validFields = [
        'price', 'ranking', 'min_time', 'max_time', 'month', 'hour',
        'min_price', 'max_price', 'min_price_hour', 'max_price_hour', 'spread_price',
        'sunrise_hour', 'sunset_hour', 'sunrise_offset_hour', 'sunset_offset_hour',
        'electricity_level'
    ];
    $validOps = ['>', '>=', '<', '<=', '==', '!=', 'in'];
    $validValueRefs = [
        'min_price', 'max_price', 'min_price_hour', 'max_price_hour',
        'max_price_hour_am', 'max_price_hour_pm', 'spread_price',
        'sunrise_hour', 'sunset_hour'
    ];
    if (!in_array($field, $validFields, true)) {
        return false;
    }
    if (!in_array($op, $validOps, true)) {
        return false;
    }
    if ($hasValueRef && !in_array((string) $condition['value_ref'], $validValueRefs, true)) {
        return false;
    }
    if ($hasValueRef && $hasValue) {
        $value = $condition['value'];
        if ($value !== null) {
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value !== '' && !is_numeric($value)) {
                return false;
            }
        }
    }
    return true;
}

function normalizeRuleColor($value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    if (!preg_match('/^#([0-9a-fA-F]{6})$/', $trimmed)) {
        return null;
    }

    return strtoupper($trimmed);
}

function normalizeRules(array $rules): array
{
    $out = [];
    $usedRuleIds = [];
    foreach ($rules as $rule) {
        if (!is_array($rule) || !array_key_exists('value', $rule)) {
            continue;
        }
        $name = isset($rule['name']) ? trim((string) $rule['name']) : '';
        if ($name === '') {
            continue;
        }
        if (!validateValue($rule['value'])) {
            continue;
        }

        $normalized = [];
        $normalized['rule_id'] = normalizeRuleId($rule['rule_id'] ?? null, $usedRuleIds);
        $normalized['name'] = $name;
        $normalized['value'] = is_numeric($rule['value']) ? (int) $rule['value'] : (string) $rule['value'];
        $normalized['enabled'] = !array_key_exists('enabled', $rule) ? true : (bool) $rule['enabled'];
        $color = array_key_exists('color', $rule) ? normalizeRuleColor($rule['color']) : null;
        if ($color !== null) {
            $normalized['color'] = $color;
        }

        if (isset($rule['key']) && is_string($rule['key']) && $rule['key'] !== '') {
            $normalized['key'] = $rule['key'];
        }
        foreach (['month', 'hour', 'min_time', 'max_time'] as $k) {
            if (array_key_exists($k, $rule) && $rule[$k] !== '' && $rule[$k] !== null) {
                $normalized[$k] = $rule[$k];
            }
        }
        if (array_key_exists('fallback_value', $rule) && $rule['fallback_value'] !== '' && $rule['fallback_value'] !== null && validateValue($rule['fallback_value'])) {
            $normalized['fallback_value'] = is_numeric($rule['fallback_value']) ? (int) $rule['fallback_value'] : (string) $rule['fallback_value'];
        }
        if ($normalized['value'] === 'netzero' || $normalized['value'] === 'netzero-' || $normalized['value'] === 'netzero+') {
            $minValue = array_key_exists('min_power', $rule) ? normalizeOptionalRuleBound($rule['min_power']) : null;
            $maxValue = array_key_exists('max_power', $rule) ? normalizeOptionalRuleBound($rule['max_power']) : null;
            $normalized['min_power'] = $minValue;
            $normalized['max_power'] = $maxValue;
            if (
                $normalized['min_power'] !== null &&
                $normalized['max_power'] !== null &&
                $normalized['min_power'] > $normalized['max_power']
            ) {
                $normalized['min_power'] = null;
                $normalized['max_power'] = null;
            }
        }
        if ($normalized['value'] === 'empty_at_solar_charge') {
            $batteryLimits = loadSystemConfig()['battery'];
            $minimumTargetSoc = (float) $batteryLimits['minChargePercent'];
            $maximumTargetSoc = (float) $batteryLimits['maxChargePercent'];
            $targetSoc = isset($rule['target_soc_percent']) && is_numeric($rule['target_soc_percent'])
                ? (float) $rule['target_soc_percent']
                : null;
            if ($targetSoc === null || $targetSoc < $minimumTargetSoc || $targetSoc > $maximumTargetSoc) {
                continue;
            }
            $normalized['target_soc_percent'] = round($targetSoc, 1);
            $normalized['target_anchor'] = 'next_solar_capable_netzero';
            $maxDischarge = normalizeOptionalRuleBound($rule['max_discharge_power'] ?? null);
            if ($maxDischarge !== null && $maxDischarge > 0) {
                $normalized['max_discharge_power'] = $maxDischarge;
            }
        }
        if ($normalized['value'] === 'full_at_netzero_minus') {
            $normalized['target_anchor'] = 'next_netzero_minus';
        }

        if (isset($rule['conditions']) && is_array($rule['conditions'])) {
            $conditions = [];
            $hasRuntimeOnlyCondition = false;
            foreach ($rule['conditions'] as $condition) {
                if (!is_array($condition) || !validateCondition($condition)) {
                    continue;
                }
                $conditions[] = [
                    'field' => (string) $condition['field'],
                    'op' => (string) $condition['op'],
                ];
                if (array_key_exists('value', $condition)) {
                    $conditions[count($conditions) - 1]['value'] = $condition['value'];
                }
                if (isset($condition['value_ref']) && $condition['value_ref'] !== '') {
                    $conditions[count($conditions) - 1]['value_ref'] = (string) $condition['value_ref'];
                }
                if (isRuntimeOnlyConditionField((string) $condition['field'])) {
                    $hasRuntimeOnlyCondition = true;
                }
            }
            if (!empty($conditions)) {
                $normalized['conditions'] = $conditions;
                $conditionRelation = normalizeConditionRelationValue($rule['condition_relation'] ?? 'and');
                $normalized['condition_relation'] = $hasRuntimeOnlyCondition ? 'and' : $conditionRelation;
            }
        }

        $out[] = $normalized;
    }
    return $out;
}

$isApi = isset($_GET['api']) && $_GET['api'] === '1';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$initialRule = isset($_GET['rule']) && is_numeric($_GET['rule']) ? (int) $_GET['rule'] : null;
if ($initialRule !== null && $initialRule < 1) {
    $initialRule = null;
}

if ($isApi) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

    if ($method === 'OPTIONS') {
        http_response_code(200);
        exit();
    }

    try {
        if ($method === 'GET') {
            $rawRules = readRulesFile($rulesFile);
            $rules = normalizeRules($rawRules);
            if ($rules !== $rawRules) {
                writeRulesFileAtomic($rulesFile, $rules);
            }

            $rawProfiles = readRulesFile($profilesFile);
            $profiles = normalizeProfiles($rawProfiles, $rules);
            if ($profiles !== $rawProfiles) {
                writeRulesFileAtomic($profilesFile, $profiles);
            }

            jsonResponse([
                'success' => true,
                'rules' => $rules,
                'rule_profiles' => $profiles,
                'rule_profile_auto_state' => readRuleProfileAutoState(),
            ]);
        }

        if ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input) || !isset($input['rules']) || !is_array($input['rules'])) {
                jsonResponse(['success' => false, 'error' => 'Expected JSON body: { "rules": [], "rule_profiles": {} }'], 400);
            }
            $normalized = normalizeRules($input['rules']);
            $normalizedProfiles = normalizeProfiles(
                isset($input['rule_profiles']) && is_array($input['rule_profiles']) ? $input['rule_profiles'] : [],
                $normalized
            );
            writeRulesFileAtomic($rulesFile, $normalized);
            writeRulesFileAtomic($profilesFile, $normalizedProfiles);
            jsonResponse([
                'success' => true,
                'message' => 'Rules saved successfully.',
                'count' => count($normalized),
                'rules' => $normalized,
                'rule_profiles' => $normalizedProfiles,
                'rule_profile_auto_state' => readRuleProfileAutoState(),
            ]);
        }

        jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

$editorSystemConfig = loadSystemConfig();
$editorLimits = resolveRuleEditorLimits($editorSystemConfig);
$editorLimitMin = $editorLimits['min'];
$editorLimitMax = $editorLimits['max'];
$editorPowerStepW = (int) $editorSystemConfig['schedule']['powerStepW'];
$editorMinChargePercent = (int) $editorSystemConfig['battery']['minChargePercent'];
$editorMaxChargePercent = (int) $editorSystemConfig['battery']['maxChargePercent'];
$editorTodayContext = resolveEditorTodayContext();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Rules</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/icons/edit-rules-icon-32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/icons/edit-rules-icon-180.png">
    <link rel="stylesheet" href="../themes/graphite-signal-dark/assets/css/theme.css">
    <link rel="stylesheet" href="../themes/graphite-signal-dark/assets/css/components.css">
    <link rel="stylesheet" href="assets/css/edit_rules.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/edit_rules.css'); ?>">
    <link rel="stylesheet" href="assets/css/edit_rules_color_picker.css">
</head>
<body data-theme="graphite-signal-dark">
<main class="rules-page">
    <section class="gsd-card card">
        <div class="card-header-row">
            <h1>⚡ Zendure Rules Editor</h1>
            <div class="actions">
                <a class="gsd-btn gsd-btn--quiet btn-link" href="./">Back to /main</a>
                <button id="btn-save-imported" class="gsd-btn gsd-btn--primary" type="button" hidden disabled>Save Imported Rules</button>
                <div class="global-actions-menu" id="global-actions-menu">
                    <button id="btn-global-actions" class="gsd-btn gsd-btn--secondary global-actions-toggle" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="global-actions-popover">
                        Actions <span aria-hidden="true">▾</span>
                    </button>
                    <div class="global-actions-popover" id="global-actions-popover" role="menu">
                        <button id="btn-reload" class="gsd-btn gsd-btn--quiet" type="button" role="menuitem">Reload rules</button>
                        <button id="btn-raw-json" class="gsd-btn gsd-btn--quiet" type="button" role="menuitem">View raw JSON</button>
                        <button id="btn-export-json" class="gsd-btn gsd-btn--quiet" type="button" role="menuitem">Export JSON</button>
                        <button id="btn-import-json" class="gsd-btn gsd-btn--quiet" type="button" role="menuitem">Import JSON…</button>
                        <button id="btn-reevaluate-profiles" class="gsd-btn gsd-btn--quiet" type="button" role="menuitem">Re-evaluate automatic profiles…</button>
                        <div class="rule-actions-separator" role="separator" aria-hidden="true"></div>
                        <button id="btn-delete-unused-rules" class="gsd-btn gsd-btn--danger danger" type="button" role="menuitem">Delete unused rules</button>
                    </div>
                </div>
                <a class="gsd-btn gsd-btn--quiet btn-link btn-link-icon" href="edit_rules_help.php" target="_blank" rel="noopener" title="Help" aria-label="Help">ℹ️</a>
            </div>
        </div>
        <div class="file-row">
            <div id="status" class="status"></div>
            <p class="muted">File: <code>main/data/charge_schedule_conditions.json</code></p>
        </div>
        <input id="import-json-input" type="file" accept=".json,application/json" hidden>
    </section>

    <section class="gsd-card card raw-json-card" id="raw-json-card" hidden>
        <div class="card-header-row">
            <h2>Raw JSON</h2>
            <div class="actions">
                <button id="btn-copy-raw-json" class="gsd-btn gsd-btn--secondary" type="button">Copy</button>
                <button id="btn-close-raw-json" class="gsd-btn gsd-btn--quiet" type="button">Close</button>
            </div>
        </div>
        <textarea id="raw-json-textarea" rows="12" readonly spellcheck="false"></textarea>
    </section>

    <section class="gsd-card card">
        <div class="card-header-row">
            <h2>Rule Profiles</h2>
            <div class="actions">
                <button id="btn-activate-profile" class="gsd-btn gsd-btn--secondary" type="button" hidden>Activate Profile</button>
                <button id="btn-save-profile" class="gsd-btn gsd-btn--primary" type="button">Save Profile</button>
            </div>
        </div>
        <div class="rule-profiles-box">
            <div class="profile-mode-panel">
                <div>
                    <strong>Profile selection</strong>
                    <p class="muted">Manual uses the selected fallback profile. Automatic uses the first SWR range that matches from left to right.</p>
                </div>
                <div class="profile-mode-toggle" role="radiogroup" aria-label="Profile selection mode">
                    <label><input id="profile-mode-manual" type="radio" name="profile-selection-mode" value="manual"> <span>Manual</span></label>
                    <label><input id="profile-mode-auto" type="radio" name="profile-selection-mode" value="auto"> <span>Automatic · SWR</span></label>
                </div>
            </div>
            <div id="profile-auto-status" class="profile-auto-status" aria-live="polite"></div>
            <div id="profile-selection-status" class="profile-selection-status" aria-live="polite"></div>
            <div id="profile-button-bar" class="profile-button-bar" aria-label="Rule profiles"></div>
            <div id="profile-editor" class="profile-editor" hidden>
                <div class="profile-editor-column profile-editor-column-fields">
                    <div class="profile-field-group">
                        <label for="inp-profile-short-name">Short Name</label>
                        <input id="inp-profile-short-name" type="text" maxlength="20" placeholder="A">
                    </div>
                    <div class="profile-field-group">
                        <label for="inp-profile-description">Description</label>
                        <input id="inp-profile-description" type="text" maxlength="120" placeholder="Profile description">
                    </div>
                    <div class="profile-swr-fields">
                        <strong>Automatic SWR range</strong>
                        <div class="profile-swr-grid">
                            <label>Minimum
                                <span><input id="inp-profile-swr-min" type="number" min="0" step="1" placeholder="No minimum"> Wh/m²</span>
                            </label>
                            <label>Maximum
                                <span><input id="inp-profile-swr-max" type="number" min="0" step="1" placeholder="No maximum"> Wh/m²</span>
                            </label>
                        </div>
                        <p id="profile-swr-help" class="muted"></p>
                        <div class="profile-order-actions">
                            <button id="btn-profile-left" class="gsd-btn gsd-btn--quiet" type="button">Move left</button>
                            <button id="btn-profile-right" class="gsd-btn gsd-btn--quiet" type="button">Move right</button>
                        </div>
                    </div>
                </div>
                <div class="profile-editor-column profile-editor-column-rules">
                    <label>Rules In Profile</label>
                    <div id="profile-rule-membership" class="profile-rule-membership"></div>
                </div>
            </div>
            <div id="profile-auto-preview" class="profile-auto-preview" hidden>
                <div class="profile-auto-preview-heading">
                    <strong>Upcoming automatic selections</strong>
                    <span class="muted">Fresh, stale, retained, or carried forward per date</span>
                </div>
                <div class="profile-auto-table-wrap">
                    <table class="profile-auto-table">
                        <thead><tr><th>Date</th><th>SWR</th><th>Profile</th><th>Status</th></tr></thead>
                        <tbody id="profile-auto-preview-body"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <section class="grid">
        <section class="gsd-card card">
            <div class="card-header-row rules-list-header">
                <h2>Rules</h2>
                <button id="btn-new" class="gsd-btn gsd-btn--primary" type="button">+ New Rule</button>
            </div>
            <table class="rules-table">
                <tbody id="rules-tbody"></tbody>
            </table>
        </section>

        <section class="gsd-card card">
            <h2 id="editor-title">Rule Editor</h2>
            <form id="rule-form" novalidate>
                <div class="editor-top-grid">
                    <div class="editor-grid-item editor-grid-name">
                        <label for="inp-name">Name</label>
                        <input id="inp-name" type="text" placeholder="Rule name" required>
                    </div>
                    <div class="editor-grid-item editor-grid-mode">
                        <label for="inp-value-mode">Value Mode</label>
                        <select id="inp-value-mode" class="value-mode-select">
                            <option value="fixed">Fixed</option>
                            <option value="netzero">netzero</option>
                            <option value="netzero-">netzero-</option>
                            <option value="netzero+">netzero+</option>
                            <option value="empty_at_solar_charge">Target @ solar charge</option>
                            <option value="full_at_netzero_minus">Target @ next NZ-</option>
                        </select>
                    </div>
                    <div class="editor-grid-item editor-grid-fixed">
                        <label for="inp-fixed-value">Fixed Value (W)</label>
                        <input id="inp-fixed-value" type="number" step="1" placeholder="500">
                    </div>
                    <div class="editor-grid-item editor-grid-color">
                        <label for="inp-color">Rule Color</label>
                        <div class="rule-color-field" data-rule-color-field>
                            <input id="inp-color" type="text" placeholder="#FF7043" inputmode="text" autocomplete="off">
                        </div>
                    </div>

                    <div class="editor-grid-item editor-grid-month">
                        <label for="inp-month">Month</label>
                        <input id="inp-month" type="text" placeholder="10,11,12,1,2,3">
                    </div>
                    <div class="editor-grid-item editor-grid-hour">
                        <label for="inp-hour">Hour</label>
                        <input id="inp-hour" type="text" placeholder="1,2,17,18">
                    </div>
                    <div class="editor-grid-item editor-grid-min-time">
                        <label for="inp-min-time">Min Time</label>
                        <input id="inp-min-time" type="text" placeholder="10">
                    </div>
                    <div class="editor-grid-item editor-grid-max-time">
                        <label for="inp-max-time">Max Time</label>
                        <input id="inp-max-time" type="text" placeholder="11">
                    </div>
                </div>

                <div id="charge-target-row" class="battery-target-panel" hidden>
                    <div class="battery-target-heading">
                        <div>
                            <span class="battery-target-eyebrow">Live recalculated minimum</span>
                            <h3>Charge target</h3>
                        </div>
                        <span class="battery-target-anchor">At first scheduled NZ-</span>
                    </div>
                    <div class="battery-target-grid">
                        <div class="editor-grid-item">
                            <label>Target battery level</label>
                            <strong><?php echo htmlspecialchars((string) $editorMaxChargePercent); ?>%</strong>
                        </div>
                        <div class="editor-grid-item">
                            <label>Maximum charge power</label>
                            <strong><?php echo htmlspecialchars((string) max(0, $editorLimitMax)); ?> W</strong>
                        </div>
                    </div>
                    <p class="battery-target-help">Every schedule refresh, the planner divides the remaining battery deficit across this rule's remaining matching hours before NZ-. It emits NZ+ with a minimum charge limit rounded upward to 100 W.</p>
                    <div class="battery-target-preview">The live minimum appears in the Prices and Energy Plan after the rule is saved.</div>
                </div>

                <div id="battery-target-row" class="battery-target-panel" hidden>
                    <div class="battery-target-heading">
                        <div>
                            <span class="battery-target-eyebrow">Forecast-calculated value</span>
                            <h3>Battery target</h3>
                        </div>
                        <span class="battery-target-anchor">At first solar-capable net-zero slot</span>
                    </div>
                    <div class="battery-target-grid">
                        <div class="editor-grid-item">
                            <label for="inp-target-soc">Requested spare level (%)</label>
                            <input id="inp-target-soc" type="number" step="0.1" min="<?php echo $editorMinChargePercent; ?>" max="<?php echo $editorMaxChargePercent; ?>" required>
                        </div>
                        <div class="editor-grid-item">
                            <label for="inp-max-discharge-power">Maximum discharge (W, optional)</label>
                            <input id="inp-max-discharge-power" type="number" step="1" min="1" max="<?php echo max(abs($editorLimitMin), abs($editorLimitMax)); ?>" placeholder="<?php echo abs($editorLimitMin); ?>">
                        </div>
                    </div>
                    <p class="battery-target-help">The planner calculates a fixed discharge value for this rule hour so the forecast reaches the requested level when the next NZ+ or charging-capable NZ± period starts.</p>
                    <div id="battery-target-preview" class="battery-target-preview" aria-live="polite">The live calculation appears in the Prices and Energy Plan after the rule is saved.</div>
                </div>

                <div id="limits-row" class="row" hidden>
                    <div class="limits-heading-row">
                        <label>Power Limits</label>
                        <div class="limits-toggle" role="radiogroup" aria-label="Power limits enabled">
                            <label class="limits-toggle-option">
                                <input id="limits-off" type="radio" name="limits-enabled" value="off" checked>
                                <span>Off</span>
                            </label>
                            <label class="limits-toggle-option">
                                <input id="limits-on" type="radio" name="limits-enabled" value="on">
                                <span>On</span>
                            </label>
                        </div>
                    </div>
                    <div id="limits-slider-panel" class="limits-slider-panel" hidden>
                        <input id="inp-min-value" type="hidden">
                        <input id="inp-max-value" type="hidden">
                        <div class="limits-value-row">
                            <div class="limits-value-chip limits-value-chip-min">
                                <span class="limits-value-label">Min</span>
                                <strong id="limits-min-display"><?php echo $editorLimitMin; ?> W</strong>
                            </div>
                            <div class="limits-value-chip limits-value-chip-max">
                                <span class="limits-value-label">Max</span>
                                <strong id="limits-max-display"><?php echo $editorLimitMax; ?> W</strong>
                            </div>
                        </div>
                        <div id="limits-slider" class="limits-slider">
                            <div class="limits-slider-track"></div>
                            <div id="limits-selected-range" class="limits-selected-range"></div>
                            <input id="limits-min-range" class="limits-range limits-range-min" type="range" min="<?php echo $editorLimitMin; ?>" max="<?php echo $editorLimitMax; ?>" step="<?php echo $editorPowerStepW; ?>" value="<?php echo $editorLimitMin; ?>" aria-label="Minimum power limit">
                            <input id="limits-max-range" class="limits-range limits-range-max" type="range" min="<?php echo $editorLimitMin; ?>" max="<?php echo $editorLimitMax; ?>" step="<?php echo $editorPowerStepW; ?>" value="<?php echo $editorLimitMax; ?>" aria-label="Maximum power limit">
                        </div>
                        <div id="power-range-indicator" class="power-range-indicator" hidden></div>
                    </div>
                </div>

                <div id="fallback-row" class="editor-top-grid editor-top-grid-fallback" hidden>
                    <div class="editor-grid-item editor-grid-fallback-label">
                        <label for="inp-fallback-value">Fallback Value (optional)</label>
                    </div>
                    <div class="editor-grid-item editor-grid-fallback-input">
                        <select id="inp-fallback-value" class="fallback-select">
                            <option value="">Select fallback value</option>
                            <option value="netzero">netzero</option>
                            <option value="netzero-">netzero-</option>
                            <option value="netzero+">netzero+</option>
                            <option value="-800">-800</option>
                            <option value="-400">-400</option>
                            <option value="-200">-200</option>
                            <option value="0">0</option>
                            <option value="200">200</option>
                            <option value="400">400</option>
                            <option value="800">800</option>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="conditions-header">
                        <div class="conditions-header-main">
                            <label title="Static condition rows use this relation. Runtime-only rows force AND.">Conditions</label>
                            <div class="condition-relation-controls">
                                <label for="inp-condition-relation">Relation</label>
                                <select id="inp-condition-relation" title="Choose how static condition rows are combined within this rule.">
                                    <option value="and">AND</option>
                                    <option value="or">OR</option>
                                </select>
                            </div>
                        </div>
                        <button id="btn-add-condition" class="gsd-btn gsd-btn--secondary" type="button" title="Add a new condition row.">Add Condition</button>
                    </div>
                    <div id="condition-relation-note" class="muted condition-relation-note">OR applies only to static condition rows. Runtime-only rows force AND.</div>
                    <div id="conditions-list"></div>
                </div>

                <div class="actions">
                    <button id="btn-cancel" class="gsd-btn gsd-btn--quiet" type="button">Cancel</button>
                    <button id="btn-save-rule" type="submit" class="gsd-btn gsd-btn--primary">Save Rule</button>
                </div>
            </form>
        </section>
    </section>
</main>

<dialog class="gsd-dialog" id="unsaved-rule-dialog" role="alertdialog" aria-labelledby="unsaved-rule-dialog-title" aria-describedby="unsaved-rule-dialog-message">
    <header class="gsd-dialog__header gsd-dialog__header--simple">
        <h2 class="gsd-dialog__title" id="unsaved-rule-dialog-title">Unsaved changes</h2>
        <button class="gsd-icon-btn" type="button" id="unsaved-rule-dialog-close" aria-label="Close dialog">
            <span aria-hidden="true">&times;</span>
        </button>
    </header>
    <div class="gsd-dialog__body">
        <p class="gsd-dialog__lead" id="unsaved-rule-dialog-message">This rule has unsaved changes. Save before leaving?</p>
    </div>
    <footer class="gsd-dialog__footer">
        <button class="gsd-btn gsd-btn--secondary" type="button" id="unsaved-rule-dialog-cancel" data-gsd-initial-focus>Keep editing</button>
        <button class="gsd-btn gsd-btn--quiet" type="button" id="unsaved-rule-dialog-discard">Discard</button>
        <button class="gsd-btn gsd-btn--primary" type="button" id="unsaved-rule-dialog-save">Save</button>
    </footer>
</dialog>

<dialog class="gsd-dialog" id="delete-unused-rules-dialog" role="alertdialog" aria-labelledby="delete-unused-rules-dialog-title" aria-describedby="delete-unused-rules-dialog-message">
    <header class="gsd-dialog__header gsd-dialog__header--simple">
        <h2 class="gsd-dialog__title" id="delete-unused-rules-dialog-title">Delete unused rules?</h2>
        <button class="gsd-icon-btn" type="button" id="delete-unused-rules-dialog-close" aria-label="Close dialog">
            <span aria-hidden="true">&times;</span>
        </button>
    </header>
    <div class="gsd-dialog__body">
        <p class="gsd-dialog__lead" id="delete-unused-rules-dialog-message"></p>
        <p class="muted delete-unused-rules-scope">Every profile is checked, regardless of which profile is currently selected.</p>
        <ul id="delete-unused-rules-list" class="delete-unused-rules-list"></ul>
    </div>
    <footer class="gsd-dialog__footer">
        <button class="gsd-btn gsd-btn--secondary" type="button" id="delete-unused-rules-dialog-cancel" data-gsd-initial-focus>Cancel</button>
        <button class="gsd-btn gsd-btn--danger" type="button" id="delete-unused-rules-dialog-confirm">Delete unused rules</button>
    </footer>
</dialog>

<script>
window.EDIT_RULES_API_URL = '<?php echo htmlspecialchars(basename(__FILE__), ENT_QUOTES, 'UTF-8'); ?>?api=1';
window.EDIT_AUTO_PROFILES_API_URL = 'api/evaluate_auto_profiles_api.php';
window.EDIT_SCHEDULE_REFRESH_URL = 'api/refresh_schedule_proxy.php';
window.EDIT_RULES_INITIAL_RULE = <?php echo $initialRule !== null ? $initialRule : 'null'; ?>;
window.EDIT_RULES_CONFIG = <?php echo json_encode([
    'limitMin' => $editorLimitMin,
    'limitMax' => $editorLimitMax,
    'powerStepW' => $editorPowerStepW,
    'minChargePercent' => $editorMinChargePercent,
    'maxChargePercent' => $editorMaxChargePercent,
    'sunToday' => $editorTodayContext,
    'todayContext' => $editorTodayContext,
], JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="assets/js/edit_rules.js?v=<?php echo (int) filemtime(__DIR__ . '/assets/js/edit_rules.js'); ?>"></script>
<script src="assets/js/edit_rules_color_picker.js"></script>
</body>
</html>
