<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app_energy_history.php';
require_once dirname(__DIR__, 2) . '/daily_report/includes/report_smart_common.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use GET.']);
    exit();
}

try {
    $systemConfig = dailyReportSystemConfig();
    $timezone = dailyReportTimezone();
    date_default_timezone_set($timezone->getName());
    $requestedDays = appEnergyHistoryResolveDays($_GET['days'] ?? null);
    $endDate = new DateTimeImmutable('today', $timezone);
    $startDate = $endDate->modify('-' . $requestedDays . ' days');
    $pdo = appEnergyHistoryCreatePdo();
    $rows = [];
    $historyEnd = $endDate->modify('-1 day');
    if ($startDate <= $historyEnd) {
        $rows = appEnergyHistoryFetchRows(
            $pdo,
            $startDate->format('Y-m-d'),
            $historyEnd->format('Y-m-d')
        );
    }

    $today = $endDate->format('Y-m-d');
    $todaySource = 'sqlite_replication.status_updates';
    $isStale = false;
    try {
        $live = dailyReportGenerateLive($today);
        $report = is_array($live['report'] ?? null) ? $live['report'] : [];
        $priceRows = appEnergyHistoryFetchPriceRows($pdo, $today);
        $rows = array_merge($rows, appEnergyHistoryMapLiveReportRows($report, $priceRows, $today));
    } catch (Throwable $liveError) {
        error_log('App energy history live source failed, using aggregate fallback: ' . $liveError->getMessage());
        $todaySource = 'sqlite_replication.hourly_report_inputs_fallback';
        $isStale = true;
        $rows = array_merge($rows, appEnergyHistoryFetchRows($pdo, $today, $today));
    }

    $payload = appEnergyHistoryBuildPayload($rows, $requestedDays, $todaySource, $isStale);
    $payload['baseWh'] = $systemConfig['battery']['capacityWh'];
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (SystemConfigException $error) {
    error_log('App energy history configuration: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Shared system configuration: ' . $error->getMessage()]);
} catch (Throwable $error) {
    error_log('App energy history API: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Hourly battery energy could not be loaded from the database.']);
}
