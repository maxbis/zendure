<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Amsterdam');

require_once __DIR__ . '/../../main/includes/config_loader.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use GET.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

const TARGET_LOOKBACK_DAYS = 7;
const NEUTRAL_BAND_PERCENT = 3.0;
const POPUP_POWER_EFFICIENCY_FALLBACK = 0.9;
const SOLAR_REFERENCE_CHARGE_W = 450.0;

try {
    $payload = buildPathPayload();
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function buildPathPayload(): array
{
    $tz = new DateTimeZone('Europe/Amsterdam');
    $now = new DateTimeImmutable('now', $tz);

    $baseWh = max(1, (int) ConfigLoader::get('baseWh', 5760));
    $minChargeLevel = clampPercent((float) ConfigLoader::get('MIN_CHARGE_LEVEL', 15));
    $maxChargeLevelRaw = clampPercent((float) ConfigLoader::get('MAX_CHARGE_LEVEL', 96));
    $maxChargeLevel = max($minChargeLevel, $maxChargeLevelRaw);
    $efficiency = (float) ConfigLoader::get('popupPowerEfficiency', POPUP_POWER_EFFICIENCY_FALLBACK);
    if ($efficiency <= 0) {
        $efficiency = POPUP_POWER_EFFICIENCY_FALLBACK;
    }

    $rootPath = projectRootPath();
    $localChargeStatusUrl = buildLocalUrl($rootPath . '/main/api/charge_status_all_proxy.php');
    $localShortwaveUrl = buildLocalUrl($rootPath . '/main/api/shortwave_radiation_api.php');
    $whPerHourUrl = appendDaysQueryParam(resolveConfiguredUrl('wh-per-hourApi'), TARGET_LOOKBACK_DAYS);

    $errors = [];

    $chargeStatus = fetchJson($localChargeStatusUrl, 'charge status', $errors);
    $shortwave = fetchJson($localShortwaveUrl, 'shortwave radiation', $errors);
    $whPerHour = fetchJson($whPerHourUrl, 'wh_per_hour', $errors);

    $currentSoc = extractCurrentSoc($chargeStatus);
    $todayDate = $now->format('Y-m-d');
    $tomorrowDate = $now->modify('+1 day')->format('Y-m-d');

    $solarByDateHour = extractSolarByDateHour($shortwave, [$todayDate, $tomorrowDate], $tz);
    $solarPeak = maxSolarValue($solarByDateHour);

    $historyProfile = buildUsageMedianProfile($whPerHour, $todayDate, TARGET_LOOKBACK_DAYS, $baseWh, $efficiency);
    $effectiveLookbackDays = $historyProfile['effectiveLookbackDays'];
    $usageMedianByHour = $historyProfile['usageMedianByHour'];

    $todayRows = (is_array($whPerHour) && isset($whPerHour[$todayDate]) && is_array($whPerHour[$todayDate])) ? $whPerHour[$todayDate] : [];
    $anchorSoc = extractDayStartSoc($todayRows, $currentSoc);

    $usablePercentWh = ($baseWh / 100.0) * $efficiency;
    $peakSolarChargePctPerHour = usableWhToPercent(SOLAR_REFERENCE_CHARGE_W, $usablePercentWh);

    $slots = [];
    $slotIndexByDateHour = [];
    $runningSoc = $anchorSoc;
    $currentHourStart = $now->setTime((int) $now->format('H'), 0, 0);
    $expectedNow = null;

    $cursor = $now->setTime(0, 0, 0);
    $end = $cursor->modify('+2 days');

    while ($cursor < $end) {
        $slotDate = $cursor->format('Y-m-d');
        $hour = (int) $cursor->format('H');
        $hourKey = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
        $slotStartSoc = $runningSoc;

        $solarValue = $solarByDateHour[$slotDate][$hourKey] ?? 0.0;
        $solarScore = ($solarPeak > 0.0) ? ($solarValue / $solarPeak) : 0.0;
        $solarChargePct = $solarScore * $peakSolarChargePctPerHour;
        $usageDischargePct = (float) ($usageMedianByHour[$hour] ?? 0.0);
        $netDeltaPct = $solarChargePct - $usageDischargePct;

        $runningSoc = clampSoc($slotStartSoc + $netDeltaPct, $minChargeLevel, $maxChargeLevel);

        $slotEnd = $cursor->modify('+1 hour');
        $slot = [
            'timestamp' => $cursor->format(DATE_ATOM),
            'date' => $slotDate,
            'hour' => $hour,
            'start_soc' => round($slotStartSoc, 2),
            'end_soc' => round($runningSoc, 2),
            'solar_score' => round($solarScore, 4),
            'solar_charge_pct' => round($solarChargePct, 3),
            'usage_discharge_pct' => round($usageDischargePct, 3),
            'net_delta_pct' => round($runningSoc - $slotStartSoc, 3),
        ];
        $slots[] = $slot;
        $slotIndexByDateHour[$slotDate . '|' . $hour] = $slot['timestamp'];

        if ($cursor == $currentHourStart) {
            $fraction = min(1.0, max(0.0, (((int) $now->format('i')) * 60 + (int) $now->format('s')) / 3600.0));
            $interpolated = $slotStartSoc + (($runningSoc - $slotStartSoc) * $fraction);
            $expectedNow = clampSoc($interpolated, $minChargeLevel, $maxChargeLevel);
        }

        $cursor = $slotEnd;
    }

    if ($expectedNow === null) {
        $expectedNow = $currentSoc;
    }

    $actualSlots = buildActualPathSlots($whPerHour, $todayDate, $slotIndexByDateHour, $now);

    $deltaNow = $currentSoc - $expectedNow;
    $status = classifyDelta($deltaNow, NEUTRAL_BAND_PERCENT);

    return [
        'success' => true,
        'generatedAt' => $now->format(DATE_ATOM),
        'timezone' => $tz->getName(),
        'config' => [
            'baseWh' => $baseWh,
            'minChargeLevel' => $minChargeLevel,
            'maxChargeLevel' => $maxChargeLevel,
            'neutralBandPercent' => NEUTRAL_BAND_PERCENT,
            'targetLookbackDays' => TARGET_LOOKBACK_DAYS,
            'effectiveLookbackDays' => $effectiveLookbackDays,
        ],
        'summary' => [
            'currentSoc' => round($currentSoc, 2),
            'expectedSocNow' => round($expectedNow, 2),
            'deltaSocNow' => round($deltaNow, 2),
            'status' => $status,
            'anchorSoc' => round($anchorSoc, 2),
            'solarPeak' => round($solarPeak, 2),
        ],
        'profiles' => [
            'usageMedianByHour' => array_map(static fn ($value) => round((float) $value, 3), $usageMedianByHour),
        ],
        'actualPath' => [
            'slots' => $actualSlots,
        ],
        'slots' => $slots,
        'warnings' => $errors,
    ];
}

function projectRootPath(): string
{
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/pathlab/api/path_data.php';
    $root = dirname(dirname(dirname($scriptName)));
    if ($root === '\\' || $root === '.') {
        return '';
    }
    return rtrim(str_replace('\\', '/', $root), '/');
}

function buildLocalUrl(string $path): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . $path;
}

