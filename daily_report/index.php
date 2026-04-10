<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Amsterdam');

$requestedDate = isset($_GET['date']) && is_string($_GET['date']) ? trim($_GET['date']) : '';
if ($requestedDate === '') {
    $requestedDate = (new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d');
}
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
                <h1>Daily Report</h1>
                <p class="hero-text">
                    A read-only day view for battery, grid, and cost behavior. It shows saved hourly report data,
                    today-so-far by default, with one independent page and no runtime dependency on PathLab assets.
                </p>
            </div>
            <div class="hero-meta">
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
                    <span class="meta-label">Saved JSON</span>
                    <span class="meta-value" data-role="saved-path">Waiting for report</span>
                </div>
                <div class="meta-pill">
                    <span class="meta-label">Data source</span>
                    <span class="meta-value" data-role="report-source">Waiting for report</span>
                </div>
            </div>
        </section>

        <section class="status-grid status-grid--eight">
            <article class="summary-card summary-card--primary">
                <div class="summary-label">Charged</div>
                <div class="summary-value" data-role="charged-total">--</div>
                <div class="summary-subtle">Total for selected day</div>
            </article>
            <article class="summary-card">
                <div class="summary-label">Discharged</div>
                <div class="summary-value" data-role="discharged-total">--</div>
                <div class="summary-subtle">Total for selected day</div>
            </article>
            <article class="summary-card">
                <div class="summary-label">Battery Delta</div>
                <div class="summary-value" data-role="battery-delta-total">--</div>
                <div class="summary-subtle">Interpolated day delta</div>
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
                <div class="summary-subtle">Charged kWh × hourly price</div>
            </article>
            <article class="summary-card summary-card--pnl">
                <div class="summary-label">P&amp;L</div>
                <div class="summary-value" data-role="pnl-total">--</div>
                <div class="summary-subtle">Charge costs - savings + net cost</div>
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
                </div>
            </div>
            <div class="chart-status" data-role="chart-status">Loading report...</div>
            <div class="chart-wrap" data-role="chart-wrap" hidden>
                <svg class="daily-report-chart" data-role="chart" viewBox="0 0 1200 420" preserveAspectRatio="none" aria-label="Daily report chart"></svg>
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
                                <th>Import cost</th>
                                <th>Export cost</th>
                                <th>Net cost</th>
                                <th>Partial</th>
                            </tr>
                        </thead>
                        <tbody data-role="hourly-table-body">
                            <tr><td colspan="13" class="table-placeholder">Loading report...</td></tr>
                        </tbody>
                    </table>
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
        window.DAILY_REPORT_BOOT = {
            apiUrl: 'api/report_data.php',
            requestedDate: <?php echo json_encode($requestedDate, JSON_UNESCAPED_SLASHES); ?>
        };
    </script>
    <script src="assets/js/daily_report.js"></script>
</body>
</html>
