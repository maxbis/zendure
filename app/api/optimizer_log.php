<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/login/validate.php';
require_once dirname(__DIR__) . '/includes/optimizer_log.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET.']);
    exit();
}

$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 24;
$limit = max(1, min(48, $limit));
$path = optimizerLogDefaultPath();

if (!is_file($path)) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'No optimizer log exists yet. Run the shadow optimizer first.',
    ]);
    exit();
}

if (!is_readable($path)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'The optimizer log is not readable.']);
    exit();
}

$result = optimizerLogReadPlans($path, $limit);
echo json_encode([
    'success' => true,
    'records' => $result['records'],
    'invalidLines' => $result['invalid_lines'],
    'scannedLines' => $result['scanned_lines'],
    'updatedAt' => date(DATE_ATOM, (int)filemtime($path)),
], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

