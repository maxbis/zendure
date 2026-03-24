<?php
declare(strict_types=1);

$latitude = isset($_GET['latitude']) ? (float) $_GET['latitude'] : 52.3;
$longitude = isset($_GET['longitude']) ? (float) $_GET['longitude'] : 4.863;
$timezone = isset($_GET['timezone']) && is_string($_GET['timezone']) && $_GET['timezone'] !== ''
    ? $_GET['timezone']
    : 'Europe/Amsterdam';

$apiUrl = sprintf(
    'https://api.open-meteo.com/v1/forecast?latitude=%s&longitude=%s&hourly=shortwave_radiation&timezone=%s',
    rawurlencode((string) $latitude),
    rawurlencode((string) $longitude),
    rawurlencode($timezone)
);

[$payload, $error] = fetchOpenMeteoJson($apiUrl);
$times = [];
$values = [];
$unit = 'W/m²';
$timezoneLabel = $timezone;
$summary = null;

if ($payload !== null) {
    $hourly = $payload['hourly'] ?? null;
    $units = $payload['hourly_units'] ?? [];
    $times = is_array($hourly['time'] ?? null) ? $hourly['time'] : [];
    $values = is_array($hourly['shortwave_radiation'] ?? null) ? $hourly['shortwave_radiation'] : [];
    $unit = isset($units['shortwave_radiation']) ? (string) $units['shortwave_radiation'] : $unit;
    $timezoneLabel = isset($payload['timezone']) ? (string) $payload['timezone'] : $timezone;

    if (count($times) === 0 || count($times) !== count($values)) {
        $error = 'The API returned invalid hourly shortwave radiation data.';
    } else {
        $values = array_map(static fn ($value): float => (float) $value, $values);
        $summary = buildSummary($times, $values, $unit, $timezoneLabel);
    }
}

if ($summary !== null) {
    $chart = buildChart($times, $values, $unit);
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
        'peak_time' => $times[$peakIndex],
        'average' => number_format($total / count($values), 1) . ' ' . $unit,
        'sunlight_hours' => (string) $sunlightHours,
        'timezone' => $timezone,
    ];
}

function buildChart(array $times, array $values, string $unit): array
{
    $chartWidth = 1200.0;
    $chartHeight = 500.0;
    $paddingLeft = 70.0;
    $paddingRight = 20.0;
    $paddingTop = 20.0;
    $paddingBottom = 140.0;
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
        'Mon' => 'mo',
        'Tue' => 'tu',
        'Wed' => 'we',
        'Thu' => 'th',
        'Fri' => 'fr',
        'Sat' => 'sa',
        'Sun' => 'su',
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
                'weekday' => $weekdayMap[$dt->format('D')] ?? strtolower($dt->format('D')),
                'total' => 0.0,
            ];
        }

        $groups[$dateKey]['end_index'] = $index;
        $groups[$dateKey]['total'] += (float) $values[$index];
    }

    $summaries = [];
    foreach ($groups as $group) {
        $startX = $paddingLeft + ($group['start_index'] / $lastIndex) * $plotWidth;
        $endX = $paddingLeft + ($group['end_index'] / $lastIndex) * $plotWidth;
        $summaries[] = [
            'weekday' => $group['weekday'],
            'center_x' => ($startX + $endX) / 2.0,
            'start_x' => $startX,
            'total' => number_format($group['total'], 0) . ' Wh/m²',
        ];
    }

    return $summaries;
}

