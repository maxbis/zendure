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
    <title>Scarcity overview · Zendure</title>
    <link rel="icon" type="image/svg+xml" href="assets/icons/scarcity-app-icon.svg">
    <link rel="stylesheet" href="../themes/graphite-signal-dark/assets/css/theme.css">
    <link rel="stylesheet" href="../themes/graphite-signal-dark/assets/css/components.css">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/optimizer-scarcity-v1.css?v=<?= (int)filemtime(__DIR__ . '/assets/css/optimizer-scarcity-v1.css'); ?>">
    <script>window.OPTIMIZER_SCARCITY_V1_CONFIG = <?= json_encode($pageConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
    <script src="../themes/graphite-signal-dark/assets/js/graphite-controls.js" defer></script>
    <script src="assets/js/optimizer-scarcity-v1.js?v=<?= (int)filemtime(__DIR__ . '/assets/js/optimizer-scarcity-v1.js'); ?>" defer></script>
</head>
<body data-theme="graphite-signal-dark">
    <div class="gsd-flash-region" data-gsd-flash-region aria-live="polite" aria-relevant="additions"></div>

    <main class="app-shell scarcity-v1" data-component="optimizer-scarcity-v1" data-state="loading" aria-busy="true">
        <header class="app-topbar">
            <div class="app-brand">
                <span class="app-brand__mark scarcity-v1__brand-mark" aria-hidden="true">
                    <img src="assets/icons/scarcity-app-icon.svg" alt="">
                </span>
                <div class="app-brand__copy">
                    <h1 class="app-brand__title">Scarcity</h1>
                    <p class="app-brand__meta">
                        <span data-role="page-date"><?= htmlspecialchars(date('l, j F'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <span aria-hidden="true"> · </span>
                        <span data-role="last-update">Waiting for optimizer data</span>
                    </p>
                </div>
            </div>
            <div class="app-topbar__actions">
                <span class="gsd-badge scarcity-v1__connection" data-role="connection-badge">Connecting</span>
                <button class="gsd-icon-btn" type="button" aria-label="Refresh scarcity overview" data-role="refresh">
                    <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#refresh"></use></svg>
                </button>
            </div>
        </header>

        <section class="gsd-card scarcity-v1__state" data-role="loading" role="status">
            <span class="app-loading-orb" aria-hidden="true"></span>
            <div><strong>Loading scarcity status</strong><p>Reading the latest optimizer schedule…</p></div>
        </section>

        <section class="gsd-card scarcity-v1__state scarcity-v1__state--error" data-role="error" role="alert" hidden>
            <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#error"></use></svg>
            <div><strong>Scarcity status unavailable</strong><p data-role="error-message">The optimizer data could not be loaded.</p></div>
            <button class="gsd-btn gsd-btn--secondary" type="button" data-role="retry">Try again</button>
        </section>

        <div class="scarcity-v1__content" data-role="content" hidden>
            <section class="gsd-card scarcity-v1__panel" aria-labelledby="scarcity-v1-status-title">
                <div class="scarcity-v1__panel-top">
                    <div class="scarcity-v1__title-row">
                        <button
                            class="scarcity-v1__band-dots-trigger"
                            type="button"
                            data-role="band-dots-trigger"
                            aria-label="Show all scarcity bands"
                            aria-haspopup="dialog"
                            aria-controls="scarcity-v1-bands-dialog"
                            aria-expanded="false"
                        >
                            <span class="scarcity-v1__band-dots" data-role="band-dots" aria-hidden="true"></span>
                        </button>
                        <h2 id="scarcity-v1-status-title">Scarcity bands</h2>
                    </div>
                    <span class="app-state-pill scarcity-v1__pill" data-role="status-pill">Checking</span>
                </div>

                <div class="scarcity-v1__status-overview">
                    <div class="scarcity-v1__status-main">
                        <span class="scarcity-v1__status-metric-label">Applied bands</span>
                        <strong class="scarcity-v1__status-value" data-role="status-value">—</strong>
                    </div>
                    <div class="scarcity-v1__first-limit" data-role="first-limit">
                        <span class="scarcity-v1__first-limit-label">First applied limit</span>
                        <strong data-role="first-limit-boundary">—</strong>
                        <span data-role="first-limit-price">Determining price…</span>
                    </div>
                </div>
                <p class="scarcity-v1__caption scarcity-v1__status-footer" data-role="status-caption">Waiting for the current activation input</p>
            </section>

            <section class="gsd-card scarcity-v1__panel scarcity-v1__battery-panel" aria-labelledby="scarcity-v1-battery-title">
                <div class="scarcity-v1__panel-top">
                    <div class="scarcity-v1__title-row">
                        <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#battery"></use></svg>
                        <h2 id="scarcity-v1-battery-title">Scheduled refill</h2>
                    </div>
                    <button
                        class="app-state-pill scarcity-v1__pill scarcity-v1__refill-trigger"
                        type="button"
                        data-role="refill-pill"
                        aria-label="Explain scheduled refill percentage"
                        aria-haspopup="dialog"
                        aria-controls="scarcity-v1-refill-dialog"
                        aria-expanded="false"
                    >—</button>
                </div>

                <div class="scarcity-v1__battery-main">
                    <strong class="scarcity-v1__battery-value" data-role="current-soc">—</strong>
                    <div class="scarcity-v1__battery" data-role="battery" role="meter" aria-label="Current and maximum scheduled battery level" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                        <span class="scarcity-v1__battery-segments" aria-hidden="true">
                            <?php for ($segment = 0; $segment < 10; $segment++): ?>
                                <span class="scarcity-v1__battery-segment" data-segment="<?= $segment; ?>"></span>
                            <?php endfor; ?>
                            <span class="scarcity-v1__battery-reserve" data-role="battery-reserve"></span>
                            <span class="scarcity-v1__battery-upper-buffer" data-role="battery-upper-buffer"></span>
                            <span class="scarcity-v1__battery-scarcity-layer" data-role="battery-scarcity-layer">
                                <?php for ($segment = 0; $segment < 10; $segment++): ?>
                                    <span class="scarcity-v1__battery-scarcity-clip" data-scarcity-segment="<?= $segment; ?>"></span>
                                <?php endfor; ?>
                            </span>
                        </span>
                    </div>
                </div>

                <div class="scarcity-v1__battery-legend" aria-label="Battery fill legend">
                    <span data-role="reserve-legend" hidden><i class="scarcity-v1__legend-key scarcity-v1__legend-key--reserve" aria-hidden="true"></i><span data-role="reserve-label">Operating range —</span></span>
                    <span><i class="scarcity-v1__legend-key scarcity-v1__legend-key--current" aria-hidden="true"></i><span data-role="current-label">Current —</span></span>
                    <span><i class="scarcity-v1__legend-key scarcity-v1__legend-key--scheduled" aria-hidden="true"></i><span data-role="scheduled-label">Scheduled —</span></span>
                    <span data-role="scarcity-legend" hidden><i class="scarcity-v1__legend-key scarcity-v1__legend-key--scarcity" aria-hidden="true"></i><span data-role="scarcity-label">Scarcity —</span></span>
                </div>
                <p class="scarcity-v1__caption" data-role="battery-caption">Waiting for the scheduled battery maximum</p>
            </section>

            <nav class="scarcity-v1__links" aria-label="Scarcity navigation">
                <a href="optimizer_scarcity.php">Full scarcity details</a>
                <a href="optimizer.php">Optimizer</a>
                <a href="./">Live app</a>
            </nav>
        </div>

        <dialog class="gsd-dialog scarcity-v1__bands-dialog" id="scarcity-v1-bands-dialog" aria-labelledby="scarcity-v1-bands-dialog-title">
            <header class="gsd-dialog__header gsd-dialog__header--simple">
                <h2 class="gsd-dialog__title" id="scarcity-v1-bands-dialog-title">Scarcity bands</h2>
                <button class="gsd-icon-btn" type="button" aria-label="Close scarcity bands" data-gsd-dialog-close>
                    <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#close"></use></svg>
                </button>
            </header>
            <div class="gsd-dialog__body scarcity-v1__bands-dialog-body">
                <div class="scarcity-v1__bands-list" data-role="bands-list"></div>
            </div>
        </dialog>

        <dialog class="gsd-dialog scarcity-v1__refill-dialog" id="scarcity-v1-refill-dialog" aria-labelledby="scarcity-v1-refill-dialog-title">
            <header class="gsd-dialog__header gsd-dialog__header--simple">
                <h2 class="gsd-dialog__title" id="scarcity-v1-refill-dialog-title">Scheduled refill</h2>
                <button class="gsd-icon-btn" type="button" aria-label="Close scheduled refill explanation" data-gsd-dialog-close>
                    <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#close"></use></svg>
                </button>
            </header>
            <div class="gsd-dialog__body scarcity-v1__refill-dialog-body">
                <p class="scarcity-v1__refill-explanation" data-role="refill-explanation">Waiting for the scheduled refill calculation.</p>
                <dl class="scarcity-v1__refill-facts">
                    <div><dt>Starting SoC</dt><dd data-role="refill-starting-soc">—</dd></div>
                    <div><dt>Maximum allowed</dt><dd data-role="refill-maximum-soc">—</dd></div>
                    <div><dt>Available gap</dt><dd data-role="refill-available-gap">—</dd></div>
                    <div><dt>Scheduled gain</dt><dd data-role="refill-scheduled-gain">—</dd></div>
                </dl>
                <p class="scarcity-v1__refill-equation" data-role="refill-equation">—</p>
            </div>
        </dialog>
    </main>
</body>
</html>
