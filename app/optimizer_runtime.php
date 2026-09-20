<?php

declare(strict_types=1);

require_once __DIR__ . '/../login/validate.php';
require_once __DIR__ . '/../common/php/system_config.php';
require_once __DIR__ . '/includes/optimizer_runtime_log.php';
require_once __DIR__ . '/includes/optimizer_runtime_grid_accuracy.php';

$systemConfig = loadSystemConfig();
$timezone = new DateTimeZone($systemConfig['installation']['timezone']);
$now = new DateTimeImmutable('now', $timezone);
date_default_timezone_set($timezone->getName());

$allowedViews = ['hours', 'samples', 'all'];
$allowedPeriods = ['24h', 'today', '7d', 'date', 'all'];
$allowedLimits = [25, 50, 100, 250];
$view = in_array($_GET['view'] ?? '', $allowedViews, true) ? (string) $_GET['view'] : 'hours';
$period = in_array($_GET['period'] ?? '', $allowedPeriods, true) ? (string) $_GET['period'] : '24h';
$limit = in_array((int) ($_GET['limit'] ?? 50), $allowedLimits, true) ? (int) $_GET['limit'] : 50;
$date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['date'])
    ? (string) $_GET['date']
    : null;
if ($period === 'date' && $date === null) {
    $date = $now->format('Y-m-d');
}

$logPath = optimizerRuntimeLogDefaultPath();
$logExists = is_file($logPath) && is_readable($logPath);
$result = optimizerRuntimeLogRead($logPath, $timezone, $now, $view, $period, $date, $limit);

function rtEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rtNumber(mixed $value, int $digits = 1): string
{
    return is_numeric($value) ? number_format((float) $value, $digits, '.', ',') : '—';
}

function rtSigned(mixed $value, string $unit, int $digits = 1): string
{
    if (!is_numeric($value)) {
        return '—';
    }
    $number = (float) $value;
    $sign = $number > 0 ? '+' : ($number < 0 ? '−' : '');
    return $sign . number_format(abs($number), $digits, '.', ',') . ' ' . $unit;
}

function rtPower(mixed $value): string
{
    return is_numeric($value) ? number_format((float) $value, 0, '.', ',') . ' W' : '—';
}

function rtEnergy(mixed $value): string
{
    return is_numeric($value) ? number_format((float) $value, 0, '.', ',') . ' Wh' : '—';
}

function rtSoc(mixed $value): string
{
    return is_numeric($value) ? number_format((float) $value, 1, '.', ',') . '%' : '—';
}

function rtSchedule(array $event, string $prefix): string
{
    $value = $event[$prefix . '_schedule'] ?? null;
    if ($value === null) {
        return '—';
    }
    if (is_numeric($value)) {
        return rtSigned($value, 'W', 0);
    }
    $labels = ['netzero+' => 'NZ+', 'netzero-' => 'NZ−', 'netzero' => 'NZ±'];
    return $labels[(string) $value] ?? (string) $value;
}

function rtLocalTime(mixed $value, DateTimeZone $timezone, string $format): string
{
    if (!is_string($value) || $value === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($value))->setTimezone($timezone)->format($format);
    } catch (Exception) {
        return '—';
    }
}

function rtGridDirection(mixed $value): string
{
    if (!is_numeric($value) || (float) $value === 0.0) {
        return '';
    }
    return (float) $value > 0 ? ' import' : ' export';
}

