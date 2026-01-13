<!-- Price Overview Bar Graph Section - Full Width -->
 <?php
    $today_modified = $today;
    if(strlen($today_modified) > 6) { 
        $today_modified = substr($today_modified, 0, 4) . '-' . substr($today_modified, 4, 2) . '-' . substr($today_modified, 6); 
    } elseif(strlen($today_modified) > 4) { 
        $today_modified = substr($today_modified, 0, 4) . '-' . substr($today_modified, 4); 
    }
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
        <?php 
        $currentHourInt = (int)date('H');
        if ($currentHourInt >= 15): 
        ?>
        <div class="card">
            <h2>Tomorrow <span style="font-size: 1rem; color: #d0d0d0;">(
                    <?= htmlspecialchars($today_modified); ?>
                )</span>
            </h2>
            <div class="price-graph-row" id="price-graph-tomorrow"></div>
        </div>
        <?php endif; ?>
    </div>
</div>
