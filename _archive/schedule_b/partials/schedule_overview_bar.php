
<?php
    $today_modified = $today;
    if(strlen($today_modified) > 6) { 
        $today_modified = substr($today_modified, 0, 4) . '-' . substr($today_modified, 4, 2) . '-' . substr($today_modified, 6); 
    } elseif(strlen($today_modified) > 4) { 
        $today_modified = substr($today_modified, 0, 4) . '-' . substr($today_modified, 4); 
    }
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
                    <?= htmlspecialchars($today_modified); ?>
                )</span>
            </h2>
            <div class="bar-graph-row" id="bar-graph-tomorrow"></div>
        </div>
    </div>
</div>
