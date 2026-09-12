<?php

declare(strict_types=1);

const OPTIMIZER_SCHEDULE_MAX_AGE_SECONDS = 4200;

function optimizerScheduleDataDir(): string
{
    return dirname(__DIR__, 2) . '/planner/data';
}

function optimizerScheduleLatestPath(): string
{
    return getenv('OPTIMIZER_SCHEDULE_LATEST_PATH') ?: optimizerScheduleDataDir() . '/optimizer_schedule_latest.json';
}

function optimizerScheduleModePath(): string
{
    return getenv('OPTIMIZER_SCHEDULE_MODE_PATH') ?: optimizerScheduleDataDir() . '/optimizer_mode.json';
}

function optimizerScheduleAuditPath(): string
{
    return getenv('OPTIMIZER_SCHEDULE_AUDIT_PATH') ?: optimizerScheduleDataDir() . '/optimizer_mode_audit.log';
}

function optimizerScheduleReadJson(string $path): ?array
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw)) {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function optimizerScheduleWriteJsonAtomic(string $path, array $payload): bool
{
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
        return false;
    }
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        return false;
    }
    return true;
}

function optimizerScheduleRequestedMode(): string
{
    $state = optimizerScheduleReadJson(optimizerScheduleModePath());
    return (($state['requested_mode'] ?? null) === 'optimizer') ? 'optimizer' : 'rules';
}

/**
 * @return array{valid: bool, reason: ?string, payload: ?array, generated_at: ?string, age_seconds: ?int, horizon_end: ?string}
 */
function optimizerScheduleValidateLatest(DateTimeImmutable $now, array $systemConfig): array
{
    $invalid = static fn(string $reason): array => [
        'valid' => false,
        'reason' => $reason,
        'payload' => null,
        'generated_at' => null,
        'age_seconds' => null,
        'horizon_end' => null,
    ];
    $payload = optimizerScheduleReadJson(optimizerScheduleLatestPath());
    if ($payload === null) {
        return $invalid('No published optimizer schedule is available.');
    }
    if (($payload['type'] ?? null) !== 'optimizer_executable_schedule' || ($payload['version'] ?? null) !== 1) {
        return $invalid('The published optimizer schedule has an unsupported format.');
    }
    $plan = $payload['plan'] ?? null;
    if (!is_array($plan) || !is_array($plan['decisions'] ?? null) || $plan['decisions'] === []) {
        return $invalid('The published optimizer schedule has no decisions.');
    }
    try {
        $generatedAt = new DateTimeImmutable((string) ($plan['generated_at'] ?? ''));
        $horizonStart = new DateTimeImmutable((string) ($plan['horizon_start'] ?? ''));
        $horizonEnd = new DateTimeImmutable((string) ($plan['horizon_end'] ?? ''));
    } catch (Exception) {
        return $invalid('The published optimizer schedule contains invalid timestamps.');
    }
    $age = $now->getTimestamp() - $generatedAt->getTimestamp();
    if ($age < -60) {
        return $invalid('The optimizer schedule timestamp is in the future.');
    }
    if ($age > OPTIMIZER_SCHEDULE_MAX_AGE_SECONDS) {
        return $invalid('The optimizer schedule is stale.');
    }
    if ($now < $horizonStart || $now >= $horizonEnd) {
        return $invalid('The optimizer schedule does not cover the current time.');
    }

    $minPower = (int) $systemConfig['schedule']['minPowerW'];
    $maxPower = (int) $systemConfig['schedule']['maxPowerW'];
    $powerStep = (int) $systemConfig['schedule']['powerStepW'];
    $previousEnd = null;
    $firstStart = null;
    $coversNow = false;
    foreach ($plan['decisions'] as $decision) {
        if (!is_array($decision)) {
            return $invalid('The optimizer schedule contains an invalid decision.');
        }
        try {
            $start = new DateTimeImmutable((string) ($decision['start'] ?? ''));
            $end = new DateTimeImmutable((string) ($decision['end'] ?? ''));
        } catch (Exception) {
            return $invalid('An optimizer decision contains an invalid timestamp.');
        }
        if ($end <= $start || ($previousEnd !== null && $start->getTimestamp() !== $previousEnd->getTimestamp())) {
            return $invalid('Optimizer decisions are not a continuous horizon.');
        }
        $firstStart ??= $start;
        $previousEnd = $end;
        $coversNow = $coversNow || ($start <= $now && $now < $end);

        $value = $decision['schedule_value'] ?? null;
        $isDynamic = in_array($value, ['netzero', 'netzero-', 'netzero+'], true);
        $isFixed = is_int($value) || (is_float($value) && floor($value) === $value);
        if (!$isDynamic && !$isFixed) {
            return $invalid('An optimizer decision contains an unsupported schedule value.');
        }
        if ($isFixed) {
            $watts = (int) $value;
            if ($watts < $minPower || $watts > $maxPower || $watts % $powerStep !== 0) {
                return $invalid('An optimizer decision exceeds the configured power limits.');
            }
        }
        foreach (['min_power', 'max_power'] as $field) {
            if (array_key_exists($field, $decision) && (!is_int($decision[$field]) || $decision[$field] < $minPower || $decision[$field] > $maxPower || $decision[$field] % $powerStep !== 0)) {
                return $invalid('An optimizer decision contains invalid dynamic power limits.');
            }
        }
        if (isset($decision['min_power'], $decision['max_power']) && $decision['min_power'] > $decision['max_power']) {
            return $invalid('An optimizer decision has reversed power limits.');
        }
    }
    if (!$coversNow) {
        return $invalid('No optimizer decision covers the current time.');
    }
    if ($firstStart?->getTimestamp() !== $horizonStart->getTimestamp() || $previousEnd?->getTimestamp() !== $horizonEnd->getTimestamp()) {
        return $invalid('Optimizer decisions do not match the declared horizon.');
    }

    return [
        'valid' => true,
        'reason' => null,
        'payload' => $payload,
        'generated_at' => $generatedAt->format(DateTimeInterface::ATOM),
        'age_seconds' => max(0, $age),
        'horizon_end' => $horizonEnd->format(DateTimeInterface::ATOM),
    ];
}

