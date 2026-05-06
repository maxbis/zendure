<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/report_api_common.php';
require_once dirname(__DIR__) . '/includes/report_pnl_common.php';

date_default_timezone_set('Europe/Amsterdam');

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

$requestMethod = pnlRequestMethod();

if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($requestMethod !== 'GET') {
    http_response_code(405);
    echo dailyReportJsonEncode(['success' => false, 'error' => 'Method not allowed. Use GET.']);
    exit();
}

try {
    echo dailyReportJsonEncode(buildDailyPnlPayload());
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo dailyReportJsonEncode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo dailyReportJsonEncode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * @return array<string, mixed>
 */
function buildDailyPnlPayload(): array
{
    $tz = dailyReportTimezone();
    $requestedDate = pnlRequestDate($tz);
    $requestedDayCount = pnlRequestDayCount();
    $today = new DateTimeImmutable('now', $tz);
    $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $requestedDate, $tz);

    if (!$endDate instanceof DateTimeImmutable) {
        throw new InvalidArgumentException('Invalid date. Expected YYYY-MM-DD.');
    }

    $startDate = $endDate->modify('-' . ($requestedDayCount - 1) . ' days');
    $days = [];

    for ($cursor = $startDate; $cursor <= $endDate; $cursor = $cursor->modify('+1 day')) {
        $date = $cursor->format('Y-m-d');
        $loaded = dailyReportLoadOrGenerate($date, $date === $today->format('Y-m-d'));
        $days[] = dailyReportBuildPnlDayPayload(
            $date,
            $loaded,
            dailyReportResolveSource((bool)$loaded['generated'], $date, $today)
        );
    }

    return [
        'success' => true,
        'requestedDate' => $requestedDate,
        'requestedDayCount' => $requestedDayCount,
        'startDate' => $startDate->format('Y-m-d'),
        'endDate' => $endDate->format('Y-m-d'),
        'days' => $days,
    ];
}

function pnlRequestMethod(): string
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    return is_string($method) && $method !== '' ? strtoupper($method) : 'GET';
}

function pnlRequestDate(DateTimeZone $tz): string
{
    $raw = $_GET['date'] ?? null;
    if (!is_string($raw) || trim($raw) === '') {
        throw new InvalidArgumentException('Missing date. Expected YYYY-MM-DD.');
    }

    $date = trim($raw);
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date, $tz);
    if ($dt === false || $dt->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid date. Expected YYYY-MM-DD.');
    }

    return $date;
}

function pnlRequestDayCount(): int
{
    $raw = $_GET['n'] ?? null;
    if ($raw === null) {
        return 1;
    }
    if (!is_string($raw) && !is_int($raw)) {
        throw new InvalidArgumentException('Invalid n. Expected an integer >= 1.');
    }

    $value = is_int($raw) ? (string)$raw : trim($raw);
    if ($value === '' || !preg_match('/^\d+$/', $value)) {
        throw new InvalidArgumentException('Invalid n. Expected an integer >= 1.');
    }

    $dayCount = (int)$value;
    if ($dayCount < 1) {
        throw new InvalidArgumentException('Invalid n. Expected an integer >= 1.');
    }

    return $dayCount;
}
