<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/report_api_common.php';

date_default_timezone_set('Europe/Amsterdam');

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo dailyReportJsonEncode(['success' => false, 'error' => 'Method not allowed. Use GET.']);
    exit();
}

try {
    echo dailyReportJsonEncode(buildDailyReportPayload());
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo dailyReportJsonEncode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo dailyReportJsonEncode(['success' => false, 'error' => $e->getMessage()]);
}

function buildDailyReportPayload(): array
{
    $tz = dailyReportTimezone();
    $requestedDate = requestDate($tz);
    $today = new DateTimeImmutable('now', $tz);
    $todayDate = $today->format('Y-m-d');
    $isToday = $requestedDate === $todayDate;
    $loaded = dailyReportLoadOrGenerate($requestedDate, $isToday);
    $source = $loaded['generated']
        ? ($isToday ? 'regenerated_today' : 'generated_on_demand')
        : 'saved';

    return [
        'success' => true,
        'requestedDate' => $requestedDate,
        'source' => $source,
        'savedAt' => $loaded['savedAt'],
        'report' => $loaded['report'],
    ];
}

function requestDate(DateTimeZone $tz): string
{
    $raw = $_GET['date'] ?? '';
    if (!is_string($raw) || trim($raw) === '') {
        return (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    }

    $date = trim($raw);
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date, $tz);
    if ($dt === false || $dt->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid date. Expected YYYY-MM-DD.');
    }
    return $date;
}
