<!-- Price Overview Bar Graph Section - Mobile (Today Only with Scrollbar) -->
<?php
$today_modified = $today;
if(strlen($today_modified) > 6) { 
    $today_modified = substr($today_modified, 0, 4) . '-' . substr($today_modified, 4, 2) . '-' . substr($today_modified, 6); 
} elseif(strlen($today_modified) > 4) { 
    $today_modified = substr($today_modified, 0, 4) . '-' . substr($today_modified, 4); 
}
?>
<div class="card">
    <h2>Today's Prices <span style="font-size: 0.85rem; color: var(--text-tertiary);">(<?= htmlspecialchars($today_modified); ?>)</span></h2>
    <div class="price-graph-row-mobile" id="price-graph-today"></div>
</div>
