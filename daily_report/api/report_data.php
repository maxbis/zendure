<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/report_api_common.php';

date_default_timezone_set('Europe/Amsterdam');

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

$requestMethod = requestMethod();

if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($requestMethod !== 'GET' && $requestMethod !== 'POST') {
    http_response_code(405);
    echo dailyReportJsonEncode(['success' => false, 'error' => 'Method not allowed. Use GET or POST.']);
    exit();
}

try {
    echo dailyReportJsonEncode(buildDailyReportPayload($requestMethod));
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo dailyReportJsonEncode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo dailyReportJsonEncode(['success' => false, 'error' => $e->getMessage()]);
}

function buildDailyReportPayload(string $requestMethod): array
{
    $tz = dailyReportTimezone();
    $requestedDate = requestDate($tz, $requestMethod);
    $today = new DateTimeImmutable('now', $tz);
    $todayDate = $today->format('Y-m-d');
    $isToday = $requestedDate === $todayDate;
    $isManualRegenerate = $requestMethod === 'POST';

    if ($isManualRegenerate) {
        $action = requestAction();
        if ($action !== 'regenerate') {
            throw new InvalidArgumentException('Invalid action. Expected regenerate.');
        }
    }

    $loaded = dailyReportLoadOrGenerate($requestedDate, $isToday || $isManualRegenerate);
    if ($isManualRegenerate) {
        $source = 'regenerated_manual';
    } else {
        $source = $loaded['generated']
            ? ($isToday ? 'regenerated_today' : 'generated_on_demand')
            : 'saved';
    }

    return [
        'success' => true,
        'requestedDate' => $requestedDate,
        'source' => $source,
        'canRegenerate' => canRegenerateSource($source),
        'savedAt' => $loaded['savedAt'],
        'report' => $loaded['report'],
    ];
}

function requestMethod(): string
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    return is_string($method) && $method !== '' ? strtoupper($method) : 'GET';
}

function requestDate(DateTimeZone $tz, string $requestMethod): string
{
    $raw = requestValue('date', $requestMethod);
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

function requestAction(): string
{
    $raw = requestValue('action', 'POST');
    if (!is_string($raw)) {
        return '';
    }
    return trim($raw);
}

/**
 * @return mixed
 */
function requestValue(string $key, string $requestMethod)
{
    if ($requestMethod === 'POST') {
        return $_POST[$key] ?? null;
    }
    return $_GET[$key] ?? null;
}

function canRegenerateSource(string $source): bool
{
    return in_array($source, ['saved', 'generated_on_demand', 'regenerated_today', 'regenerated_manual'], true);
}
