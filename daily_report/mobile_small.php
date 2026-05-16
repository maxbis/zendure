<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Amsterdam');

require_once __DIR__ . '/../main/includes/price_conversion.php';

$timezone = new DateTimeZone('Europe/Amsterdam');
$todayDate = (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
$requestedDate = isset($_GET['date']) && is_string($_GET['date']) ? trim($_GET['date']) : '';
if ($requestedDate === '') {
    $requestedDate = $todayDate;
}
$priceConversionConfig = getPriceConversionConfig();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Daily Report Mobile Small</title>
    <link rel="stylesheet" href="assets/css/daily_report_mobile_small.css">
</head>
<body>
    <main class="mobile-small-shell">
        <section class="mobile-small-chart-card">
            <div class="mobile-small-chart-card__header">
                <p class="mobile-small-kicker">Daily Energy View</p>
                <div class="mobile-small-title-row">
                    <button
                        type="button"
                        class="mobile-small-nav-button"
                        data-role="prev-day"
                        aria-label="Previous day"
                    >&larr;</button>
                    <button
                        type="button"
                        class="mobile-small-title-button"
                        data-role="today-jump"
                        aria-label="Go to today"
                    >
                        <span class="mobile-small-title" data-role="chart-title"><?php echo htmlspecialchars($requestedDate, ENT_QUOTES, 'UTF-8'); ?></span>
                    </button>
                    <button
                        type="button"
                        class="mobile-small-nav-button"
                        data-role="next-day"
                        aria-label="Next day"
                    >&rarr;</button>
                </div>
            </div>
            <div class="mobile-small-chart-scroll" data-role="chart-scroll" hidden>
                <svg
                    class="mobile-small-chart"
                    data-role="chart"
                    viewBox="0 0 390 860"
                    preserveAspectRatio="none"
                    aria-label="Daily report mobile small chart"
                ></svg>
            </div>
            <div class="mobile-small-legend">
                <span><i class="mobile-small-swatch mobile-small-swatch--charge"></i>Charged</span>
                <span><i class="mobile-small-swatch mobile-small-swatch--discharge"></i>Discharged</span>
                <span><i class="mobile-small-swatch mobile-small-swatch--grid-from"></i>Grid from</span>
                <span><i class="mobile-small-swatch mobile-small-swatch--grid-to"></i>Grid to</span>
                <span><i class="mobile-small-swatch mobile-small-swatch--line"></i>Net cost</span>
                <span><i class="mobile-small-swatch mobile-small-swatch--pnl"></i>P&amp;L</span>
                <span><i class="mobile-small-swatch mobile-small-swatch--battery"></i>Electric level</span>
            </div>
            <div class="mobile-small-pnl-card">
                <div class="mobile-small-pnl-label">P&amp;L</div>
                <div class="mobile-small-pnl-value" data-role="pnl-total">--</div>
                <div class="mobile-small-pnl-secondary" data-role="pnl-spot-total">--</div>
                <div class="mobile-small-pnl-subtle">Savings - charge costs - net cost</div>
            </div>
        </section>
    </main>

    <script>
        window.PRICE_CONVERSION_CONFIG = {
            supplierMarkupEurPerKwh: <?php echo json_encode($priceConversionConfig['supplierMarkupEurPerKwh'], JSON_UNESCAPED_SLASHES); ?>,
            energyTaxEurPerKwh: <?php echo json_encode($priceConversionConfig['energyTaxEurPerKwh'], JSON_UNESCAPED_SLASHES); ?>,
            vatMultiplier: <?php echo json_encode($priceConversionConfig['vatMultiplier'], JSON_UNESCAPED_SLASHES); ?>,
            consumerPrecision: <?php echo json_encode($priceConversionConfig['consumerPrecision'], JSON_UNESCAPED_SLASHES); ?>,
            spotPrecision: <?php echo json_encode($priceConversionConfig['spotPrecision'], JSON_UNESCAPED_SLASHES); ?>
        };
        window.DAILY_REPORT_MOBILE_SMALL_BOOT = {
            apiUrl: 'api/report_data.php',
            requestedDate: <?php echo json_encode($requestedDate, JSON_UNESCAPED_SLASHES); ?>,
            todayDate: <?php echo json_encode($todayDate, JSON_UNESCAPED_SLASHES); ?>
        };
    </script>
    <script src="../main/assets/js/price_conversion.js"></script>
    <script src="assets/js/daily_report_mobile_small.js"></script>
</body>
</html>