function resolveConfiguredUrl(string $configKey): string
{
    $rawUrl = ConfigLoader::get($configKey);
    if (!is_string($rawUrl) || trim($rawUrl) === '') {
        throw new RuntimeException('Missing config value for ' . $configKey);
    }

    $baseUrl = ConfigLoader::get('apiBaseUrlPiControl', '');
    if (is_string($baseUrl) && $baseUrl !== '') {
        $rawUrl = str_replace('${apiBaseUrlPiControl}', $baseUrl, $rawUrl);
    }

    return $rawUrl;
}

function appendDaysQueryParam(string $url, int $days): string
{
    $separator = (strpos($url, '?') === false) ? '?' : '&';
    return $url . $separator . 'days=' . rawurlencode((string) $days);
}

function fetchJson(string $url, string $label, array &$errors): ?array
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 12,
            'ignore_errors' => true,
            'method' => 'GET',
            'header' => 'User-Agent: PathLab',
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if ($raw === false || $raw === '') {
        $errors[] = 'Failed to fetch ' . $label . '.';
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $errors[] = 'Invalid JSON from ' . $label . '.';
        return null;
    }

    if (array_key_exists('success', $decoded) && $decoded['success'] === false) {
        $message = isset($decoded['error']) && is_string($decoded['error']) && trim($decoded['error']) !== ''
            ? trim($decoded['error'])
            : ('Upstream ' . $label . ' returned success=false.');
        $errors[] = $message;
        return null;
    }

    return $decoded;
}

