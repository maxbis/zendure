<?php
/**
 * Zendure Energy Manager — Graphite Signal Dark application.
 *
 * This page intentionally reuses the existing authenticated backend endpoints
 * while keeping the new presentation independent from the legacy /main UI.
 */

require_once __DIR__ . '/../login/validate.php';
require_once __DIR__ . '/../common/php/system_config.php';
require_once __DIR__ . '/../main/includes/config_loader.php';

$systemConfig = null;
$systemConfigLoadError = null;
try {
    $systemConfig = loadSystemConfig();
} catch (SystemConfigException $error) {
    $systemConfigLoadError = $error->getMessage();
}

date_default_timezone_set($systemConfig['installation']['timezone'] ?? 'UTC');

$configurationErrors = [];
if ($systemConfigLoadError !== null) {
    $configurationErrors[] = 'Shared system configuration: ' . $systemConfigLoadError;
}
$webConfigLoadError = ConfigLoader::getLoadError();
if ($webConfigLoadError !== null) {
    $configurationErrors[] = 'Web configuration: ' . $webConfigLoadError;
}
$configLoadError = $configurationErrors === [] ? null : implode(' ', $configurationErrors);
$reloadToken = isset($_GET['_reload']) && preg_match('/^\d{10,16}$/', (string) $_GET['_reload'])
    ? (string) $_GET['_reload']
    : null;

function appAssetUrl(string $path, ?string $reloadToken): string
{
    if ($reloadToken === null) {
        return $path;
    }

    return $path . (str_contains($path, '?') ? '&' : '?') . 'reload=' . rawurlencode($reloadToken);
}

/**
 * Build exact local sunrise and sunset times for the dates the timeline can show.
 * An extra day keeps the values available if an open page crosses midnight.
 */
function buildAppSolarEvents(array $location): array
{
    $timezone = new DateTimeZone($location['timezone']);
    $today = new DateTimeImmutable('today', $timezone);
    $dates = [];

    for ($offset = 0; $offset <= 2; $offset++) {
        $date = $today->modify('+' . $offset . ' days');
        $sunInfo = date_sun_info(
            $date->setTime(12, 0)->getTimestamp(),
            (float) $location['latitude'],
            (float) $location['longitude']
        );
        if (!is_array($sunInfo)) {
            continue;
        }

        $events = [];
        foreach (['sunrise', 'sunset'] as $eventName) {
            $timestamp = $sunInfo[$eventName] ?? null;
            if (!is_int($timestamp) || $timestamp <= 0) {
                continue;
            }
            $eventDate = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
            $events[$eventName] = [
                'time' => $eventDate->format('H:i'),
                'minuteOfDay' => (((int) $eventDate->format('H')) * 60) + (int) $eventDate->format('i'),
            ];
        }

        if ($events !== []) {
            $dates[$date->format('Ymd')] = $events;
        }
    }

    return $dates;
}

