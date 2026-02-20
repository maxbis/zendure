<?php
/**
 * Energy Graph Partial - Mobile Version
 * Tabs: Graph (Wh per hour) and Daily totals. Dark mode styling.
 * Graph: today + 3 days (same as desktop). Table: today + 7 days (8 lines, scroll).
 */
?>

<div class="card energy-graph-mobile">
    <h3 class="card-header">Energy per Hour <span class="energy-unit">(Wh)</span></h3>
    <div class="energy-graph-mobile-tabs" role="tablist">
        <button type="button" class="energy-graph-mobile-tab active" data-tab="graph" role="tab" aria-selected="true">Graph</button>
        <button type="button" class="energy-graph-mobile-tab" data-tab="daily" role="tab" aria-selected="false">Daily totals</button>
    </div>
    <div class="energy-graph-mobile-tab-panels">
        <div class="energy-graph-mobile-tab-panel active" data-tab="graph" role="tabpanel" aria-hidden="false">
            <div class="energy-graph-canvas-mobile">
                <canvas id="energyChartMobile"></canvas>
            </div>
        </div>
        <div class="energy-graph-mobile-tab-panel" data-tab="daily" role="tabpanel" aria-hidden="true">
            <h3 class="card-header">Daily totals</h3>
            <div class="energy-graph-mobile-daily-table">
                <p class="energy-graph-mobile-no-data">Loading…</p>
            </div>
        </div>
    </div>
</div>
<script>
    (function() {
        var tabs = document.querySelectorAll('.energy-graph-mobile-tab');
        var panels = document.querySelectorAll('.energy-graph-mobile-tab-panel');
        tabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                var targetTab = this.getAttribute('data-tab');
                tabs.forEach(function(t) {
                    t.classList.toggle('active', t.getAttribute('data-tab') === targetTab);
                    t.setAttribute('aria-selected', t.getAttribute('data-tab') === targetTab ? 'true' : 'false');
                });
                panels.forEach(function(panel) {
                    var isActive = panel.getAttribute('data-tab') === targetTab;
                    panel.classList.toggle('active', isActive);
                    panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
                });
                if (targetTab === 'graph' && window.energyChartMobile) {
                    window.energyChartMobile.resize();
                }
            });
        });
    })();
</script>
