<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Amsterdam');

require_once __DIR__ . '/../main/includes/price_conversion.php';

$requestedDate = isset($_GET['date']) && is_string($_GET['date']) ? trim($_GET['date']) : '';
if ($requestedDate === '') {
    $requestedDate = (new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d');
}
$priceConversionConfig = getPriceConversionConfig();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Daily Report Mobile</title>
    <link rel="stylesheet" href="assets/css/daily_report_mobile.css">
</head>
<body>
    <main class="mobile-report-shell">
        <section class="mobile-hero">
            <div class="mobile-hero__copy">
                <p class="mobile-kicker">Daily report</p>
                <h1>Mobile View</h1>
                <p class="mobile-hero__text">Phone-first summary and chart view for one selected day.</p>
            </div>
            <div class="mobile-hero__actions">
                <a class="mobile-link" href="./?date=<?php echo htmlspecialchars($requestedDate, ENT_QUOTES, 'UTF-8'); ?>">Desktop view</a>
                <div class="mobile-source-card">
                    <span class="mobile-label">Data source</span>
                    <div class="mobile-source-row">
                        <span class="mobile-source-value" data-role="report-source">Waiting for report</span>
                        <button type="button" class="mobile-button mobile-button--ghost" data-role="report-regenerate" hidden>Regenerate</button>
                    </div>
                </div>
            </div>
        </section>

        <form class="mobile-date-bar" data-role="date-form">
            <button type="button" class="mobile-button" data-role="prev-day" aria-label="Previous day">&larr;</button>
            <input id="report-date" name="date" class="mobile-date-input" type="date" value="<?php echo htmlspecialchars($requestedDate, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
            <button type="button" class="mobile-button" data-role="next-day" aria-label="Next day">&rarr;</button>
            <button type="submit" class="mobile-button mobile-button--primary">Load</button>
        </form>

        <section class="mobile-summary-grid">
            <article class="mobile-summary-card mobile-summary-card--highlight">
                <div class="mobile-summary-label">Charged</div>
                <div class="mobile-summary-value" data-role="charged-total">--</div>
                <div class="mobile-summary-subtle">Total for selected day</div>
            </article>
            <article class="mobile-summary-card">
                <div class="mobile-summary-label">Discharged</div>
                <div class="mobile-summary-value" data-role="discharged-total">--</div>
                <div class="mobile-summary-subtle">Total for selected day</div>
            </article>
            <article class="mobile-summary-card">
                <div class="mobile-summary-label">Battery Delta</div>
                <div class="mobile-summary-value" data-role="battery-delta-total">--</div>
                <div class="mobile-summary-secondary" data-role="battery-delta-range">--</div>
                <div class="mobile-summary-subtle" data-role="battery-delta-extrema">Interpolated day delta</div>
            </article>
            <article class="mobile-summary-card">
                <div class="mobile-summary-label">Grid From</div>
                <div class="mobile-summary-value" data-role="grid-from-total">--</div>
                <div class="mobile-summary-subtle">Import energy</div>
            </article>
            <article class="mobile-summary-card">
                <div class="mobile-summary-label">Grid To</div>
                <div class="mobile-summary-value" data-role="grid-to-total">--</div>
                <div class="mobile-summary-subtle">Export energy</div>
            </article>
            <article class="mobile-summary-card mobile-summary-card--muted">
                <div class="mobile-summary-label">Price Variation</div>
                <div class="mobile-summary-value" data-role="price-variation-total">--</div>
                <div class="mobile-summary-secondary" data-role="price-variation-range">--</div>
                <div class="mobile-summary-subtle" data-role="price-variation-indicator">No hourly prices</div>
            </article>
            <article class="mobile-summary-card">
                <div class="mobile-summary-label">Net Cost</div>
                <div class="mobile-summary-value" data-role="net-cost-total">--</div>
                <div class="mobile-summary-secondary" data-role="net-cost-spot-total">--</div>
                <div class="mobile-summary-badge" data-role="cost-badge">Loading</div>
            </article>
            <article class="mobile-summary-card mobile-summary-card--highlight">
                <div class="mobile-summary-label">Savings</div>
                <div class="mobile-summary-value" data-role="savings-total">--</div>
                <div class="mobile-summary-subtle">Discharged kWh × hourly price</div>
            </article>
            <article class="mobile-summary-card mobile-summary-card--cool">
                <div class="mobile-summary-label">Charge Costs</div>
                <div class="mobile-summary-value" data-role="charge-cost-total">--</div>
                <div class="mobile-summary-secondary" data-role="charge-cost-spot-total">--</div>
                <div class="mobile-summary-subtle">Charged kWh × hourly price</div>
            </article>
            <article class="mobile-summary-card mobile-summary-card--warm">
                <div class="mobile-summary-label">P&amp;L</div>
                <div class="mobile-summary-value" data-role="pnl-total">--</div>
                <div class="mobile-summary-secondary" data-role="pnl-spot-total">--</div>
                <div class="mobile-summary-subtle">Savings - charge costs - net cost</div>
            </article>
        </section>

        <section class="mobile-chart-card">
            <div class="mobile-chart-card__header">
                <div>
                    <p class="mobile-kicker">Daily Energy View</p>
                    <h2 data-role="chart-title">Selected day</h2>
                    <p class="mobile-chart-status" data-role="chart-status">Loading report...</p>
                </div>
                <div class="mobile-legend">
                    <span><i class="mobile-swatch mobile-swatch--charge"></i>Charged</span>
                    <span><i class="mobile-swatch mobile-swatch--discharge"></i>Discharged</span>
                    <span><i class="mobile-swatch mobile-swatch--grid-from"></i>Grid from</span>
                    <span><i class="mobile-swatch mobile-swatch--grid-to"></i>Grid to</span>
                    <span><i class="mobile-swatch mobile-swatch--line"></i>Net cost</span>
                    <span><i class="mobile-swatch mobile-swatch--pnl"></i>P&amp;L</span>
                    <span><i class="mobile-swatch mobile-swatch--battery"></i>Electric level</span>
                </div>
            </div>
            <div class="mobile-chart-scroll" data-role="chart-scroll" hidden>
                <svg class="mobile-chart" data-role="chart" viewBox="0 0 980 360" preserveAspectRatio="none" aria-label="Daily report mobile chart"></svg>
            </div>
        </section>

        <section class="mobile-meta-card">
            <h3>Report Metadata</h3>
            <dl class="mobile-metadata">
                <div><dt>Requested date</dt><dd data-role="meta-date">--</dd></div>
                <div><dt>Timezone</dt><dd data-role="meta-timezone">--</dd></div>
                <div><dt>Partial day</dt><dd data-role="meta-partial">--</dd></div>
                <div><dt>Price file</dt><dd data-role="meta-price-file">--</dd></div>
                <div><dt>Price hours</dt><dd data-role="meta-price-hours">--</dd></div>
                <div><dt>Generated at</dt><dd data-role="meta-generated-at">--</dd></div>
                <div><dt>Saved report</dt><dd data-role="meta-saved-at">--</dd></div>
            </dl>
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
        window.DAILY_REPORT_MOBILE_BOOT = {
            apiUrl: 'api/report_data.php',
            requestedDate: <?php echo json_encode($requestedDate, JSON_UNESCAPED_SLASHES); ?>
        };
    </script>
    <script src="../main/assets/js/price_conversion.js"></script>
    <script src="assets/js/daily_report_mobile.js"></script>
</body>
</html>
