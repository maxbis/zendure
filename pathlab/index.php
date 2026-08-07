<?php
declare(strict_types=1);

require_once __DIR__ . '/../common/php/system_config.php';

$systemConfig = loadSystemConfig();
date_default_timezone_set($systemConfig['installation']['timezone']);

$minChargeLevel = (int) $systemConfig['battery']['minChargePercent'];
$maxChargeLevel = (int) $systemConfig['battery']['maxChargePercent'];
$baseWh = (int) $systemConfig['battery']['capacityWh'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PathLab</title>
    <link rel="stylesheet" href="assets/css/pathlab.css">
</head>
<body>
    <main class="pathlab-shell">
        <section class="hero-card">
            <div class="hero-copy">
                <p class="eyebrow">Prototype</p>
                <h1>PathLab</h1>
                <p class="hero-text">
                    A separate, read-only battery path view for today and tomorrow.
                    It combines solar forecast and recent hourly discharge behavior into one expected SoC path.
                </p>
            </div>
            <div class="hero-meta">
                <div class="meta-pill">
                    <span class="meta-label">Battery window</span>
                    <span class="meta-value"><?php echo htmlspecialchars((string) $minChargeLevel, ENT_QUOTES, 'UTF-8'); ?>% to <?php echo htmlspecialchars((string) $maxChargeLevel, ENT_QUOTES, 'UTF-8'); ?>%</span>
                </div>
                <div class="meta-pill">
                    <span class="meta-label">Base capacity</span>
                    <span class="meta-value"><?php echo htmlspecialchars(number_format($baseWh / 1000, 2), ENT_QUOTES, 'UTF-8'); ?> kWh</span>
                </div>
            </div>
        </section>

        <section class="status-grid">
            <article class="summary-card summary-card--primary">
                <div class="summary-label">Current SoC</div>
                <div class="summary-value" data-role="current-soc">--</div>
                <div class="summary-subtle" data-role="current-time">Waiting for live data</div>
            </article>
            <article class="summary-card">
                <div class="summary-label">Expected Now</div>
                <div class="summary-value" data-role="expected-soc">--</div>
                <div class="summary-subtle" data-role="effective-lookback">Looking back -- days</div>
            </article>
            <article class="summary-card">
                <div class="summary-label">Path Delta</div>
                <div class="summary-value" data-role="delta-soc">--</div>
                <div class="summary-badge" data-role="status-badge">Loading</div>
            </article>
        </section>

        <section class="chart-card">
            <div class="chart-card__header">
                <div>
                    <p class="chart-kicker">Expected Battery Path</p>
                    <h2>Today and tomorrow</h2>
                </div>
                <div class="chart-legend">
                    <span><i class="legend-swatch legend-swatch--line legend-swatch--path"></i>Expected path</span>
                    <span><i class="legend-swatch legend-swatch--line legend-swatch--actual-path"></i>Actual path</span>
                    <span><i class="legend-swatch legend-swatch--actual"></i>Actual now</span>
                    <span><i class="legend-swatch legend-swatch--solar"></i>Solar pressure</span>
                    <span><i class="legend-swatch legend-swatch--usage"></i>Usage pressure</span>
                </div>
            </div>
            <div class="chart-status" data-role="chart-status">Loading model...</div>
            <div class="chart-wrap" data-role="chart-wrap" hidden>
                <svg class="pathlab-chart" data-role="chart" viewBox="0 0 1200 420" preserveAspectRatio="none" aria-label="Expected battery path chart"></svg>
            </div>
        </section>

        <section class="detail-grid">
            <article class="detail-card">
                <h3>Model Notes</h3>
                <ul class="detail-list">
                    <li>Green line shows the expected path for today and tomorrow.</li>
                    <li>Amber line shows the actual measured SoC so far today.</li>
                    <li>Charge pressure comes from normalized hourly shortwave radiation.</li>
                    <li>Discharge pressure comes from hourly median discharge energy of recent days.</li>
                    <li>This page is advisory only and does not change schedule or automation behavior.</li>
                </ul>
            </article>
            <article class="detail-card">
                <h3>Live Inputs</h3>
                <dl class="metric-list">
                    <div>
                        <dt>History window</dt>
                        <dd class="metric-list__compound">
                            <span data-role="lookback-days">--</span>
                            <span class="metric-list__helper" data-role="valid-lookback-days">Valid days used: --</span>
                        </dd>
                    </div>
                    <div>
                        <dt>Solar peak</dt>
                        <dd data-role="solar-peak">--</dd>
                    </div>
                    <div>
                        <dt>Day-start anchor</dt>
                        <dd data-role="anchor-soc">--</dd>
                    </div>
                </dl>
            </article>
        </section>
    </main>

    <script src="assets/js/constants.js"></script>
    <script>
        window.PATHLAB_BOOT = {
            minChargeLevel: <?php echo json_encode($minChargeLevel, JSON_UNESCAPED_SLASHES); ?>,
            maxChargeLevel: <?php echo json_encode($maxChargeLevel, JSON_UNESCAPED_SLASHES); ?>
        };
    </script>
    <script src="assets/js/pathlab.js"></script>
</body>
</html>
