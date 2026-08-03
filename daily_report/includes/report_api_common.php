<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/load_env.php';
require_once dirname(__DIR__, 2) . '/common/php/system_config.php';
daily_report_bootstrap_env();

if (!defined('DAILY_REPORT_ROOT')) {
    define('DAILY_REPORT_ROOT', dirname(__DIR__));
}

if (!defined('DAILY_REPORT_GENERATOR_SCRIPT')) {
    define('DAILY_REPORT_GENERATOR_SCRIPT', DAILY_REPORT_ROOT . '/tools/hourly_daily_grid_battery_report.py');
}

/** @return array<string, mixed> */
function dailyReportSystemConfig(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $config = loadSystemConfig();
    return $config;
}

function dailyReportTimezone(): DateTimeZone
{
    static $tz = null;
    if ($tz instanceof DateTimeZone) {
        return $tz;
    }

    $config = dailyReportSystemConfig();
    $tz = new DateTimeZone($config['installation']['timezone']);
    return $tz;
}

/**
 * @param array<string, mixed> $data
 */
function dailyReportJsonEncode(array $data): string
{
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
    $json = json_encode($data, $flags);
    if ($json === false) {
        $fallback = json_encode(
            ['success' => false, 'error' => 'JSON encoding failed: ' . json_last_error_msg()],
            $flags
        );
        return $fallback !== false ? $fallback : '{"success":false,"error":"JSON encoding failed"}';
    }
    return $json;
}

function dailyReportDataRoot(): string
{
    $fromEnv = getenv('DAILY_REPORT_DATA_DIR');
    if (!is_string($fromEnv)) {
        return DAILY_REPORT_ROOT . '/data';
    }

    $trimmed = trim($fromEnv);
    if ($trimmed === '') {
        return DAILY_REPORT_ROOT . '/data';
    }

    if ($trimmed[0] === '/' || (strlen($trimmed) > 1 && ($trimmed[1] === ':' || $trimmed[0] === '\\'))) {
        return rtrim(str_replace('\\', DIRECTORY_SEPARATOR, $trimmed), '/\\');
    }

    return rtrim(DAILY_REPORT_ROOT . DIRECTORY_SEPARATOR . $trimmed, '/\\');
}

function dailyReportPathForDate(string $date): string
{
    $root = dailyReportDataRoot();
    $yyyymm = str_replace('-', '', substr($date, 0, 7));
    $yyyymmdd = str_replace('-', '', $date);
    return $root . '/' . $yyyymm . '/daily_report_' . $yyyymmdd . '.json';
}

/**
 * @return array{
 *   generated: bool,
 *   path: string,
 *   savedAt: string,
 *   report: array<string, mixed>
 * }
 */
function dailyReportLoadOrGenerate(string $date, bool $forceRegenerate = false): array
{
    $reportPath = dailyReportPathForDate($date);
    $generated = false;

    if ($forceRegenerate || !file_exists($reportPath)) {
        dailyReportGenerateAndSave($date, $reportPath);
        $generated = true;
    }

    if (!file_exists($reportPath)) {
        throw new RuntimeException('Daily report file was not created.');
    }

    $report = dailyReportLoadJsonFile($reportPath, 'saved daily report');
    $savedAt = (new DateTimeImmutable('@' . filemtime($reportPath)))
        ->setTimezone(dailyReportTimezone())
        ->format(DATE_ATOM);

    return [
        'generated' => $generated,
        'path' => $reportPath,
        'savedAt' => $savedAt,
        'report' => $report,
    ];
}

function dailyReportGenerateAndSave(string $date, string $outputPath): void
{
    $scriptPath = DAILY_REPORT_GENERATOR_SCRIPT;
    if (!file_exists($scriptPath)) {
        throw new RuntimeException('Report generator script not found.');
    }

    $outputDir = dirname($outputPath);
    if (!is_dir($outputDir)) {
        $created = @mkdir($outputDir, 0755, true);
        if (!$created && !is_dir($outputDir)) {
            $phpUser = null;
            if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
                $pw = posix_getpwuid(posix_geteuid());
                $phpUser = is_array($pw) ? ($pw['name'] ?? null) : null;
            }
            $hint = 'Set DAILY_REPORT_DATA_DIR in daily_report/.env to an absolute path writable by PHP';
            if ($phpUser) {
                $hint .= ' (e.g. www-data), or create the directory and chown/chmod it for that user';
            } else {
                $hint .= ', or fix ownership on the data directory';
            }
            throw new RuntimeException(
                'Cannot create report directory: ' . $outputDir . '. ' . $hint . '.'
            );
        }
    }

    if (!is_writable($outputDir)) {
        throw new RuntimeException('Report directory is not writable: ' . $outputDir . '.');
    }

    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (in_array('exec', $disabled, true)) {
        throw new RuntimeException(
            'PHP exec() is disabled (php.ini disable_functions). Remove exec from that list, or generate reports via cron/CLI.'
        );
    }

    $pythonBin = getenv('PYTHON_BIN');
    if (!is_string($pythonBin) || trim($pythonBin) === '') {
        $pythonBin = PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
    }

    $command = escapeshellarg($pythonBin)
        . ' ' . escapeshellarg($scriptPath)
        . ' --date ' . escapeshellarg($date)
        . ' --timezone ' . escapeshellarg(dailyReportTimezone()->getName())
        . ' --output ' . escapeshellarg($outputPath)
        . (PHP_OS_FAMILY === 'Windows' ? '' : ' 2>&1');

    $outputLines = [];
    $exitCode = 0;
    exec($command, $outputLines, $exitCode);

    if ($exitCode !== 0) {
        $message = trim(implode("\n", $outputLines));
        if ($message === '') {
            $message = 'Report generator exited with code ' . $exitCode . ' and no output.';
        }

        $lower = strtolower($message);
        $looksLikeMissingInterpreter =
            $exitCode === 127
            || str_contains($lower, 'no such file')
            || str_contains($lower, 'not found')
            || str_contains($lower, 'cannot execute')
            || str_contains($lower, 'is not recognized')
            || str_contains($lower, 'specified program');

        if ($looksLikeMissingInterpreter) {
            $hint = 'Set PYTHON_BIN in daily_report/.env to your Python executable';
            if (PHP_OS_FAMILY === 'Windows') {
                $hint .= ' (e.g. py, python, or the full path to python.exe where pymysql is installed).';
            } else {
                $hint .= ' (e.g. /usr/bin/python3).';
            }
            $message .= ' ' . $hint;
        }

        throw new RuntimeException($message);
    }
}

/**
 * @return array<string, mixed>
 */
function dailyReportLoadJsonFile(string $path, string $label): array
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
