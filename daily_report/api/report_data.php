<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Amsterdam');

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    echo json_encode(buildDailyReportPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function buildDailyReportPayload(): array
{
    $tz = new DateTimeZone('Europe/Amsterdam');
    $requestedDate = requestDate($tz);
    $today = new DateTimeImmutable('now', $tz);
    $todayDate = $today->format('Y-m-d');
    $isToday = $requestedDate === $todayDate;

    $reportPath = buildReportPath($requestedDate);
    $source = 'saved';

    if ($isToday || !file_exists($reportPath)) {
        generateAndSaveReport($requestedDate, $reportPath);
        $source = $isToday ? 'regenerated_today' : 'generated_on_demand';
    }

    if (!file_exists($reportPath)) {
        throw new RuntimeException('Daily report file was not created.');
    }

    $report = loadJsonFile($reportPath, 'saved daily report');
    $savedAt = (new DateTimeImmutable('@' . filemtime($reportPath)))->setTimezone($tz)->format(DATE_ATOM);

    return [
        'success' => true,
        'requestedDate' => $requestedDate,
        'source' => $source,
        'savedPath' => $reportPath,
        'savedAt' => $savedAt,
        'report' => $report,
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

function buildReportPath(string $date): string
{
    $root = dirname(__DIR__);
    $yyyymm = str_replace('-', '', substr($date, 0, 7));
    $yyyymmdd = str_replace('-', '', $date);
    return $root . '/data/' . $yyyymm . '/daily_report_' . $yyyymmdd . '.json';
}

function generateAndSaveReport(string $date, string $outputPath): void
{
    $repoRoot = dirname(__DIR__, 2);
    $scriptPath = $repoRoot . '/tools/hourly_daily_grid_battery_report.py';
    if (!file_exists($scriptPath)) {
        throw new RuntimeException('Report generator script not found.');
    }

    $outputDir = dirname($outputPath);
    if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
        throw new RuntimeException('Failed to create report output directory.');
    }

    $pythonBin = getenv('PYTHON_BIN');
    if (!is_string($pythonBin) || trim($pythonBin) === '') {
        $pythonBin = 'python';
    }

    $command = escapeshellarg($pythonBin)
        . ' ' . escapeshellarg($scriptPath)
        . ' --date ' . escapeshellarg($date)
        . ' --output ' . escapeshellarg($outputPath)
        . ' 2>&1';

    $outputLines = [];
    $exitCode = 0;
    exec($command, $outputLines, $exitCode);
    if ($exitCode !== 0) {
        $message = trim(implode("\n", $outputLines));
        if ($message === '') {
            $message = 'Unknown error while generating daily report.';
        }
        throw new RuntimeException($message);
    }
}

function loadJsonFile(string $path, string $label): array
{
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        throw new RuntimeException('Failed to read ' . $label . '.');
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON in ' . $label . '.');
    }
    return $decoded;
}
