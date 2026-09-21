<?php

declare(strict_types=1);

const OPTIMIZER_RUNTIME_HOUSEHOLD_MEDIAN_WINDOW_DAYS = 30;
const OPTIMIZER_RUNTIME_HOUSEHOLD_MEDIAN_MIN_SAMPLES = 7;
const OPTIMIZER_RUNTIME_HOUSEHOLD_MEDIAN_MIN_COVERAGE_PCT = 75.0;

function optimizerRuntimeLogDefaultPath(): string
{
    $configured = getenv('PLANNER_RUNTIME_LOG_PATH');
    if (is_string($configured) && trim($configured) !== '') {
        return $configured;
    }

    return dirname(__DIR__, 2) . '/planner/data/optimizer_runtime.jsonl';
}

/**
 * @return array<string, mixed>|null
 */
function optimizerRuntimeLogDecodeLine(string $line): ?array
{
    try {
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    if (!is_array($decoded) || !is_string($decoded['event'] ?? null) || !is_string($decoded['observed_at'] ?? null)) {
        return null;
    }

    return $decoded;
}

function optimizerRuntimeLogEventTime(array $event, DateTimeZone $timezone): ?DateTimeImmutable
{
    $raw = is_string($event['observed_at'] ?? null) ? $event['observed_at'] : null;
    if ($raw === null) {
        return null;
    }

    try {
        return (new DateTimeImmutable($raw))->setTimezone($timezone);
    } catch (Exception) {
        return null;
    }
}

function optimizerRuntimeLogHourStart(array $event, DateTimeZone $timezone): ?DateTimeImmutable
{
    $raw = is_string($event['hour_start'] ?? null) ? $event['hour_start'] : null;
    if ($raw === null) {
        return null;
    }

    try {
        return (new DateTimeImmutable($raw))->setTimezone($timezone);
    } catch (Exception) {
        return null;
    }
}

function optimizerRuntimeLogIsHouseholdMedianSample(array $event): bool
{
    return ($event['event'] ?? null) === 'HOUR_CLOSED'
        && ($event['status'] ?? null) === 'complete'
        && is_numeric($event['coverage_pct'] ?? null)
        && (float) $event['coverage_pct'] >= OPTIMIZER_RUNTIME_HOUSEHOLD_MEDIAN_MIN_COVERAGE_PCT
        && is_numeric($event['predicted_solar_wh'] ?? null)
        && (float) $event['predicted_solar_wh'] === 0.0
        && is_numeric($event['actual_usage_wh'] ?? null);
}

/**
 * @param list<float> $values
 */
function optimizerRuntimeLogMedian(array $values): ?float
{
    $count = count($values);
    if ($count === 0) {
        return null;
    }

    sort($values, SORT_NUMERIC);
    $middle = intdiv($count, 2);
    if ($count % 2 === 1) {
        return $values[$middle];
    }

    return ($values[$middle - 1] + $values[$middle]) / 2;
}

function optimizerRuntimeLogMatchesView(array $event, string $view): bool
{
    $type = (string) ($event['event'] ?? '');
    return match ($view) {
        'hours' => $type === 'HOUR_CLOSED',
        'samples' => $type === 'SAMPLE',
        default => in_array($type, ['HOUR_OPENED', 'SAMPLE', 'HOUR_CLOSED'], true),
    };
}

function optimizerRuntimeLogMatchesPeriod(
    DateTimeImmutable $eventTime,
    DateTimeImmutable $now,
    string $period,
    ?string $date
): bool {
    if ($period === 'date' && $date !== null) {
        return $eventTime->format('Y-m-d') === $date;
    }
    if ($period === 'today') {
        return $eventTime >= $now->setTime(0, 0);
    }
    if ($period === '7d') {
        return $eventTime >= $now->modify('-7 days');
    }
    if ($period === 'all') {
        return true;
    }

    return $eventTime >= $now->modify('-24 hours');
}

/**
 * @return array{events: list<array<string, mixed>>, matching_count: int, invalid_lines: int, scanned_lines: int, latest_at: ?string}
 */
function optimizerRuntimeLogRead(
    string $path,
    DateTimeZone $timezone,
    DateTimeImmutable $now,
    string $view,
    string $period,
    ?string $date,
    int $limit
): array {
    $limit = max(1, min(250, $limit));
    if (!is_file($path) || !is_readable($path)) {
        return [
            'events' => [],
            'matching_count' => 0,
            'invalid_lines' => 0,
            'scanned_lines' => 0,
            'latest_at' => null,
        ];
    }

    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return [
            'events' => [],
            'matching_count' => 0,
            'invalid_lines' => 0,
            'scanned_lines' => 0,
            'latest_at' => null,
        ];
    }

    $events = [];
    $matchingCount = 0;
    $invalidLines = 0;
    $scannedLines = 0;
    $latestAt = null;
    $latestTimestamp = null;
    /** @var array<int, list<array{timestamp: int, value: float}>> $householdHistoryByHour */
    $householdHistoryByHour = array_fill(0, 24, []);

    try {
        @flock($handle, LOCK_SH);
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $scannedLines++;
            $event = optimizerRuntimeLogDecodeLine($line);
            if ($event === null) {
                $invalidLines++;
                continue;
            }
            $eventTime = optimizerRuntimeLogEventTime($event, $timezone);
            if ($eventTime === null) {
                $invalidLines++;
                continue;
            }
            if ($latestTimestamp === null || $eventTime->getTimestamp() > $latestTimestamp) {
                $latestTimestamp = $eventTime->getTimestamp();
                $latestAt = $eventTime->format(DateTimeInterface::ATOM);
            }

            $hourStart = optimizerRuntimeLogHourStart($event, $timezone);
            if ($hourStart !== null && optimizerRuntimeLogIsHouseholdMedianSample($event)) {
                $hour = (int) $hourStart->format('G');
                $hourStartTimestamp = $hourStart->getTimestamp();
                $windowStartTimestamp = $hourStart->modify(
                    '-' . OPTIMIZER_RUNTIME_HOUSEHOLD_MEDIAN_WINDOW_DAYS . ' days'
                )->getTimestamp();
                $householdHistoryByHour[$hour] = array_values(array_filter(
                    $householdHistoryByHour[$hour],
                    static fn (array $sample): bool => $sample['timestamp'] >= $windowStartTimestamp
                ));
                $priorSamples = array_values(array_filter(
                    $householdHistoryByHour[$hour],
                    static fn (array $sample): bool => $sample['timestamp'] < $hourStartTimestamp
                ));
                $median = optimizerRuntimeLogMedian(array_map(
                    static fn (array $sample): float => $sample['value'],
                    $priorSamples
                ));

                $event['_household_rolling_median_wh'] = $median;
                $event['_household_rolling_median_samples'] = count($priorSamples);
                $householdHistoryByHour[$hour][] = [
                    'timestamp' => $hourStartTimestamp,
                    'value' => (float) $event['actual_usage_wh'],
                ];
            }
            if (!optimizerRuntimeLogMatchesView($event, $view)
                || !optimizerRuntimeLogMatchesPeriod($eventTime, $now, $period, $date)) {
                continue;
            }

            $matchingCount++;
            $event['_local_time'] = $eventTime->format(DateTimeInterface::ATOM);
            $events[] = $event;
            if (count($events) > $limit) {
                array_shift($events);
            }
        }
    } finally {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }

    return [
        'events' => array_reverse($events),
        'matching_count' => $matchingCount,
        'invalid_lines' => $invalidLines,
        'scanned_lines' => $scannedLines,
        'latest_at' => $latestAt,
    ];
}
