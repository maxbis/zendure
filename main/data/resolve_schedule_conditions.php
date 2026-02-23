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
// - Supports conditions: price, ranking, min_time, max_time, month, hour
// - Supports dynamic references via condition.value_ref:
//   min_price, max_price, min_price_hour, max_price_hour, spread_price

date_default_timezone_set('Europe/Amsterdam');

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

/**
 * Read a JSON file and return data plus an error message if something fails.
 *
 * @return array{data:?array, error:?string}
 */
function readJsonFileWithError(string $path): array
{
    if (!file_exists($path)) {
        return ['data' => null, 'error' => 'File not found: ' . basename($path)];
    }
    if (!is_readable($path)) {
        return ['data' => null, 'error' => 'File not readable: ' . basename($path)];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return ['data' => null, 'error' => 'Failed to read file: ' . basename($path)];
    }
    $data = json_decode($raw, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        return ['data' => null, 'error' => 'Invalid JSON: ' . json_last_error_msg() . ' (' . basename($path) . ')'];
    }
    if (!is_array($data)) {
        return ['data' => null, 'error' => 'JSON root is not an array: ' . basename($path)];
    }
    return ['data' => $data, 'error' => null];
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

function parseMonthList($raw): ?array
{
    $parts = [];
    if (is_string($raw)) {
        $parts = explode(',', $raw);
    } elseif (is_array($raw)) {
        $parts = $raw;
    } elseif (is_int($raw) || is_float($raw)) {
        $parts = [(string) $raw];
    } else {
        return null;
    }

    $months = [];
    foreach ($parts as $part) {
        $m = trim((string) $part);
        if ($m === '' || preg_match('/^\d{1,2}$/', $m) !== 1) {
            return null;
        }
        $mi = (int) $m;
        if ($mi < 1 || $mi > 12) {
            return null;
        }
        $months[$mi] = true;
    }

    return empty($months) ? null : array_keys($months);
}

function parseHourList($raw): ?array
{
    $parts = [];
    if (is_string($raw)) {
        $parts = explode(',', $raw);
    } elseif (is_array($raw)) {
        $parts = $raw;
    } elseif (is_int($raw) || is_float($raw)) {
        $parts = [(string) $raw];
    } else {
        return null;
    }

    $hours = [];
    foreach ($parts as $part) {
        $h = trim((string) $part);
        if ($h === '' || preg_match('/^\d{1,2}$/', $h) !== 1) {
            return null;
        }
        $hi = (int) $h;
        if ($hi < 0 || $hi > 23) {
            return null;
        }
        $hours[$hi] = true;
    }

    return empty($hours) ? null : array_keys($hours);
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
 * Build numeric context values derived from daily price data.
 *
 * Prices are interpreted as EUR/kWh in source files and normalized to cents/kWh
 * for condition evaluation to match existing "price >= 25" usage.
 *
 * @param array $priceByHour
 * @return array{min_price:?float,max_price:?float,min_price_hour:?int,max_price_hour:?int,spread_price:?float,ranking_by_hour:array<int,int>}
 */
function buildPriceContext(array $priceByHour): array
{
    $minPrice = null;
    $maxPrice = null;
    $minHour = null;
    $maxHour = null;
    $pairs = [];

    for ($hour = 0; $hour < 24; $hour++) {
        $hourKey = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
        if (!array_key_exists($hourKey, $priceByHour) || !is_numeric($priceByHour[$hourKey])) {
            continue;
        }
        $priceCents = ((float) $priceByHour[$hourKey]) * 100.0;
        $pairs[] = ['hour' => $hour, 'price' => $priceCents];

        if ($minPrice === null || $priceCents < $minPrice) {
            $minPrice = $priceCents;
            $minHour = $hour;
        }
        if ($maxPrice === null || $priceCents > $maxPrice) {
            $maxPrice = $priceCents;
            $maxHour = $hour;
        }
    }

    usort($pairs, function ($a, $b) {
        if ($a['price'] < $b['price']) {
            return -1;
        }
        if ($a['price'] > $b['price']) {
            return 1;
        }
        // Within equal price, earlier hour gets lower rank.
        return $a['hour'] - $b['hour'];
    });

    $rankingByHour = [];
    foreach ($pairs as $idx => $pair) {
        $rankingByHour[(int) $pair['hour']] = $idx + 1; // 1-based rank
    }

    return [
        'min_price' => $minPrice,
        'max_price' => $maxPrice,
        'min_price_hour' => $minHour,
        'max_price_hour' => $maxHour,
        'spread_price' => ($minPrice !== null && $maxPrice !== null) ? ($maxPrice - $minPrice) : null,
        'ranking_by_hour' => $rankingByHour,
    ];
}

/**
 * Resolve right-side operand from condition value or value_ref.
 *
 * @param array $condition
 * @param array $ctx
 * @return float|null
 */
function resolveConditionOperand(array $condition, array $ctx): ?float
{
    if (isset($condition['value_ref'])) {
        $ref = (string) $condition['value_ref'];
        if (!array_key_exists($ref, $ctx) || $ctx[$ref] === null || !is_numeric($ctx[$ref])) {
            return null;
        }
        return (float) $ctx[$ref];
    }

    $value = $condition['value'] ?? null;
    if (!is_numeric($value)) {
        return null;
    }
    return (float) $value;
}

/** @return bool */
function conditionMatchesPrice(array $condition, array $priceByHour, int $hour, array $ctx): bool
{
    $op = isset($condition['op']) ? (string) $condition['op'] : '==';
    $right = resolveConditionOperand($condition, $ctx);
    if ($right === null) {
        return false;
    }
    $hourKey = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
    if (!array_key_exists($hourKey, $priceByHour) || !is_numeric($priceByHour[$hourKey])) {
        return false;
    }
    $priceCents = ((float) $priceByHour[$hourKey]) * 100.0;
    return compareNumeric($priceCents, $op, $right);
}

/** @return bool */
function conditionMatchesMinTime(array $condition, int $hour): bool
{
    $minHour = parseHourBound($condition['value'] ?? null);
    return $minHour !== null && $hour >= $minHour;
}

/** @return bool */
function conditionMatchesMaxTime(array $condition, int $hour): bool
{
    $maxHour = parseHourBound($condition['value'] ?? null);
    return $maxHour !== null && $hour <= $maxHour;
}

/** @return bool */
function conditionMatchesMonth(array $condition, string $yyyymmdd): bool
{
    $months = parseMonthList($condition['value'] ?? null);
    if ($months === null) {
        return false;
    }
    return in_array((int) substr($yyyymmdd, 4, 2), $months, true);
}

/** @return bool */
function conditionMatchesHour(array $condition, int $hour, array $ctx): bool
{
    $op = isset($condition['op']) ? (string) $condition['op'] : 'in';
    if ($op === 'in') {
        $hours = parseHourList($condition['value'] ?? null);
        return $hours !== null && in_array($hour, $hours, true);
    }

    // Numeric comparison, e.g. hour < min_price_hour (via value_ref).
    $right = resolveConditionOperand($condition, $ctx);
    return $right !== null && compareNumeric((float) $hour, $op, $right);
}

/** @return bool */
function conditionMatchesRanking(array $condition, int $hour, array $ctx): bool
{
    $op = isset($condition['op']) ? (string) $condition['op'] : '==';
    if (!isset($ctx['ranking_by_hour']) || !is_array($ctx['ranking_by_hour'])) {
        return false;
    }
    if (!array_key_exists($hour, $ctx['ranking_by_hour']) || !is_numeric($ctx['ranking_by_hour'][$hour])) {
        return false;
    }
    $rank = (float) $ctx['ranking_by_hour'][$hour];
    $right = resolveConditionOperand($condition, $ctx);
    if ($right === null) {
        return false;
    }
    return compareNumeric($rank, $op, $right);
}

/** @return bool */
function conditionMatchesContextNumber(array $condition, array $ctx): bool
{
    if (!isset($condition['field'])) {
        return false;
    }
    $field = (string) $condition['field'];
    $op = isset($condition['op']) ? (string) $condition['op'] : '==';
    if (!array_key_exists($field, $ctx) || $ctx[$field] === null || !is_numeric($ctx[$field])) {
        return false;
    }
    $right = resolveConditionOperand($condition, $ctx);
    if ($right === null) {
        return false;
    }
    return compareNumeric((float) $ctx[$field], $op, $right);
}

/**
 * Evaluate rule conditions for a single hour.
 * Supports: price, ranking, min_time, max_time, month, hour.
 *
 * @param array $rule
 * @param array $priceByHour
 * @param int $hour
 * @param string $yyyymmdd
 * @return bool
 */
function ruleConditionsMatch(array $rule, array $priceByHour, int $hour, string $yyyymmdd, array $ctx): bool
{
    $conditions = isset($rule['conditions']) && is_array($rule['conditions']) ? $rule['conditions'] : [];
    if (array_key_exists('min_time', $rule)) {
        $conditions[] = ['field' => 'min_time', 'op' => '>=', 'value' => $rule['min_time']];
    }
    if (array_key_exists('max_time', $rule)) {
        $conditions[] = ['field' => 'max_time', 'op' => '<=', 'value' => $rule['max_time']];
    }
    if (array_key_exists('month', $rule)) {
        $conditions[] = ['field' => 'month', 'op' => 'in', 'value' => $rule['month']];
    }
    if (array_key_exists('hour', $rule)) {
        $conditions[] = ['field' => 'hour', 'op' => 'in', 'value' => $rule['hour']];
    }

    foreach ($conditions as $condition) {
        if (!is_array($condition) || !isset($condition['field'])) {
            return false;
        }
        $field = (string) $condition['field'];
        $match = false;
        if ($field === 'price') {
            $match = conditionMatchesPrice($condition, $priceByHour, $hour, $ctx);
        } elseif ($field === 'ranking') {
            $match = conditionMatchesRanking($condition, $hour, $ctx);
        } elseif ($field === 'min_price' || $field === 'max_price' || $field === 'min_price_hour' || $field === 'max_price_hour' || $field === 'spread_price') {
            $match = conditionMatchesContextNumber($condition, $ctx);
        } elseif ($field === 'min_time') {
            $match = conditionMatchesMinTime($condition, $hour);
        } elseif ($field === 'max_time') {
            $match = conditionMatchesMaxTime($condition, $hour);
        } elseif ($field === 'month') {
            $match = conditionMatchesMonth($condition, $yyyymmdd);
        } elseif ($field === 'hour') {
            $match = conditionMatchesHour($condition, $hour, $ctx);
        }
        if (!$match) {
            return false;
        }
    }
    return true;
}

/**
 * Build one normalized rule from an entry array and key string.
 *
 * @param array $entry
 * @param string $keyStr
 * @param int $order
 * @return array{key:string,value:mixed,conditions:array,_order:int,...}
 */
function buildRuleFromEntry(array $entry, string $keyStr, int $order): array
{
    $rule = [
        'key' => $keyStr,
        'value' => normalizeRuleValue($entry['value']),
        'conditions' => isset($entry['conditions']) && is_array($entry['conditions']) ? $entry['conditions'] : [],
        '_order' => $order,
    ];
    if (array_key_exists('min_time', $entry)) {
        $rule['min_time'] = $entry['min_time'];
    }
    if (array_key_exists('max_time', $entry)) {
        $rule['max_time'] = $entry['max_time'];
    }
    if (array_key_exists('month', $entry)) {
        $rule['month'] = $entry['month'];
    }
    if (array_key_exists('hour', $entry)) {
        $rule['hour'] = $entry['hour'];
    }
    return $rule;
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
    $isListFormat = array_keys($raw) === range(0, count($raw) - 1);

    if ($isListFormat) {
        foreach ($raw as $idx => $entry) {
            if (!is_array($entry) || !array_key_exists('value', $entry) || !isValidRuleValue($entry['value'])) {
                continue;
            }
            $keyStr = isset($entry['key']) ? (string) $entry['key'] : '************';
            if (!isValidRuleKey($keyStr)) {
                continue;
            }
            $rules[] = buildRuleFromEntry($entry, $keyStr, (int) $idx);
        }
    } else {
        foreach ($raw as $key => $entry) {
            $keyStr = (string) $key;
            if (!isValidRuleKey($keyStr) || !is_array($entry) || !array_key_exists('value', $entry) || !isValidRuleValue($entry['value'])) {
                continue;
            }
            $rules[] = buildRuleFromEntry($entry, $keyStr, count($rules));
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
        return ($a['_order'] ?? 0) - ($b['_order'] ?? 0);
    });

    return $rules;
}

function resolveForDate(string $yyyymmdd, array $rules, array $priceByHour): array
{
    $items = [];
    $ctx = buildPriceContext($priceByHour);

    for ($hour = 0; $hour < 24; $hour++) {
        $hourStr = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
        $slotKey = $yyyymmdd . $hourStr . '00';
        foreach ($rules as $rule) {
            if (!matchesKeyPattern($rule['key'], $slotKey) || !ruleConditionsMatch($rule, $priceByHour, $hour, $yyyymmdd, $ctx)) {
                continue;
            }
            $items[] = ['time' => $hourStr . '00', 'value' => $rule['value']];
            break;
        }
    }
    return $items;
}

/**
 * Load conditions, resolve for today and tomorrow, return response payload.
 *
 * @return array{success:bool, resolved?:array, error?:string}
 */
function runResolve(): array
{
    $conditionsResult = readJsonFileWithError(CONDITIONS_FILE);
    if ($conditionsResult['error'] !== null) {
        return ['success' => false, 'error' => $conditionsResult['error']];
    }
    $rawRules = $conditionsResult['data'];

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
            continue;
        }
        $resolved[] = [
            'date' => $dateYmd,
            'items' => resolveForDate($dateYmd, $rules, $priceData),
        ];
    }

    return ['success' => true, 'resolved' => $resolved];
}

// --- Request handling ---

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
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET.']);
    exit();
}

$output = runResolve();
if (!$output['success']) {
    http_response_code(500);
}
echo json_encode($output, JSON_PRETTY_PRINT);
