<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/report_smart_common.php';

date_default_timezone_set('Europe/Amsterdam');

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

$requestMethod = dailyReportV2RequestMethod();

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
    echo dailyReportJsonEncode(dailyReportV2BuildPayload($requestMethod));
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo dailyReportJsonEncode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo dailyReportJsonEncode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * V2 stays aggregate-only as a comparison page while the production API uses
 * the smart today/live plus historical/aggregate policy.
 *
 * @return array<string, mixed>
 */
function dailyReportV2BuildPayload(string $requestMethod): array
{
    $tz = dailyReportTimezone();
    $requestedDate = dailyReportV2RequestDate($tz, $requestMethod);
    $isManualRegenerate = $requestMethod === 'POST';

    if ($isManualRegenerate) {
        $action = dailyReportV2RequestAction();
        if ($action !== 'regenerate') {
            throw new InvalidArgumentException('Invalid action. Expected regenerate.');
        }
        dailyReportRegenerateAggregate($requestedDate);
    }

    $loaded = dailyReportLoadFromAggregate(dailyReportCreatePdo(), $requestedDate, $tz);
    $source = $isManualRegenerate ? 'aggregate_regenerated_manual' : 'aggregate_saved';

    return [
        'success' => true,
        'requestedDate' => $requestedDate,
        'source' => $source,
        'canRegenerate' => true,
        'savedAt' => $loaded['savedAt'],
        'report' => $loaded['report'],
    ];
}

function dailyReportV2RequestMethod(): string
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    return is_string($method) && $method !== '' ? strtoupper($method) : 'GET';
}

function dailyReportV2RequestDate(DateTimeZone $tz, string $requestMethod): string
{
    $raw = dailyReportV2RequestValue('date', $requestMethod);
    if (!is_string($raw) || trim($raw) === '') {
        return (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    }

    $date = trim($raw);
    dailyReportValidateDate($date, $tz);
    return $date;
}

function dailyReportV2RequestAction(): string
{
    $raw = dailyReportV2RequestValue('action', 'POST');
    return is_string($raw) ? trim($raw) : '';
}

/**
 * @return mixed
 */
function dailyReportV2RequestValue(string $key, string $requestMethod)
{
    if ($requestMethod === 'POST') {
        return $_POST[$key] ?? null;
    }
    return $_GET[$key] ?? null;
}
