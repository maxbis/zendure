<?php
/**
 * Charge Schedule Manager - Mobile Version
 * Mobile-optimized dark mode version with reordered sections
 */

// Ensure server timezone matches local expectation
date_default_timezone_set('Europe/Amsterdam');

// Validate user access
$validateFile = __DIR__ . '/../login/validate.php';
require_once $validateFile;

require_once __DIR__ . '/api/charge_schedule_functions.php';
// Include centralized configuration loader
require_once __DIR__ . '/includes/config_loader.php';
require_once __DIR__ . '/includes/price_conversion.php';

$configLoadError = ConfigLoader::getLoadError();
if ($configLoadError !== null) {
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Zendure Energy Manager - Config Error</title>
        <link rel="stylesheet" href="assets/css/general_mobile.css">
        <link rel="stylesheet" href="assets/css/charge_schedule_mobile.css">
    </head>
    <body class="mobile-dark">
        <div class="container">
            <div class="card" style="margin-top: 24px;">
                <h1 class="card-header">Configuration Error</h1>
                <p style="color: var(--text-secondary); line-height: 1.5;">
                    The app could not start because the configuration file is invalid.
                </p>
                <p style="color: #ff8a80; line-height: 1.5;">
                    <?= htmlspecialchars($configLoadError, ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <p style="color: var(--text-tertiary); line-height: 1.5;">
                    Fix the JSON in the config file and reload this page.
                </p>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$dataFile = __DIR__ . '/data/charge_schedule.json';

// Load API URLs from centralized config loader
$apiUrl = ConfigLoader::get('scheduleApiUrl', 'api/charge_schedule_api.php');

// Use get_prices for remote
$priceApiUrl = ConfigLoader::get('priceApiUrl');


$calculateScheduleApiUrl = ConfigLoader::get('calculate_schedule_apiUrl');
$zendureFetchApiUrl = ConfigLoader::getWithLocation('zendureFetchApiUrl');

// Initial Server-Side Render Data
$schedule = loadSchedule($dataFile);
$today = isset($_GET['initial_date']) ? $_GET['initial_date'] : date('Ymd');
$tomorrow = date('Ymd', strtotime($today . ' +1 day'));
$includeConditions = filter_var(ConfigLoader::get('include_conditions', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
$includeConditions = ($includeConditions === null) ? false : $includeConditions;
$resolvedToday = resolveScheduleForDateWithConditions($schedule, $today, $includeConditions);
$resolvedTomorrow = resolveScheduleForDateWithConditions($schedule, $tomorrow, $includeConditions);
$currentHour = date('H') . '00';
$currentTime = date('Hi'); // Current time in HHmm format (e.g., "0930")
$priceConversionConfig = getPriceConversionConfig();

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="mobile-web-app-capable" content="yes">
    <title>⚡Zendure Energy Manager</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
    <link rel="stylesheet" href="assets/css/general_mobile.css">
    <link rel="stylesheet" href="assets/css/charge_schedule_mobile.css">
    <link rel="stylesheet" href="assets/css/automation_status.css">
    <link rel="stylesheet" href="assets/css/charge_status_defines.css">
    <link rel="stylesheet" href="assets/css/charge_status.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>

<body class="mobile-dark">
    <div class="container">
        <div class="header">
            <h1>⚡Zendure Energy Manager</h1>
        </div>

        <!-- 1. Charge/Discharge Status (three boxes) -->
        <div class="charge-status-wrapper">
            <?php include __DIR__ . '/partials/charge_status_mobile.php'; ?>
        </div>

        <!-- System & Grid (charge status details) -->
        <div class="charge-status-wrapper">
            <?php include __DIR__ . '/partials/charge_status_details_mobile.php'; ?>
        </div>

        <!-- 2. Today's Prices (with scrollbar) -->
        <div class="price-graph-wrapper-mobile">
            <?php include __DIR__ . '/partials/price_overview_bar_mobile.php'; ?>
        </div>

        <!-- Energy Graph (Wh per hour) -->
        <div class="energy-graph-wrapper-mobile">
        <?php include __DIR__ . '/partials/energy_graph_mobile.php'; ?>
        </div>

        <div class="shortwave-radiation-wrapper-mobile">
            <?php include __DIR__ . '/partials/shortwave_radiation_graph.php'; ?>
        </div>

        <!-- 5. Automation Status -->
        <div class="automation-status-wrapper">
            <?php include __DIR__ . '/partials/automation_status.php'; ?>
        </div>

        <!-- Schedule Panels -->
        <?php include __DIR__ . '/partials/schedule_panels_mobile.php'; ?>

        <!-- Edit Modal -->
        <?php include __DIR__ . '/partials/edit_modal.php'; ?>
        <!-- Confirm Dialog -->
        <?php include __DIR__ . '/partials/confirm_dialog.php'; ?>
        <!-- No Back-end Dialog (502) -->
        <?php include __DIR__ . '/partials/no_backend_dialog.php'; ?>


        <script>
            // Inject API URL from PHP config
            const API_URL = <?php echo json_encode($apiUrl, JSON_UNESCAPED_SLASHES); ?>;
            const PRICE_API_URL = <?php echo json_encode($priceApiUrl, JSON_UNESCAPED_SLASHES); ?>;
            const CALCULATE_SCHEDULE_API_URL = <?php echo json_encode($calculateScheduleApiUrl, JSON_UNESCAPED_SLASHES); ?>;
            const ENERGY_GRAPH_API_URL = <?php echo json_encode('api/energy_graph_proxy.php', JSON_UNESCAPED_SLASHES); ?>;

            // Charge status unified API (same-origin proxy) + config levels
            const CHARGE_STATUS_ALL_API_URL = <?php echo json_encode('api/charge_status_all_proxy.php', JSON_UNESCAPED_SLASHES); ?>;
            const CHARGE_STATUS_MIN_CHARGE_LEVEL = <?php echo (int) ConfigLoader::get('MIN_CHARGE_LEVEL', 20); ?>;
            const CHARGE_STATUS_MAX_CHARGE_LEVEL = <?php echo (int) ConfigLoader::get('MAX_CHARGE_LEVEL', 90); ?>;
            const BASE_WH = <?php echo (int) ConfigLoader::get('baseWh', 5760); ?>;
            const GRID_MIN_POWER = <?php echo (int) ConfigLoader::get('minGridPower', -1200); ?>;
            const GRID_MAX_POWER = <?php echo (int) ConfigLoader::get('maxGridPower', 1200); ?>;
            window.PRICE_OVERVIEW_CONFIG = {
                priceProxyNoData: <?php echo json_encode(ConfigLoader::get('priceProxyNoData', 0.24), JSON_UNESCAPED_SLASHES); ?>,
                popupPowerEfficiency: <?php echo json_encode(ConfigLoader::get('popupPowerEfficiency', 0.9), JSON_UNESCAPED_SLASHES); ?>,
                popupNetzeroReferenceW: <?php echo json_encode(ConfigLoader::get('popupNetzeroReferenceW', 200), JSON_UNESCAPED_SLASHES); ?>,
                popupNetzeroMinusReferenceW: <?php echo json_encode(ConfigLoader::get('popupNetzeroMinusReferenceW', -180), JSON_UNESCAPED_SLASHES); ?>,
                popupNetzeroPlusReferenceW: <?php echo json_encode(ConfigLoader::get('popupNetzeroPlusReferenceW', 300), JSON_UNESCAPED_SLASHES); ?>
            };
            window.PRICE_CONVERSION_CONFIG = {
                supplierMarkupEurPerKwh: <?php echo json_encode($priceConversionConfig['supplierMarkupEurPerKwh'], JSON_UNESCAPED_SLASHES); ?>,
                energyTaxEurPerKwh: <?php echo json_encode($priceConversionConfig['energyTaxEurPerKwh'], JSON_UNESCAPED_SLASHES); ?>,
                vatMultiplier: <?php echo json_encode($priceConversionConfig['vatMultiplier'], JSON_UNESCAPED_SLASHES); ?>,
                consumerPrecision: <?php echo json_encode($priceConversionConfig['consumerPrecision'], JSON_UNESCAPED_SLASHES); ?>,
                spotPrecision: <?php echo json_encode($priceConversionConfig['spotPrecision'], JSON_UNESCAPED_SLASHES); ?>
            };
        </script>

        <!-- Core modules (must load first) -->
        <script src="assets/js/api_client.js"></script>
        <script src="assets/js/notification_service.js"></script>
        <script src="assets/js/state_manager.js"></script>
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
        <script src="assets/js/price_conversion.js"></script>
        <script src="assets/js/price_overview_bar.js"></script>
        <script src="assets/js/automation_status.js"></script>
        <script src="assets/js/charge_status.js"></script>
        <script src="assets/js/energy_graph_refresh.js"></script>

        <!-- Main application (must load last) -->
        <script src="assets/js/charge_schedule.js"></script>

        <!-- Mobile-specific: Auto-scroll price graph to current time -->
        <script>
            // Function to scroll price graph to current time
            function scrollPriceGraphToCurrent() {
                // Target today's container specifically (not tomorrow's)
                const todayContainer = document.getElementById('price-graph-today');
                if (!todayContainer) return;
                
                // Try to find current hour bar in today's container
                const currentBar = todayContainer.querySelector('.price-graph-bar.price-current');
                if (currentBar) {
                    const containerWidth = todayContainer.clientWidth;
                    const barLeft = currentBar.offsetLeft;
                    const barWidth = currentBar.clientWidth;
                    
                    // Calculate scroll position to center the bar, or scroll to show more of the right side
                    const scrollPos = barLeft - (containerWidth / 2) + (barWidth / 2);
                    todayContainer.scrollTo({
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
                    const scrollPos = (currentHour * barWidth) - (todayContainer.clientWidth / 2);
                    todayContainer.scrollTo({
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
