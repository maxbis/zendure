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
// - Always attempts today + tomorrow in the configured installation timezone
// - Includes a date only when a corresponding price file exists
// - Missing/invalid hour price => condition false for that hour (skip hour)
// - Supports static conditions: price, ranking, min_time, max_time, month, hour
// - Runtime-only conditions (e.g. electricity_level) are passed through as metadata
// - Supports dynamic references via condition.value_ref:
//   min_price, max_price, min_price_hour, max_price_hour,
//   max_price_hour_am, max_price_hour_pm, spread_price
// - When both condition.value_ref and numeric condition.value are present,
//   condition.value is used as an additive offset
// - Supports sun context for static conditions:
//   sunrise_hour (floor), sunset_hour (ceil), and dynamic offset fields

require_once dirname(__DIR__, 2) . '/common/php/system_config.php';
require_once dirname(__DIR__) . '/includes/sun_context.php';

const CONDITIONS_FILE = __DIR__ . '/charge_schedule_conditions.json';
const RULE_PROFILES_FILE = __DIR__ . '/rule_profiles.json';
const PRICE_DIR = __DIR__ . '/price';
const SHOW_ALL_PROFILE_ID = 'show_all';

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
    return $value === 'netzero' || $value === 'netzero-' || $value === 'netzero+' || $value === 'empty_at_solar_charge' || $value === 'full_at_netzero_minus' || is_numeric($value);
}

function normalizeConditionRelationValue($value): string
{
    if (!is_string($value)) {
        return 'and';
    }
    $normalized = strtolower(trim($value));
    return $normalized === 'or' ? 'or' : 'and';
}

function normalizeRuleValue($value)
{
    return is_numeric($value) ? (int) $value : $value;
}

function normalizeOptionalRuleBoundValue($value): ?int
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

function normalizeRuleId($value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $trimmed = trim($value);
    return $trimmed === '' ? null : $trimmed;
}