function formatTimeLabel(string $timestamp): string
{
    try {
        $dt = new DateTimeImmutable($timestamp);
        return $dt->format('d M H:i');
    } catch (Exception $e) {
        return $timestamp;
    }
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
    <title>Shortwave Radiation Visualizer</title>
    <style>
        :root {
            --bg: #eef5fb;
            --panel: #ffffff;
            --line: #d08b00;
            --fill: rgba(208, 139, 0, 0.18);
            --axis: #5e6b78;
            --grid: #d7e0ea;
            --text: #1e2a35;
            --muted: #607080;
            --border: #e5edf4;
            --shadow: 0 20px 60px rgba(31, 45, 61, 0.12);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 32px;
            background:
                radial-gradient(circle at top left, rgba(255, 197, 61, 0.16), transparent 32%),
                linear-gradient(180deg, #eef5fb 0%, #f9fbfd 100%);
            color: var(--text);
            font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .wrap {
            max-width: 1280px;
            margin: 0 auto;
        }

        .panel {
            background: var(--panel);
            border-radius: 20px;
            padding: 24px;
            box-shadow: var(--shadow);
        }

        h1 {
            margin: 0 0 4px;
            font-size: 28px;
        }

        p {
            margin: 0;
            color: var(--muted);
        }

        form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin: 20px 0 24px;
        }

        label {
            display: flex;
            flex-direction: column;
            gap: 6px;
            color: var(--muted);
            font-size: 13px;
        }

        input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            font: inherit;
            color: var(--text);
            background: #fbfdff;
        }

        button {
            align-self: end;
            height: 44px;
            border: 0;
            border-radius: 12px;
            background: #16344d;
            color: #fff;
            font: inherit;
            cursor: pointer;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin: 0 0 24px;
        }

        .stat {
            padding: 14px 16px;
            border-radius: 14px;
            background: #f7fafc;
            border: 1px solid var(--border);
        }

        .stat span {
            display: block;
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 4px;
        }

        .stat strong {
            font-size: 18px;
        }

        .error {
            padding: 16px 18px;
            border: 1px solid #f3c4c4;
            background: #fff4f4;
            color: #8c2b2b;
            border-radius: 14px;
            margin-bottom: 20px;
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
            stroke-width: 1.5;
        }

        .grid-line {
            stroke: var(--grid);
            stroke-width: 1;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="panel">
        <h1>Open-Meteo Shortwave Radiation</h1>
        <p>Fetches live hourly shortwave radiation data and renders it as a standalone SVG chart.</p>

        <form method="get">
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
            <button type="submit">Load Chart</button>
        </form>

        <?php if ($error !== null): ?>
            <div class="error"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($summary !== null && isset($chart)): ?>
            <p style="margin-bottom: 20px;">
                API:
                <a href="<?= h($apiUrl) ?>" target="_blank" rel="noreferrer"><?= h($apiUrl) ?></a>
            </p>

            <div class="stats">
                <div class="stat"><span>Hours</span><strong><?= h((string) $summary['hours']) ?></strong></div>
                <div class="stat"><span>Peak</span><strong><?= h((string) $summary['peak_value']) ?></strong></div>
                <div class="stat"><span>Peak time</span><strong><?= h((string) $summary['peak_time']) ?></strong></div>
                <div class="stat"><span>Average</span><strong><?= h((string) $summary['average']) ?></strong></div>
                <div class="stat"><span>Sunlight hours</span><strong><?= h((string) $summary['sunlight_hours']) ?></strong></div>
                <div class="stat"><span>Timezone</span><strong><?= h((string) $summary['timezone']) ?></strong></div>
            </div>

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
                        fill="#4b5b6b"
                    ><?= h((string) $tick['label']) ?></text>
                <?php endforeach; ?>

                <text
                    x="18"
                    y="<?= h((string) ($chart['padding_top'] + 10)) ?>"
                    font-size="12"
                    fill="#4b5b6b"
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
                        stroke="#5e6b78"
                        stroke-width="1"
                    />
                    <text
                        x="<?= h((string) $tick['x']) ?>"
                        y="<?= h((string) ($chart['height'] - $chart['padding_bottom'] + 24)) ?>"
                        text-anchor="middle"
                        font-size="12"
                        fill="#4b5b6b"
                    ><?= h((string) $tick['label']) ?></text>
                <?php endforeach; ?>

                <?php foreach ($chart['day_summaries'] as $index => $day): ?>
                    <?php if ($index > 0): ?>
                        <line
                            x1="<?= h((string) $day['start_x']) ?>"
                            y1="<?= h((string) ($chart['height'] - $chart['padding_bottom'] + 34)) ?>"
                            x2="<?= h((string) $day['start_x']) ?>"
                            y2="<?= h((string) ($chart['height'] - 18)) ?>"
                            stroke="#d7e0ea"
                            stroke-width="1"
                        />
                    <?php endif; ?>
                    <text
                        x="<?= h((string) $day['center_x']) ?>"
                        y="<?= h((string) ($chart['height'] - $chart['padding_bottom'] + 56)) ?>"
                        text-anchor="middle"
                        font-size="14"
                        font-weight="700"
                        fill="#1e2a35"
                    ><?= h((string) $day['weekday']) ?></text>
                    <text
                        x="<?= h((string) $day['center_x']) ?>"
                        y="<?= h((string) ($chart['height'] - $chart['padding_bottom'] + 80)) ?>"
                        text-anchor="middle"
                        font-size="12"
                        fill="#607080"
                    ><?= h((string) $day['total']) ?></text>
                <?php endforeach; ?>
            </svg>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
