<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Amsterdam');
$requestedMonth = is_string($_GET['month'] ?? null) && preg_match('/^\d{4}-\d{2}$/', (string)$_GET['month'])
    ? (string)$_GET['month']
    : date('Y-m');
$dailyDate = $requestedMonth . '-01';
$assetVersion = (string)max(
    filemtime(__DIR__ . '/assets/css/report.css'),
    filemtime(__DIR__ . '/assets/js/monthly.js')
);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0d0f12">
    <title>Monthly Energy Report</title>
    <link rel="icon" type="image/png" sizes="32x32" href="../main/favicon-32x32.png">
    <link rel="stylesheet" href="../themes/graphite-signal-dark/assets/css/theme.css">
    <link rel="stylesheet" href="../themes/graphite-signal-dark/assets/css/components.css">
    <link rel="stylesheet" href="assets/css/report.css?v=<?= rawurlencode($assetVersion); ?>">
    <script>
        window.MONTHLY_REPORT_BOOT = <?= json_encode([
            'month' => $requestedMonth,
            'apiUrl' => 'api/monthly.php',
        ], JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script src="assets/js/monthly.js?v=<?= rawurlencode($assetVersion); ?>" defer></script>
</head>
<body data-theme="graphite-signal-dark">
<main class="report-shell">
    <header class="report-header gsd-card">
        <div class="report-header__title">
            <a class="report-back-link" href="../app/"><span aria-hidden="true">←</span> Energy Manager</a>
            <p class="report-eyebrow">Energy reporting</p>
            <h1>Monthly energy report</h1>
            <nav class="report-view-switch" aria-label="Report views">
                <a href="./?date=<?= htmlspecialchars($dailyDate, ENT_QUOTES, 'UTF-8'); ?>">Daily</a>
                <span aria-current="page">Monthly</span>
            </nav>
        </div>
        <form class="report-period" data-role="month-form">
            <label class="gsd-field__label" for="report-month">Report month</label>
            <div class="report-period__controls">
                <button class="gsd-icon-btn" type="button" data-role="previous-month" aria-label="Previous month">←</button>
                <input class="gsd-input" id="report-month" name="month" type="month" max="<?= date('Y-m'); ?>" value="<?= htmlspecialchars($requestedMonth, ENT_QUOTES, 'UTF-8'); ?>">
                <button class="gsd-icon-btn" type="button" data-role="next-month" aria-label="Next month">→</button>
                <button class="gsd-btn gsd-btn--secondary" type="submit">Load</button>
            </div>
        </form>
    </header>

    <div class="report-status" role="status" aria-live="polite">
        <span class="gsd-badge" data-role="month-status">Loading</span>
        <span data-role="coverage-status">Retrieving daily reports…</span>
    </div>
    <div class="report-error gsd-card" data-role="error" role="alert" hidden></div>

    <section class="outcome-grid" aria-label="Monthly outcomes">
        <article class="outcome-card gsd-card"><p class="outcome-card__label">Net grid cost</p><p class="outcome-card__value" data-role="net-grid-cost">—</p><p class="outcome-card__detail" data-role="net-grid-detail">Selected month</p></article>
        <article class="outcome-card outcome-card--primary gsd-card"><p class="outcome-card__label">Estimated battery contribution</p><p class="outcome-card__value" data-role="battery-contribution">—</p><p class="outcome-card__detail" data-role="battery-confidence">Waiting for valuation</p></article>
        <article class="outcome-card gsd-card"><p class="outcome-card__label">Energy balance</p><dl class="outcome-pairs"><div><dt>Grid import</dt><dd data-role="grid-import-energy">—</dd></div><div><dt>Grid export</dt><dd data-role="grid-export-energy">—</dd></div><div><dt>Battery charged</dt><dd data-role="battery-charged">—</dd></div><div><dt>Battery discharged</dt><dd data-role="battery-discharged">—</dd></div></dl></article>
    </section>

    <section class="report-chart-card gsd-card" aria-labelledby="monthly-chart-title">
        <header class="section-heading"><div><p class="report-eyebrow">Daily outcomes</p><h2 id="monthly-chart-title" data-role="chart-title">Month overview</h2></div><div class="chart-legend"><span><i class="legend-key legend-key--cost"></i>Net grid cost</span><span><i class="legend-key legend-key--contribution"></i>Battery contribution</span></div></header>
        <div class="chart-frame" data-role="chart" aria-live="polite"><p class="empty-state">Loading monthly values…</p></div>
    </section>

    <section class="economics-grid">
        <article class="economics-card gsd-card"><header class="section-heading"><div><p class="report-eyebrow">Household exchange</p><h2>Grid costs</h2></div></header><dl class="money-list"><div><dt>Imported <span>consumer price</span></dt><dd data-role="grid-import-cost">—</dd></div><div><dt>Exported <span>spot price</span></dt><dd data-role="grid-export-value">—</dd></div><div class="money-list__total"><dt>Net grid cost</dt><dd data-role="grid-net-cost-detail">—</dd></div></dl></article>
        <article class="economics-card gsd-card"><header class="section-heading"><div><p class="report-eyebrow">Sum of daily estimates</p><h2>Battery economics</h2></div></header><dl class="money-list"><div><dt>Battery flow P&amp;L</dt><dd data-role="battery-flow-pnl">—</dd></div><div><dt>Stored-value changes</dt><dd data-role="battery-stored-value">—</dd></div><div class="money-list__total"><dt>Estimated economic contribution</dt><dd data-role="battery-contribution-detail">—</dd></div></dl><p class="explanation" data-role="monthly-note"></p></article>
    </section>

    <details class="detail-disclosure gsd-card" open>
        <summary>Daily detail <span data-role="day-count">0 days</span></summary>
        <div class="table-scroll"><table><thead><tr><th>Date</th><th>Grid import</th><th>Grid export</th><th>Net cost</th><th>Battery charged</th><th>Battery discharged</th><th>Flow P&amp;L</th><th>Stored value</th><th>Contribution</th><th>Valuation coverage</th></tr></thead><tbody data-role="day-table"><tr><td colspan="10">Loading daily data…</td></tr></tbody></table></div>
    </details>

    <aside class="report-note"><strong>Operational estimate.</strong> Monthly battery contribution sums the daily estimates. It is not a formal inventory revaluation and must not be combined with net grid cost.</aside>
</main>
</body>
</html>
