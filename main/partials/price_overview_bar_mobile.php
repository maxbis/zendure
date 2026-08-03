<!-- Price Overview Bar Graph Section - Mobile (Today and Tomorrow with Scrollbar) -->
<?php
$today_modified = $today;
if(strlen($today_modified) > 6) { 
    $today_modified = substr($today_modified, 0, 4) . '-' . substr($today_modified, 4, 2) . '-' . substr($today_modified, 6); 
} elseif(strlen($today_modified) > 4) { 
    $today_modified = substr($today_modified, 0, 4) . '-' . substr($today_modified, 4); 
}

// Calculate tomorrow's date for display
$tz = new DateTimeZone(date_default_timezone_get());
$todayDigits = preg_replace('/\D/', '', (string)($today ?? ''));
$todayDt = null;
if (strlen($todayDigits) === 8) {
    $todayDt = DateTimeImmutable::createFromFormat('Ymd', $todayDigits, $tz) ?: null;
}
if (!$todayDt) {
    $todayDt = new DateTimeImmutable('today', $tz);
}
$tomorrow_modified = $todayDt->modify('+1 day')->format('Y-m-d');
?>
<div class="price-cards">
    <div class="card">
        <div class="price-graph-card-header">
            <h3 class="card-header">💲 Today's Prices <span style="font-size: 0.85rem; color: var(--text-tertiary);">(<?= htmlspecialchars($today_modified); ?>)</span></h3>
            <button
                type="button"
                class="price-graph-zoom-toggle"
                data-price-graph-zoom-toggle
                aria-pressed="false"
                aria-controls="price-graph-today price-graph-tomorrow-mobile">
                Zoom
            </button>
        </div>
        <div class="price-graph-row-mobile" id="price-graph-today"></div>
    </div>
    <div class="card" id="tomorrow-price-card-mobile" style="display: none;">
        <h3 class="card-header">💲 Tomorrow's Prices <span style="font-size: 0.85rem; color: var(--text-tertiary);">(<?= htmlspecialchars($tomorrow_modified); ?>)</span></h3>
        <div class="price-graph-row-mobile" id="price-graph-tomorrow-mobile"></div>
    </div>
</div>
