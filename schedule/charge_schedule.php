<?php
/**
 * Charge Schedule Manager
 * View, edit, and visualize charge/discharge schedule.
 */

// Ensure server timezone matches local expectation
date_default_timezone_set('Europe/Amsterdam');

// Validate user access
require_once __DIR__ . '/../login/validate.php';


require_once __DIR__ . '/api/charge_schedule_functions.php';
// Include local helpers for automation status functions
require_once __DIR__ . '/includes/status.php';
// Include centralized configuration loader
require_once __DIR__ . '/includes/config_loader.php';

$dataFile = __DIR__ . '/../data/charge_schedule.json';

// Load API URLs from centralized config loader
$apiUrl = ConfigLoader::get('scheduleApiUrl', 'api/charge_schedule_api.php');
// Check for get_price (singular) first, then get_prices (plural) for backward compatibility
$priceApiUrl = ConfigLoader::get('priceUrls.get_price') 
            ?? ConfigLoader::get('priceUrls.get_prices');
$calculateScheduleApiUrl = ConfigLoader::get('calculate_schedule_apiUrl');
$zendureFetchApiUrl = ConfigLoader::getWithLocation('zendureFetchApiUrl');

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
            const CALCULATE_SCHEDULE_API_URL = <?php echo json_encode($calculateScheduleApiUrl, JSON_UNESCAPED_SLASHES); ?>;
        </script>

        <!-- Core modules (must load first) -->
        <script src="assets/js/api_client.js"></script>
        <script src="assets/js/notification_service.js"></script>
        <script src="assets/js/state_manager.js"></script>
        <script src="assets/js/data_service.js"></script>
        <script src="assets/js/utils_performance.js"></script>
        <script src="assets/js/component_base.js"></script>
        <script src="assets/js/schedule_utils.js"></script>
        <script src="assets/js/schedule_api.js"></script>
        <script src="assets/js/schedule_renderer.js"></script>

        <!-- UI components -->
        <script src="assets/js/edit_modal.js"></script>
        <script src="assets/js/confirm_dialog.js"></script>

        <!-- Component modules -->
        <script src="assets/js/components/schedule_panel_component.js"></script>
        <script src="assets/js/components/price_graph_component.js"></script>
        
        <!-- Feature modules -->
        <script src="assets/js/price_overview_bar.js"></script>
        <script src="assets/js/price_statistics.js"></script>
        <script src="assets/js/schedule_calculator.js"></script>
        <script src="assets/js/automation_status.js"></script>
        <script src="assets/js/charge_status.js"></script>

        <!-- Main application (must load last) -->
        <script src="assets/js/charge_schedule.js"></script>

    </div>

</body>

</html>