$viewLabels = ['hours' => 'Hour summaries', 'samples' => '15-minute samples', 'all' => 'All events'];
$periodLabels = ['24h' => 'Last 24 hours', 'today' => 'Today', '7d' => 'Last 7 days', 'date' => 'Specific date', 'all' => 'All history'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0d0f12">
    <title>Optimizer runtime · Zendure</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/icons/app-icon-32.png">
    <link rel="stylesheet" href="../themes/graphite-signal-dark/assets/css/theme.css">
    <link rel="stylesheet" href="../themes/graphite-signal-dark/assets/css/components.css">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/optimizer-runtime.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/optimizer-runtime.css'); ?>">
</head>
<body data-theme="graphite-signal-dark">
    <main class="app-shell runtime-shell">
        <header class="app-topbar runtime-topbar">
            <div class="app-brand">
                <span class="app-brand__mark" aria-hidden="true"><img src="assets/icons/app-icon-180.png" alt=""></span>
                <div class="app-brand__copy">
                    <h1 class="app-brand__title">Optimizer runtime</h1>
                    <p class="app-brand__meta">
                        <?php if ($result['latest_at'] !== null): ?>Latest event <?= rtEscape(rtLocalTime($result['latest_at'], $timezone, 'D j M, H:i:s')); ?><?php else: ?>Waiting for runtime events<?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="app-topbar__actions">
                <a class="gsd-btn gsd-btn--secondary" href="optimizer.php">Optimizer</a>
                <a class="gsd-btn gsd-btn--secondary runtime-live-link" href="./">Live app</a>
            </div>
        </header>

        <form class="gsd-card runtime-filters" method="get" aria-label="Runtime log filters">
            <label class="gsd-field">
                <span class="gsd-field__label">Show</span>
                <select class="gsd-select" name="view">
                    <?php foreach ($viewLabels as $value => $label): ?>
                        <option value="<?= rtEscape($value); ?>" <?= $view === $value ? 'selected' : ''; ?>><?= rtEscape($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="gsd-field">
                <span class="gsd-field__label">Period</span>
                <select class="gsd-select" name="period" data-role="runtime-period">
                    <?php foreach ($periodLabels as $value => $label): ?>
                        <option value="<?= rtEscape($value); ?>" <?= $period === $value ? 'selected' : ''; ?>><?= rtEscape($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="gsd-field" data-role="runtime-date-field" <?= $period === 'date' ? '' : 'hidden'; ?>>
                <span class="gsd-field__label">Date</span>
                <input class="gsd-input" type="date" name="date" value="<?= rtEscape($date ?? $now->format('Y-m-d')); ?>" <?= $period === 'date' ? '' : 'disabled'; ?>>
            </label>
            <label class="gsd-field">
                <span class="gsd-field__label">Maximum items</span>
                <select class="gsd-select" name="limit">
                    <?php foreach ($allowedLimits as $value): ?>
                        <option value="<?= $value; ?>" <?= $limit === $value ? 'selected' : ''; ?>><?= $value; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="gsd-btn gsd-btn--primary" type="submit">Apply filters</button>
        </form>

        <section class="runtime-summary" aria-label="Log summary">
            <article class="gsd-card runtime-summary__item"><span>Selection</span><strong><?= rtEscape($viewLabels[$view]); ?></strong><small><?= rtEscape($periodLabels[$period]); ?></small></article>
            <article class="gsd-card runtime-summary__item"><span>Matching events</span><strong><?= number_format($result['matching_count']); ?></strong><small>Showing up to <?= $limit; ?></small></article>
            <article class="gsd-card runtime-summary__item"><span>Journal health</span><strong><?= $result['invalid_lines'] === 0 ? 'Healthy' : rtEscape((string) $result['invalid_lines']) . ' invalid'; ?></strong><small><?= number_format($result['scanned_lines']); ?> lines scanned</small></article>
        </section>

        <?php if (!$logExists): ?>
            <section class="gsd-card runtime-empty" role="status">
                <h2>No runtime journal yet</h2>
                <p>The file will be created at the next successful optimizer cron run.</p>
                <code><?= rtEscape($logPath); ?></code>
            </section>
        <?php elseif ($result['events'] === []): ?>
            <section class="gsd-card runtime-empty" role="status">
                <h2>No events match these filters</h2>
                <p>Choose a broader period or select all event types.</p>
            </section>
        <?php else: ?>
            <section class="runtime-events" aria-label="Runtime events">
                <?php foreach ($result['events'] as $event): ?>
                    <?php
                    $type = (string) $event['event'];
                    $isClosed = $type === 'HOUR_CLOSED';
                    $isSample = $type === 'SAMPLE';
                    $title = $isClosed ? 'Hour completed' : ($isSample ? 'Runtime sample' : 'Hour forecast opened');
                    $timeSource = $isClosed ? ($event['hour_start'] ?? null) : ($event['observed_at'] ?? null);
                    $gridForecastStatus = $isClosed
                        ? rtGridForecastStatus($event['actual_grid_wh'] ?? null, $event['predicted_grid_wh'] ?? null)
                        : null;
                    ?>
                    <article class="gsd-card runtime-event" data-event="<?= rtEscape(strtolower($type)); ?>">
                        <header class="runtime-event__header">
                            <div>
                                <span class="runtime-event__eyebrow"><?= rtEscape(rtLocalTime($timeSource, $timezone, 'D j M')); ?></span>
                                <h2><?= rtEscape($title); ?></h2>
                                <p>
                                    <?php if ($isClosed): ?>
                                        <?= rtEscape(rtLocalTime($event['hour_start'] ?? null, $timezone, 'H:i')); ?>–<?= rtEscape(rtLocalTime($event['hour_end'] ?? null, $timezone, 'H:i')); ?>
                                    <?php else: ?>
                                        Measured <?= rtEscape(rtLocalTime($event['observed_at'] ?? null, $timezone, 'H:i:s')); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <?php if ($isClosed): ?>
                                <span class="gsd-badge <?= ($event['status'] ?? '') === 'complete' ? 'gsd-badge--positive' : 'gsd-badge--warning'; ?>"><?= rtEscape(ucfirst((string) ($event['status'] ?? 'unknown'))); ?></span>
                            <?php elseif ($isSample): ?>
                                <span class="gsd-badge <?= ($event['measurement_status'] ?? '') === 'fresh' ? 'gsd-badge--positive' : 'gsd-badge--warning'; ?>"><?= rtEscape(ucfirst((string) ($event['measurement_status'] ?? 'unknown'))); ?></span>
                            <?php else: ?>
                                <span class="gsd-badge gsd-badge--info">Forecast</span>
                            <?php endif; ?>
                        </header>

                        <?php if ($isClosed): ?>
                            <div class="runtime-metrics">
                                <div><span>Household</span><strong><?= rtEnergy($event['actual_usage_wh'] ?? null); ?></strong><small>Forecast <?= rtEnergy($event['predicted_usage_wh'] ?? null); ?> · error <?= rtSigned($event['usage_error_wh'] ?? null, 'Wh', 0); ?></small></div>
                                <div><span>Solar</span><strong><?= rtEnergy($event['actual_solar_wh'] ?? null); ?></strong><small>Forecast <?= rtEnergy($event['predicted_solar_wh'] ?? null); ?></small></div>
                                <div>
                                    <span>Grid exchange</span>
                                    <strong><?= rtSigned($event['actual_grid_wh'] ?? null, 'Wh', 0); ?><?= rtEscape(rtGridDirection($event['actual_grid_wh'] ?? null)); ?></strong>
                                    <small>
                                        <?php if ($gridForecastStatus !== null): ?>
                                            <span
                                                class="runtime-grid-forecast-dot"
                                                data-status="<?= rtEscape($gridForecastStatus); ?>"
                                                role="img"
                                                aria-label="<?= rtEscape(rtGridForecastStatusLabel($gridForecastStatus)); ?>"
                                            ></span>
                                        <?php endif; ?>
                                        Forecast <?= rtSigned($event['predicted_grid_wh'] ?? null, 'Wh', 0); ?><?= rtEscape(rtGridDirection($event['predicted_grid_wh'] ?? null)); ?>
                                    </small>
                                </div>
                                <div><span>Battery at close</span><strong><?= rtSoc($event['actual_soc'] ?? null); ?></strong><small>Forecast <?= rtSoc($event['predicted_end_soc'] ?? null); ?> · error <?= rtSigned($event['soc_error_ppt'] ?? null, 'ppt'); ?></small></div>
                            </div>
                            <footer class="runtime-event__footer"><?= rtNumber($event['coverage_pct'] ?? null); ?>% measurement coverage · <?= (int) ($event['samples'] ?? 0); ?> samples · closed <?= rtNumber($event['closed_late_s'] ?? null, 0); ?>s after boundary</footer>
                        <?php else: ?>
                            <div class="runtime-schedules">
                                <section>
                                    <span>Current schedule</span>
                                    <strong><?= rtEscape(rtSchedule($event, 'current')); ?></strong>
                                    <small>Expected battery <?= rtSigned($event['current_expected_battery_w'] ?? null, 'W', 0); ?> · end SoC <?= rtSoc($event['current_predicted_end_soc'] ?? null); ?></small>
                                </section>
                                <section>
                                    <span>Next schedule</span>
                                    <strong><?= rtEscape(rtSchedule($event, 'next')); ?></strong>
                                    <small>Expected battery <?= rtSigned($event['next_expected_battery_w'] ?? null, 'W', 0); ?> · end SoC <?= rtSoc($event['next_predicted_end_soc'] ?? null); ?></small>
                                </section>
                            </div>
                            <?php if ($isSample): ?>
                                <div class="runtime-metrics runtime-metrics--sample">
                                    <div><span>Household</span><strong><?= rtPower($event['actual_household_w'] ?? null); ?></strong><small>Forecast <?= rtPower($event['current_predicted_load_w'] ?? null); ?></small></div>
                                    <div><span>Solar</span><strong><?= rtPower($event['actual_solar_w'] ?? null); ?></strong><small>Forecast <?= rtPower($event['current_predicted_solar_w'] ?? null); ?></small></div>
                                    <div><span>Grid exchange</span><strong><?= rtSigned($event['actual_grid_w'] ?? null, 'W', 0); ?><?= rtEscape(rtGridDirection($event['actual_grid_w'] ?? null)); ?></strong><small>Forecast <?= rtSigned($event['current_predicted_grid_w'] ?? null, 'W', 0); ?><?= rtEscape(rtGridDirection($event['current_predicted_grid_w'] ?? null)); ?></small></div>
                                    <div><span>Battery</span><strong><?= rtSigned($event['actual_battery_w'] ?? null, 'W', 0); ?></strong><small>SoC <?= rtSoc($event['actual_soc'] ?? null); ?></small></div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </main>
    <script>
        (() => {
            const period = document.querySelector('[data-role="runtime-period"]');
            const dateField = document.querySelector('[data-role="runtime-date-field"]');
            const dateInput = dateField?.querySelector('input');
            if (!period || !dateField || !dateInput) return;
            const updateDateVisibility = () => {
                const visible = period.value === 'date';
                dateField.hidden = !visible;
                dateInput.disabled = !visible;
            };
            period.addEventListener('change', updateDateVisibility);
            updateDateVisibility();
        })();
    </script>
</body>
</html>
