<?php
/**
 * Charge Schedule Manager - Mobile Version
 * Mobile-optimized dark mode version with reordered sections
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
    <title>Schedule</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
    <link rel="stylesheet" href="assets/css/charge_schedule_mobile.css">
    <link rel="stylesheet" href="assets/css/automation_status.css">
    <link rel="stylesheet" href="assets/css/charge_status_defines.css">
    <link rel="stylesheet" href="assets/css/charge_status.css">
</head>

<body class="mobile-dark">
    <div class="container">
        <div class="header">
            <h1>Schedule</h1>
        </div>

        <!-- 1. Charge/Discharge Status (three boxes) -->
        <div class="charge-status-wrapper">
            <?php include __DIR__ . '/partials/charge_status_mobile.php'; ?>
        </div>

        <!-- 2. Today's Prices (with scrollbar) -->
        <div class="price-graph-wrapper-mobile">
            <?php include __DIR__ . '/partials/price_overview_bar_mobile.php'; ?>
        </div>

        <!-- 3. Today's Schedule -->
        <!-- 4. Schedule Entries (only Add button) -->
        <?php include __DIR__ . '/partials/schedule_panels_mobile.php'; ?>
        <?php include __DIR__ . '/partials/edit_modal.php'; ?>
        <?php include __DIR__ . '/partials/confirm_dialog.php'; ?>

        <!-- 5. Automation Status -->
        <div class="automation-status-wrapper">
            <?php include __DIR__ . '/partials/automation_status.php'; ?>
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
        <script src="assets/js/automation_status.js"></script>
        <script src="assets/js/charge_status.js"></script>

        <!-- Main application (must load last) -->
        <script src="assets/js/charge_schedule.js"></script>

        <!-- Mobile-specific: Auto-scroll price graph to current time -->
        <script>
            // Function to scroll price graph to current time
            function scrollPriceGraphToCurrent() {
                const container = document.querySelector('.price-graph-row-mobile');
                if (!container) return;
                
                // Try to find current hour bar
                const currentBar = container.querySelector('.price-graph-bar.price-current');
                if (currentBar) {
                    const containerWidth = container.clientWidth;
                    const barLeft = currentBar.offsetLeft;
                    const barWidth = currentBar.clientWidth;
                    
                    // Calculate scroll position to center the bar, or scroll to show more of the right side
                    const scrollPos = barLeft - (containerWidth / 2) + (barWidth / 2);
                    container.scrollTo({
                        left: Math.max(0, scrollPos),
                        behavior: 'smooth'
                    });
                } else {
                    // If no current bar found, scroll to the right (toward end of day)
                    // Calculate approximate position for current hour
                    const now = new Date();
                    const currentHour = now.getHours();
                    // Each bar is approximately 18px + 2px gap = 20px
                    const barWidth = 20;
                    const scrollPos = (currentHour * barWidth) - (container.clientWidth / 2);
                    container.scrollTo({
                        left: Math.max(0, scrollPos),
                        behavior: 'smooth'
                    });
                }
            }
            
            // Override or extend the price graph scroll functionality for mobile
            (function() {
                const originalFetchAndRenderPrices = window.fetchAndRenderPrices;
                if (originalFetchAndRenderPrices) {
                    window.fetchAndRenderPrices = async function(priceApiUrl, scheduleEntries, editModal) {
                        await originalFetchAndRenderPrices(priceApiUrl, scheduleEntries, editModal);
                        
                        // Auto-scroll mobile price graph to current time
                        setTimeout(scrollPriceGraphToCurrent, 300);
                        // Also try after a longer delay in case rendering takes longer
                        setTimeout(scrollPriceGraphToCurrent, 1000);
                    };
                }
                
                // Also listen for when price graph is rendered via component
                document.addEventListener('DOMContentLoaded', () => {
                    // Try scrolling after initial load
                    setTimeout(scrollPriceGraphToCurrent, 500);
                    setTimeout(scrollPriceGraphToCurrent, 2000);
                });
            })();
        </script>

    </div>

</body>

</html>
