<?php
/**
 * Schedule Panels Partial
 * Contains the two main panels: Today's Schedule and Schedule Entries
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
    <!-- Left Panel: Today's Schedule -->
    <div class="card">
        <h2>Today's Schedule</h2>
        <div class="helper-text" style="margin-bottom: 10px;">
        <?php echo htmlspecialchars($today); ?>
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
                    <?php if ($slot['key']): ?>
                        <div class="schedule-item-key"><?php echo htmlspecialchars($slot['key']); ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Right Panel: Schedule Entries -->
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <h2>Schedule Entries</h2>
            <button class="btn btn-primary" id="add-entry-btn">Add Entry</button>
        </div>
        <div class="status-bar" id="status-bar" style="margin-top:5px; font-size:0.8rem; color:#666;">
            <span><?php echo count($schedule); ?> entries loaded.</span>
        </div>
        <div class="table-wrapper">
            <table id="schedule-table">
                <thead>
                    <tr>
                        <th style="width: 40px;">#</th>
                        <th>Key</th>
                        <th>Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Sort for display
                    uksort($schedule, 'strcmp');
                    $idx = 0;
                    foreach ($schedule as $k => $v):
                        $idx++;
                        $isWild = strpos($k, '*') !== false;
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
                            <td style="color:#888;"><?php echo $idx; ?></td>
                            <td style="font-family:monospace;"><?php echo htmlspecialchars($k); ?></td>
                            <td class="<?php echo $valClass; ?>" style="font-weight:500;">
                                <?php echo htmlspecialchars($displayVal); ?>
                            </td>
                            <td><span
                                    class="badge <?php echo $isWild ? 'badge-wildcard' : 'badge-exact'; ?>"><?php echo $isWild ? 'Wildcard' : 'Exact'; ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
