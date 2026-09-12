<?php

declare(strict_types=1);

require_once __DIR__ . '/../login/validate.php';
require_once __DIR__ . '/../common/php/system_config.php';
require_once __DIR__ . '/../main/includes/config_loader.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['optimizer_csrf'])) {
    $_SESSION['optimizer_csrf'] = bin2hex(random_bytes(24));
}

$systemConfig = loadSystemConfig();
date_default_timezone_set($systemConfig['installation']['timezone']);
$scheduleUrl = ConfigLoader::get(
    'scheduleApiUrl',
    '../main/data/api/data_api.php?type=schedule&resolved=1'
);
$scheduleUrl .= str_contains($scheduleUrl, '?') ? '&source=rules' : '?source=rules';
$viewerConfig = [
    'logUrl' => 'api/optimizer_log.php?limit=24',
    'modeUrl' => 'api/optimizer_mode.php',
    'refreshScheduleUrl' => '../main/api/refresh_schedule_proxy.php',
    'csrfToken' => $_SESSION['optimizer_csrf'],
    'scheduleUrl' => $scheduleUrl,
    'timezone' => $systemConfig['installation']['timezone'],
    'refreshIntervalMs' => 300000,
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0d0f12">
    <title>Optimizer comparison · Zendure</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/icons/app-icon-32.png">
    <link rel="stylesheet" href="../themes/graphite-signal-dark/assets/css/theme.css">
    <link rel="stylesheet" href="../themes/graphite-signal-dark/assets/css/components.css">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/optimizer-viewer.css?v=<?= (int)filemtime(__DIR__ . '/assets/css/optimizer-viewer.css'); ?>">
    <script>
        window.OPTIMIZER_VIEWER_CONFIG = <?= json_encode(
            $viewerConfig,
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ); ?>;
    </script>
    <script src="../themes/graphite-signal-dark/assets/js/graphite-controls.js" defer></script>
    <script src="assets/js/optimizer-pnl.js?v=<?= (int)filemtime(__DIR__ . '/assets/js/optimizer-pnl.js'); ?>" defer></script>
    <script src="assets/js/optimizer-viewer.js?v=<?= (int)filemtime(__DIR__ . '/assets/js/optimizer-viewer.js'); ?>" defer></script>
</head>
<body data-theme="graphite-signal-dark">
    <div class="gsd-flash-region" data-gsd-flash-region aria-live="polite" aria-relevant="additions"></div>

    <main class="app-shell optimizer-shell" data-component="optimizer-viewer" data-state="loading" aria-busy="true">
        <header class="app-topbar optimizer-topbar">
            <div class="app-brand">
                <span class="app-brand__mark" aria-hidden="true">
                    <img src="assets/icons/app-icon-180.png" alt="">
                </span>
                <div class="app-brand__copy">
                    <h1 class="app-brand__title">Optimizer comparison</h1>
                    <p class="app-brand__meta" data-role="viewer-meta">Loading latest shadow plan</p>
                </div>
            </div>
            <div class="app-topbar__actions">
                <a class="gsd-btn gsd-btn--secondary optimizer-return" href="./">Live app</a>
                <button class="gsd-icon-btn" type="button" aria-label="Refresh optimizer comparison" data-role="refresh">
                    <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#refresh"></use></svg>
                </button>
            </div>
        </header>

        <section class="optimizer-safety" role="status" aria-label="Schedule control status" data-role="mode-banner">
            <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#info"></use></svg>
            <div>
                <strong data-role="mode-banner-title">Checking active schedule</strong>
                <p data-role="mode-banner-copy">The rule-based schedule remains active unless optimizer mode is explicitly enabled.</p>
            </div>
        </section>

        <section class="gsd-card optimizer-mode" aria-labelledby="optimizer-mode-title">
            <div>
                <h2 id="optimizer-mode-title">Active schedule</h2>
                <p data-role="mode-detail">Checking which schedule the battery controller receives…</p>
            </div>
            <div class="optimizer-mode__actions" role="group" aria-label="Choose active schedule">
                <button class="gsd-btn gsd-btn--secondary" type="button" data-role="mode-rules" data-mode="rules">Rules</button>
                <button class="gsd-btn gsd-btn--secondary" type="button" data-role="mode-optimizer" data-mode="optimizer">Optimizer</button>
            </div>
        </section>

        <section class="gsd-card optimizer-status" aria-labelledby="optimizer-status-title">
            <div>
                <h2 id="optimizer-status-title">Optimizer status</h2>
                <p>Reported timestamps without a freshness rating.</p>
            </div>
            <dl class="optimizer-status__timestamps">
                <div>
                    <dt>Last successful calculation</dt>
                    <dd data-role="optimizer-last-run">—</dd>
                </div>
                <div>
                    <dt>Solar forecast updated</dt>
                    <dd data-role="solar-forecast-updated">—</dd>
                </div>
            </dl>
        </section>

        <section class="gsd-card optimizer-controls" aria-labelledby="optimizer-run-title">
            <div>
                <h2 id="optimizer-run-title">Optimizer run</h2>
                <p>Select a logged calculation. It is always compared with the rule-based schedule.</p>
            </div>
            <label class="gsd-field optimizer-run-select">
                <span class="gsd-field__label">Calculation time</span>
                <select class="gsd-select" data-role="run-select" disabled>
                    <option>Loading…</option>
                </select>
            </label>
        </section>

        <section class="optimizer-loading" data-role="loading" role="status">
            <span class="app-loading-orb" aria-hidden="true"></span>
            <div><strong>Loading optimizer comparison</strong><p>Reading the shadow log and current schedule…</p></div>
        </section>

        <section class="optimizer-error" data-role="error" role="alert" hidden>
            <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#error"></use></svg>
            <div><strong>Comparison unavailable</strong><p data-role="error-message">The optimizer log could not be loaded.</p></div>
            <button class="gsd-btn gsd-btn--secondary" type="button" data-role="retry">Try again</button>
        </section>

        <div class="optimizer-content" data-role="content" hidden>
            <section class="optimizer-summary" aria-label="Optimizer plan summary">
                <article class="gsd-card optimizer-metric">
                    <span>Active schedule</span>
                    <strong data-role="active-mode">—</strong>
                    <small data-role="plan-freshness">—</small>
                </article>
                <article class="gsd-card optimizer-metric">
                    <span>Forecast horizon</span>
                    <strong data-role="horizon">—</strong>
                    <small data-role="price-status">—</small>
                </article>
                <article class="gsd-card optimizer-metric">
                    <span>Final battery</span>
                    <strong data-role="final-soc">—</strong>
                    <small data-role="final-soc-detail">Rules versus Optimizer</small>
                </article>
                <article class="gsd-card optimizer-metric">
                    <span>Complete forecast difference</span>
                    <strong class="gsd-price" data-role="forecast-difference">—</strong>
                    <small data-role="forecast-difference-detail">Optimizer versus Rules cash P&amp;L</small>
                </article>
            </section>

            <details class="gsd-card optimizer-technical">
                <summary>Technical optimizer details</summary>
                <div class="optimizer-technical__metrics">
                    <p><span>Calculated</span><strong data-role="generated">—</strong></p>
                    <p><span>Optimizer battery path</span><strong data-role="soc">—</strong></p>
                    <p><span>Round-trip efficiency</span><strong data-role="efficiency">—</strong></p>
                    <p><span>Optimizer energy cost</span><strong data-role="cost">—</strong></p>
                    <p><span>Objective after terminal value</span><strong data-role="objective">—</strong></p>
                    <p><span>Different schedule segments</span><strong data-role="difference-count">—</strong></p>
                </div>
            </details>

            <section class="gsd-card optimizer-pnl" aria-labelledby="optimizer-pnl-title">
                <div class="gsd-card__header optimizer-pnl__header">
                    <div>
                        <h2 class="gsd-card__title" id="optimizer-pnl-title">Daily forecast cash P&amp;L</h2>
                        <p>Rule-based schedule versus optimizer, using identical forecast inputs.</p>
                    </div>
                    <span class="gsd-badge gsd-badge--warning">Forecast</span>
                </div>
                <div class="optimizer-pnl__days" data-role="daily-pnl"></div>
            </section>

            <section class="gsd-card optimizer-graphs" aria-labelledby="optimizer-graphs-title">
                <div class="gsd-card__header optimizer-graphs__header">
                    <div>
                        <h2 class="gsd-card__title" id="optimizer-graphs-title">Schedule comparison</h2>
                        <p>The selected optimizer calculation compared with the rules currently resolved.</p>
                    </div>
                    <div class="optimizer-legend" aria-label="Schedule graph legend">
                        <span class="optimizer-legend__charge">Charge</span>
                        <span class="optimizer-legend__idle">Idle</span>
                        <span class="optimizer-legend__discharge">Discharge</span>
                    </div>
                </div>
                <div class="optimizer-graph-pair">
                    <article class="optimizer-graph" aria-labelledby="optimizer-rules-graph-title">
                        <header>
                            <div>
                                <h3 id="optimizer-rules-graph-title">Rules</h3>
                                <p>Schedule currently resolved from rules and manual overrides</p>
                            </div>
                            <span class="gsd-badge">Current</span>
                        </header>
                        <div class="optimizer-graph__scroll" data-role="rules-graph-scroll" tabindex="0" aria-label="Scrollable rule-based schedule graph">
                            <div class="optimizer-graph__canvas" data-role="rules-graph"></div>
                        </div>
                    </article>
                    <article class="optimizer-graph" aria-labelledby="optimizer-plan-graph-title">
                        <header>
                            <div>
                                <h3 id="optimizer-plan-graph-title">Optimizer</h3>
                                <p>Schedule from the selected optimizer calculation</p>
                            </div>
                            <span class="gsd-badge gsd-badge--info">Selected run</span>
                        </header>
                        <div class="optimizer-graph__scroll" data-role="optimizer-graph-scroll" tabindex="0" aria-label="Scrollable optimizer schedule graph">
                            <div class="optimizer-graph__canvas" data-role="optimizer-graph"></div>
                        </div>
                    </article>
                </div>
                <p class="optimizer-graphs__note">Both graphs use the selected calculation's prices and forecast assumptions. Scrolling either graph keeps the hours aligned.</p>
            </section>

            <section class="gsd-card optimizer-comparison" aria-labelledby="comparison-title">
                <div class="gsd-card__header optimizer-comparison__header">
                    <div>
                        <h2 class="gsd-card__title" id="comparison-title">Hourly comparison</h2>
                        <p>Optimizer intent versus the currently resolved rules and manual schedule.</p>
                    </div>
                    <div class="optimizer-legend" aria-label="Power legend">
                        <span class="optimizer-legend__charge">Charge</span>
                        <span class="optimizer-legend__idle">Idle</span>
                        <span class="optimizer-legend__discharge">Discharge</span>
                    </div>
                </div>
                <div class="optimizer-table-wrap">
                    <table class="optimizer-table">
                        <thead>
                            <tr>
                                <th scope="col">Time</th>
                                <th scope="col">Optimizer</th>
                                <th scope="col">Rules</th>
                                <th scope="col">SoC</th>
                                <th scope="col">Prices</th>
                                <th scope="col">Forecast</th>
                            </tr>
                        </thead>
                        <tbody data-role="comparison-body"></tbody>
                    </table>
                </div>
            </section>

            <p class="optimizer-footnote" data-role="footnote"></p>
        </div>
    </main>
</body>
</html>
