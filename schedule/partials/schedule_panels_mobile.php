<?php
/**
 * Schedule Panels Partial - Mobile Version
 * Contains Today's Schedule and Schedule Entries (simplified for mobile)
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
<!-- Today's and Tomorrow's Schedule Side by Side -->
<div class="card">
    <h2>Schedule</h2>
    <div class="schedule-days-container">
        <!-- Today's Schedule (Left) -->
        <div class="schedule-day">
            <div class="schedule-day-header">
                <h3>Today <?php echo substr($today, -2); ?></h3>
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
                <h3>Tomorrow <?php echo substr($tomorrow, -2); ?></h3>
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

<!-- Schedule Entries - Mobile (only Add button, no badges) -->
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h2>Schedule Entries</h2>
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
</div><!-- /.layout -->
