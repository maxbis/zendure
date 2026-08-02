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
    'refreshIntervalMs' => 20000,
    'staleAfterMs' => 90000,
    'minChargePercent' => $systemConfig['battery']['minChargePercent'] ?? null,
    'maxChargePercent' => $systemConfig['battery']['maxChargePercent'] ?? null,
    'capacityWh' => $systemConfig['battery']['capacityWh'] ?? null,
    'powerMinW' => (float) ConfigLoader::get('minGridPower', -1200),
    'powerMaxW' => (float) ConfigLoader::get('maxGridPower', 1200),
    'scheduleUrl' => ConfigLoader::get(
        'scheduleApiUrl',
        '../main/api/charge_schedule_api.php'
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0d0f12">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Zendure Energy Manager</title>
    <link rel="icon" type="image/png" sizes="32x32" href="../main/favicon-32x32.png">
    <link rel="apple-touch-icon" href="../main/apple-touch-icon.png">
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
                            <span class="app-state-pill" data-role="freshness-label">Live</span>
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
                                    <span class="app-state-pill" data-role="power-simple-freshness">Live</span>
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

        <section
            class="gsd-card app-price-plan"
            data-component="price-plan"
            data-state="loading"
            aria-labelledby="price-plan-title"
            aria-busy="true"
        >
            <header class="app-section-heading">
                <div>
                    <h2 id="price-plan-title">Prices &amp; energy plan</h2>
                    <p data-role="price-plan-date">Loading today and tomorrow</p>
                </div>
                <div class="app-section-heading__actions">
                    <span class="app-tomorrow-status" data-role="tomorrow-status">
                        <span class="app-day-availability" data-role="tomorrow-availability" data-availability="loading" aria-hidden="true"></span>
                        <span data-role="tomorrow-status-label">Checking tomorrow</span>
                    </span>
                    <button class="gsd-icon-btn app-price-refresh" type="button" aria-label="Refresh prices and energy plan" data-role="price-refresh">
                        <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#refresh"></use></svg>
                    </button>
                </div>
            </header>

            <div class="app-price-plan__loading" data-role="price-loading" role="status">
                <span class="app-loading-orb" aria-hidden="true"></span>
                <span>Loading prices and resolved schedule</span>
            </div>

            <div class="app-price-plan__error" data-role="price-error" role="alert" hidden>
                <span data-role="price-error-message">Price and schedule data could not be loaded.</span>
                <button class="gsd-btn gsd-btn--secondary" type="button" data-role="price-retry">Try again</button>
            </div>

            <div class="app-price-plan__content" data-role="price-content" hidden>
                <div class="app-price-summary" aria-label="Price summary for the visible planning horizon">
                    <button class="app-price-kpi" type="button" data-role="price-current-kpi" disabled>
                        <span data-role="price-current-label">Current</span>
                        <strong data-role="price-current">—</strong>
                    </button>
                    <button class="app-price-kpi" type="button" data-role="price-low-kpi" disabled>
                        <span>Horizon low</span>
                        <strong class="app-price-kpi--low" data-role="price-low">—</strong>
                    </button>
                    <button class="app-price-kpi" type="button" data-role="price-high-kpi" disabled>
                        <span>Horizon high</span>
                        <strong class="app-price-kpi--high" data-role="price-high">—</strong>
                    </button>
                </div>

                <div class="app-price-timeline-scroll" data-role="price-scroll" tabindex="0" aria-label="Scrollable hourly price and schedule timeline">
                    <div class="app-price-timeline" data-role="price-timeline"></div>
                </div>

                <div class="app-price-legend" aria-label="Timeline legend">
                    <span><i class="app-price-legend__swatch app-price-legend__swatch--low"></i>Low price</span>
                    <span><i class="app-price-legend__swatch app-price-legend__swatch--current"></i>Current hour</span>
                    <span><i class="app-price-legend__swatch app-price-legend__swatch--high"></i>High price</span>
                    <span><i class="app-price-legend__swatch app-price-legend__swatch--plan"></i>Scheduled action</span>
                    <span><i class="app-price-legend__swatch app-price-legend__swatch--limited" aria-hidden="true"></i>Limit value</span>
                </div>

            </div>
        </section>

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

                <div class="app-energy-history__chart-scroll" data-role="energy-chart-scroll" tabindex="0" aria-label="Scrollable hourly battery energy chart for the last four days">
                    <div class="app-energy-history__chart" data-role="energy-chart" role="group" aria-label="Hourly charged and discharged battery energy with battery level for the last four days"></div>
                </div>

                <div class="app-energy-history__detail" data-role="energy-hour-detail" aria-live="polite" hidden>
                    <span data-role="energy-detail-time">Select an hour</span>
                    <strong data-role="energy-detail-flow">Explore the chart for exact values</strong>
                    <span data-role="energy-detail-battery">Battery level appears when available</span>
                </div>

                <p class="app-energy-history__summary-period" data-role="energy-summary-period" aria-live="polite">Today totals · through now</p>
                <div class="app-energy-history__summary" data-role="energy-summary" aria-label="Energy and price totals for today">
                    <div>
                        <span class="app-energy-history__summary-title">Charged</span>
                        <strong class="gsd-positive" data-role="energy-total-charged">—</strong>
                        <div class="app-energy-history__prices">
                            <p><span>Consumer</span><strong data-role="energy-charged-consumer">—</strong></p>
                            <p><span>Spot</span><strong data-role="energy-charged-spot">—</strong></p>
                        </div>
                    </div>
                    <div>
                        <span class="app-energy-history__summary-title">Discharged</span>
                        <strong class="gsd-negative" data-role="energy-total-discharged">—</strong>
                        <div class="app-energy-history__prices">
                            <p><span>Consumer</span><strong data-role="energy-discharged-consumer">—</strong></p>
                            <p><span>Spot</span><strong data-role="energy-discharged-spot">—</strong></p>
                        </div>
                    </div>
                    <div>
                        <span class="app-energy-history__summary-title">Net</span>
                        <strong data-role="energy-total-net">—</strong>
                        <div class="app-energy-history__prices">
                            <p><span>Consumer</span><strong data-role="energy-net-consumer">—</strong></p>
                            <p><span>Spot</span><strong data-role="energy-net-spot">—</strong></p>
                        </div>
                    </div>
                </div>

                <p class="app-energy-history__status" data-role="energy-history-status" hidden></p>
            </div>
        </section>

        <section class="app-next-step" aria-label="Implementation status">
            <span>Graphite Signal Dark migration</span>
            <strong>Step 3 · Energy history</strong>
            <p>Automation remains in the legacy application for now.</p>
            <a class="gsd-btn gsd-btn--quiet" href="../main/charge_schedule_mobile.php">
                Open legacy application
            </a>
        </section>
    </main>

    <dialog class="gsd-dialog app-schedule-edit-dialog" id="app-schedule-edit-dialog" aria-labelledby="app-schedule-edit-title">
        <header class="gsd-dialog__header gsd-dialog__header--simple">
            <div class="app-schedule-edit-dialog__heading">
                <h2 class="gsd-dialog__title" id="app-schedule-edit-title" data-role="schedule-edit-title">Edit hourly override</h2>
                <p class="app-schedule-edit-dialog__price" data-role="schedule-edit-price-summary">Price (— / —)</p>
            </div>
            <button class="gsd-icon-btn" type="button" aria-label="Close dialog" title="Close without saving changes" data-gsd-dialog-close>
                <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#close"></use></svg>
            </button>
        </header>
        <form data-role="schedule-edit-form">
            <div class="gsd-dialog__body">
                <fieldset class="app-edit-fieldset">
                    <legend>Battery action</legend>
                    <div class="app-mode-options">
                        <label title="Balance household load using battery discharge only"><input type="radio" name="schedule-mode" value="netzero-"><span><span class="app-mode-option__heading"><svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#bolt"></use></svg><b class="app-netzero-token">NZ<span class="app-netzero-sign">−</span></b></span>Discharge-only</span></label>
                        <label title="Balance household load using battery charging or discharging"><input type="radio" name="schedule-mode" value="netzero"><span><span class="app-mode-option__heading"><svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#bidirectional"></use></svg><b class="app-netzero-token">NZ<span class="app-netzero-sign">±</span></b></span>Bidirectional</span></label>
                        <label title="Balance household load using battery charging only"><input type="radio" name="schedule-mode" value="netzero+"><span><span class="app-mode-option__heading"><svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#sun"></use></svg><b class="app-netzero-token">NZ<span class="app-netzero-sign">+</span></b></span>Charge-only</span></label>
                        <label title="Set a constant battery power value for this hour"><input type="radio" name="schedule-mode" value="fixed"><span><span class="app-mode-option__heading"><svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#battery"></use></svg><b>W</b></span>Fixed power</span></label>
                        <label title="Let the controller choose the battery action automatically"><input type="radio" name="schedule-mode" value="auto"><span><span class="app-mode-option__heading"><svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#refresh"></use></svg><b>A</b></span>Automatic</span></label>
                    </div>
                </fieldset>

                <div class="gsd-field" data-role="schedule-fixed-field" hidden>
                    <label class="gsd-field__label" for="schedule-edit-watts">Fixed power</label>
                    <div class="app-input-with-unit">
                        <input class="gsd-input" id="schedule-edit-watts" name="watts" type="number" step="100" inputmode="numeric">
                        <span>W</span>
                    </div>
                    <small>Positive charges the battery; negative discharges it.</small>
                    <div class="app-fixed-power-panel">
                        <div class="app-limit-value app-fixed-power-value">
                            <span>Value</span>
                            <strong data-role="schedule-fixed-display">0 W</strong>
                        </div>
                        <div class="app-fixed-slider" data-role="schedule-fixed-slider">
                            <span class="app-fixed-slider__track" aria-hidden="true"></span>
                            <span class="app-fixed-slider__selection" data-role="schedule-fixed-selection" aria-hidden="true"></span>
                            <input type="range" step="100" data-role="schedule-fixed-range" aria-label="Fixed power value">
                        </div>
                        <p class="app-fixed-power-summary" data-role="schedule-fixed-summary">Idle: 0 W</p>
                    </div>
                </div>

                <div class="app-limit-editor" data-role="schedule-limit-editor">
                    <div class="app-limit-toggle" role="group" aria-label="Apply explicit power limits">
                        <label title="Do not apply explicit minimum or maximum power limits"><input type="radio" name="limits-enabled" value="off" data-role="schedule-limits-disabled"><span>Off</span></label>
                        <label title="Apply the selected minimum and maximum power limits"><input type="radio" name="limits-enabled" value="on" data-role="schedule-limits-enabled"><span>On</span></label>
                    </div>
                    <div class="app-limit-editor__fields" data-role="schedule-limit-fields" hidden>
                        <input name="minimum-power" type="hidden">
                        <input name="maximum-power" type="hidden">
                        <div class="app-limit-values">
                            <div class="app-limit-value">
                                <span>Min</span>
                                <strong data-role="schedule-limit-min-display">—</strong>
                            </div>
                            <div class="app-limit-value">
                                <span>Max</span>
                                <strong data-role="schedule-limit-max-display">—</strong>
                            </div>
                        </div>
                        <div class="app-limit-slider" data-role="schedule-limit-slider">
                            <span class="app-limit-slider__track" aria-hidden="true"></span>
                            <span class="app-limit-slider__selection" data-role="schedule-limit-selection" aria-hidden="true"></span>
                            <input type="range" step="100" data-role="schedule-limit-min-range" aria-label="Minimum power limit">
                            <input type="range" step="100" data-role="schedule-limit-max-range" aria-label="Maximum power limit">
                        </div>
                    </div>
                    <p class="app-limit-editor__summary" data-role="schedule-limit-summary"></p>
                </div>

                <p class="app-edit-error" data-role="schedule-edit-error" role="alert" hidden></p>
            </div>
            <footer class="gsd-dialog__footer">
                <button class="gsd-btn gsd-btn--secondary" type="button" title="Close without saving changes" data-gsd-dialog-close>Cancel</button>
                <button class="gsd-btn gsd-btn--primary" type="submit" title="Save this hourly override" data-role="schedule-edit-save">Save schedule</button>
            </footer>
        </form>
    </dialog>
</body>
</html>
