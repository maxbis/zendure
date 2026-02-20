<?php
/**
 * Schedule Panels Partial - Mobile Version
 * One card with two tabs: Schedule (today/tomorrow) and Schedule entries.
 */

// Helper functions for rendering
function getTimeClass($h)
{
    return ($h >= 22 || $h < 6) ? 'time-night' : (($h < 12) ? 'time-morning' : (($h < 18) ? 'time-afternoon' : 'time-evening'));
}

function getValueLabel($val)
{
    if ($val === null)
        return '-';
    if ($val === 'netzero')
        return 'Net Zero';
    if ($val === 'netzero+')
        return 'Solar Charge';
    if (is_numeric($val))
        return ($val > 0 ? '+' : '') . intval($val) . ' W';
    return $val . ' W';
}
?>
<div class="layout">
<div class="card schedule-mobile-card">
    <h3 class="card-header">Schedule</h3>
    <div class="schedule-mobile-tabs" role="tablist">
        <button type="button" class="schedule-mobile-tab active" data-tab="schedule" role="tab" aria-selected="true">Schedule</button>
        <button type="button" class="schedule-mobile-tab" data-tab="entries" role="tab" aria-selected="false">Schedule entries</button>
    </div>
    <div class="schedule-mobile-tab-panels">
        <!-- Tab 1: Today's and Tomorrow's Schedule -->
        <div class="schedule-mobile-tab-panel active" data-tab="schedule" role="tabpanel" aria-hidden="false">
            <div class="schedule-days-container">
                <!-- Today's Schedule (Left) -->
                <div class="schedule-day">
                    <div class="schedule-day-header">
                        <h3 class="card-header">Today <?php echo substr($today, -2); ?></h3>
                    </div>
                    <div class="schedule-list" id="today-schedule-grid">
                        <?php
                        $prevVal = null;
                        // First pass: collect displayed slots to find the active one
                        $displayedSlots = [];
                        foreach ($resolvedToday as $slot) {
                            $val = $slot['value'];
                            // Filter logic: Only show changes or first item
                            if ($prevVal !== null && $val === $prevVal) {
                                continue;
                            }
                            $prevVal = $val;
                            $displayedSlots[] = $slot;
                        }

                        // Find the current active entry from displayed slots (closest to current time but not larger)
                        $currentActiveTime = null;
                        foreach ($displayedSlots as $slot) {
                            $time = $slot['time'];
                            if ($time <= $currentTime) {
                                if ($currentActiveTime === null || $time > $currentActiveTime) {
                                    $currentActiveTime = $time;
                                }
                            }
                        }

                        // Second pass: render the displayed slots
                        foreach ($displayedSlots as $slot):
                            $val = $slot['value'];
                            $time = $slot['time'];
                            $h = intval(substr($time, 0, 2));
                            $isCurrent = ($time === $currentActiveTime);
                            $bgClass = getTimeClass($h);

                            $valDisplay = getValueLabel($val);
                            $catClass = 'neutral';
                            if ($val === 'netzero') {
                                $catClass = 'netzero';
                            } elseif ($val === 'netzero+') {
                                $catClass = 'netzero-plus';
                            } elseif (is_numeric($val)) {
                                $catClass = ($val > 0) ? 'charge' : (($val < 0) ? 'discharge' : 'neutral');
                            }
                            ?>
                            <div class="schedule-item <?php echo $bgClass; ?> <?php echo $isCurrent ? 'slot-current' : ''; ?>">
                                <div class="schedule-item-time"><?php echo substr($time, 0, 2) . ':' . substr($time, 2, 2); ?>
                                </div>
                                <div class="schedule-item-value <?php echo $catClass; ?>">
                                    <?php echo htmlspecialchars($valDisplay); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Tomorrow's Schedule (Right) -->
                <div class="schedule-day">
                    <div class="schedule-day-header">
                        <h3 class="card-header">Tomorrow <?php echo substr($tomorrow, -2); ?></h3>
                    </div>
                    <div class="schedule-list" id="tomorrow-schedule-grid">
                        <?php
                        $prevVal = null;
                        // First pass: collect displayed slots
                        $displayedSlots = [];
                        foreach ($resolvedTomorrow as $slot) {
                            $val = $slot['value'];
                            // Filter logic: Only show changes or first item
                            if ($prevVal !== null && $val === $prevVal) {
                                continue;
                            }
                            $prevVal = $val;
                            $displayedSlots[] = $slot;
                        }

                        // Second pass: render the displayed slots (no current time for tomorrow)
                        foreach ($displayedSlots as $slot):
                            $val = $slot['value'];
                            $time = $slot['time'];
                            $h = intval(substr($time, 0, 2));
                            $bgClass = getTimeClass($h);

                            $valDisplay = getValueLabel($val);
                            $catClass = 'neutral';
                            if ($val === 'netzero') {
                                $catClass = 'netzero';
                            } elseif ($val === 'netzero+') {
                                $catClass = 'netzero-plus';
                            } elseif (is_numeric($val)) {
                                $catClass = ($val > 0) ? 'charge' : (($val < 0) ? 'discharge' : 'neutral');
                            }
                            ?>
                            <div class="schedule-item <?php echo $bgClass; ?>">
                                <div class="schedule-item-time"><?php echo substr($time, 0, 2) . ':' . substr($time, 2, 2); ?>
                                </div>
                                <div class="schedule-item-value <?php echo $catClass; ?>">
                                    <?php echo htmlspecialchars($valDisplay); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 2: Schedule Entries -->
        <div class="schedule-mobile-tab-panel" data-tab="entries" role="tabpanel" aria-hidden="true">
            <div class="schedule-entries-header" style="display:flex; justify-content:space-between; align-items:center;">
                <h3 class="card-header">🧾 Schedule Entries</h3>
                <button class="btn btn-add" id="add-entry-btn">Add</button>
            </div>
            <div class="status-bar" id="status-bar" style="margin-top:6px; font-size:0.75rem; color:var(--text-tertiary);">
                <span><?php echo count($schedule); ?> entries loaded.</span>
            </div>
            <div class="table-wrapper">
                <table id="schedule-table">
                    <thead>
                        <tr>
                            <th style="width: 30px;">#</th>
                            <th>Key</th>
                            <th>Value</th>
                            <th>Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Sort for display
                        uksort($schedule, 'strcmp');
                        $idx = 0;
                        foreach ($schedule as $k => $v):
                            $idx++;
                            $displayVal = getValueLabel($v);
                            $valClass = 'neutral';
                            if ($v === 'netzero') {
                                $valClass = 'netzero';
                            } elseif ($v === 'netzero+') {
                                $valClass = 'netzero-plus';
                            } elseif (is_numeric($v)) {
                                $valClass = ($v > 0) ? 'charge' : (($v < 0) ? 'discharge' : 'neutral');
                            }
                            ?>
                            <tr data-key="<?php echo htmlspecialchars($k); ?>"
                                data-value="<?php echo htmlspecialchars($v); ?>">
                                <td style="color:var(--text-tertiary);"><?php echo $idx; ?></td>
                                <td style="font-family:monospace; font-size:0.8rem;"><?php echo htmlspecialchars($k); ?></td>
                                <td class="<?php echo $valClass; ?>" style="font-weight:500;">
                                    <?php echo htmlspecialchars($displayVal); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    var tabs = document.querySelectorAll('.schedule-mobile-card .schedule-mobile-tab');
    var panels = document.querySelectorAll('.schedule-mobile-card .schedule-mobile-tab-panel');
    if (!tabs.length || !panels.length) return;
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
        });
    });
})();
</script>
</div><!-- /.layout -->
