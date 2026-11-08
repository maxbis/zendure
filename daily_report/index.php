<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Amsterdam');
$requestedDate = is_string($_GET['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date'])
    ? (string)$_GET['date']
    : date('Y-m-d');
$month = substr($requestedDate, 0, 7);
$assetVersion = (string)max(
    filemtime(__DIR__ . '/assets/css/report.css'),
    filemtime(__DIR__ . '/assets/js/daily.js')
);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0d0f12">
    <title>Daily Energy Report</title>
    <link rel="icon" type="image/png" sizes="32x32" href="../main/favicon-32x32.png">
    <link rel="stylesheet" href="../themes/graphite-signal-dark/assets/css/theme.css">
    <link rel="stylesheet" href="../themes/graphite-signal-dark/assets/css/components.css">
    <link rel="stylesheet" href="assets/css/report.css?v=<?= rawurlencode($assetVersion); ?>">
    <script>
        window.DAILY_REPORT_BOOT = <?= json_encode([
            'date' => $requestedDate,
            'apiUrl' => 'api/daily.php',
        ], JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script src="assets/js/daily.js?v=<?= rawurlencode($assetVersion); ?>" defer></script>
</head>
<body data-theme="graphite-signal-dark">
<main class="report-shell">
    <header class="report-header gsd-card">
        <div class="report-header__title">
            <a class="report-back-link" href="../app/"><span aria-hidden="true">←</span> Energy Manager</a>
            <p class="report-eyebrow">Energy reporting</p>
            <h1>Daily energy report</h1>
            <nav class="report-view-switch" aria-label="Report views">
                <span aria-current="page">Daily</span>
                <a href="monthly.php?month=<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8'); ?>">Monthly</a>
            </nav>
        </div>
        <form class="report-period" data-role="date-form">
            <label class="gsd-field__label" for="report-date">Report date</label>
            <div class="report-period__controls">
                <button class="gsd-icon-btn" type="button" data-role="previous-date" aria-label="Previous day">←</button>
                <input class="gsd-input" id="report-date" name="date" type="date" max="<?= date('Y-m-d'); ?>" value="<?= htmlspecialchars($requestedDate, ENT_QUOTES, 'UTF-8'); ?>">
                <button class="gsd-icon-btn" type="button" data-role="next-date" aria-label="Next day">→</button>
                <button class="gsd-btn gsd-btn--secondary" type="submit">Load</button>
            </div>
        </form>
    </header>

    <div class="report-status" role="status" aria-live="polite">
        <span class="gsd-badge" data-role="day-status">Loading</span>
        <span data-role="coverage-status">Retrieving hourly data…</span>
        <span data-role="saved-status"></span>
    </div>
    <div class="report-error gsd-card" data-role="error" role="alert" hidden></div>

    <section class="outcome-grid" aria-label="Daily outcomes">
        <article class="outcome-card gsd-card">
            <p class="outcome-card__label">Net grid cost</p>
            <p class="outcome-card__value" data-role="net-grid-cost">—</p>
            <p class="outcome-card__detail" data-role="net-grid-detail">Consumer-price import minus spot-price export</p>
        </article>
        <article class="outcome-card outcome-card--primary gsd-card">
            <p class="outcome-card__label">Estimated battery contribution</p>
            <p class="outcome-card__value" data-role="battery-contribution">—</p>
            <p class="outcome-card__detail" data-role="battery-confidence">Waiting for valuation</p>
        </article>
        <article class="outcome-card gsd-card">
            <p class="outcome-card__label">Energy balance</p>
            <dl class="outcome-pairs">
                <div><dt>Grid import</dt><dd data-role="grid-import-energy">—</dd></div>
                <div><dt>Grid export</dt><dd data-role="grid-export-energy">—</dd></div>
                <div><dt>Battery charged</dt><dd data-role="battery-charged">—</dd></div>
                <div><dt>Battery discharged</dt><dd data-role="battery-discharged">—</dd></div>
            </dl>
        </article>
    </section>

    <section class="report-chart-card gsd-card" aria-labelledby="daily-chart-title">
        <header class="section-heading">
            <div>
                <p class="report-eyebrow">Hourly timeline</p>
                <h2 id="daily-chart-title" data-role="chart-title">Energy flow</h2>
            </div>
            <div class="chart-legend" aria-label="Chart legend">
                <span><i class="legend-key legend-key--import"></i>Grid net</span>
                <span><i class="legend-key legend-key--battery"></i>Battery net</span>
                <span><i class="legend-key legend-key--level"></i>Battery level</span>
            </div>
        </header>
        <div class="chart-frame" data-role="chart" aria-live="polite">
            <p class="empty-state">Loading hourly energy…</p>
        </div>
    </section>

    <section class="economics-grid">
        <article class="economics-card gsd-card" aria-labelledby="grid-cost-title">
            <header class="section-heading"><div><p class="report-eyebrow">Household exchange</p><h2 id="grid-cost-title">Grid costs</h2></div></header>
            <dl class="money-list">
                <div><dt>Imported <span>consumer price</span></dt><dd data-role="grid-import-cost">—</dd></div>
                <div><dt>Exported <span>spot price</span></dt><dd data-role="grid-export-value">—</dd></div>
                <div class="money-list__total"><dt>Net grid cost</dt><dd data-role="grid-net-cost-detail">—</dd></div>
            </dl>
        </article>
        <article class="economics-card gsd-card" aria-labelledby="battery-economics-title">
            <header class="section-heading"><div><p class="report-eyebrow">Schedule-assisted estimate</p><h2 id="battery-economics-title">Battery economics</h2></div></header>
            <dl class="money-list">
                <div><dt>Charging cost</dt><dd data-role="battery-charge-cost">—</dd></div>
                <div><dt>Estimated home-use value</dt><dd data-role="battery-home-value">—</dd></div>
                <div><dt>Estimated export value</dt><dd data-role="battery-export-value">—</dd></div>
                <div><dt>Battery flow P&amp;L</dt><dd data-role="battery-flow-pnl">—</dd></div>
                <div><dt>Change in stored value</dt><dd data-role="battery-stored-value">—</dd></div>
                <div class="money-list__total"><dt>Estimated economic contribution</dt><dd data-role="battery-contribution-detail">—</dd></div>
            </dl>
            <p class="explanation" data-role="battery-basis">The estimate will show its valuation basis and confidence when available.</p>
        </article>
    </section>

    <details class="detail-disclosure gsd-card">
        <summary>Hourly detail <span data-role="hour-count">0 hours</span></summary>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Hour</th><th>Battery</th><th>Level</th><th>Grid</th><th>Consumer</th><th>Spot</th><th>Grid cost</th><th>Battery value</th><th>Confidence</th></tr></thead>
                <tbody data-role="hour-table"><tr><td colspan="9">Loading hourly data…</td></tr></tbody>
            </table>
        </div>
    </details>

    <aside class="report-note">
        <strong>Keep the figures separate.</strong> Battery contribution is already reflected in measured grid flows and must not be added to or subtracted from net grid cost. Fixed fees, battery ownership and degradation are excluded.
    </aside>
</main>
</body>
</html>
