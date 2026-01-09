<!-- Price Overview Bar Graph Section - Full Width -->
<div class="price-graph-wrapper" style="margin-top: 20px;">
    <div class="price-graph-layout">
        <div class="card">
            <h2>Today (<?php echo htmlspecialchars($today); ?>)</h2>
            <div class="price-graph-row" id="price-graph-today"></div>
        </div>
        <?php 
        $currentHourInt = (int)date('H');
        if ($currentHourInt >= 15): 
        ?>
        <div class="card">
            <h2>Tomorrow (<?php echo htmlspecialchars(date('Ymd', strtotime('+1 day'))); ?>)</h2>
            <div class="price-graph-row" id="price-graph-tomorrow"></div>
        </div>
        <?php endif; ?>
    </div>
</div>
