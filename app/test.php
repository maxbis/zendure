<?php

declare(strict_types=1);

require_once __DIR__ . '/../login/validate.php';
require_once __DIR__ . '/../common/php/system_config.php';
require_once __DIR__ . '/../main/includes/config_loader.php';

$systemConfig = loadSystemConfig();
$timezone = new DateTimeZone($systemConfig['installation']['timezone']);
date_default_timezone_set($systemConfig['installation']['timezone']);

$defaultDate = (new DateTimeImmutable('yesterday', $timezone))->format('Ymd');
$requestedDate = isset($_GET['date']) ? preg_replace('/\D/', '', (string) $_GET['date']) : $defaultDate;
if (!is_string($requestedDate) || preg_match('/^\d{8}$/', $requestedDate) !== 1) {
    $requestedDate = $defaultDate;
}
$requestedSoc = isset($_GET['soc']) && is_numeric($_GET['soc']) ? (float) $_GET['soc'] : 50.0;
$requestedSoc = round(min(100, max(0, $requestedSoc)), 1);

$dateInput = DateTimeImmutable::createFromFormat('!Ymd', $requestedDate, $timezone);
$dateInputValue = $dateInput instanceof DateTimeImmutable
    ? $dateInput->format('Y-m-d')
    : (new DateTimeImmutable('yesterday', $timezone))->format('Y-m-d');
$maximumDate = (new DateTimeImmutable('yesterday', $timezone))->format('Y-m-d');

$appConfig = [
    'mode' => 'simulation',
    'scenarioDate' => $requestedDate,
    'startingBatteryPercent' => $requestedSoc,
    'backtestUrl' => '../main/api/backtest_schedule_api.php',
    'rulesUrl' => '../main/edit_rules.php?api=1',
    'priceConversion' => $systemConfig['priceConversion'],
    'minChargePercent' => $systemConfig['battery']['minChargePercent'],
    'maxChargePercent' => $systemConfig['battery']['maxChargePercent'],
    'capacityWh' => $systemConfig['battery']['capacityWh'],
    'batteryEfficiency' => $systemConfig['battery']['efficiency'],
    'forecastHouseholdUsageWByHour' => $systemConfig['forecast']['defaultHouseholdUsageWByHour'],
    'powerMinW' => $systemConfig['schedule']['minPowerW'],
    'powerMaxW' => $systemConfig['schedule']['maxPowerW'],
    'powerStepW' => $systemConfig['schedule']['powerStepW'],
    'solarLocation' => $systemConfig['installation'],
    'solarEvents' => [],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0d0f12">
    <title>Historical rule backtest · Zendure</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/icons/app-icon-32.png">
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
    <script src="assets/js/battery-forecast.js" defer></script>
    <script src="assets/js/price-plan.js" defer></script>
</head>
<body class="app-simulation-page" data-theme="graphite-signal-dark">
    <main class="app-shell">
        <header class="app-topbar">
            <div class="app-brand">
                <span class="app-brand__mark" aria-hidden="true">
                    <img src="assets/icons/app-icon-180.png" alt="">
                </span>
                <div class="app-brand__copy">
                    <h1 class="app-brand__title">Historical rule backtest</h1>
                    <p class="app-brand__meta">Current rules against archived prices</p>
                </div>
            </div>
            <a class="gsd-btn gsd-btn--secondary" href="./">Return to live app</a>
        </header>

        <section class="app-simulation-banner" role="status" aria-label="Simulation safety notice">
            <svg class="gsd-icon" aria-hidden="true">
                <use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#warning"></use>
            </svg>
            <div>
                <strong>Simulation — no live control</strong>
                <p>This page cannot save schedules, refresh automation, or send device commands.</p>
            </div>
        </section>

        <section class="gsd-card app-simulation-controls" aria-labelledby="simulation-controls-title">
            <div>
                <h2 id="simulation-controls-title">Scenario inputs</h2>
                <p>The selected battery level is applied at 00:00 on the first day.</p>
            </div>
            <form method="get" action="test.php">
                <label class="gsd-field">
                    <span class="gsd-field__label">Historical date</span>
                    <input class="gsd-input" type="date" name="date" value="<?= htmlspecialchars($dateInputValue, ENT_QUOTES, 'UTF-8'); ?>" max="<?= htmlspecialchars($maximumDate, ENT_QUOTES, 'UTF-8'); ?>" required>
                </label>
                <label class="gsd-field">
                    <span class="gsd-field__label">Starting battery</span>
                    <span class="app-input-with-unit">
                        <input class="gsd-input" type="number" name="soc" min="0" max="100" step="0.1" value="<?= htmlspecialchars((string) $requestedSoc, ENT_QUOTES, 'UTF-8'); ?>" required>
                        <span>%</span>
                    </span>
                </label>
                <button class="gsd-btn gsd-btn--primary" type="submit">Run backtest</button>
            </form>
        </section>

        <?php $appPricePlanReadOnly = true; require __DIR__ . '/partials/price-plan.php'; ?>

        <p class="app-simulation-note">
            This is a planning replay using the current saved rules. Exact net-zero watts and actual savings require historical P1 replay and are not calculated here.
        </p>
    </main>
</body>
</html>
