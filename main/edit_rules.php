<?php
// main/edit_rules.php
// Standalone rule editor for data/charge_schedule_conditions.json

date_default_timezone_set('Europe/Amsterdam');

require_once __DIR__ . '/includes/config_loader.php';

$rulesFile = __DIR__ . '/data/charge_schedule_conditions.json';
$profilesFile = __DIR__ . '/data/rule_profiles.json';
const SHOW_ALL_PROFILE_ID = 'show_all';
const DEFAULT_PROFILE_IDS = ['profile_a', 'profile_b', 'profile_c', 'profile_d', 'profile_e'];
const DEFAULT_LIMIT_MIN = -1200;
const DEFAULT_LIMIT_MAX = 1200;

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
    return $value === 'netzero' || $value === 'netzero-' || $value === 'netzero+' || is_numeric($value);
}

function resolveRuleEditorLimits(): array
{
    $min = ConfigLoader::get('minGridPower', DEFAULT_LIMIT_MIN);
    $max = ConfigLoader::get('maxGridPower', DEFAULT_LIMIT_MAX);

    $min = is_numeric($min) ? (int) $min : DEFAULT_LIMIT_MIN;
    $max = is_numeric($max) ? (int) $max : DEFAULT_LIMIT_MAX;

    if ($min > $max) {
        return ['min' => DEFAULT_LIMIT_MIN, 'max' => DEFAULT_LIMIT_MAX];
    }

    return ['min' => $min, 'max' => $max];
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
        'active_profile_id' => SHOW_ALL_PROFILE_ID,
        'profiles' => [
            ['id' => 'profile_a', 'short_name' => 'A', 'description' => '', 'rule_ids' => []],
            ['id' => 'profile_b', 'short_name' => 'B', 'description' => '', 'rule_ids' => []],
            ['id' => 'profile_c', 'short_name' => 'C', 'description' => '', 'rule_ids' => []],
            ['id' => 'profile_d', 'short_name' => 'D', 'description' => '', 'rule_ids' => []],
            ['id' => 'profile_e', 'short_name' => 'E', 'description' => '', 'rule_ids' => []],
        ],
    ];
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
        $profilesById[$profileId] = [
            'id' => $profileId,
            'short_name' => $shortName !== '' ? $shortName : strtoupper(substr($profileId, -1)),
            'description' => $description,
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

    $orderedProfiles = [];
    foreach (DEFAULT_PROFILE_IDS as $profileId) {
        if (isset($profilesById[$profileId])) {
            $orderedProfiles[] = $profilesById[$profileId];
            unset($profilesById[$profileId]);
        }
    }
    foreach ($profilesById as $profile) {
        $orderedProfiles[] = $profile;
    }

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

        if (isset($rule['conditions']) && is_array($rule['conditions'])) {
            $conditions = [];
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
            }
            if (!empty($conditions)) {
                $normalized['conditions'] = $conditions;
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
            ]);
        }

        jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

$editorLimits = resolveRuleEditorLimits();
$editorLimitMin = $editorLimits['min'];
$editorLimitMax = $editorLimits['max'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Rules</title>
    <link rel="stylesheet" href="assets/css/edit_rules.css">
    <link rel="stylesheet" href="assets/css/edit_rules_color_picker.css">
</head>
<body>
<main class="rules-page">
    <section class="card">
        <div class="card-header-row">
            <h1>⚡ Zendure Rules Editor</h1>
            <div class="actions">
                <a class="btn-link" href="./">Back to /main</a>
                <button id="btn-export-json" type="button">Export JSON</button>
                <button id="btn-import-json" type="button">Import JSON</button>
                <button id="btn-save-imported" type="button" hidden disabled>Save Imported Rules</button>
                <button id="btn-raw-json" type="button">Raw JSON</button>
                <button id="btn-reload" type="button">Reload</button>
                <a class="btn-link btn-link-icon" href="edit_rules_help.php" target="_blank" rel="noopener" title="Help" aria-label="Help">ℹ️</a>
            </div>
        </div>
        <div class="file-row">
            <div id="status" class="status"></div>
            <p class="muted">File: <code>main/data/charge_schedule_conditions.json</code></p>
        </div>
        <input id="import-json-input" type="file" accept=".json,application/json" hidden>
    </section>

    <section class="card raw-json-card" id="raw-json-card" hidden>
        <div class="card-header-row">
            <h2>Raw JSON</h2>
            <div class="actions">
                <button id="btn-copy-raw-json" type="button">Copy</button>
                <button id="btn-close-raw-json" type="button">Close</button>
            </div>
        </div>
        <textarea id="raw-json-textarea" rows="12" readonly spellcheck="false"></textarea>
    </section>

    <section class="card">
        <div class="card-header-row">
            <h2>Rule Profiles</h2>
            <div class="actions">
                <button id="btn-activate-profile" type="button" hidden>Activate Profile</button>
                <button id="btn-save-profile" type="button">Save Profile</button>
            </div>
        </div>
        <div class="rule-profiles-box">
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
                </div>
                <div class="profile-editor-column profile-editor-column-rules">
                    <label>Rules In Profile</label>
                    <div id="profile-rule-membership" class="profile-rule-membership"></div>
                </div>
            </div>
        </div>
    </section>

    <section class="grid">
        <section class="card">
            <div class="card-header-row rules-list-header">
                <h2>Rules</h2>
                <button id="btn-new" type="button">+ New Rule</button>
            </div>
            <table class="rules-table">
                <tbody id="rules-tbody"></tbody>
            </table>
        </section>

        <section class="card">
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
                            <input id="limits-min-range" class="limits-range limits-range-min" type="range" min="<?php echo $editorLimitMin; ?>" max="<?php echo $editorLimitMax; ?>" step="100" value="<?php echo $editorLimitMin; ?>" aria-label="Minimum power limit">
                            <input id="limits-max-range" class="limits-range limits-range-max" type="range" min="<?php echo $editorLimitMin; ?>" max="<?php echo $editorLimitMax; ?>" step="100" value="<?php echo $editorLimitMax; ?>" aria-label="Maximum power limit">
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
                        <label title="All listed conditions must be true (AND).">Conditions</label>
                        <button id="btn-add-condition" type="button" title="Add a new condition row.">Add Condition</button>
                    </div>
                    <div id="conditions-list"></div>
                </div>

                <div class="actions">
                    <button id="btn-cancel" type="button">Cancel</button>
                    <button id="btn-save-rule" type="submit" class="primary">Save Rule</button>
                </div>
            </form>
        </section>
    </section>
</main>
<script>
window.EDIT_RULES_API_URL = '<?php echo htmlspecialchars(basename(__FILE__), ENT_QUOTES, 'UTF-8'); ?>?api=1';
window.EDIT_RULES_INITIAL_RULE = <?php echo $initialRule !== null ? $initialRule : 'null'; ?>;
window.EDIT_RULES_CONFIG = <?php echo json_encode([
    'limitMin' => $editorLimitMin,
    'limitMax' => $editorLimitMax,
], JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="assets/js/edit_rules.js"></script>
<script src="assets/js/edit_rules_color_picker.js"></script>
</body>
</html>
