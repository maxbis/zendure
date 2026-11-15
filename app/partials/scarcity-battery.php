<?php
/**
 * Reusable scarcity battery visualization.
 *
 * Data is populated client-side from app/api/optimizer_scarcity.php. Keep all
 * selectors scoped to the nearest [data-component="scarcity-battery"] root so
 * the visualization can be embedded in both a page card and a dialog.
 */
?>
<div class="scarcity-v1__battery-visual" data-component="scarcity-battery">
    <div class="scarcity-v1__battery-main">
        <strong class="scarcity-v1__battery-value" data-role="current-soc">—</strong>
        <div class="scarcity-v1__battery" data-role="battery" role="meter" aria-label="Current and maximum scheduled battery level" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
            <span class="scarcity-v1__battery-segments" aria-hidden="true">
                <?php for ($segment = 0; $segment < 10; $segment++): ?>
                    <span class="scarcity-v1__battery-segment" data-segment="<?= $segment; ?>"></span>
                <?php endfor; ?>
                <span class="scarcity-v1__battery-scarcity-layer" data-role="battery-scarcity-layer">
                    <?php for ($segment = 0; $segment < 10; $segment++): ?>
                        <span class="scarcity-v1__battery-scarcity-clip" data-scarcity-segment="<?= $segment; ?>"></span>
                    <?php endfor; ?>
                </span>
            </span>
        </div>
    </div>

    <div class="scarcity-v1__battery-legend" aria-label="Battery fill legend">
        <span data-role="reserve-legend" hidden><i class="scarcity-v1__legend-key scarcity-v1__legend-key--reserve" aria-hidden="true"></i><span data-role="reserve-label">Operating range —</span></span>
        <span><i class="scarcity-v1__legend-key scarcity-v1__legend-key--current" aria-hidden="true"></i><span data-role="current-label">Current —</span></span>
        <span><i class="scarcity-v1__legend-key scarcity-v1__legend-key--scheduled" aria-hidden="true"></i><span data-role="scheduled-label">Scheduled —</span></span>
        <span data-role="scarcity-legend" hidden><i class="scarcity-v1__legend-key scarcity-v1__legend-key--scarcity" aria-hidden="true"></i><span data-role="scarcity-label">Scarcity —</span></span>
    </div>
    <p class="scarcity-v1__caption" data-role="battery-caption">Waiting for the scheduled battery maximum</p>
</div>
