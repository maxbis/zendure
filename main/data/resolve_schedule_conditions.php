<?php
// data/resolve_schedule_conditions.php
//
// Standalone resolver for condition-based schedule rules in:
//   data/charge_schedule_conditions.json
//
// Output format:
// {
//   "success": true,
//   "resolved": [
//     { "date": "YYYYMMDD", "items": [ { "time": "HH00", "value": ... } ] }
//   ]
// }
//
// Behavior:
// - Always attempts today + tomorrow (Europe/Amsterdam)
// - Includes a date only when a corresponding price file exists
// - Missing/invalid hour price => condition false for that hour (skip hour)
// - Supports conditions: price, min_time, max_time

date_default_timezone_set('Europe/Amsterdam');

$method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($method !== 'GET' && $method !== 'CLI') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use GET.',
    ]);
    exit();
}

const CONDITIONS_FILE = __DIR__ . '/charge_schedule_conditions.json';
const PRICE_DIR = __DIR__ . '/price';

function buildPriceFilePath(string $yyyymmdd): string
{
    $yyyymm = substr($yyyymmdd, 0, 6);
    return PRICE_DIR . '/' . $yyyymm . '/price' . $yyyymmdd . '.json';
}

function readJsonFileAsArray(string $path): ?array
{
    if (!file_exists($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }
    return is_array($data) ? $data : null;
}

function isValidRuleKey(string $key): bool
{
    return strlen($key) === 12 && preg_match('/^[\d\*]{12}$/', $key) === 1;
}

function isValidRuleValue($value): bool
{
    return $value === 'netzero' || $value === 'netzero+' || is_numeric($value);
}

function normalizeRuleValue($value)
{
    return is_numeric($value) ? (int) $value : $value;
}

function matchesKeyPattern(string $ruleKey, string $slotKey): bool
{
    for ($i = 0; $i < 12; $i++) {
        if ($ruleKey[$i] !== '*' && $ruleKey[$i] !== $slotKey[$i]) {
            return false;
        }
    }
    return true;
}

function specificityScore(string $key): int
{
    $score = 0;
    for ($i = 0; $i < strlen($key); $i++) {
        if ($key[$i] !== '*') {
            $score++;
        }
    }
    return $score;
}

function parseHourBound($raw): ?int
{
    if ($raw === null || $raw === '') {
        return null;
    }

    if (is_string($raw)) {
        $trim = trim($raw);
        // HH format
        if (preg_match('/^\d{1,2}$/', $trim) === 1) {
            $h = (int) $trim;
            return ($h >= 0 && $h <= 23) ? $h : null;
        }
        // HHmm format (only full hour supported: mm=00)
        if (preg_match('/^\d{4}$/', $trim) === 1) {
            $h = (int) substr($trim, 0, 2);
            $m = (int) substr($trim, 2, 2);
            if ($h >= 0 && $h <= 23 && $m === 0) {
                return $h;
            }
            return null;
        }
        return null;
    }

    if (is_int($raw) || is_float($raw)) {
        $h = (int) $raw;
        return ($h >= 0 && $h <= 23) ? $h : null;
    }

    return null;
}

function compareNumeric(float $left, string $op, float $right): bool
{
    if ($op === '>') {
        return $left > $right;
    }
    if ($op === '>=') {
        return $left >= $right;
    }
    if ($op === '<') {
        return $left < $right;
    }
    if ($op === '<=') {
        return $left <= $right;
    }
    if ($op === '==') {
        return $left == $right;
    }
    if ($op === '!=') {
        return $left != $right;
    }
    return false;
}

/**
 * Evaluate rule conditions for a single hour.
 * Supports condition fields:
 * - price
 * - min_time
 * - max_time
 *
 * @param array $rule
 * @param array $priceByHour
 * @param int $hour
 * @return bool
 */
function ruleConditionsMatch(array $rule, array $priceByHour, int $hour): bool
{
    $conditions = [];
    if (isset($rule['conditions']) && is_array($rule['conditions'])) {
        $conditions = $rule['conditions'];
    }

    // Support min_time/max_time as top-level shorthand.
    if (array_key_exists('min_time', $rule)) {
        $conditions[] = ['field' => 'min_time', 'op' => '>=', 'value' => $rule['min_time']];
    }
    if (array_key_exists('max_time', $rule)) {
        $conditions[] = ['field' => 'max_time', 'op' => '<=', 'value' => $rule['max_time']];
    }

    foreach ($conditions as $condition) {
        if (!is_array($condition) || !isset($condition['field'])) {
            return false;
        }
        $field = (string) $condition['field'];
        $op = isset($condition['op']) ? (string) $condition['op'] : '==';
        $value = $condition['value'] ?? null;

        if ($field === 'price') {
            $hourKey = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
            if (!array_key_exists($hourKey, $priceByHour) || !is_numeric($priceByHour[$hourKey])) {
                return false; // missing/invalid price => false
            }

            // Prices are stored in EUR/kWh (e.g. 0.2012).
            // Compare in cents/kWh so rules like price >= 25 are intuitive.
            $priceCents = ((float) $priceByHour[$hourKey]) * 100.0;
            if (!is_numeric($value) || !compareNumeric($priceCents, $op, (float) $value)) {
                return false;
            }
            continue;
        }

        if ($field === 'min_time') {
            $minHour = parseHourBound($value);
            if ($minHour === null) {
                return false;
            }
            if ($hour < $minHour) {
                return false;
            }
            continue;
        }

        if ($field === 'max_time') {
            $maxHour = parseHourBound($value);
            if ($maxHour === null) {
                return false;
            }
            if ($hour > $maxHour) {
                return false;
            }
            continue;
        }

        // Unknown fields are treated as non-matching for now.
        return false;
    }

    return true;
}

/**
 * Normalize raw conditions JSON to an internal rule list.
 *
 * @param array $raw
 * @return array<int, array{key:string,value:mixed,conditions:array,min_time?:mixed,max_time?:mixed}>
 */
function normalizeRules(array $raw): array
{
    $rules = [];

    // Supported input formats:
    // 1) Map format:
    //    { "************": { "value": "...", "conditions": [...] } }
    // 2) List format:
    //    [ { "value": "...", "conditions": [...], "min_time": "10", ... } ]
    //    Optional per-item "key" defaults to ************.
    $isListFormat = array_keys($raw) === range(0, count($raw) - 1);

    if ($isListFormat) {
        foreach ($raw as $idx => $entry) {
            if (!is_array($entry) || !array_key_exists('value', $entry)) {
                continue;
            }
            if (!isValidRuleValue($entry['value'])) {
                continue;
            }

            $keyStr = isset($entry['key']) ? (string) $entry['key'] : '************';
            if (!isValidRuleKey($keyStr)) {
                continue;
            }

            $rule = [
                'key' => $keyStr,
                'value' => normalizeRuleValue($entry['value']),
                'conditions' => [],
                '_order' => (int) $idx,
            ];
            if (isset($entry['conditions']) && is_array($entry['conditions'])) {
                $rule['conditions'] = $entry['conditions'];
            }
            if (array_key_exists('min_time', $entry)) {
                $rule['min_time'] = $entry['min_time'];
            }
            if (array_key_exists('max_time', $entry)) {
                $rule['max_time'] = $entry['max_time'];
            }

            $rules[] = $rule;
        }
    } else {
        foreach ($raw as $key => $entry) {
            $keyStr = (string) $key;
            if (!isValidRuleKey($keyStr)) {
                continue;
            }
            if (!is_array($entry) || !array_key_exists('value', $entry)) {
                continue;
            }
            if (!isValidRuleValue($entry['value'])) {
                continue;
            }

            $rule = [
                'key' => $keyStr,
                'value' => normalizeRuleValue($entry['value']),
                'conditions' => [],
                '_order' => count($rules),
            ];
            if (isset($entry['conditions']) && is_array($entry['conditions'])) {
                $rule['conditions'] = $entry['conditions'];
            }
            if (array_key_exists('min_time', $entry)) {
                $rule['min_time'] = $entry['min_time'];
            }
            if (array_key_exists('max_time', $entry)) {
                $rule['max_time'] = $entry['max_time'];
            }

            $rules[] = $rule;
        }
    }

    usort($rules, function ($a, $b) {
        $specA = specificityScore($a['key']);
        $specB = specificityScore($b['key']);
        if ($specA !== $specB) {
            return $specB - $specA;
        }
        $keyCmp = strcmp($b['key'], $a['key']);
        if ($keyCmp !== 0) {
            return $keyCmp;
        }
        // Earlier file order wins for same specificity/key.
        return ($a['_order'] ?? 0) - ($b['_order'] ?? 0);
    });

    return $rules;
}

function resolveForDate(string $yyyymmdd, array $rules, array $priceByHour): array
{
    $items = [];

    for ($hour = 0; $hour < 24; $hour++) {
        $hourStr = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
        $slotKey = $yyyymmdd . $hourStr . '00';

        foreach ($rules as $rule) {
            if (!matchesKeyPattern($rule['key'], $slotKey)) {
                continue;
            }
            if (!ruleConditionsMatch($rule, $priceByHour, $hour)) {
                continue;
            }

            $items[] = [
                'time' => $hourStr . '00',
                'value' => $rule['value'],
            ];
            // First matching rule wins based on pre-sorted priority.
            break;
        }
    }

    return $items;
}

$rawRules = readJsonFileAsArray(CONDITIONS_FILE);
if ($rawRules === null) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to read or parse charge_schedule_conditions.json',
    ]);
    exit();
}

$rules = normalizeRules($rawRules);

$tz = new DateTimeZone('Europe/Amsterdam');
$today = new DateTimeImmutable('now', $tz);
$dates = [
    $today->format('Ymd'),
    $today->modify('+1 day')->format('Ymd'),
];

$resolved = [];
foreach ($dates as $dateYmd) {
    $pricePath = buildPriceFilePath($dateYmd);
    $priceData = readJsonFileAsArray($pricePath);
    if ($priceData === null) {
        continue; // only include dates that have prices
    }

    $items = resolveForDate($dateYmd, $rules, $priceData);
    $resolved[] = [
        'date' => $dateYmd,
        'items' => $items,
    ];
}

echo json_encode([
    'success' => true,
    'resolved' => $resolved,
], JSON_PRETTY_PRINT);