function extractCurrentSoc(?array $chargeStatus): float
{
    if (!is_array($chargeStatus)) {
        return 0.0;
    }

    $properties = null;
    if (isset($chargeStatus['data']['properties']) && is_array($chargeStatus['data']['properties'])) {
        $properties = $chargeStatus['data']['properties'];
    } elseif (
        isset($chargeStatus['zendure']['readings']['properties']) &&
        is_array($chargeStatus['zendure']['readings']['properties'])
    ) {
        $properties = $chargeStatus['zendure']['readings']['properties'];
    } elseif (isset($chargeStatus['properties']) && is_array($chargeStatus['properties'])) {
        $properties = $chargeStatus['properties'];
    }

    if (!is_array($properties) || !isset($properties['electricLevel']) || !is_numeric($properties['electricLevel'])) {
        return 0.0;
    }

    return clampPercent((float) $properties['electricLevel']);
}

function extractDayStartSoc(array $rows, float $fallback): float
{
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $level = $row['electric_level'] ?? null;
        if ($level !== null && $level !== '' && is_numeric($level)) {
            return clampPercent((float) $level);
        }
    }

    return clampPercent($fallback);
}

function extractSolarByDateHour(?array $shortwave, array $dates, DateTimeZone $tz): array
{
    $allowed = array_fill_keys($dates, true);
    $result = [];
    foreach ($dates as $date) {
        $result[$date] = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $result[$date][str_pad((string) $hour, 2, '0', STR_PAD_LEFT)] = 0.0;
        }
    }

    if (!is_array($shortwave) || !isset($shortwave['hourly']) || !is_array($shortwave['hourly'])) {
        return $result;
    }

    $times = $shortwave['hourly']['time'] ?? [];
    $values = $shortwave['hourly']['shortwave_radiation'] ?? [];
    if (!is_array($times) || !is_array($values)) {
        return $result;
    }

    foreach ($times as $index => $rawTime) {
        if (!isset($values[$index]) || !is_numeric($values[$index])) {
            continue;
        }
        try {
            $dt = new DateTimeImmutable((string) $rawTime, $tz);
        } catch (Throwable $e) {
            continue;
        }
        $date = $dt->format('Y-m-d');
        if (!isset($allowed[$date])) {
            continue;
        }
        $hourKey = $dt->format('H');
        $result[$date][$hourKey] = max(0.0, (float) $values[$index]);
    }

    return $result;
}

function maxSolarValue(array $solarByDateHour): float
{
    $max = 0.0;
    foreach ($solarByDateHour as $byHour) {
        foreach ($byHour as $value) {
            $max = max($max, (float) $value);
        }
    }
    return $max;
}

