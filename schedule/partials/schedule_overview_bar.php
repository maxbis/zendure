<!-- Bar Graph Section - Full Width -->
<div class="bar-graph-wrapper" style="margin-top: 20px;">
    <div class="card">
        <h2>Schedule Overview</h2>
        <div class="bar-graph-container">
            <div class="bar-graph-day">
                <div class="bar-graph-day-label">Today (<?php echo htmlspecialchars($today); ?>)</div>
                <div class="bar-graph-row" id="bar-graph-today"></div>
            </div>
            <div class="bar-graph-day">
                <div class="bar-graph-day-label">Tomorrow
                    (<?php echo htmlspecialchars(date('Ymd', strtotime('+1 day'))); ?>)</div>
                <div class="bar-graph-row" id="bar-graph-tomorrow"></div>
            </div>
        </div>
    </div>
</div>
