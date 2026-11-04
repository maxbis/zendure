<?php

declare(strict_types=1);

require_once __DIR__ . '/../login/validate.php';
require_once __DIR__ . '/../common/php/system_config.php';

$systemConfig = loadSystemConfig();
date_default_timezone_set($systemConfig['installation']['timezone']);
$pageConfig = [
    'apiUrl' => 'api/optimizer_scarcity.php',
    'refreshIntervalMs' => 60000,
    'timezone' => $systemConfig['installation']['timezone'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0d0f12">
    <title>Scarcity bands · Zendure</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/icons/app-icon-32.png">
    <link rel="stylesheet" href="../themes/graphite-signal-dark/assets/css/theme.css">
    <link rel="stylesheet" href="../themes/graphite-signal-dark/assets/css/components.css">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/optimizer-scarcity.css?v=<?= (int)filemtime(__DIR__ . '/assets/css/optimizer-scarcity.css'); ?>">
    <script>window.OPTIMIZER_SCARCITY_CONFIG = <?= json_encode($pageConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
    <script src="../themes/graphite-signal-dark/assets/js/graphite-controls.js" defer></script>
    <script src="assets/js/optimizer-scarcity.js?v=<?= (int)filemtime(__DIR__ . '/assets/js/optimizer-scarcity.js'); ?>" defer></script>
</head>
<body data-theme="graphite-signal-dark">
    <div class="gsd-flash-region" data-gsd-flash-region aria-live="polite" aria-relevant="additions"></div>
    <main class="app-shell scarcity-shell" data-component="optimizer-scarcity" data-state="loading" aria-busy="true">
        <header class="app-topbar scarcity-topbar">
            <div class="app-brand">
                <span class="app-brand__mark" aria-hidden="true"><img src="assets/icons/app-icon-180.png" alt=""></span>
                <div class="app-brand__copy">
                    <h1 class="app-brand__title">Scarcity bands</h1>
                    <p class="app-brand__meta" data-role="page-meta">Loading the latest optimizer calculation</p>
                </div>
            </div>
            <div class="app-topbar__actions">
                <a class="gsd-btn gsd-btn--secondary" href="docs.php">Documentation</a>
                <a class="gsd-btn gsd-btn--secondary" href="optimizer.php">Optimizer</a>
                <a class="gsd-btn gsd-btn--secondary" href="./">Live app</a>
                <button class="gsd-icon-btn" type="button" aria-label="Refresh scarcity metrics" data-role="refresh">
                    <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#refresh"></use></svg>
                </button>
            </div>
        </header>

        <section class="scarcity-loading" data-role="loading" role="status">
            <span class="app-loading-orb" aria-hidden="true"></span>
            <div><strong>Loading scarcity metrics</strong><p>Reading the latest published optimizer calculation…</p></div>
        </section>

        <section class="scarcity-error" data-role="error" role="alert" hidden>
            <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#error"></use></svg>
            <div><strong>Scarcity metrics unavailable</strong><p data-role="error-message">The API could not be loaded.</p></div>
            <button class="gsd-btn gsd-btn--secondary" type="button" data-role="retry">Try again</button>
        </section>

        <div class="scarcity-content" data-role="content" hidden>
            <section class="scarcity-banner" data-role="status-banner" role="status">
                <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#info"></use></svg>
                <div><strong data-role="status-title">Checking calculation</strong><p data-role="status-copy">—</p></div>
            </section>

            <section class="scarcity-summary" aria-label="Scarcity calculation summary">
                <article class="gsd-card scarcity-metric"><span>Algorithm</span><strong data-role="algorithm">—</strong><small>Whether active bands influence optimizer decisions.</small></article>
                <article class="gsd-card scarcity-metric"><span>Expected refill</span><strong data-role="refill-percent">—</strong><small>The share of missing battery energy expected to be restored.</small></article>
                <article class="gsd-card scarcity-metric"><span>Applied bands</span><strong data-role="applied-count">—</strong><small>Active bands currently adding virtual discharge cost.</small></article>
                <article class="gsd-card scarcity-metric"><span>Calculation age</span><strong data-role="age">—</strong><small>Age of the published optimizer calculation.</small></article>
            </section>

            <section class="gsd-card scarcity-section" aria-labelledby="refill-title">
                <div class="scarcity-section__header"><div><h2 id="refill-title">Predicted refill</h2><p>How much of the energy missing below maximum SoC can be restored during this optimizer horizon.</p></div></div>
                <div class="scarcity-refill-bar" aria-hidden="true"><span data-role="refill-bar"></span></div>
                <dl class="scarcity-details">
                    <div><dt>Energy required</dt><dd data-role="required-refill">—</dd><small>Energy needed to reach the configured maximum SoC.</small></div>
                    <div><dt>Solar refill</dt><dd data-role="solar-refill">—</dd><small>Usable forecast solar surplus after charging limits and efficiency.</small></div>
                    <div><dt>Cheap-grid refill</dt><dd data-role="grid-refill">—</dd><small>Available charging in hours priced below the cheap-grid boundary.</small></div>
                    <div><dt>Total predicted refill</dt><dd data-role="total-refill">—</dd><small>Solar and cheap-grid opportunities combined, capped at required energy.</small></div>
                    <div><dt>Refill factor</dt><dd data-role="refill-factor">—</dd><small>The decimal form used to derive the expected refill percentage.</small></div>
                    <div><dt>Forecast horizon</dt><dd data-role="horizon">—</dd><small>The period searched for solar and cheap-grid charging opportunities.</small></div>
                </dl>
            </section>

            <section class="gsd-card scarcity-section" aria-labelledby="prices-title">
                <div class="scarcity-section__header"><div><h2 id="prices-title">Price references</h2><p>The current forecast horizon supplies the stored-energy baseline; weighted historical prices establish the cheap-grid and scarcity thresholds.</p></div></div>
                <div class="scarcity-reference-groups">
                    <section class="scarcity-reference-group" aria-labelledby="horizon-reference-title">
                        <h3 id="horizon-reference-title">Forecast-horizon reference</h3>
                        <dl class="scarcity-details scarcity-details--horizon">
                            <div><dt>Horizon-average terminal value</dt><dd data-role="terminal-price">—</dd><small>Average consumer price across the current optimizer horizon.</small></div>
                        </dl>
                    </section>
                    <section class="scarcity-reference-group" aria-labelledby="historical-reference-title">
                        <h3 id="historical-reference-title">Historical references</h3>
                        <dl class="scarcity-details scarcity-details--prices">
                            <div><dt>Cheap-grid boundary</dt><dd data-role="cheap-price">—</dd><small>Grid prices at or below this historical threshold count as predicted refill.</small></div>
                            <div><dt>History source</dt><dd data-role="history-source">—</dd><small>Price database first, with local JSON files as fallback.</small></div>
                            <div><dt>History period</dt><dd data-role="history-period">—</dd><small>Completed local dates included in the percentile calculation.</small></div>
                            <div><dt>Hourly samples</dt><dd data-role="sample-count">—</dd><small>At least 168 valid hourly prices are required.</small></div>
                            <div><dt>Weighting half-life</dt><dd data-role="half-life">—</dd><small>Prices this old receive half the weight of the newest prices.</small></div>
                        </dl>
                    </section>
                </div>
                <div class="scarcity-percentile" data-role="percentile-panel" hidden>
                    <div class="scarcity-percentile__header">
                        <div><h3>Weighted price percentiles</h3><p>Historical consumer price at each percentile, with the optimizer’s current references highlighted.</p></div>
                    </div>
                    <div class="scarcity-percentile__chart" data-role="percentile-chart"></div>
                    <div class="scarcity-percentile__legend" data-role="percentile-legend" aria-label="Price percentile references"></div>
                </div>
            </section>

            <section class="scarcity-section scarcity-bands-section" aria-labelledby="bands-title">
                <div class="scarcity-section__header"><div><h2 id="bands-title">Scarcity bands</h2><p>Deeper energy requires a more exceptional price when forecast refill is limited. Values are virtual optimizer costs, not bill charges.</p></div></div>
                <div class="scarcity-bands" data-role="bands"></div>
            </section>

            <section class="gsd-card scarcity-section scarcity-explainer" aria-labelledby="logic-title">
                <div class="scarcity-section__header"><div><h2 id="logic-title">How to read this page</h2><p>The optimizer applies the calculation in three steps.</p></div><a class="gsd-btn gsd-btn--secondary" href="docs.php?doc=planner%2Fscarcity-bands.md">Read full documentation</a></div>
                <ol><li>Estimate solar and cheap-grid refill over the visible horizon.</li><li>Activate each band whose refill boundary is higher than the predicted refill percentage.</li><li>When the master switch is enabled, add the band’s scarcity value to discharge through that SoC range.</li></ol>
            </section>
        </div>
    </main>
</body>
</html>