function buildUsageMedianProfile(?array $whPerHour, string $todayDate, int $targetLookbackDays, int $baseWh, float $efficiency): array
{
    $usablePercentWh = ($baseWh / 100.0) * $efficiency;
    $byHour = [];
    for ($hour = 0; $hour < 24; $hour++) {
        $byHour[$hour] = [];
    }

    $dates = [];
    if (is_array($whPerHour)) {
        $dates = array_keys($whPerHour);
        sort($dates);
    }

    $historyDates = [];
    foreach ($dates as $date) {
        if ($date >= $todayDate) {
            continue;
        }
        $historyDates[] = $date;
    }

    if (count($historyDates) === 0) {
        foreach ($dates as $date) {
            if ($date <= $todayDate) {
                $historyDates[] = $date;
            }
        }
    }

    if (count($historyDates) > $targetLookbackDays) {
        $historyDates = array_slice($historyDates, -$targetLookbackDays);
    }

    foreach ($historyDates as $date) {
        $rows = $whPerHour[$date] ?? [];
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hour = isset($row['hour']) ? (int) $row['hour'] : null;
            if ($hour === null || $hour < 0 || $hour > 23) {
                continue;
            }
            $dischargedWh = isset($row['discharged_wh']) && is_numeric($row['discharged_wh'])
                ? (float) $row['discharged_wh']
                : 0.0;
            $byHour[$hour][] = usableWhToPercent($dischargedWh, $usablePercentWh);
        }
    }

    $medianByHour = [];
    foreach ($byHour as $hour => $values) {
        $medianByHour[$hour] = median($values);
    }

    return [
        'effectiveLookbackDays' => count($historyDates),
        'usageMedianByHour' => $medianByHour,
    ];
}

function buildActualPathSlots(?array $whPerHour, string $todayDate, array $slotIndexByDateHour, DateTimeImmutable $now): array
{
    if (!is_array($whPerHour) || !isset($whPerHour[$todayDate]) || !is_array($whPerHour[$todayDate])) {
        return [];
    }

    $actualSlots = [];
    foreach ($whPerHour[$todayDate] as $row) {
        if (!is_array($row)) {
            continue;
        }

        $hour = $row['hour'] ?? null;
        $electricLevel = $row['electric_level'] ?? null;
        if (!is_numeric($hour) || !is_numeric($electricLevel)) {
            continue;
        }

        $hourInt = (int) $hour;
        if ($hourInt < 0 || $hourInt > 23) {
            continue;
        }

        $slotKey = $todayDate . '|' . $hourInt;
        if (!isset($slotIndexByDateHour[$slotKey])) {
            continue;
        }

        $timestamp = $slotIndexByDateHour[$slotKey];
        $timestampMs = strtotime($timestamp);
        if ($timestampMs === false || $timestampMs > $now->getTimestamp()) {
            continue;
        }

        $actualSlots[] = [
            'timestamp' => $timestamp,
            'soc' => round(clampPercent((float) $electricLevel), 2),
        ];
    }

    usort($actualSlots, static fn (array $left, array $right): int => strcmp($left['timestamp'], $right['timestamp']));

    return $actualSlots;
}

function usableWhToPercent(float $wh, float $usablePercentWh): float
{
    if ($usablePercentWh <= 0) {
        return 0.0;
    }
    return max(0.0, $wh / $usablePercentWh);
}

function median(array $values): float
{
    $filtered = array_values(array_filter($values, static fn ($value) => is_numeric($value)));
    $count = count($filtered);
    if ($count === 0) {
        return 0.0;
    }
    sort($filtered, SORT_NUMERIC);
    $middle = intdiv($count, 2);
    if (($count % 2) === 1) {
        return (float) $filtered[$middle];
    }
    return ((float) $filtered[$middle - 1] + (float) $filtered[$middle]) / 2.0;
}

function classifyDelta(float $delta, float $neutralBand): string
{
    if ($delta > $neutralBand) {
        return 'ahead';
    }
    if ($delta < (-1.0 * $neutralBand)) {
        return 'behind';
    }
    return 'on-path';
}

function clampPercent(float $value): float
{
    return max(0.0, min(100.0, $value));
}

function clampSoc(float $value, float $minChargeLevel, float $maxChargeLevel): float
{
    return max($minChargeLevel, min($maxChargeLevel, $value));
}
