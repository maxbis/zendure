<?php
/**
 * Zendure Energy Manager — Graphite Signal Dark application.
 *
 * This page intentionally reuses the existing authenticated backend endpoints
 * while keeping the new presentation independent from the legacy /main UI.
 */

date_default_timezone_set('Europe/Amsterdam');

require_once __DIR__ . '/../login/validate.php';
require_once __DIR__ . '/../main/includes/config_loader.php';

$configLoadError = ConfigLoader::getLoadError();
$appConfig = [
    'statusUrl' => '../main/api/charge_status_all_proxy.php',
    'refreshIntervalMs' => 20000,
    'staleAfterMs' => 90000,
    'minChargePercent' => (float) ConfigLoader::get('MIN_CHARGE_LEVEL', 20),
    'maxChargePercent' => (float) ConfigLoader::get('MAX_CHARGE_LEVEL', 90),
    'capacityWh' => (float) ConfigLoader::get('baseWh', 5760),
    'powerMinW' => (float) ConfigLoader::get('minGridPower', -1200),
    'powerMaxW' => (float) ConfigLoader::get('maxGridPower', 1200),
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0d0f12">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Zendure Energy Manager</title>
    <link rel="icon" type="image/png" sizes="32x32" href="../main/favicon-32x32.png">
    <link rel="apple-touch-icon" href="../main/apple-touch-icon.png">
    <link rel="stylesheet" href="../themes/graphite-signal-dark/assets/css/theme.css">
    <link rel="stylesheet" href="../themes/graphite-signal-dark/assets/css/components.css">
    <link rel="stylesheet" href="assets/css/app.css">
    <script>
        window.GRAPHITE_APP_CONFIG = <?= json_encode(
            $appConfig,
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ); ?>;
    </script>
    <script src="../themes/graphite-signal-dark/assets/js/graphite-controls.js" defer></script>
    <script src="assets/js/power-bar-scale.js" defer></script>
    <script src="assets/js/battery-color-scale.js" defer></script>
    <script src="assets/js/grid-exchange-color-scale.js" defer></script>
    <script src="assets/js/current-energy-status.js" defer></script>
</head>
<body data-theme="graphite-signal-dark">
    <div class="gsd-flash-region" data-gsd-flash-region aria-live="polite" aria-relevant="additions"></div>

    <main class="app-shell">
        <header class="app-topbar">
            <div class="app-brand">
                <span class="app-brand__mark" aria-hidden="true">
                    <svg class="gsd-icon">
                        <use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#bolt"></use>
                    </svg>
                </span>
                <div class="app-brand__copy">
                    <h1 class="app-brand__title">Zendure Energy Manager</h1>
                    <p class="app-brand__meta">
                        <span data-role="page-date"><?= htmlspecialchars(date('l, j F'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <span aria-hidden="true"> · </span>
                        <span data-role="last-update">Waiting for live data</span>
                    </p>
                </div>
            </div>

            <div class="app-topbar__actions">
                <span class="gsd-badge app-connection-badge" data-role="connection-badge">
                    Connecting
                </span>
                <button
                    class="gsd-icon-btn app-refresh"
                    type="button"
                    aria-label="Refresh energy status"
                    data-role="refresh"
                >
                    <svg class="gsd-icon" aria-hidden="true">
                        <use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#refresh"></use>
                    </svg>
                </button>
            </div>
        </header>

        <?php if ($configLoadError !== null): ?>
            <section class="app-config-error" role="alert">
                <svg class="gsd-icon" aria-hidden="true">
                    <use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#error"></use>
                </svg>
                <div>
                    <strong>Configuration error</strong>
                    <p><?= htmlspecialchars($configLoadError, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            </section>
        <?php else: ?>
            <section
                class="gsd-card app-energy"
                data-component="current-energy-status"
                data-state="loading"
                aria-labelledby="current-energy-title"
                aria-busy="true"
            >
                <h2 class="gsd-sr-only" id="current-energy-title">Current energy status</h2>

                <div class="app-energy__loading" data-role="loading-state" role="status">
                    <span class="app-loading-orb" aria-hidden="true"></span>
                    <div>
                        <strong>Loading live energy status</strong>
                        <p>Connecting to the existing energy controller…</p>
                    </div>
                </div>

                <div class="app-energy__error" data-role="error-state" role="alert" hidden>
                    <span class="app-state-icon" aria-hidden="true">
                        <svg class="gsd-icon">
                            <use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#error"></use>
                        </svg>
                    </span>
                    <div class="app-energy__error-copy">
                        <strong data-role="error-title">Energy status unavailable</strong>
                        <p data-role="error-message">The live controller could not be reached.</p>
                    </div>
                    <button class="gsd-btn gsd-btn--secondary" type="button" data-role="retry">
                        Try again
                    </button>
                </div>

                <div class="app-energy__content" data-role="energy-content" hidden>
                    <article class="app-compact-stat app-power-hero">
                        <div class="app-compact-stat__top">
                            <span class="app-compact-stat__label">
                                <span class="app-eyebrow__dot" data-role="mode-dot" aria-hidden="true"></span>
                                <span data-role="mode-label">Standby</span>
                            </span>
                            <span class="app-state-pill" data-role="freshness-label">Live</span>
                        </div>

                        <div class="app-compact-stat__reading app-power-reading">
                            <p class="app-compact-stat__value app-power-value" data-role="power-value">
                                0 <span class="app-power-value__unit">W</span>
                            </p>
                            <p class="app-compact-stat__hint app-power-copy" data-role="power-description">
                                No active battery power flow
                            </p>
                        </div>

                        <div
                            class="app-flow"
                            data-role="power-flow"
                            role="meter"
                            aria-label="Battery power"
                            aria-valuemin="-1600"
                            aria-valuemax="1600"
                            aria-valuenow="0"
                        >
                            <span class="app-flow__track app-flow__track--negative"></span>
                            <span class="app-flow__zero">0 W</span>
                            <span class="app-flow__track app-flow__track--positive"></span>
                            <span class="app-flow__fill" data-role="power-flow-fill" aria-hidden="true"></span>
                        </div>
                        <div class="app-flow__labels" aria-hidden="true">
                            <span data-role="power-min-label">−1600 W</span>
                            <span data-role="power-max-label">+1600 W</span>
                        </div>
                    </article>

                    <article class="app-compact-stat" data-state="loading">
                            <div class="app-compact-stat__top">
                                <span class="app-compact-stat__label">
                                    <svg class="gsd-icon" aria-hidden="true">
                                        <use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#battery"></use>
                                    </svg>
                                    Battery
                                </span>
                                <span class="app-target-label" data-role="battery-target">Target --%</span>
                            </div>
                            <div class="app-compact-stat__reading">
                                <p class="app-compact-stat__value" data-role="battery-percent">--%</p>
                                <span class="app-compact-stat__hint" data-role="battery-energy">Capacity unavailable</span>
                            </div>
                            <div
                                class="app-battery-progress"
                                data-role="battery-progress"
                                role="progressbar"
                                aria-label="Battery charge"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-valuenow="0"
                            >
                                <span class="app-battery-progress__fill" data-role="battery-progress-fill"></span>
                                <span class="app-battery-progress__min" data-role="battery-min-marker" aria-hidden="true"></span>
                                <span class="app-battery-progress__target" data-role="battery-target-marker" aria-hidden="true"></span>
                            </div>
                            <div class="app-battery-progress__labels">
                                <span data-role="battery-min-label">Minimum --%</span>
                                <span data-role="battery-target-label">Maximum --%</span>
                            </div>
                    </article>

                    <article class="app-compact-stat" data-state="loading">
                            <div class="app-compact-stat__top">
                                <span class="app-compact-stat__label">
                                    <svg class="gsd-icon" aria-hidden="true">
                                        <use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#grid"></use>
                                    </svg>
                                    Grid exchange
                                </span>
                                <span class="app-state-pill" data-role="grid-state">Unknown</span>
                            </div>
                            <div class="app-compact-stat__reading">
                                <p class="app-compact-stat__value" data-role="grid-power">-- W</p>
                                <span class="app-compact-stat__hint" data-role="grid-description">Waiting for P1 data</span>
                            </div>
                            <div
                                class="app-grid-flow"
                                data-role="grid-flow"
                                role="meter"
                                aria-label="Grid exchange"
                                aria-valuemin="-1600"
                                aria-valuemax="1600"
                                aria-valuenow="0"
                            >
                                <span class="app-grid-flow__center" aria-hidden="true"></span>
                                <span class="app-grid-flow__fill" data-role="grid-flow-fill" aria-hidden="true"></span>
                            </div>
                            <div class="app-grid-flow__labels" aria-hidden="true">
                                <span data-role="grid-min-label">−1600 W</span>
                                <span>0</span>
                                <span data-role="grid-max-label">+1600 W</span>
                            </div>
                    </article>
                </div>
            </section>
        <?php endif; ?>

        <section class="app-next-step" aria-label="Implementation status">
            <span>Graphite Signal Dark migration</span>
            <strong>Step 1 · Live energy status</strong>
            <p>Prices, energy graphs, automation, and scheduling remain in the legacy application for now.</p>
            <a class="gsd-btn gsd-btn--quiet" href="../main/charge_schedule_mobile.php">
                Open legacy application
            </a>
        </section>
    </main>
</body>
</html>
