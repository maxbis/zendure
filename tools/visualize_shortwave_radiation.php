<?php
declare(strict_types=1);

const SHORTWAVE_CACHE_FILE = __DIR__ . '/shortwave_radiation_cache.json';
const SHORTWAVE_CACHE_TTL = 6 * 60 * 60;

$latitude = isset($_GET['latitude']) ? (float) $_GET['latitude'] : 52.3099;
$longitude = isset($_GET['longitude']) ? (float) $_GET['longitude'] : 4.8540;
$timezone = isset($_GET['timezone']) && is_string($_GET['timezone']) && $_GET['timezone'] !== ''
    ? $_GET['timezone']
    : 'Europe/Amsterdam';

$apiUrl = sprintf(
    'https://api.open-meteo.com/v1/forecast?latitude=%s&longitude=%s&hourly=shortwave_radiation&timezone=%s',
    rawurlencode((string) $latitude),
    rawurlencode((string) $longitude),
    rawurlencode($timezone)
);

$times = [];
$values = [];
$unit = "W/m\u{00B2}";
$timezoneLabel = $timezone;
$summary = null;
$chart = null;
$dayTotals = [];
$error = null;
$statusMessage = null;
$cacheInfo = readShortwaveCache(SHORTWAVE_CACHE_FILE);
$cacheState = 'none';
$payload = null;

if (isValidCacheForRequest($cacheInfo, $latitude, $longitude, $timezone) && !isCacheExpired($cacheInfo, SHORTWAVE_CACHE_TTL)) {
    $payload = $cacheInfo['payload'];
    $cacheState = 'fresh';
    $statusMessage = 'Showing cached Open-Meteo data from ' . formatCacheTime((int) $cacheInfo['cachedAt']) . '.';
} else {
    [$payload, $fetchError] = fetchOpenMeteoJson($apiUrl);
    if ($payload !== null) {
        $cacheInfo = [
            'cachedAt' => time(),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'timezone' => $timezone,
            'payload' => $payload,
        ];
        if (!writeShortwaveCache(SHORTWAVE_CACHE_FILE, $cacheInfo)) {
            $statusMessage = 'Fetched live Open-Meteo data, but failed to update the local cache file.';
        } else {
            $statusMessage = 'Fetched fresh Open-Meteo data and updated the 6-hour cache.';
        }
        $cacheState = 'live';
    } elseif (isValidCacheForRequest($cacheInfo, $latitude, $longitude, $timezone)) {
        $payload = $cacheInfo['payload'];
        $cacheState = 'stale';
        $statusMessage = 'Live fetch failed, so this page is using stale cached data from ' . formatCacheTime((int) $cacheInfo['cachedAt']) . '.';
        $error = $fetchError;
    } else {
        $error = $fetchError;
    }
}

if ($payload !== null) {
    $hourly = $payload['hourly'] ?? null;
    $units = $payload['hourly_units'] ?? [];
    $times = is_array($hourly['time'] ?? null) ? $hourly['time'] : [];
    $values = is_array($hourly['shortwave_radiation'] ?? null) ? $hourly['shortwave_radiation'] : [];
    $unit = hourlyRadiationUnit();
    $timezoneLabel = isset($payload['timezone']) ? (string) $payload['timezone'] : $timezone;

    if (count($times) === 0 || count($times) !== count($values)) {
        $error = 'The API returned invalid hourly shortwave radiation data.';
    } else {
        $values = array_map(static fn ($value): float => (float) $value, $values);
        $summary = buildSummary($times, $values, $unit, $timezoneLabel);
        $chart = buildChart($times, $values, $unit);
        $dayTotals = extractDayTotals($times, $values);
    }
}

function fetchOpenMeteoJson(string $url): array
{
    $body = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'zendure-shortwave-visualizer/1.0',
        ]);

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $statusCode >= 400) {
            $message = $curlError !== '' ? $curlError : ('HTTP ' . $statusCode);
            return [null, 'Failed to fetch Open-Meteo data: ' . $message];
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 20,
                'header' => "User-Agent: zendure-shortwave-visualizer/1.0\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return [null, 'Failed to fetch Open-Meteo data with file_get_contents().'];
        }
    }

    try {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return [null, 'Failed to decode API response: ' . $e->getMessage()];
    }

    if (!is_array($decoded)) {
        return [null, 'The API response is not a JSON object.'];
    }

    return [$decoded, null];
}

