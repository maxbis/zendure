<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Amsterdam');

$requestedMonth = isset($_GET['month']) && is_string($_GET['month']) ? trim($_GET['month']) : '';
if ($requestedMonth === '') {
    $requestedMonth = (new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m');
}
$dailyReportDate = preg_match('/^\d{4}-\d{2}$/', $requestedMonth)
    ? $requestedMonth . '-01'
    : (new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Monthly Report</title>
    <link rel="stylesheet" href="assets/css/daily_report.css">
    <link rel="stylesheet" href="assets/css/monthly_report.css">
</head>
<body>
    <main class="daily-report-shell">
        <section class="hero-card">
            <div class="hero-copy">
                <p class="eyebrow">Prototype</p>
                <h1>Monthly Report</h1>
                <p class="hero-text">
                    A read-only month view for battery, grid, and cost behavior. It reuses saved daily reports,
                    generates missing day files when needed, and aggregates one selected month at a time.
                </p>
            </div>
            <div class="hero-meta">
                <nav class="view-switch" aria-label="Report views">
                    <a href="./?date=<?php echo htmlspecialchars($dailyReportDate, ENT_QUOTES, 'UTF-8'); ?>">Daily</a>
                    <span class="view-switch__sep" aria-hidden="true">·</span>
                    <span class="view-switch__current">Monthly</span>
                </nav>
                <form class="nav-card" data-role="month-form">
                    <label class="meta-label" for="report-month">Report month</label>
                    <div class="nav-row">
                        <button type="button" class="nav-button" data-role="prev-month" aria-label="Previous month">&larr;</button>
                        <input id="report-month" name="month" class="date-input" type="month" value="<?php echo htmlspecialchars($requestedMonth, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
                        <button type="button" class="nav-button" data-role="next-month" aria-label="Next month">&rarr;</button>
                        <button type="submit" class="refresh-button">Load month</button>
                    </div>
                </form>
                <div class="meta-pill">
                    <span class="meta-label">Included days</span>
                    <span class="meta-value" data-role="month-included-days">Waiting for report</span>
                </div>
                <div class="meta-pill">
                    <span class="meta-label">Cost coverage</span>
                    <span class="meta-value" data-role="month-cost-coverage">Waiting for report</span>
                </div>
            </div>
        </section>

        <section class="status-grid status-grid--eight">
            <article class="summary-card summary-card--primary">
                <div class="summary-label">Charged</div>
                <div class="summary-value" data-role="charged-total">--</div>
                <div class="summary-subtle">Total for selected month</div>
            </article>
            <article class="summary-card">
                <div class="summary-label">Discharged</div>
                <div class="summary-value" data-role="discharged-total">--</div>
                <div class="summary-subtle">Total for selected month</div>
            </article>
            <article class="summary-card">
                <div class="summary-label">Battery Delta</div>
                <div class="summary-value" data-role="battery-delta-total">--</div>
                <div class="summary-secondary" data-role="battery-delta-range">--</div>
                <div class="summary-subtle" data-role="battery-delta-extrema">Interpolated month delta</div>
            </article>
            <article class="summary-card">
                <div class="summary-label">Grid From</div>
                <div class="summary-value" data-role="grid-from-total">--</div>
                <div class="summary-subtle">Import energy</div>
            </article>
            <article class="summary-card">
                <div class="summary-label">Grid To</div>
                <div class="summary-value" data-role="grid-to-total">--</div>
                <div class="summary-subtle">Export energy</div>
            </article>
            <article class="summary-card">
                <div class="summary-label">Net Cost</div>
                <div class="summary-value" data-role="net-cost-total">--</div>
                <div class="summary-secondary summary-secondary--net-cost" data-role="net-cost-spot-total">--</div>
                <div class="summary-badge" data-role="cost-badge">Loading</div>
            </article>
            <article class="summary-card summary-card--savings">
                <div class="summary-label">Savings</div>
                <div class="summary-value" data-role="savings-total">--</div>
                <div class="summary-subtle">Discharged kWh × hourly price</div>
            </article>
            <article class="summary-card summary-card--charge-cost">
                <div class="summary-label">Charge Costs</div>
                <div class="summary-value" data-role="charge-cost-total">--</div>
                <div class="summary-secondary summary-secondary--charge-cost" data-role="charge-cost-spot-total">--</div>
                <div class="summary-subtle">Charged kWh × hourly price</div>
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
                    <p class="chart-kicker">Monthly Energy View</p>
                    <h2 data-role="chart-title">Selected month</h2>
                </div>
                <div class="chart-legend">
                    <span><i class="legend-swatch legend-swatch--charge"></i>Charged</span>
                    <span><i class="legend-swatch legend-swatch--discharge"></i>Discharged</span>
                    <span><i class="legend-swatch legend-swatch--grid-from"></i>Grid from</span>
                    <span><i class="legend-swatch legend-swatch--grid-to"></i>Grid to</span>
                    <span><i class="legend-swatch legend-swatch--line"></i>Cumulative net cost</span>
                </div>
            </div>
            <div class="chart-status" data-role="chart-status">Loading month report...</div>
            <div class="chart-wrap" data-role="chart-wrap" hidden>
                <svg class="daily-report-chart" data-role="chart" viewBox="0 0 1200 420" preserveAspectRatio="none" aria-label="Monthly report chart"></svg>
            </div>
        </section>

        <section class="detail-grid">
            <article class="detail-card">
                <h3>Daily table</h3>
                <div class="table-wrap">
                    <table class="hourly-table monthly-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Charged</th>
                                <th>Discharged</th>
                                <th>Battery start</th>
                                <th>Battery end</th>
                                <th>Battery range</th>
                                <th>Grid from</th>
                                <th>Grid to</th>
                                <th>Net cost</th>
                                <th>Spot net cost</th>
                                <th>Savings</th>
                                <th>Charge costs</th>
                                <th>Spot charge costs</th>
                                <th>P&amp;L</th>
                                <th>Spot P&amp;L</th>
                                <th>Partial</th>
                            </tr>
                        </thead>
                        <tbody data-role="monthly-table-body">
                            <tr><td colspan="16" class="table-placeholder">Loading month report...</td></tr>
                        </tbody>
                    </table>
                </div>
            </article>
            <article class="detail-card">
                <h3>Report metadata</h3>
                <dl class="metric-list">
                    <div><dt>Selected month</dt><dd data-role="meta-month">--</dd></div>
                    <div><dt>Timezone</dt><dd data-role="meta-timezone">--</dd></div>
                    <div><dt>Included days</dt><dd data-role="meta-included-days">--</dd></div>
                    <div><dt>Saved days</dt><dd data-role="meta-saved-days">--</dd></div>
                    <div><dt>Generated days</dt><dd data-role="meta-generated-days">--</dd></div>
                    <div><dt>Missing price days</dt><dd data-role="meta-missing-price-days">--</dd></div>
                    <div><dt>Cost coverage</dt><dd data-role="meta-cost-coverage">--</dd></div>
                    <div><dt>Partial month</dt><dd data-role="meta-partial-month">--</dd></div>
                    <div><dt>Last included date</dt><dd data-role="meta-last-included-date">--</dd></div>
                    <div><dt>Generated at</dt><dd data-role="meta-generated-at">--</dd></div>
                </dl>
            </article>
        </section>
    </main>

    <script>
        window.MONTHLY_REPORT_BOOT = {
            apiUrl: 'api/monthly_report_data.php',
            requestedMonth: <?php echo json_encode($requestedMonth, JSON_UNESCAPED_SLASHES); ?>
        };
    </script>
    <script src="assets/js/monthly_report.js"></script>
</body>
</html>
