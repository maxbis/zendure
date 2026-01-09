<!-- Bar Graph Section - Full Width -->
<div class="bar-graph-wrapper" style="margin-top: 20px;">
    <div class="bar-graph-layout">
        <div class="card">
            <h2>Today (<?php echo htmlspecialchars($today); ?>)</h2>
            <div class="bar-graph-row" id="bar-graph-today"></div>
        </div>
        <div class="card">
            <h2>Tomorrow (<?php echo htmlspecialchars(date('Ymd', strtotime('+1 day'))); ?>)</h2>
            <div class="bar-graph-row" id="bar-graph-tomorrow"></div>
        </div>
    </div>
</div>