function readShortwaveCache(string $path): ?array
{
    if (!file_exists($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (
        !is_array($decoded) ||
        !isset($decoded['cachedAt'], $decoded['latitude'], $decoded['longitude'], $decoded['timezone'], $decoded['payload']) ||
        !is_array($decoded['payload'])
    ) {
        return null;
    }

    return $decoded;
}

function writeShortwaveCache(string $path, array $cacheData): bool
{
    $encoded = json_encode($cacheData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        return false;
    }

    return @file_put_contents($path, $encoded, LOCK_EX) !== false;
}

function isValidCacheForRequest(?array $cacheInfo, float $latitude, float $longitude, string $timezone): bool
{
    if ($cacheInfo === null) {
        return false;
    }

    return
        (float) $cacheInfo['latitude'] === $latitude &&
        (float) $cacheInfo['longitude'] === $longitude &&
        (string) $cacheInfo['timezone'] === $timezone;
}

function isCacheExpired(array $cacheInfo, int $ttl): bool
{
    return !isset($cacheInfo['cachedAt']) || (time() - (int) $cacheInfo['cachedAt']) > $ttl;
}

function formatCacheTime(int $timestamp): string
{
    return date('Y-m-d H:i:s', $timestamp);
}

function buildSummary(array $times, array $values, string $unit, string $timezone): array
{
    $peakIndex = 0;
    $peakValue = $values[0];
    $sunlightHours = 0;
    $total = 0.0;

    foreach ($values as $index => $value) {
        $total += $value;
        if ($value > $peakValue) {
            $peakValue = $value;
            $peakIndex = $index;
        }
        if ($value > 0) {
            $sunlightHours++;
        }
    }

    return [
        'hours' => count($values),
        'peak_value' => number_format($peakValue, 0) . ' ' . $unit,
        'peak_time' => formatSummaryDateTime($times[$peakIndex]),
        'average' => number_format($total / count($values), 1) . ' ' . $unit,
        'sunlight_hours' => (string) $sunlightHours,
        'timezone' => $timezone,
    ];
}

function buildChart(array $times, array $values, string $unit): array
{
    $chartWidth = 1200.0;
    $chartHeight = 420.0;
    $paddingLeft = 70.0;
    $paddingRight = 24.0;
    $paddingTop = 22.0;
    $paddingBottom = 118.0;
    $plotWidth = $chartWidth - $paddingLeft - $paddingRight;
    $plotHeight = $chartHeight - $paddingTop - $paddingBottom;
    $maxValue = max($values);
    $scaleMax = max(50.0, ceil($maxValue / 50.0) * 50.0);
    $points = [];

    foreach ($values as $index => $value) {
        $x = count($values) === 1
            ? $paddingLeft
            : $paddingLeft + ($index / (count($values) - 1)) * $plotWidth;
        $y = $paddingTop + $plotHeight - (($value / $scaleMax) * $plotHeight);
        $points[] = sprintf('%.2f,%.2f', $x, $y);
    }

    $yAxis = [];
    for ($step = 0; $step <= 5; $step++) {
        $tickValue = ($scaleMax / 5.0) * $step;
        $y = $paddingTop + $plotHeight - ($step / 5.0) * $plotHeight;
        $yAxis[] = [
            'y' => $y,
            'label' => number_format($tickValue, 0),
        ];
    }

    $xAxis = [];
    $stepSize = max(1, (int) floor(count($times) / 8));
    for ($index = 0; $index < count($times); $index += $stepSize) {
        $x = count($times) === 1
            ? $paddingLeft
            : $paddingLeft + ($index / (count($times) - 1)) * $plotWidth;
        $xAxis[] = [
            'x' => $x,
            'label' => formatTimeLabel($times[$index]),
        ];
    }

    $daySummaries = buildDaySummaries($times, $values, $paddingLeft, $plotWidth);

    return [
        'width' => $chartWidth,
        'height' => $chartHeight,
        'padding_left' => $paddingLeft,
        'padding_right' => $paddingRight,
        'padding_top' => $paddingTop,
        'padding_bottom' => $paddingBottom,
        'polyline_points' => implode(' ', $points),
        'polygon_points' => sprintf(
            '%.2f,%.2f %s %.2f,%.2f',
            $paddingLeft,
            $chartHeight - $paddingBottom,
            implode(' ', $points),
            $chartWidth - $paddingRight,
            $chartHeight - $paddingBottom
        ),
        'y_axis' => $yAxis,
        'x_axis' => $xAxis,
        'day_summaries' => $daySummaries,
        'unit' => $unit,
    ];
}

function buildDaySummaries(array $times, array $values, float $paddingLeft, float $plotWidth): array
{
    $groups = [];
    $weekdayMap = [
        'Mon' => 'Mon',
        'Tue' => 'Tue',
        'Wed' => 'Wed',
        'Thu' => 'Thu',
        'Fri' => 'Fri',
        'Sat' => 'Sat',
        'Sun' => 'Sun',
    ];
    $lastIndex = max(1, count($times) - 1);

    foreach ($times as $index => $timestamp) {
        try {
            $dt = new DateTimeImmutable($timestamp);
        } catch (Exception $e) {
            continue;
        }

        $dateKey = $dt->format('Y-m-d');
        if (!isset($groups[$dateKey])) {
            $groups[$dateKey] = [
                'start_index' => $index,
                'end_index' => $index,
                'weekday' => $weekdayMap[$dt->format('D')] ?? $dt->format('D'),
                'date_label' => $dt->format('d-m'),
                'total' => 0.0,
            ];
        }

        $groups[$dateKey]['end_index'] = $index;
        $groups[$dateKey]['total'] += (float) $values[$index];
    }

    $summaries = [];
    foreach ($groups as $date => $group) {
        $startX = $paddingLeft + ($group['start_index'] / $lastIndex) * $plotWidth;
        $endX = $paddingLeft + ($group['end_index'] / $lastIndex) * $plotWidth;
        $summaries[] = [
            'date' => $date,
            'weekday' => $group['weekday'],
            'date_label' => $group['date_label'],
            'center_x' => ($startX + $endX) / 2.0,
            'start_x' => $startX,
            'total' => number_format($group['total'], 0) . ' ' . dailyEnergyUnit(),
        ];
    }

    return $summaries;
}

function extractDayTotals(array $times, array $values): array
{
    $groups = [];

    foreach ($times as $index => $timestamp) {
        try {
            $dt = new DateTimeImmutable($timestamp);
        } catch (Exception $e) {
            continue;
        }

        $dateKey = $dt->format('Y-m-d');
        if (!isset($groups[$dateKey])) {
            $groups[$dateKey] = [
                'weekday' => $dt->format('D'),
                'date_label' => $dt->format('d M'),
                'total' => 0.0,
            ];
        }
        $groups[$dateKey]['total'] += (float) $values[$index];
    }

    $days = [];
    foreach ($groups as $date => $group) {
        $days[] = [
            'date' => $date,
            'weekday' => $group['weekday'],
            'date_label' => $group['date_label'],
            'total' => number_format($group['total'], 0),
        ];
    }

    return $days;
}

function formatTimeLabel(string $timestamp): string
{
    try {
        $dt = new DateTimeImmutable($timestamp);
        return $dt->format('d-m H:i');
    } catch (Exception $e) {
        return $timestamp;
    }
}

function formatSummaryDateTime(string $timestamp): string
{
    try {
        $dt = new DateTimeImmutable($timestamp);
        return $dt->format('d M H:i');
    } catch (Exception $e) {
        return $timestamp;
    }
}

function normalizeShortwaveUnit(string $unit): string
{
    $normalized = str_replace(["Â²", "Ã‚Â²"], "²", $unit);
    $normalized = trim($normalized);
    if ($normalized === '' || stripos($normalized, 'w/m') === false) {
        return hourlyRadiationUnit();
    }
    return $normalized;
}

function hourlyRadiationUnit(): string
{
    return "W/m\u{00B2}";
}

function dailyEnergyUnit(): string
{
    return "Wh/m\u{00B2}";
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Shortwave Radiation</title>
    <style>
        :root {
            --bg-primary: #141414;
            --bg-secondary: #1f1f1f;
            --bg-tertiary: #2b2b2b;
            --panel-border: #3a3a3a;
            --text-primary: #e6e6e6;
            --text-secondary: #b8b8b8;
            --text-muted: #8f8f8f;
            --accent: #64b5f6;
            --accent-soft: rgba(100, 181, 246, 0.16);
            --line: #7ec8ff;
            --fill: rgba(126, 200, 255, 0.18);
            --grid: rgba(255, 255, 255, 0.10);
            --axis: rgba(255, 255, 255, 0.22);
            --success-bg: rgba(129, 199, 132, 0.12);
            --success-border: rgba(129, 199, 132, 0.35);
            --warning-bg: rgba(255, 193, 7, 0.10);
            --warning-border: rgba(255, 193, 7, 0.32);
            --error-bg: rgba(229, 115, 115, 0.14);
            --error-border: rgba(229, 115, 115, 0.35);
            --shadow: 0 22px 60px rgba(0, 0, 0, 0.42);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(100, 181, 246, 0.12), transparent 28%),
                linear-gradient(180deg, #121212 0%, #0e0e0e 100%);
            color: var(--text-primary);
            font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        .page {
            max-width: 980px;
            margin: 0 auto;
            padding: 22px;
        }

        .card {
            background: var(--bg-secondary);
            border: 1px solid var(--panel-border);
            border-radius: 18px;
            padding: 22px 22px 20px;
            box-shadow: var(--shadow);
        }

        .card-header {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .card-header .unit {
            color: var(--text-muted);
            font-weight: 500;
        }

        .card-subtitle {
            margin: 6px 0 18px;
            color: var(--text-secondary);
            font-size: 14px;
        }

        .controls {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }

        label {
            display: flex;
            flex-direction: column;
            gap: 6px;
            color: var(--text-secondary);
            font-size: 13px;
        }

        input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--panel-border);
            border-radius: 10px;
            background: var(--bg-tertiary);
            color: var(--text-primary);
            font: inherit;
        }

        .controls button {
            align-self: end;
            min-height: 42px;
            border: 1px solid rgba(100, 181, 246, 0.35);
            border-radius: 10px;
            background: linear-gradient(180deg, #4d88bf 0%, #3d73a9 100%);
            color: #eef6ff;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        .status {
            margin-bottom: 16px;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 13px;
            border: 1px solid transparent;
        }

        .status.live,
        .status.fresh {
            background: var(--success-bg);
            border-color: var(--success-border);
            color: #d6f0d7;
        }

        .status.stale {
            background: var(--warning-bg);
            border-color: var(--warning-border);
            color: #ffe29a;
        }

        .error {
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid var(--error-border);
            background: var(--error-bg);
            color: #ffb4b4;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 10px;
            margin: 0 0 16px;
        }

        .stat {
            padding: 13px 14px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .stat span {
            display: block;
            color: var(--text-muted);
            font-size: 12px;
            margin-bottom: 4px;
        }

        .stat strong {
            font-size: 17px;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
        }

        .stat--peak-time strong,
        .stat--timezone strong {
            font-size: 15px;
        }

        .tabs {
            display: flex;
            gap: 4px;
            border-bottom: 1px solid var(--panel-border);
            margin-bottom: 12px;
        }

        .tab {
            appearance: none;
            padding: 8px 12px;
            color: var(--text-secondary);
            border: 0;
            border-radius: 0;
            background: transparent;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
            font-weight: 500;
            font-size: 0.9rem;
            font-family: inherit;
            line-height: 1.2;
            cursor: pointer;
        }

        .tab.active {
            color: var(--text-primary);
            border-bottom-color: var(--accent);
        }

        .tab-panels {
            margin-top: 0;
        }

        .tab-panel {
            display: none;
        }

        .tab-panel.active {
            display: block;
        }

        .chart-shell {
            background: #2d2d2d;
            border-radius: 14px;
            padding: 12px 12px 8px;
            margin-bottom: 18px;
        }

        svg {
            width: 100%;
            height: auto;
            display: block;
        }

        .plot-area {
            fill: var(--fill);
        }

        .plot-line {
            fill: none;
            stroke: var(--line);
            stroke-width: 3;
            stroke-linejoin: round;
            stroke-linecap: round;
        }

        .axis-line {
            stroke: var(--axis);
            stroke-width: 1.2;
        }

        .grid-line {
            stroke: var(--grid);
            stroke-width: 1;
        }

        .daily-panel {
            background: rgba(255, 255, 255, 0.025);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 14px;
            padding: 14px;
        }

        .daily-list {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 10px;
        }

        .day-card {
            padding: 12px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
        }

        .day-card .weekday {
            color: var(--text-primary);
            font-weight: 600;
            font-size: 14px;
            white-space: nowrap;
        }

        .day-card .date {
            color: var(--text-muted);
            font-size: 12px;
            margin-top: 2px;
            white-space: nowrap;
        }

        .day-card .value {
            margin-top: 8px;
            color: #dcefff;
            font-size: 18px;
            font-weight: 700;
            white-space: nowrap;
        }

        .meta {
            margin-top: 16px;
            color: var(--text-muted);
            font-size: 13px;
        }

        .meta a {
            color: #9fd3ff;
        }

        @media (max-width: 700px) {
            .page {
                padding: 14px;
            }

            .card {
                padding: 16px;
            }

            .chart-shell {
                padding: 10px 8px 4px;
            }
        }

        @media (max-width: 980px) {
            .stats {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .daily-list {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .daily-list {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="card">
        <h1 class="card-header">Energy per Hour <span class="unit">(Shortwave Radiation)</span></h1>
        <p class="card-subtitle">Open-Meteo hourly shortwave radiation with daily totals and a fixed 6-hour local cache.</p>

        <form method="get" class="controls">
            <label>
                Latitude
                <input type="number" step="any" name="latitude" value="<?= h((string) $latitude) ?>">
            </label>
            <label>
                Longitude
                <input type="number" step="any" name="longitude" value="<?= h((string) $longitude) ?>">
            </label>
            <label>
                Timezone
                <input type="text" name="timezone" value="<?= h($timezone) ?>">
            </label>
            <button type="submit">Refresh Chart</button>
        </form>

        <?php if ($statusMessage !== null): ?>
            <div class="status <?= h($cacheState) ?>"><?= h($statusMessage) ?></div>
        <?php endif; ?>

        <?php if ($error !== null): ?>
            <div class="error"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($summary !== null && $chart !== null): ?>
            <div class="tabs" role="tablist">
                <button type="button" class="tab active" data-tab="graph" role="tab" aria-selected="true">Graph</button>
                <button type="button" class="tab" data-tab="daily" role="tab" aria-selected="false">Daily totals</button>
            </div>

            <div class="tab-panels">
                <div class="tab-panel active" data-tab="graph" role="tabpanel" aria-hidden="false">
                    <div class="stats">
                        <div class="stat"><span>Hours</span><strong><?= h((string) $summary['hours']) ?></strong></div>
                        <div class="stat"><span>Peak</span><strong><?= h((string) $summary['peak_value']) ?></strong></div>
                        <div class="stat stat--peak-time"><span>Peak time</span><strong><?= h((string) $summary['peak_time']) ?></strong></div>
                        <div class="stat"><span>Average</span><strong><?= h((string) $summary['average']) ?></strong></div>
                        <div class="stat"><span>Sunlight hours</span><strong><?= h((string) $summary['sunlight_hours']) ?></strong></div>
                        <div class="stat stat--timezone"><span>Timezone</span><strong><?= h((string) $summary['timezone']) ?></strong></div>
                    </div>

                    <div class="chart-shell">
                        <svg
                            viewBox="0 0 <?= h((string) $chart['width']) ?> <?= h((string) $chart['height']) ?>"
                            role="img"
                            aria-label="Shortwave radiation chart"
                        >
                            <?php foreach ($chart['y_axis'] as $tick): ?>
                                <line
                                    class="grid-line"
                                    x1="<?= h((string) $chart['padding_left']) ?>"
                                    y1="<?= h((string) $tick['y']) ?>"
                                    x2="<?= h((string) ($chart['width'] - $chart['padding_right'])) ?>"
                                    y2="<?= h((string) $tick['y']) ?>"
                                />
                                <text
                                    x="<?= h((string) ($chart['padding_left'] - 12)) ?>"
                                    y="<?= h((string) ($tick['y'] + 5)) ?>"
                                    text-anchor="end"
                                    font-size="12"
                                    fill="#c9c9c9"
                                ><?= h((string) $tick['label']) ?></text>
                            <?php endforeach; ?>

                            <text
                                x="0"
                                y="<?= h((string) ($chart['padding_top'] + 30)) ?>"
                                font-size="14"
                                font-weight="400"
                                fill="#7ec8ff"
                            ><?= h((string) $chart['unit']) ?></text>

                            <line
                                class="axis-line"
                                x1="<?= h((string) $chart['padding_left']) ?>"
                                y1="<?= h((string) ($chart['height'] - $chart['padding_bottom'])) ?>"
                                x2="<?= h((string) ($chart['width'] - $chart['padding_right'])) ?>"
                                y2="<?= h((string) ($chart['height'] - $chart['padding_bottom'])) ?>"
                            />
                            <line
                                class="axis-line"
                                x1="<?= h((string) $chart['padding_left']) ?>"
                                y1="<?= h((string) $chart['padding_top']) ?>"
                                x2="<?= h((string) $chart['padding_left']) ?>"
                                y2="<?= h((string) ($chart['height'] - $chart['padding_bottom'])) ?>"
                            />
                            <polygon class="plot-area" points="<?= h((string) $chart['polygon_points']) ?>" />
                            <polyline class="plot-line" points="<?= h((string) $chart['polyline_points']) ?>" />

                            <?php foreach ($chart['x_axis'] as $tick): ?>
                                <line
                                    x1="<?= h((string) $tick['x']) ?>"
                                    y1="<?= h((string) ($chart['height'] - $chart['padding_bottom'])) ?>"
                                    x2="<?= h((string) $tick['x']) ?>"
                                    y2="<?= h((string) ($chart['height'] - $chart['padding_bottom'] + 6)) ?>"
                                    stroke="rgba(255,255,255,0.18)"
                                    stroke-width="1"
                                />
                                <text
                                    x="<?= h((string) $tick['x']) ?>"
                                    y="<?= h((string) ($chart['height'] - $chart['padding_bottom'] + 24)) ?>"
                                    text-anchor="middle"
                                    font-size="12"
                                    fill="#c2c2c2"
                                ><?= h((string) $tick['label']) ?></text>
                            <?php endforeach; ?>

                            <?php foreach ($chart['day_summaries'] as $index => $day): ?>
                                <?php if ($index > 0): ?>
                                    <line
                                        x1="<?= h((string) $day['start_x']) ?>"
                                        y1="<?= h((string) ($chart['height'] - $chart['padding_bottom'] + 34)) ?>"
                                        x2="<?= h((string) $day['start_x']) ?>"
                                        y2="<?= h((string) ($chart['height'] - 18)) ?>"
                                        stroke="rgba(255,255,255,0.08)"
                                        stroke-width="1"
                                    />
                                <?php endif; ?>
                                <text
                                    x="<?= h((string) $day['center_x']) ?>"
                                    y="<?= h((string) ($chart['height'] - $chart['padding_bottom'] + 54)) ?>"
                                    text-anchor="middle"
                                    font-size="14"
                                    font-weight="700"
                                    fill="#dfe9f5"
                                ><?= h((string) $day['weekday']) ?></text>
                                <text
                                    x="<?= h((string) $day['center_x']) ?>"
                                    y="<?= h((string) ($chart['height'] - $chart['padding_bottom'] + 72)) ?>"
                                    text-anchor="middle"
                                    font-size="14"
                                    fill="#8ea0b0"
                                ><?= h((string) $day['date_label']) ?></text>
                                <text
                                    x="<?= h((string) $day['center_x']) ?>"
                                    y="<?= h((string) ($chart['height'] - $chart['padding_bottom'] + 92)) ?>"
                                    text-anchor="middle"
                                    font-size="16"
                                    fill="#9fd3ff"
                                ><?= h((string) $day['total']) ?></text>
                            <?php endforeach; ?>
                        </svg>
                    </div>
                </div>

                <div class="tab-panel" data-tab="daily" role="tabpanel" aria-hidden="true">
                    <div class="daily-panel">
                        <div class="daily-list">
                            <?php foreach ($dayTotals as $day): ?>
                                <div class="day-card">
                                    <div class="weekday"><?= h((string) $day['weekday']) ?></div>
                                    <div class="date"><?= h((string) $day['date_label']) ?></div>
                                    <div class="value"><?= h((string) $day['total']) ?> <span style="font-size: 13px; color: #8ea0b0; font-weight: 500;">Wh/m²</span></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <p class="meta">
                API:
                <a href="<?= h($apiUrl) ?>" target="_blank" rel="noreferrer"><?= h($apiUrl) ?></a>
                <br>
                Cache file: <code><?= h(SHORTWAVE_CACHE_FILE) ?></code>
            </p>
        <?php endif; ?>
    </div>
</div>
<script>
    (function() {
        var tabs = document.querySelectorAll('.tab[role="tab"]');
        var panels = document.querySelectorAll('.tab-panel[role="tabpanel"]');
        tabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                var targetTab = this.getAttribute('data-tab');
                tabs.forEach(function(t) {
                    var isActive = t.getAttribute('data-tab') === targetTab;
                    t.classList.toggle('active', isActive);
                    t.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });
                panels.forEach(function(panel) {
                    var isActive = panel.getAttribute('data-tab') === targetTab;
                    panel.classList.toggle('active', isActive);
                    panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
                });
            });
        });
    })();
</script>
</body>
</html>