$appConfig = [
    'statusUrl' => '../main/api/charge_status_all_proxy.php',
    'automationStatusUrl' => '../main/api/automation_status_proxy.php?type=all&limit=20',
    'refreshIntervalMs' => 20000,
    'boostRefreshIntervalMs' => 5100,
    'boostTickCount' => 8,
    'scheduleDisplayRefreshMs' => 300000,
    'staleAfterMs' => 90000,
    'pendingWindowSeconds' => 35,
    'pendingMatchToleranceW' => 50,
    'minChargePercent' => $systemConfig['battery']['minChargePercent'] ?? null,
    'maxChargePercent' => $systemConfig['battery']['maxChargePercent'] ?? null,
    'capacityWh' => $systemConfig['battery']['capacityWh'] ?? null,
    'powerMinW' => $systemConfig['schedule']['minPowerW'] ?? null,
    'powerMaxW' => $systemConfig['schedule']['maxPowerW'] ?? null,
    'powerStepW' => $systemConfig['schedule']['powerStepW'] ?? null,
    'scheduleUrl' => ConfigLoader::get(
        'scheduleApiUrl',
        '../main/data/api/data_api.php?type=schedule&resolved=1'
    ),
    'scheduleRefreshUrl' => '../main/api/refresh_schedule_proxy.php',
    'rulesUrl' => '../main/edit_rules.php?api=1',
    'priceUrls' => ConfigLoader::get('priceApiUrl', []),
    'priceConversion' => $systemConfig['priceConversion'] ?? null,
    'energyHistoryUrl' => '../main/api/app_energy_history.php?days=3',
    'solarLocation' => $systemConfig['installation'] ?? null,
    'solarEvents' => $systemConfig === null ? [] : buildAppSolarEvents($systemConfig['installation']),
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0d0f12">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Zendure">
    <title>Zendure Energy Manager</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/icons/app-icon-32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/icons/app-icon-180.png">
    <link rel="stylesheet" href="<?= htmlspecialchars(appAssetUrl('../themes/graphite-signal-dark/assets/css/theme.css', $reloadToken), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(appAssetUrl('../themes/graphite-signal-dark/assets/css/components.css', $reloadToken), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(appAssetUrl('assets/css/app.css', $reloadToken), ENT_QUOTES, 'UTF-8'); ?>">
    <script>
        window.GRAPHITE_APP_CONFIG = <?= json_encode(
            $appConfig,
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ); ?>;
    </script>
    <script src="<?= htmlspecialchars(appAssetUrl('../themes/graphite-signal-dark/assets/js/graphite-controls.js', $reloadToken), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <script src="<?= htmlspecialchars(appAssetUrl('assets/js/power-bar-scale.js', $reloadToken), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <script src="<?= htmlspecialchars(appAssetUrl('assets/js/battery-color-scale.js', $reloadToken), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <script src="<?= htmlspecialchars(appAssetUrl('assets/js/grid-exchange-color-scale.js', $reloadToken), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <script src="<?= htmlspecialchars(appAssetUrl('assets/js/health-metric-color-scale.js', $reloadToken), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <script src="<?= htmlspecialchars(appAssetUrl('assets/js/current-energy-status.js', $reloadToken), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <script src="<?= htmlspecialchars(appAssetUrl('assets/js/price-plan.js', $reloadToken), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <script src="<?= htmlspecialchars(appAssetUrl('assets/js/energy-history.js', $reloadToken), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
</head>
<body data-theme="graphite-signal-dark">
    <div class="gsd-flash-region" data-gsd-flash-region aria-live="polite" aria-relevant="additions"></div>

    <main class="app-shell">
        <header class="app-topbar">
            <div class="app-brand">
                <span class="app-brand__mark" aria-hidden="true">
                    <img src="assets/icons/app-icon-180.png" alt="">
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
                    aria-label="Refresh energy status. Press and hold to reload the app and styles"
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
                    <article class="app-compact-stat app-power-hero app-power-card" data-flipped="false">
                        <div class="app-power-card__inner">
                            <div class="app-power-card__face app-power-card__face--front" data-role="power-front">
                        <div class="app-compact-stat__top">
                            <button
                                class="app-compact-stat__label app-title-view-toggle app-power-view-toggle"
                                type="button"
                                data-role="power-view-toggle"
                                aria-label="Show simplified charging status view"
                                aria-pressed="false"
                            >
                                <span class="app-eyebrow__dot" data-role="mode-dot" aria-hidden="true"></span>
                                <span data-role="mode-label">Standby</span>
                            </button>
                            <button
                                class="app-state-pill"
                                type="button"
                                data-role="freshness-label"
                                aria-label="Start fast status refresh"
                            >
                                Live
                            </button>
                        </div>

                        <div class="app-compact-stat__reading app-power-reading">
                            <p class="app-compact-stat__value app-power-value" data-role="power-value">
                                0 <span class="app-power-value__unit">W</span>
                            </p>
                        </div>

                        <div class="app-power-flow-display">
                            <span
                                class="app-flow"
                                data-role="power-flow"
                                role="meter"
                                aria-label="Battery power"
                                aria-valuemin="-1600"
                                aria-valuemax="1600"
                                aria-valuenow="0"
                            >
                                <span class="app-flow__track app-flow__track--negative">
                                    <span
                                        class="app-flow__fill app-flow__fill--negative"
                                        data-role="power-flow-fill-negative"
                                        aria-hidden="true"
                                    ></span>
                                </span>
                                <span class="app-flow__track app-flow__track--positive">
                                    <span
                                        class="app-flow__fill app-flow__fill--positive"
                                        data-role="power-flow-fill-positive"
                                        aria-hidden="true"
                                    ></span>
                                </span>
                            </span>
                        </div>
                        <div class="app-flow__labels" aria-hidden="true">
                            <span data-role="power-min-label">−1600 W</span>
                            <span class="app-flow__label-zero">0</span>
                            <span data-role="power-max-label">+1600 W</span>
                        </div>
                            </div>

                            <div class="app-power-card__face app-power-card__face--back" inert>
                                <span class="app-power-simple__top">
                                    <button
                                        class="app-compact-stat__label app-title-view-toggle app-power-view-toggle"
                                        type="button"
                                        data-role="power-view-toggle"
                                        aria-label="Show detailed charging status view"
                                        aria-pressed="false"
                                        tabindex="-1"
                                    >
                                        <span class="app-eyebrow__dot" aria-hidden="true"></span>
                                        <span data-role="power-simple-mode">Standby</span>
                                    </button>
                                    <button
                                        class="app-state-pill"
                                        type="button"
                                        data-role="power-simple-freshness"
                                        aria-label="Start fast status refresh"
                                        tabindex="-1"
                                    >
                                        Live
                                    </button>
                                </span>
                                <span class="app-power-simple__content">
                                    <strong class="app-power-simple__value" data-role="power-simple-value">0 <span class="app-simple-value__unit">W</span></strong>
                                    <span class="app-power-flow-icon" data-role="power-simple-flow" data-direction="standby" aria-hidden="true">
                                        <span class="app-power-flow-icon__segments">
                                            <?php for ($segment = 1; $segment <= 10; $segment++): ?>
                                                <span
                                                    class="app-power-flow-icon__segment"
                                                    data-power-segment="<?= $segment; ?>"
                                                    style="--app-segment-delay: <?= ($segment - 1) * 60; ?>ms"
                                                >
                                                    <svg class="gsd-icon">
                                                        <use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#chevron-right"></use>
                                                    </svg>
                                                </span>
                                            <?php endfor; ?>
                                        </span>
                                        <span class="app-power-flow-icon__battery">
                                            <svg class="gsd-icon">
                                                <use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#battery"></use>
                                            </svg>
                                        </span>
                                    </span>
                                </span>
                                <span class="app-power-simple__caption" data-role="power-simple-caption">No active battery power flow</span>
                            </div>
                        </div>
                    </article>

                    <article class="app-compact-stat app-battery-card" data-state="loading" data-flipped="false">
                        <div class="app-battery-card__inner">
                            <div class="app-battery-card__face app-battery-card__face--front" data-role="battery-front">
                            <div class="app-compact-stat__top">
                                <button
                                    class="app-compact-stat__label app-title-view-toggle app-battery-view-toggle"
                                    type="button"
                                    data-role="battery-view-toggle"
                                    aria-label="Show simplified battery view"
                                    aria-pressed="false"
                                >
                                    <svg class="gsd-icon" aria-hidden="true">
                                        <use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#battery"></use>
                                    </svg>
                                    Battery
                                </button>
                                <button class="app-target-label app-battery-dialog-trigger" type="button" data-role="battery-target" disabled>Estimating</button>
                            </div>
                            <div class="app-compact-stat__reading">
                                <p class="app-compact-stat__value" data-role="battery-percent">--%</p>
                                <span class="app-compact-stat__hint" data-role="battery-energy">Capacity unavailable</span>
                            </div>
                            <div class="app-battery-progress-display">
                                <span
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
                                </span>
                            </div>
                            <div class="app-battery-progress__labels">
                                <span data-role="battery-min-label">Minimum --%</span>
                                <span data-role="battery-target-label">Maximum --%</span>
                            </div>
                            </div>

                            <div
                                class="app-battery-card__face app-battery-card__face--back"
                                data-role="battery-back"
                                inert
                            >
                                <span class="app-battery-simple__top">
                                    <button
                                        class="app-compact-stat__label app-battery-simple__label app-title-view-toggle app-battery-view-toggle"
                                        type="button"
                                        data-role="battery-view-toggle"
                                        aria-label="Show detailed battery view"
                                        aria-pressed="false"
                                        tabindex="-1"
                                    >
                                        <svg class="gsd-icon" aria-hidden="true">
                                            <use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#battery"></use>
                                        </svg>
                                        Battery
                                    </button>
                                    <button class="app-target-label app-battery-dialog-trigger" type="button" data-role="battery-simple-target" disabled>Estimating</button>
                                </span>
                                <span class="app-battery-simple__content">
                                    <strong class="app-battery-simple__percent" data-battery-simple-percent>--<span class="app-simple-value__unit">%</span></strong>
                                    <button class="app-battery-icon app-battery-dialog-trigger" type="button" data-role="battery-icon" aria-label="Show battery energy details" disabled>
                                        <span class="app-battery-icon__segments">
                                            <?php for ($segment = 1; $segment <= 10; $segment++): ?>
                                                <span class="app-battery-icon__segment" data-battery-segment="<?= $segment; ?>"></span>
                                            <?php endfor; ?>
                                        </span>
                                    </button>
                                </span>
                                <span class="app-battery-simple__range" data-role="battery-simple-range">
                                    Operating range --%–--%
                                </span>
                            </div>
                        </div>
                    </article>

                    <article class="app-compact-stat app-grid-card" data-state="loading" data-flipped="false">
                        <div class="app-grid-card__inner">
                            <div class="app-grid-card__face app-grid-card__face--front" data-role="grid-front">
                            <div class="app-compact-stat__top">
                                <button
                                    class="app-compact-stat__label app-title-view-toggle app-grid-view-toggle"
                                    type="button"
                                    data-role="grid-view-toggle"
                                    aria-label="Show simplified grid exchange view"
                                    aria-pressed="false"
                                >
                                    <svg class="gsd-icon" aria-hidden="true">
                                        <use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#grid-pylon"></use>
                                    </svg>
                                    Grid exchange
                                </button>
                                <span class="app-state-pill" data-role="grid-state">Unknown</span>
                            </div>
                            <div class="app-compact-stat__reading">
                                <p class="app-compact-stat__value" data-role="grid-power">-- W</p>
                                <span class="app-compact-stat__hint" data-role="grid-description">Waiting for P1 data</span>
                            </div>
                            <div class="app-grid-flow-display">
                                <span
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
                                </span>
                            </div>
                            <div class="app-grid-flow__labels" aria-hidden="true">
                                <span data-role="grid-min-label">−1600 W</span>
                                <span>0</span>
                                <span data-role="grid-max-label">+1600 W</span>
                            </div>
                            </div>

                            <div class="app-grid-card__face app-grid-card__face--back" inert>
                                <span class="app-grid-simple__top">
                                    <button
                                        class="app-compact-stat__label app-title-view-toggle app-grid-view-toggle"
                                        type="button"
                                        data-role="grid-view-toggle"
                                        aria-label="Show detailed grid exchange view"
                                        aria-pressed="false"
                                        tabindex="-1"
                                    >
                                        <svg class="gsd-icon" aria-hidden="true">
                                            <use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#grid-pylon"></use>
                                        </svg>
                                        Grid exchange
                                    </button>
                                    <span class="app-state-pill" data-role="grid-simple-state">Unknown</span>
                                </span>
                                <span class="app-grid-simple__content">
                                    <strong class="app-grid-simple__value" data-role="grid-simple-value">-- <span class="app-simple-value__unit">W</span></strong>
                                    <span class="app-grid-flow-icon" data-role="grid-simple-flow" data-direction="balanced" aria-hidden="true">
                                        <span class="app-grid-flow-icon__segments">
                                            <?php for ($segment = 1; $segment <= 10; $segment++): ?>
                                                <span
                                                    class="app-grid-flow-icon__segment"
                                                    data-grid-segment="<?= $segment; ?>"
                                                    style="--app-segment-delay: <?= ($segment - 1) * 60; ?>ms"
                                                >
                                                    <svg class="gsd-icon">
                                                        <use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#chevron-right"></use>
                                                    </svg>
                                                </span>
                                            <?php endfor; ?>
                                        </span>
                                        <span class="app-grid-flow-icon__grid">
                                            <svg class="gsd-icon">
                                                <use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#grid-pylon"></use>
                                            </svg>
                                        </span>
                                    </span>
                                </span>
                                <span class="app-grid-simple__caption" data-role="grid-simple-caption">Waiting for P1 data</span>
                            </div>
                        </div>
                    </article>
                </div>
            </section>
        <?php endif; ?>

        <?php $appPricePlanReadOnly = false; require __DIR__ . '/partials/price-plan.php'; ?>

        <section
            class="gsd-card app-energy-history"
            data-component="energy-history"
            data-state="loading"
            aria-labelledby="energy-history-title"
            aria-busy="true"
        >
            <header class="app-section-heading app-energy-history__heading">
                <div>
                    <h2 id="energy-history-title">Battery energy</h2>
                    <p data-role="energy-history-date">Loading recent battery activity</p>
                </div>
                <div class="app-section-heading__actions">
                    <button class="gsd-icon-btn app-energy-history__refresh" type="button" aria-label="Refresh battery energy history" data-role="energy-history-refresh">
                        <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#refresh"></use></svg>
                    </button>
                </div>
            </header>

            <div class="app-energy-history__loading" data-role="energy-history-loading" role="status">
                <span class="app-loading-orb" aria-hidden="true"></span>
                <span>Loading hourly battery energy</span>
            </div>

            <div class="app-energy-history__error" data-role="energy-history-error" role="alert" hidden>
                <span data-role="energy-history-error-message">Battery energy history could not be loaded.</span>
                <button class="gsd-btn gsd-btn--secondary" type="button" data-role="energy-history-retry">Try again</button>
            </div>

            <div class="app-energy-history__content" data-role="energy-history-content" hidden>
                <div class="app-energy-history__legend" aria-label="Energy chart legend">
                    <span><i class="app-energy-history__key app-energy-history__key--charged" aria-hidden="true"></i>Charged</span>
                    <span><i class="app-energy-history__key app-energy-history__key--discharged" aria-hidden="true"></i>Discharged</span>
                    <span><i class="app-energy-history__key app-energy-history__key--battery" aria-hidden="true"></i>Battery level</span>
                    <span><i class="app-energy-history__key app-energy-history__key--now" aria-hidden="true"></i>Now</span>
                </div>

                <div class="app-chart-scroll-shell" data-role="energy-chart-scroll-shell">
                    <button
                        class="app-chart-scroll-btn app-chart-scroll-btn--prev"
                        type="button"
                        data-role="energy-chart-scroll-prev"
                        aria-label="Scroll battery energy chart left"
                        tabindex="-1"
                        hidden
                    >
                        <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#chevron-left"></use></svg>
                    </button>
                    <button
                        class="app-chart-scroll-btn app-chart-scroll-btn--next"
                        type="button"
                        data-role="energy-chart-scroll-next"
                        aria-label="Scroll battery energy chart right"
                        tabindex="-1"
                        hidden
                    >
                        <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#chevron-right"></use></svg>
                    </button>
                    <div class="app-energy-history__chart-scroll" data-role="energy-chart-scroll" tabindex="0" aria-label="Scrollable hourly battery energy chart for the last four days">
                        <div class="app-energy-history__chart" data-role="energy-chart" role="group" aria-label="Hourly charged and discharged battery energy with battery level for the last four days"></div>
                    </div>
                </div>

                <p class="app-energy-history__summary-period" data-role="energy-summary-period" aria-live="polite">Today totals · through now</p>
                <div class="app-energy-history__summary" data-role="energy-summary" aria-label="Energy and price totals for today">
                    <button type="button" data-role="energy-charged-summary" aria-expanded="false">
                        <span class="app-energy-history__summary-title">Charged</span>
                        <strong class="gsd-positive" data-role="energy-total-charged">—</strong>
                    </button>
                    <button type="button" data-role="energy-discharged-summary" aria-expanded="false">
                        <span class="app-energy-history__summary-title">Discharged</span>
                        <strong class="gsd-negative" data-role="energy-total-discharged">—</strong>
                    </button>
                    <button type="button" data-role="energy-pnl-summary" aria-expanded="false">
                        <span class="app-energy-history__summary-title">PnL</span>
                        <strong data-role="energy-total-pnl">—</strong>
                    </button>
                </div>

                <p class="app-energy-history__status" data-role="energy-history-status" hidden></p>
            </div>
        </section>

    </main>

    <?php
    $gsdFooterMoreSpriteHref = '../themes/graphite-signal-dark/assets/icons/sprite.svg';
    $gsdFooterMoreItems = [
        [
            'href' => '../main/',
            'label' => 'Open Old GUI',
            'description' => 'Schedules, rules, and automation controls',
            'icon' => 'settings',
        ],
    ];
    include dirname(__DIR__) . '/themes/graphite-signal-dark/partials/footer-more.php';
    ?>

</body>
</html>