function optimizerScheduleStatus(DateTimeImmutable $now, array $systemConfig): array
{
    $requested = optimizerScheduleRequestedMode();
    $validation = optimizerScheduleValidateLatest($now, $systemConfig);
    $active = $requested === 'optimizer' && $validation['valid'] ? 'optimizer' : 'rules';
    return [
        'requestedMode' => $requested,
        'activeSource' => $active,
        'fallbackActive' => $requested === 'optimizer' && !$validation['valid'],
        'fallbackReason' => $requested === 'optimizer' ? $validation['reason'] : null,
        'optimizerValid' => $validation['valid'],
        'optimizerGeneratedAt' => $validation['generated_at'],
        'optimizerAgeSeconds' => $validation['age_seconds'],
        'optimizerHorizonEnd' => $validation['horizon_end'],
    ];
}

function optimizerScheduleApplyToDay(array $rulesResolved, string $date, array $payload, DateTimeZone $timezone): array
{
    $byTime = [];
    foreach (($payload['plan']['decisions'] ?? []) as $decision) {
        try {
            $start = (new DateTimeImmutable((string) $decision['start']))->setTimezone($timezone);
        } catch (Exception) {
            continue;
        }
        if ($start->format('Ymd') !== $date) {
            continue;
        }
        $entry = [
            'time' => $start->format('H') . '00',
            'value' => $decision['schedule_value'],
            'key' => 'optimizer:' . $start->format('YmdHi'),
            'source' => 'optimizer',
            'optimizer_reason' => (string) ($decision['reason'] ?? ''),
        ];
        foreach (['min_power', 'max_power'] as $field) {
            if (array_key_exists($field, $decision)) {
                $entry[$field] = $decision[$field];
            }
        }
        $byTime[$entry['time']] = $entry;
    }

    foreach ($rulesResolved as &$slot) {
        if (!is_array($slot) || !isset($slot['time'])) {
            continue;
        }
        $time = str_pad((string) $slot['time'], 4, '0', STR_PAD_LEFT);
        $isExactManual = isset($slot['key']) && preg_match('/^\d{12}$/', (string) $slot['key']) === 1;
        if (!$isExactManual && isset($byTime[$time])) {
            $slot = $byTime[$time];
        }
        unset($byTime[$time]);
    }
    unset($slot);
    foreach ($byTime as $entry) {
        $rulesResolved[] = $entry;
    }
    usort($rulesResolved, static fn(array $a, array $b): int => strcmp((string) ($a['time'] ?? ''), (string) ($b['time'] ?? '')));
    return $rulesResolved;
}

function optimizerScheduleAppendAudit(array $payload): void
{
    $path = optimizerScheduleAuditPath();
    $handle = @fopen($path, 'ab');
    if ($handle === false) {
        return;
    }
    try {
        @flock($handle, LOCK_EX);
        @fwrite($handle, json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n");
        @fflush($handle);
    } finally {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}
