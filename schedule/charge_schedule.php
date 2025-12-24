<?php
/**
 * Charge Schedule Manager
 * View, edit, and visualize charge/discharge schedule.
 */

// Ensure server timezone matches local expectation
date_default_timezone_set('Europe/Amsterdam');

require_once __DIR__ . '/api/charge_schedule_functions.php';
// Include zendure helpers for automation status functions
require_once __DIR__ . '/../zendure/includes/helpers.php';

$dataFile = __DIR__ . '/../data/charge_schedule.json';

// Initial Server-Side Render Data
$schedule = loadSchedule($dataFile);
$today = isset($_GET['initial_date']) ? $_GET['initial_date'] : date('Ymd');
$resolvedToday = resolveScheduleForDate($schedule, $today);
$currentHour = date('H') . '00';
$currentTime = date('Hi'); // Current time in HHmm format (e.g., "0930")

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Charge Schedule Manager</title>
    <link rel="stylesheet" href="assets/css/charge_schedule.css">
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>⚡ Charge Schedule Manager</h1>
            <p id="current-time"><?php echo date('Y-m-d H:i:s'); ?></p>
        </div>

        <div class="layout">
            <!-- Left Panel: Today's Schedule -->
            <div class="card">
                <h2>Today's Schedule (<?php echo htmlspecialchars($today); ?>)</h2>
                <div class="helper-text" style="margin-bottom: 10px;">
                    Showing value changes only. Highlight = Current Hour.
                </div>
                <div class="schedule-list" id="today-schedule-grid">
                    <?php
                    function getTimeClass($h)
                    {
                        return ($h >= 22 || $h < 6) ? 'time-night' : (($h < 12) ? 'time-morning' : (($h < 18) ? 'time-afternoon' : 'time-evening'));
                    }
                    
                    function getValueLabel($val)
                    {
                        if ($val === null) return '-';
                        if ($val === 'netzero') return 'Net Zero';
                        if ($val === 'netzero+') return 'Solar Charge';
                        if (is_numeric($val)) return ($val > 0 ? '+' : '') . intval($val) . ' W';
                        return $val . ' W';
                    }
                    $prevVal = null;
                    // First pass: collect displayed slots to find the active one
                    $displayedSlots = [];
                    foreach ($resolvedToday as $slot) {
                        $val = $slot['value'];
                        // Filter logic: Only show changes or first item
                        if (
                            $prevVal !== null &&
                            (($val === $prevVal) || 
                             ($val === 'netzero' && $prevVal === 'netzero') ||
                             ($val === 'netzero+' && $prevVal === 'netzero+'))
                        ) {
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
                    $prevVal = null;
                    foreach ($resolvedToday as $slot):
                        $val = $slot['value'];
                        // Filter logic: Only show changes or first item
                        if (
                            $prevVal !== null &&
                            (($val === $prevVal) || 
                             ($val === 'netzero' && $prevVal === 'netzero') ||
                             ($val === 'netzero+' && $prevVal === 'netzero+'))
                        ) {
                            continue;
                        }
                        $prevVal = $val;

                        $time = $slot['time'];
                        $h = intval(substr($time, 0, 2));
                        $isCurrent = ($time === $currentActiveTime);
                        $bgClass = getTimeClass($h);

                        $valDisplay = getValueLabel($val);
                        $catClass = 'neutral';
                        if ($val === 'netzero' || $val === 'netzero+') {
                            $catClass = 'netzero';
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
                                $isWild = strpos($k, '*') !== false;
                                $displayVal = getValueLabel($v);
                                $valClass = ($v === 'netzero' || $v === 'netzero+') ? 'netzero' : ($v > 0 ? 'charge' : ($v < 0 ? 'discharge' : 'neutral'));
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
        <?php include __DIR__ . '/partials/edit_modal.php'; ?>

        <!-- Automation Status Section - Full Width -->
        <div class="automation-status-wrapper" style="margin-top: 20px;">
            <?php include __DIR__ . '/partials/automation_status.php'; ?>
        </div>

        <script src="assets/js/edit_modal.js"></script>
        <script src="assets/js/charge_schedule.js"></script>
        <script src="../zendure/assets/js/zendure.js"></script>
        
    </div>

</body>

</html>