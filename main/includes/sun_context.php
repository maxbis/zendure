<?php
/**
 * Shared sunrise/sunset hour context for schedule conditions.
 */

function clampHour(int $hour): int
{
    return max(0, min(23, $hour));
}

function roundSunTimeToNearestHour(DateTimeInterface $dateTime): int
{
    $floatHour = ((int) $dateTime->format('H'))
        + (((int) $dateTime->format('i')) / 60.0)
        + (((int) $dateTime->format('s')) / 3600.0);

    return clampHour((int) round($floatHour, 0, PHP_ROUND_HALF_UP));
}

/**
 * @return array{
 *   sunrise_ts?: int,
 *   sunset_ts?: int,
 *   sunrise_time?: string,
 *   sunset_time?: string,
 *   sunrise_hour?: int,
 *   sunset_hour?: int
 * }
 */
function getSunContextForDate(string $yyyymmdd, float $latitude, float $longitude, DateTimeZone $tz): array
{
    $dateLocal = DateTimeImmutable::createFromFormat('Ymd H:i:s', $yyyymmdd . ' 12:00:00', $tz);
    if (!$dateLocal) {
        return [];
    }

    $sunInfo = @date_sun_info($dateLocal->getTimestamp(), $latitude, $longitude);
    if (!is_array($sunInfo) || !isset($sunInfo['sunrise'], $sunInfo['sunset'])) {
        return [];
    }

    $sunriseTs = is_numeric($sunInfo['sunrise']) ? (int) $sunInfo['sunrise'] : null;
    $sunsetTs = is_numeric($sunInfo['sunset']) ? (int) $sunInfo['sunset'] : null;
    if ($sunriseTs === null || $sunsetTs === null || $sunriseTs <= 0 || $sunsetTs <= 0) {
        return [];
    }

    $sunriseDt = (new DateTimeImmutable('@' . $sunriseTs))->setTimezone($tz);
    $sunsetDt = (new DateTimeImmutable('@' . $sunsetTs))->setTimezone($tz);
    $sunriseHour = roundSunTimeToNearestHour($sunriseDt);
    $sunsetHour = roundSunTimeToNearestHour($sunsetDt);

    return [
        'sunrise_ts' => $sunriseTs,
        'sunset_ts' => $sunsetTs,
        'sunrise_time' => $sunriseDt->format('H:i'),
        'sunset_time' => $sunsetDt->format('H:i'),
        'sunrise_hour' => $sunriseHour,
        'sunset_hour' => $sunsetHour,
    ];
}