function normalizeProfileConfig(array $raw, array $rules): array
{
    $validRuleIds = [];
    foreach ($rules as $rule) {
        if (isset($rule['rule_id']) && is_string($rule['rule_id']) && $rule['rule_id'] !== '') {
            $validRuleIds[$rule['rule_id']] = true;
        }
    }

    $profiles = [];
    if (isset($raw['profiles']) && is_array($raw['profiles'])) {
        foreach ($raw['profiles'] as $profile) {
            if (!is_array($profile)) {
                continue;
            }
            $profileId = isset($profile['id']) && is_string($profile['id']) ? trim($profile['id']) : '';
            if ($profileId === '' || $profileId === SHOW_ALL_PROFILE_ID) {
                continue;
            }
            $ruleIds = [];
            $seen = [];
            if (isset($profile['rule_ids']) && is_array($profile['rule_ids'])) {
                foreach ($profile['rule_ids'] as $ruleId) {
                    if (!is_string($ruleId)) {
                        continue;
                    }
                    $trimmedId = trim($ruleId);
                    if ($trimmedId === '' || !isset($validRuleIds[$trimmedId]) || isset($seen[$trimmedId])) {
                        continue;
                    }
                    $seen[$trimmedId] = true;
                    $ruleIds[] = $trimmedId;
                }
            }
            $profiles[$profileId] = [
                'id' => $profileId,
                'short_name' => isset($profile['short_name']) ? trim((string) $profile['short_name']) : '',
                'description' => isset($profile['description']) ? trim((string) $profile['description']) : '',
                'rule_ids' => $ruleIds,
            ];
        }
    }

    $activeProfileId = isset($raw['active_profile_id']) && is_string($raw['active_profile_id'])
        ? trim($raw['active_profile_id'])
        : SHOW_ALL_PROFILE_ID;
    if ($activeProfileId !== SHOW_ALL_PROFILE_ID && !isset($profiles[$activeProfileId])) {
        $activeProfileId = SHOW_ALL_PROFILE_ID;
    }

    return [
        'active_profile_id' => $activeProfileId,
        'profiles' => $profiles,
    ];
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
 * @return array{min_price:?float,max_price:?float,min_price_hour:?int,max_price_hour:?int,max_price_hour_am:?int,max_price_hour_pm:?int,spread_price:?float,ranking_by_hour:array<int,int>,rank_to_hour:array<int,int>}
 */
function buildPriceContext(array $priceByHour): array
{
    $minPrice = null;
    $maxPrice = null;
    $minHour = null;
    $maxHour = null;
    $maxPriceAm = null;
    $maxHourAm = null;
    $maxPricePm = null;
    $maxHourPm = null;
    $pairs = [];

    for ($hour = 0; $hour < 24; $hour++) {
        $hourKey = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
        if (!array_key_exists($hourKey, $priceByHour) || !is_numeric($priceByHour[$hourKey])) {
            continue;
        }
        $priceCents = $priceByHour[$hourKey] * 100.00;
        $pairs[] = ['hour' => $hour, 'price' => $priceCents];

        if ($minPrice === null || $priceCents < $minPrice) {
            $minPrice = $priceCents;
            $minHour = $hour;
        }
        if ($maxPrice === null || $priceCents > $maxPrice) {
            $maxPrice = $priceCents;
            $maxHour = $hour;
        }
        if ($hour < 12) {
            if ($maxPriceAm === null || $priceCents > $maxPriceAm) {
                $maxPriceAm = $priceCents;
                $maxHourAm = $hour;
            }
        } else {
            if ($maxPricePm === null || $priceCents > $maxPricePm) {
                $maxPricePm = $priceCents;
                $maxHourPm = $hour;
            }
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
    $rankToHour = [];
    foreach ($pairs as $idx => $pair) {
        $rank = $idx + 1; // 1-based rank from lowest to highest price
        $hourVal = (int) $pair['hour'];
        $rankingByHour[$hourVal] = $rank; // hour -> rank
        $rankToHour[$rank] = $hourVal;    // rank -> hour
    }

    return [
        'min_price' => $minPrice,
        'max_price' => $maxPrice,
        'min_price_hour' => $minHour,
        'max_price_hour' => $maxHour,
        'max_price_hour_am' => $maxHourAm,
        'max_price_hour_pm' => $maxHourPm,
        'spread_price' => ($minPrice !== null && $maxPrice !== null) ? ($maxPrice - $minPrice) : null,
        'ranking_by_hour' => $rankingByHour,
        'rank_to_hour' => $rankToHour,
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
        $offset = 0.0;
        if (array_key_exists('value', $condition)) {
            $value = $condition['value'];
            if ($value !== null) {
                if (is_string($value)) {
                    $value = trim($value);
                }
                if ($value !== '') {
                    if (!is_numeric($value)) {
                        return null;
                    }
                    $offset = (float) $value;
                }
            }
        }
        return (float) $ctx[$ref] + $offset;
    }

    $value = $condition['value'] ?? null;
    if (is_string($value)) {
        $value = trim($value);
    }
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

/** @return bool */
function conditionMatchesSunOffsetHour(array $condition, int $hour, array $ctx, string $anchorField): bool
{
    $op = isset($condition['op']) ? (string) $condition['op'] : '==';
    if (!array_key_exists($anchorField, $ctx) || !is_numeric($ctx[$anchorField])) {
        return false;
    }

    $offset = resolveConditionOperand($condition, $ctx);
    if ($offset === null) {
        return false;
    }

    $anchor = (int) $ctx[$anchorField];
    $targetHour = clampHour((int) round($anchor + $offset));
    return compareNumeric((float) $hour, $op, (float) $targetHour);
}

function isRuntimeOnlyConditionField(string $field): bool
{
    return in_array($field, ['electricity_level', 'electric_level', 'electricLevel'], true);
}

/**
 * Split rule conditions into static and runtime-only groups.
 *
 * @param array $rule
 * @return array{static:array,runtime:array,filters:array}
 */
function splitRuleConditions(array $rule): array
{
    $staticConditions = [];
    $runtimeConditions = [];
    $filterConditions = [];

    $conditions = isset($rule['conditions']) && is_array($rule['conditions']) ? $rule['conditions'] : [];
    foreach ($conditions as $condition) {
        if (!is_array($condition) || !isset($condition['field'])) {
            continue;
        }
        $field = (string) $condition['field'];
        if (isRuntimeOnlyConditionField($field)) {
            $runtimeConditions[] = $condition;
            continue;
        }
        $staticConditions[] = $condition;
    }

    if (array_key_exists('min_time', $rule)) {
        $filterConditions[] = ['field' => 'min_time', 'op' => '>=', 'value' => $rule['min_time']];
    }
    if (array_key_exists('max_time', $rule)) {
        $filterConditions[] = ['field' => 'max_time', 'op' => '<=', 'value' => $rule['max_time']];
    }
    if (array_key_exists('month', $rule)) {
        $filterConditions[] = ['field' => 'month', 'op' => 'in', 'value' => $rule['month']];
    }
    if (array_key_exists('hour', $rule)) {
        $filterConditions[] = ['field' => 'hour', 'op' => 'in', 'value' => $rule['hour']];
    }

    return ['static' => $staticConditions, 'runtime' => $runtimeConditions, 'filters' => $filterConditions];
}

/**
 * @param array $splitConditions
 */
function getEffectiveRuleConditionRelation(array $rule, array $splitConditions): string
{
    if (!empty($splitConditions['runtime'])) {
        return 'and';
    }
    return normalizeConditionRelationValue($rule['condition_relation'] ?? 'and');
}

function evaluateRuleCondition(array $condition, array $priceByHour, int $hour, string $yyyymmdd, array $ctx): bool
{
    if (!isset($condition['field'])) {
        return false;
    }

    $field = (string) $condition['field'];
    if ($field === 'price') {
        return conditionMatchesPrice($condition, $priceByHour, $hour, $ctx);
    }
    if ($field === 'ranking') {
        return conditionMatchesRanking($condition, $hour, $ctx);
    }
    if (
        $field === 'min_price' || $field === 'max_price' ||
        $field === 'min_price_hour' || $field === 'max_price_hour' ||
        $field === 'max_price_hour_am' || $field === 'max_price_hour_pm' ||
        $field === 'spread_price' ||
        $field === 'sunrise_hour' || $field === 'sunset_hour'
    ) {
        return conditionMatchesContextNumber($condition, $ctx);
    }
    if ($field === 'sunrise_offset_hour') {
        return conditionMatchesSunOffsetHour($condition, $hour, $ctx, 'sunrise_hour');
    }
    if ($field === 'sunset_offset_hour') {
        return conditionMatchesSunOffsetHour($condition, $hour, $ctx, 'sunset_hour');
    }
    if ($field === 'min_time') {
        return conditionMatchesMinTime($condition, $hour);
    }
    if ($field === 'max_time') {
        return conditionMatchesMaxTime($condition, $hour);
    }
    if ($field === 'month') {
        return conditionMatchesMonth($condition, $yyyymmdd);
    }
    if ($field === 'hour') {
        return conditionMatchesHour($condition, $hour, $ctx);
    }

    return false;
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
    $splitConditions = splitRuleConditions($rule);
    $filterConditions = $splitConditions['filters'];
    $conditions = $splitConditions['static'];
    $conditionRelation = getEffectiveRuleConditionRelation($rule, $splitConditions);

    foreach ($filterConditions as $condition) {
        if (!is_array($condition) || !isset($condition['field'])) {
            return false;
        }
        if (!evaluateRuleCondition($condition, $priceByHour, $hour, $yyyymmdd, $ctx)) {
            return false;
        }
    }

    if (empty($conditions)) {
        return true;
    }

    if ($conditionRelation === 'or') {
        foreach ($conditions as $condition) {
            if (!is_array($condition) || !isset($condition['field'])) {
                continue;
            }
            if (evaluateRuleCondition($condition, $priceByHour, $hour, $yyyymmdd, $ctx)) {
                return true;
            }
        }
        return false;
    }

    foreach ($conditions as $condition) {
        if (!is_array($condition) || !isset($condition['field'])) {
            return false;
        }
        if (!evaluateRuleCondition($condition, $priceByHour, $hour, $yyyymmdd, $ctx)) {
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
    if (array_key_exists('name', $entry) && is_string($entry['name']) && trim($entry['name']) !== '') {
        $rule['name'] = trim((string) $entry['name']);
    }
    $ruleId = normalizeRuleId($entry['rule_id'] ?? null);
    if ($ruleId !== null) {
        $rule['rule_id'] = $ruleId;
    }
    if (array_key_exists('enabled', $entry)) {
        $rule['enabled'] = (bool) $entry['enabled'];
    }
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
    if (!empty($rule['conditions'])) {
        $splitConditions = splitRuleConditions($rule);
        $rule['condition_relation'] = getEffectiveRuleConditionRelation($entry, $splitConditions);
    }
    if (array_key_exists('fallback_value', $entry) && isValidRuleValue($entry['fallback_value'])) {
        $rule['fallback_value'] = normalizeRuleValue($entry['fallback_value']);
    }
    if ($rule['value'] === 'netzero' || $rule['value'] === 'netzero-' || $rule['value'] === 'netzero+') {
        $minValue = array_key_exists('min_power', $entry) ? normalizeOptionalRuleBoundValue($entry['min_power']) : null;
        $maxValue = array_key_exists('max_power', $entry) ? normalizeOptionalRuleBoundValue($entry['max_power']) : null;
        if ($minValue !== null) {
            $rule['min_power'] = $minValue;
        }
        if ($maxValue !== null) {
            $rule['max_power'] = $maxValue;
        }
        if (
            array_key_exists('min_power', $rule) &&
            array_key_exists('max_power', $rule) &&
            $rule['min_power'] > $rule['max_power']
        ) {
            unset($rule['min_power'], $rule['max_power']);
        }
    }
    if ($rule['value'] === 'empty_at_solar_charge') {
        $targetSoc = isset($entry['target_soc_percent']) && is_numeric($entry['target_soc_percent'])
            ? (float) $entry['target_soc_percent']
            : null;
        if ($targetSoc !== null && $targetSoc >= 0 && $targetSoc <= 100) {
            $rule['target_soc_percent'] = round($targetSoc, 1);
            $rule['target_anchor'] = 'next_netzero_plus';
        }
        $maxDischarge = normalizeOptionalRuleBoundValue($entry['max_discharge_power'] ?? null);
        if ($maxDischarge !== null && $maxDischarge > 0) {
            $rule['max_discharge_power'] = $maxDischarge;
        }
    }
    if ($rule['value'] === 'full_at_netzero_minus') {
        $rule['target_anchor'] = 'next_netzero_minus';
    }
    return $rule;
}

function ruleAllowedByProfile(array $rule, array $profileConfig): bool
{
    $activeProfileId = $profileConfig['active_profile_id'] ?? SHOW_ALL_PROFILE_ID;
    if ($activeProfileId === SHOW_ALL_PROFILE_ID) {
        return true;
    }
    if (!isset($rule['rule_id']) || !is_string($rule['rule_id']) || $rule['rule_id'] === '') {
        return false;
    }
    $profiles = isset($profileConfig['profiles']) && is_array($profileConfig['profiles']) ? $profileConfig['profiles'] : [];
    if (!isset($profiles[$activeProfileId]) || !isset($profiles[$activeProfileId]['rule_ids']) || !is_array($profiles[$activeProfileId]['rule_ids'])) {
        return false;
    }
    return in_array($rule['rule_id'], $profiles[$activeProfileId]['rule_ids'], true);
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

function resolveForDate(string $yyyymmdd, array $rules, array $priceByHour, array $profileConfig, ?array $ctx = null): array
{
    $items = [];
    if ($ctx === null) {
        $ctx = buildPriceContext($priceByHour);
    }

    for ($hour = 0; $hour < 24; $hour++) {
        $hourStr = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
        $slotKey = $yyyymmdd . $hourStr . '00';
        foreach ($rules as $rule) {
            // Rules are enabled by default; only explicit boolean false disables evaluation.
            if (array_key_exists('enabled', $rule) && $rule['enabled'] === false) {
                continue;
            }
            if (!ruleAllowedByProfile($rule, $profileConfig)) {
                continue;
            }
            if (!matchesKeyPattern($rule['key'], $slotKey) || !ruleConditionsMatch($rule, $priceByHour, $hour, $yyyymmdd, $ctx)) {
                continue;
            }
            $ranking = null;
            if (isset($ctx['ranking_by_hour']) && is_array($ctx['ranking_by_hour']) && array_key_exists($hour, $ctx['ranking_by_hour']) && is_numeric($ctx['ranking_by_hour'][$hour])) {
                $ranking = (int) $ctx['ranking_by_hour'][$hour];
            }
            $items[] = [
                'time' => $hourStr . '00',
                'value' => $rule['value'],
                'ranking' => $ranking,
            ];
            if (array_key_exists('name', $rule) && is_string($rule['name']) && $rule['name'] !== '') {
                $items[count($items) - 1]['rule_name'] = $rule['name'];
            }
            if (array_key_exists('rule_id', $rule) && is_string($rule['rule_id']) && $rule['rule_id'] !== '') {
                $items[count($items) - 1]['rule_id'] = $rule['rule_id'];
            }
            if (array_key_exists('_order', $rule) && is_numeric($rule['_order'])) {
                $items[count($items) - 1]['rule_index'] = ((int) $rule['_order']) + 1;
            }
            $splitConditions = splitRuleConditions($rule);
            if (!empty($splitConditions['runtime'])) {
                $items[count($items) - 1]['runtime_conditions'] = array_values($splitConditions['runtime']);
            }
            if (array_key_exists('fallback_value', $rule)) {
                $items[count($items) - 1]['fallback_value'] = $rule['fallback_value'];
            }
            if (array_key_exists('min_power', $rule)) {
                $items[count($items) - 1]['min_power'] = $rule['min_power'];
            }
            if (array_key_exists('max_power', $rule)) {
                $items[count($items) - 1]['max_power'] = $rule['max_power'];
            }
            if ($rule['value'] === 'empty_at_solar_charge') {
                $items[count($items) - 1]['target_soc_percent'] = $rule['target_soc_percent'] ?? null;
                $items[count($items) - 1]['target_anchor'] = 'next_netzero_plus';
                if (array_key_exists('max_discharge_power', $rule)) {
                    $items[count($items) - 1]['max_discharge_power'] = $rule['max_discharge_power'];
                }
            }
            if ($rule['value'] === 'full_at_netzero_minus') {
                $items[count($items) - 1]['target_anchor'] = 'next_netzero_minus';
            }
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
    $profilesResult = readJsonFileWithError(RULE_PROFILES_FILE);
    $profileConfig = ($profilesResult['error'] === null && is_array($profilesResult['data']))
        ? normalizeProfileConfig($profilesResult['data'], $rules)
        : ['active_profile_id' => SHOW_ALL_PROFILE_ID, 'profiles' => []];
    $systemConfig = loadSystemConfig();
    $installation = $systemConfig['installation'];
    $tz = new DateTimeZone($installation['timezone']);
    date_default_timezone_set($installation['timezone']);
    $latitude = $installation['latitude'];
    $longitude = $installation['longitude'];
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
        $ctx = buildPriceContext($priceData);
        $sunCtx = getSunContextForDate($dateYmd, $latitude, $longitude, $tz);
        if (!empty($sunCtx)) {
            $ctx = array_merge($ctx, $sunCtx);
        }
        $group = [
            'date' => $dateYmd,
            'min_price' => $ctx['min_price'],
            'max_price' => $ctx['max_price'],
            'min_price_hour' => $ctx['min_price_hour'],
            'max_price_hour' => $ctx['max_price_hour'],
            'max_price_hour_am' => $ctx['max_price_hour_am'],
            'max_price_hour_pm' => $ctx['max_price_hour_pm'],
            'spread_price' => ($ctx['spread_price'] === null) ? null : round((float) $ctx['spread_price'], 2),
            // ranking: key = rank (1 = cheapest), value = hour (0-23)
            'ranking' => $ctx['rank_to_hour'],
            'items' => resolveForDate($dateYmd, $rules, $priceData, $profileConfig, $ctx),
        ];
        foreach ([
            'sunrise_time',
            'sunset_time',
            'sunrise_hour',
            'sunset_hour',
        ] as $sunKey) {
            if (array_key_exists($sunKey, $ctx)) {
                $group[$sunKey] = $ctx[$sunKey];
            }
        }
        $resolved[] = $group;
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

try {
    $output = runResolve();
} catch (SystemConfigException $error) {
    $output = [
        'success' => false,
        'error' => 'Shared system configuration: ' . $error->getMessage(),
    ];
}
if (!$output['success']) {
    http_response_code(500);
}
echo json_encode($output, JSON_PRETTY_PRINT);
