<?php

declare(strict_types=1);

function optimizerLogDefaultPath(): string
{
    return dirname(__DIR__, 2) . '/planner/data/optimizer_schedule.log';
}

/**
 * Read the final non-empty lines without loading an ever-growing log into memory.
 *
 * @return list<string>
 */
function optimizerLogTailLines(string $path, int $lineLimit): array
{
    if ($lineLimit < 1 || !is_file($path) || !is_readable($path)) {
        return [];
    }

    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }

    $buffer = '';
    $chunkSize = 8192;
    try {
        @flock($handle, LOCK_SH);
        fseek($handle, 0, SEEK_END);
        $position = ftell($handle);
        if (!is_int($position)) {
            return [];
        }

        while ($position > 0 && substr_count($buffer, "\n") <= $lineLimit) {
            $readSize = min($chunkSize, $position);
            $position -= $readSize;
            fseek($handle, $position);
            $chunk = fread($handle, $readSize);
            if ($chunk === false) {
                break;
            }
            $buffer = $chunk . $buffer;
        }
    } finally {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }

    $lines = preg_split('/\R/', $buffer) ?: [];
    $lines = array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));
    return array_slice($lines, -$lineLimit);
}

/**
 * @return array{records: list<array<string, mixed>>, invalid_lines: int, scanned_lines: int}
 */
function optimizerLogReadPlans(string $path, int $planLimit): array
{
    $planLimit = max(1, min(48, $planLimit));
    $lines = optimizerLogTailLines($path, ($planLimit * 4) + 20);
    $records = [];
    $invalidLines = 0;

    for ($index = count($lines) - 1; $index >= 0 && count($records) < $planLimit; $index--) {
        try {
            $decoded = json_decode($lines[$index], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $invalidLines++;
            continue;
        }
        if (!is_array($decoded) || ($decoded['type'] ?? null) !== 'optimizer_shadow_plan') {
            continue;
        }
        if (!isset($decoded['plan']) || !is_array($decoded['plan'])) {
            $invalidLines++;
            continue;
        }
        $records[] = $decoded;
    }

    return [
        'records' => $records,
        'invalid_lines' => $invalidLines,
        'scanned_lines' => count($lines),
    ];
}

