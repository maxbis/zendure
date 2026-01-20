
<?php
    $tz = new DateTimeZone(date_default_timezone_get() ?: 'Europe/Amsterdam');
    $todayDigits = preg_replace('/\D/', '', (string)($today ?? ''));
    $todayDt = null;
    if (strlen($todayDigits) === 8) {
        $todayDt = DateTimeImmutable::createFromFormat('Ymd', $todayDigits, $tz) ?: null;
    }
    if (!$todayDt) {
        $todayDt = new DateTimeImmutable('today', $tz);
    }

    $today_modified = $todayDt->format('Y-m-d');
    $tomorrow_modified = $todayDt->modify('+1 day')->format('Y-m-d');
?>

<!-- Bar Graph Section - Full Width -->
<div class="bar-graph-wrapper" style="margin-top: 20px;">
    <div class="bar-graph-layout">
        <div class="card">
            <h2>Today <span style="font-size: 1rem; color: #d0d0d0;">(
                        <?= htmlspecialchars($today_modified); ?>
                    )</span>
            </h2>
            <div class="bar-graph-row" id="bar-graph-today"></div>
        </div>
        <div class="card">
            <h2>Tomorrow <span style="font-size: 1rem; color: #d0d0d0;">(
                    <?= htmlspecialchars($tomorrow_modified); ?>
                )</span>
            </h2>
            <div class="bar-graph-row" id="bar-graph-tomorrow"></div>
        </div>
    </div>
</div>
