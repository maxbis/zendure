<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/report2_common.php'; // Canonical Report API.

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        http_response_code(405);
        throw new RuntimeException('Method not allowed. Use GET.');
    }
    $timezone = dailyReportTimezone();
    $month = is_string($_GET['month'] ?? null) && trim((string)$_GET['month']) !== ''
        ? trim((string)$_GET['month'])
        : (new DateTimeImmutable('now', $timezone))->format('Y-m');
    echo dailyReportJsonEncode(['success' => true, 'report' => dailyReport2BuildMonth($month)]);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    echo dailyReportJsonEncode(['success' => false, 'error' => $error->getMessage()]);
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(500);
    echo dailyReportJsonEncode(['success' => false, 'error' => $error->getMessage()]);
}
