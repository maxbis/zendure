<?php
/**
 * Shared sunrise/sunset hour context for schedule conditions.
 */

function clampHour(int $hour): int
{
    return max(0, min(23, $hour));
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
    $sunriseFloatHour = ((int) $sunriseDt->format('H')) + (((int) $sunriseDt->format('i')) / 60.0);
    $sunsetFloatHour = ((int) $sunsetDt->format('H')) + (((int) $sunsetDt->format('i')) / 60.0);

    $sunriseHour = clampHour((int) floor($sunriseFloatHour));
    $sunsetHour = clampHour((int) ceil($sunsetFloatHour));

    return [
        'sunrise_ts' => $sunriseTs,
        'sunset_ts' => $sunsetTs,
        'sunrise_time' => $sunriseDt->format('H:i'),
        'sunset_time' => $sunsetDt->format('H:i'),
        'sunrise_hour' => $sunriseHour,
        'sunset_hour' => $sunsetHour,
    ];
}
