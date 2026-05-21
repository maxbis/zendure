<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Amsterdam');

require_once __DIR__ . '/../main/includes/price_conversion.php';

$requestedDate = isset($_GET['date']) && is_string($_GET['date']) ? trim($_GET['date']) : '';
if ($requestedDate === '') {
    $requestedDate = (new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d');
}
$priceConversionConfig = getPriceConversionConfig();
$monthForMonthlyLink = preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate)
    ? substr($requestedDate, 0, 7)
    : (new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daily Report</title>
    <link rel="stylesheet" href="assets/css/daily_report.css">
</head>
<body>
    <main class="daily-report-shell">
        <section class="hero-card">
            <div class="hero-copy">
                <p class="eyebrow">Prototype</p>
                <h1><a class="hero-title-link" href="./">Daily Report</a></h1>
            </div>
            <div class="hero-meta">
                <nav class="view-switch" aria-label="Report views">
                    <span class="view-switch__current">Daily</span>
                    <span class="view-switch__sep" aria-hidden="true">·</span>
                    <a href="./monthly.php?month=<?php echo htmlspecialchars($monthForMonthlyLink, ENT_QUOTES, 'UTF-8'); ?>">Monthly</a>
                </nav>
                <form class="nav-card" data-role="date-form">
                    <label class="meta-label" for="report-date">Report date</label>
                    <div class="nav-row">
                        <button type="button" class="nav-button" data-role="prev-day" aria-label="Previous day">&larr;</button>
                        <input id="report-date" name="date" class="date-input" type="date" value="<?php echo htmlspecialchars($requestedDate, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
                        <button type="button" class="nav-button" data-role="next-day" aria-label="Next day">&rarr;</button>
                        <button type="submit" class="refresh-button">Load report</button>
                    </div>
                </form>
                <div class="meta-pill">
                    <span class="meta-label">Data source</span>
                    <div class="meta-pill__row">
                        <span class="meta-value" data-role="report-source">Waiting for report</span>
                        <button type="button" class="meta-action-button" data-role="report-regenerate" hidden>Regenerate</button>
                    </div>
                </div>
            </div>
        </section>

        <section class="status-grid status-grid--eight">
            <article class="summary-card summary-card--primary">
                <div class="summary-label">Battery Throughput</div>
                <div class="summary-split-metric">
                    <div class="summary-split-item">
                        <span class="summary-split-label">Charged</span>
                        <span class="summary-value summary-value--inline" data-role="charged-total">--</span>
                    </div>
                    <div class="summary-split-item">
                        <span class="summary-split-label">Discharged</span>
                        <span class="summary-value summary-value--inline" data-role="discharged-total">--</span>
                    </div>
                </div>
            </article>
            <article class="summary-card">
                <div class="summary-label">Battery Avg Price</div>
                <div class="summary-split-metric">
                    <div class="summary-split-item">
                        <span class="summary-split-label">Charge Avg</span>
                        <span class="summary-value summary-value--inline" data-role="avg-charge-price-total">--</span>
                    </div>
                    <div class="summary-split-item">
                        <span class="summary-split-label">Discharge Avg</span>
                        <span class="summary-value summary-value--inline" data-role="avg-discharge-price-total">--</span>
                    </div>
                    <div class="summary-split-item">
                        <span class="summary-split-label">Difference</span>
                        <span class="summary-value summary-value--inline" data-role="avg-price-diff-total">--</span>
                    </div>
                </div>
            </article>
            <article class="summary-card">
                <div class="summary-label">Battery</div>
                <div class="summary-split-metric">
                    <div class="summary-split-item">
                        <span class="summary-split-label">Delta</span>
                        <span class="summary-value summary-value--inline" data-role="battery-delta-total">--</span>
                    </div>
                    <div class="summary-split-item">
                        <span class="summary-split-label">Start/End</span>
                        <span class="summary-value summary-value--inline" data-role="battery-delta-range">--</span>
                    </div>
                    <div class="summary-split-item">
                        <span class="summary-split-label">Min/Max</span>
                        <span class="summary-value summary-value--inline" data-role="battery-delta-extrema">--</span>
                    </div>
                </div>
            </article>
            <article class="summary-card">
                <div class="summary-label">Grid Flow</div>
                <div class="summary-split-metric">
                    <div class="summary-split-item">
                        <span class="summary-split-label">Grid From</span>
                        <span class="summary-value summary-value--inline" data-role="grid-from-total">--</span>
                    </div>
                    <div class="summary-split-item">
                        <span class="summary-split-label">Grid To</span>
                        <span class="summary-value summary-value--inline" data-role="grid-to-total">--</span>
                    </div>
                </div>
            </article>
            <article class="summary-card summary-card--price-variation">
                <div class="summary-label">Price Variation</div>
                <div class="summary-value" data-role="price-variation-total">--</div>
                <div class="summary-secondary summary-secondary--price-variation" data-role="price-variation-range">--</div>
                <div class="summary-subtle" data-role="price-variation-indicator">No hourly prices</div>
            </article>
            <article class="summary-card">
                <div class="summary-label">Net Cost</div>
                <div class="summary-value" data-role="net-cost-total">--</div>
                <div class="summary-secondary summary-secondary--net-cost" data-role="net-cost-spot-total">--</div>
                <div class="summary-badge" data-role="cost-badge">Loading</div>
            </article>
            <article class="summary-card summary-card--charge-cost">
                <div class="summary-label">Charge Costs</div>
                <div class="summary-value" data-role="charge-cost-total">--</div>
                <div class="summary-secondary summary-secondary--charge-cost" data-role="charge-cost-spot-total">--</div>
                <div class="summary-subtle">Charged kWh × hourly spot price</div>
            </article>
            <article class="summary-card summary-card--savings">
                <div class="summary-label">Battery Economics</div>
                <div class="summary-split-metric">
                    <div class="summary-split-item">
                        <span class="summary-split-label">Savings</span>
                        <span class="summary-value summary-value--inline" data-role="battery-savings-total">--</span>
                    </div>
                    <div class="summary-split-item">
                        <span class="summary-split-label">Charge Costs</span>
                        <span class="summary-value summary-value--inline" data-role="battery-charge-cost-total">--</span>
                    </div>
                    <div class="summary-split-item">
                        <span class="summary-split-label">Battery P&amp;L</span>
                        <span class="summary-value summary-value--inline" data-role="battery-pnl-total">--</span>
                    </div>
                </div>
            </article>
            <article class="summary-card summary-card--pnl">
                <div class="summary-label">P&amp;L</div>
                <div class="summary-value" data-role="pnl-total">--</div>
                <div class="summary-secondary summary-secondary--pnl" data-role="pnl-spot-total">--</div>
                <div class="summary-subtle">Savings - charge costs - net cost</div>
            </article>
        </section>

        <section class="chart-card">
            <div class="chart-card__header">
                <div>
                    <p class="chart-kicker">Daily Energy View</p>
                    <h2 data-role="chart-title">Selected day</h2>
                </div>
                <div class="chart-legend">
                    <span><i class="legend-swatch legend-swatch--charge"></i>Charged</span>
                    <span><i class="legend-swatch legend-swatch--discharge"></i>Discharged</span>
                    <span><i class="legend-swatch legend-swatch--grid-from"></i>Grid from</span>
                    <span><i class="legend-swatch legend-swatch--grid-to"></i>Grid to</span>
                    <span><i class="legend-swatch legend-swatch--line"></i>Cumulative net cost</span>
                    <span><i class="legend-swatch legend-swatch--pnl-line"></i>Cumulative P&amp;L</span>
                    <span><i class="legend-swatch legend-swatch--battery-level"></i>Electric level</span>
                </div>
            </div>
            <div class="chart-status" data-role="chart-status">Loading report...</div>
            <div class="chart-wrap" data-role="chart-wrap" hidden>
                <svg class="daily-report-chart" data-role="chart" viewBox="0 0 1200 420" preserveAspectRatio="none" aria-label="Daily report chart"></svg>
            </div>
        </section>

        <section class="chart-card">
            <div class="chart-card__header">
                <div>
                    <p class="chart-kicker">Daily Value View</p>
                    <h2 data-role="money-chart-title">Selected day</h2>
                </div>
                <div class="chart-legend">
                    <span><i class="legend-swatch legend-swatch--charge"></i>Charged</span>
                    <span><i class="legend-swatch legend-swatch--discharge"></i>Discharged</span>
                    <span><i class="legend-swatch legend-swatch--grid-from"></i>Grid from</span>
                    <span><i class="legend-swatch legend-swatch--grid-to"></i>Grid to</span>
                    <span><i class="legend-swatch legend-swatch--line"></i>Cumulative net cost</span>
                    <span><i class="legend-swatch legend-swatch--pnl-line"></i>Cumulative P&amp;L</span>
                </div>
            </div>
            <div class="chart-status" data-role="money-chart-status">Loading report...</div>
            <div class="chart-wrap" data-role="money-chart-wrap" hidden>
                <svg class="daily-report-chart" data-role="money-chart" viewBox="0 0 1200 420" preserveAspectRatio="none" aria-label="Daily report value chart"></svg>
            </div>
        </section>

        <section class="detail-grid">
            <article class="detail-card">
                <h3>Hourly table</h3>
                <div class="table-wrap">
                    <table class="hourly-table">
                        <thead>
                            <tr>
                                <th>Hour</th>
                                <th>Charged</th>
                                <th>Discharged</th>
                                <th>Battery start</th>
                                <th>Battery end</th>
                                <th>Battery delta</th>
                                <th>Grid from</th>
                                <th>Grid to</th>
                                <th>Price</th>
                                <th>Spot Price</th>
                                <th>Import cost</th>
                                <th>Export cost</th>
                                <th>Savings</th>
                                <th>Net cost</th>
                                <th>Partial</th>
                            </tr>
                        </thead>
                        <tbody data-role="hourly-table-body">
                            <tr data-role="hourly-table-message-row">
                                <td colspan="15" class="table-placeholder" data-role="hourly-table-message-cell">Loading report...</td>
                            </tr>
                            <tr class="hourly-table__total-row" data-role="hourly-table-total-row" hidden>
                                <td data-role="hourly-total-hour">Total</td>
                                <td data-role="hourly-total-charged">--</td>
                                <td data-role="hourly-total-discharged">--</td>
                                <td data-role="hourly-total-battery-start">--</td>
                                <td data-role="hourly-total-battery-end">--</td>
                                <td data-role="hourly-total-battery-delta">--</td>
                                <td data-role="hourly-total-grid-from">--</td>
                                <td data-role="hourly-total-grid-to">--</td>
                                <td data-role="hourly-total-price">--</td>
                                <td data-role="hourly-total-spot-price">--</td>
                                <td data-role="hourly-total-grid-from-cost">--</td>
                                <td data-role="hourly-total-grid-to-cost">--</td>
                                <td data-role="hourly-total-savings">--</td>
                                <td data-role="hourly-total-net-cost">--</td>
                                <td data-role="hourly-total-partial">--</td>
                            </tr>
                        </tbody>
                    </table>
                    <template data-role="hourly-table-row-template">
                        <tr data-role="hourly-table-row">
                            <td data-role="cell-hour">--</td>
                            <td data-role="cell-charged">--</td>
                            <td data-role="cell-discharged">--</td>
                            <td data-role="cell-battery-start">--</td>
                            <td data-role="cell-battery-end">--</td>
                            <td data-role="cell-battery-delta">--</td>
                            <td data-role="cell-grid-from">--</td>
                            <td data-role="cell-grid-to">--</td>
                            <td data-role="cell-price">--</td>
                            <td data-role="cell-spot-price">--</td>
                            <td data-role="cell-grid-from-cost">--</td>
                            <td data-role="cell-grid-to-cost">--</td>
                            <td data-role="cell-savings">--</td>
                            <td data-role="cell-net-cost">--</td>
                            <td data-role="cell-partial">--</td>
                        </tr>
                    </template>
                </div>
            </article>
            <article class="detail-card">
                <h3>Report metadata</h3>
                <dl class="metric-list">
                    <div><dt>Requested date</dt><dd data-role="meta-date">--</dd></div>
                    <div><dt>Timezone</dt><dd data-role="meta-timezone">--</dd></div>
                    <div><dt>Partial day</dt><dd data-role="meta-partial">--</dd></div>
                    <div><dt>Price file</dt><dd data-role="meta-price-file">--</dd></div>
                    <div><dt>Price hours</dt><dd data-role="meta-price-hours">--</dd></div>
                    <div><dt>Generated at</dt><dd data-role="meta-generated-at">--</dd></div>
                    <div><dt>Saved report</dt><dd data-role="meta-saved-at">--</dd></div>
                </dl>
            </article>
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
        window.DAILY_REPORT_BOOT = {
            apiUrl: 'api/report_data.php',
            requestedDate: <?php echo json_encode($requestedDate, JSON_UNESCAPED_SLASHES); ?>
        };
    </script>
    <script src="../main/assets/js/price_conversion.js"></script>
    <script src="assets/js/daily_report.js"></script>
</body>
</html>
