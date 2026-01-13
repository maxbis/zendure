<?php
/**
 * Charge Schedule Manager
 * View, edit, and visualize charge/discharge schedule.
 */

// Ensure server timezone matches local expectation
date_default_timezone_set('Europe/Amsterdam');

require_once __DIR__ . '/api/charge_schedule_functions.php';
// Include local helpers for automation status functions
require_once __DIR__ . '/includes/status.php';

$dataFile = __DIR__ . '/../data/charge_schedule.json';

// Load API URL from config file
$apiUrl = 'api/charge_schedule_api.php'; // Default fallback
$priceApiUrl = null; // Price API URL

// Try main config.json first, then fallback to run_schedule/config/config.json
$mainConfigPath = __DIR__ . '/../config/config.json';
$configPath = __DIR__ . '/../run_schedule/config/config.json';
$configPathToUse = file_exists($mainConfigPath) ? $mainConfigPath : (file_exists($configPath) ? $configPath : null);

if ($configPathToUse && file_exists($configPathToUse)) {
    $configJson = file_get_contents($configPathToUse);
    if ($configJson !== false) {
        $config = json_decode($configJson, true);
        if ($config !== null) {
            if (isset($config['scheduleApiUrl'])) {
                $apiUrl = $config['scheduleApiUrl'];
            }
            // Check for get_price (singular) first, then get_prices (plural) for backward compatibility
            if (isset($config['priceUrls']['get_price'])) {
                $priceApiUrl = $config['priceUrls']['get_price'];
            } elseif (isset($config['priceUrls']['get_prices'])) {
                $priceApiUrl = $config['priceUrls']['get_prices'];
            }
            
            // Select zendureFetchApiUrl based on location (zendureStoreApiUrl removed - derive from dataApiUrl)
            $location = $config['location'] ?? 'remote';
            if ($location === 'local') {
                $zendureFetchApiUrl = $config['zendureFetchApiUrl-local'] ?? null;
            } else {
                $zendureFetchApiUrl = $config['zendureFetchApiUrl'] ?? null;
            }
        }
    }
}

// Initial Server-Side Render Data
$schedule = loadSchedule($dataFile);
$today = isset($_GET['initial_date']) ? $_GET['initial_date'] : date('Ymd');
$resolvedToday = resolveScheduleForDate($schedule, $today);
$currentHour = date('H') . '00';
$currentTime = date('Hi'); // Current time in HHmm format (e.g., "0930")

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Charge Schedule Manager</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
    <link rel="stylesheet" href="assets/css/charge_schedule.css">

    <link rel="stylesheet" href="assets/css/price_statistics.css">
    <link rel="stylesheet" href="assets/css/automation_status.css">
    <link rel="stylesheet" href="assets/css/charge_status_defines.css">
    <link rel="stylesheet" href="assets/css/charge_status.css">
    <link rel="stylesheet" href="assets/css/schedule_calculator.css">
</head>

<body>
    <div class="container">
        <div class="header">
            <h1><span class="title-desktop">⚡ Charge Schedule Manager</span><span class="title-mobile">⚡ Schedule</span></h1>
            <p id="current-time" class="title-desktop"><?php echo date('Y-m-d H:i:s'); ?></p>
        </div>

        <!-- Schedule Panels: Today's Schedule and Schedule Entries -->
        <?php include __DIR__ . '/partials/schedule_panels.php'; ?>
        <?php include __DIR__ . '/partials/edit_modal.php'; ?>
        <?php include __DIR__ . '/partials/confirm_dialog.php'; ?>

        <!-- Bar Graph Section - Full Width -->
        <?php include __DIR__ . '/partials/schedule_overview_bar.php'; ?>

        <!-- Price Overview Bar Graph Section - Full Width -->
        <?php include __DIR__ . '/partials/price_overview_bar.php'; ?>

        <!-- Price Statistics Section -->
        <?php include __DIR__ . '/partials/price_statistics.php'; ?>

        <!-- Schedule Calculator Section -->
        <?php include __DIR__ . '/partials/calculate.php'; ?>

        <!-- Automation Status Section - Full Width -->
        <div class="automation-status-wrapper" style="margin-top: 20px;">
            <?php include __DIR__ . '/partials/automation_status.php'; ?>
        </div>

        <!-- Charge/Discharge Status Section - Full Width -->
        <div class="charge-status-wrapper" style="margin-top: 20px;">
            <?php include __DIR__ . '/partials/charge_status.php'; ?>
        </div>
        
        <div class="charge-status-wrapper" style="margin-top: 20px;">
            <?php include __DIR__ . '/partials/charge_status_details.php'; ?>
        </div>

        <script>
            // Inject API URL from PHP config
            const API_URL = <?php echo json_encode($apiUrl, JSON_UNESCAPED_SLASHES); ?>;
            const PRICE_API_URL = <?php echo json_encode($priceApiUrl, JSON_UNESCAPED_SLASHES); ?>;
        </script>

        <script src="assets/js/edit_modal.js"></script>
        <script src="assets/js/confirm_dialog.js"></script>
        <script src="assets/js/schedule_panels.js"></script>
        <script src="assets/js/schedule_overview_bar.js"></script>
        <script src="assets/js/price_overview_bar.js"></script>
        <script src="assets/js/price_statistics.js"></script>
        <script src="assets/js/schedule_calculator.js"></script>
        <script src="assets/js/automation_status.js"></script>
        <script src="assets/js/charge_status.js"></script>
        <script src="assets/js/charge_schedule.js"></script>

    </div>

</body>

</html>