<!-- Price Overview Bar Graph Section - Full Width -->
<div class="price-graph-wrapper" style="margin-top: 20px;">
    <div class="card">
        <h2>Price Overview</h2>
        <div class="price-graph-container">
            <div class="price-graph-day">
                <div class="price-graph-day-label">Today (<?php echo htmlspecialchars($today); ?>)</div>
                <div class="price-graph-row" id="price-graph-today"></div>
            </div>
            <?php 
            $currentHourInt = (int)date('H');
            if ($currentHourInt >= 15): 
            ?>
            <div class="price-graph-day">
                <div class="price-graph-day-label">Tomorrow
                    (<?php echo htmlspecialchars(date('Ymd', strtotime('+1 day'))); ?>)</div>
                <div class="price-graph-row" id="price-graph-tomorrow"></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
