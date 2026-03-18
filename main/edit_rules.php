<?php
// main/edit_rules.php
// Standalone rule editor for data/charge_schedule_conditions.json

date_default_timezone_set('Europe/Amsterdam');

$rulesFile = __DIR__ . '/data/charge_schedule_conditions.json';

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
    return $value === 'netzero' || $value === 'netzero+' || is_numeric($value);
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
        'min_price', 'max_price', 'min_price_hour', 'max_price_hour', 'spread_price',
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
    return true;
}

function normalizeRules(array $rules): array
{
    $out = [];
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
        $normalized['name'] = $name;
        $normalized['value'] = is_numeric($rule['value']) ? (int) $rule['value'] : (string) $rule['value'];
        $normalized['enabled'] = !array_key_exists('enabled', $rule) ? true : (bool) $rule['enabled'];

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
        if ($normalized['value'] === 'netzero' || $normalized['value'] === 'netzero+') {
            $minValue = array_key_exists('min_value', $rule) ? normalizeOptionalRuleBound($rule['min_value']) : null;
            $maxValue = array_key_exists('max_value', $rule) ? normalizeOptionalRuleBound($rule['max_value']) : null;
            $normalized['min_value'] = $minValue;
            $normalized['max_value'] = $maxValue;
            if (
                $normalized['min_value'] !== null &&
                $normalized['max_value'] !== null &&
                $normalized['min_value'] > $normalized['max_value']
            ) {
                $normalized['min_value'] = null;
                $normalized['max_value'] = null;
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
            $rules = readRulesFile($rulesFile);
            jsonResponse(['success' => true, 'rules' => $rules]);
        }

        if ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input) || !isset($input['rules']) || !is_array($input['rules'])) {
                jsonResponse(['success' => false, 'error' => 'Expected JSON body: { "rules": [] }'], 400);
            }
            $normalized = normalizeRules($input['rules']);
            writeRulesFileAtomic($rulesFile, $normalized);
            jsonResponse([
                'success' => true,
                'message' => 'Rules saved successfully.',
                'count' => count($normalized),
            ]);
        }

        jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Rules</title>
    <link rel="stylesheet" href="assets/css/edit_rules.css">
</head>
<body>
<main class="rules-page">
    <section class="card">
        <div class="card-header-row">
            <h1>⚡ Zendure Rules Editor</h1>
            <div class="actions">
                <a class="btn-link" href="./">Back to /main</a>
                <button id="btn-raw-json" type="button">Raw JSON</button>
                <button id="btn-reload" type="button">Reload</button>
                <a class="btn-link btn-link-icon" href="edit_rules_help.php" target="_blank" rel="noopener" title="Help" aria-label="Help">ℹ️</a>
            </div>
        </div>
        <p class="muted">File: <code>main/data/charge_schedule_conditions.json</code></p>
        <div id="status" class="status"></div>
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
            <form id="rule-form">
                <div class="row">
                    <label for="inp-name">Name</label>
                    <input id="inp-name" type="text" placeholder="Rule name" required>
                </div>

                <div class="row split">
                    <div>
                        <label for="inp-value-mode">Value Mode</label>
                        <select id="inp-value-mode">
                            <option value="fixed">Fixed</option>
                            <option value="netzero">netzero</option>
                            <option value="netzero+">netzero+</option>
                        </select>
                    </div>
                    <div>
                        <label for="inp-fixed-value">Fixed Value (W)</label>
                        <input id="inp-fixed-value" type="number" step="1" placeholder="500">
                    </div>
                </div>

                <div class="row split">
                    <div>
                        <label for="inp-month">Month (optional)</label>
                        <input id="inp-month" type="text" placeholder="10,11,12,1,2,3">
                    </div>
                    <div>
                        <label for="inp-hour">Hour (optional)</label>
                        <input id="inp-hour" type="text" placeholder="1,2,17,18">
                    </div>
                </div>

                <div class="row split">
                    <div>
                        <label for="inp-min-time">Min Time (optional)</label>
                        <input id="inp-min-time" type="text" placeholder="10">
                    </div>
                    <div>
                        <label for="inp-max-time">Max Time (optional)</label>
                        <input id="inp-max-time" type="text" placeholder="11">
                    </div>
                </div>

                <div class="row split">
                    <div>
                        <label for="inp-min-value">Min Value (optional)</label>
                        <input id="inp-min-value" type="number" step="100" placeholder="None">
                    </div>
                    <div>
                        <label for="inp-max-value">Max Value (optional)</label>
                        <input id="inp-max-value" type="number" step="100" placeholder="None">
                    </div>
                </div>

                <div class="row">
                    <label for="inp-fallback-value">Fallback Value (optional)</label>
                    <input id="inp-fallback-value" type="text" placeholder="0, netzero, netzero+">
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
</script>
<script src="assets/js/edit_rules.js"></script>
</body>
</html>
