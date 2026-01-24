<!-- Price Overview Bar Graph Section - Full Width -->
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
<div class="price-graph-wrapper" style="margin-top: 20px;">
    <div class="price-graph-layout">
        <div class="card">
            <h2>Today <span style="font-size: 1rem; color: #d0d0d0;">(
                    <?= htmlspecialchars($today_modified); ?>
                )</span>
            </h2>
            <div class="price-graph-row" id="price-graph-today"></div>
        </div>
        <div class="card" id="tomorrow-price-card-desktop" style="display: none;">
            <h2>Tomorrow <span style="font-size: 1rem; color: #d0d0d0;">(
                    <?= htmlspecialchars($tomorrow_modified); ?>
                )</span>
            </h2>
            <div class="price-graph-row" id="price-graph-tomorrow"></div>
        </div>
    </div>
</div>
