<?php
/**
 * Mobile Charge Schedule View
 * Mobile-first PWA for viewing Today's Schedule and Charge/Discharge Status.
 */

// Ensure server timezone matches local expectation
date_default_timezone_set('Europe/Amsterdam');

// Include dependencies
require_once __DIR__ . '/../schedule/api/charge_schedule_functions.php';
require_once __DIR__ . '/../schedule/includes/status.php';

// Data File
$dataFile = __DIR__ . '/../data/charge_schedule.json';

// Load Schedule Data
$schedule = loadSchedule($dataFile);
$today = date('Ymd');
$resolvedToday = resolveScheduleForDate($schedule, $today);
$currentTime = date('Hi'); // Current time in HHmm format

// Helper functions (copied from schedule_panels.php)
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
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Zendure Mobile</title>
    <link rel="manifest" href="manifest.json">
    <link rel="icon" href="icon.png">
    <meta name="theme-color" content="#ffffff">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

    <!-- Styles -->
    <link rel="stylesheet" href="../schedule/assets/css/charge_schedule.css">
    <link rel="stylesheet" href="../schedule/assets/css/charge_status_defines.css">
    <link rel="stylesheet" href="../schedule/assets/css/charge_status.css">

    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f5f5f7;
            margin: 0;
            padding: 10px;
            padding-bottom: 40px;
            /* Space for bottom safe area */
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
        }

        /* Override specific desktop styles/layout issues */
        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 15px;
            padding: 15px;
        }

        h2,
        h3 {
            margin-top: 0;
            font-size: 1.2rem;
            color: #333;
        }

        /* Adjustments for the Today schedule list */
        .schedule-list {
            max-height: none;
            /* Show full list on mobile or let it scroll naturally */
            overflow-y: visible;
        }

        /* Mobile header */
        .mobile-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            padding: 5px;
        }

        .mobile-header img {
            width: 32px;
            height: 32px;
            margin-right: 10px;
        }

        .mobile-header h1 {
            font-size: 1.4rem;
            margin: 0;
        }

        /* Force single column layout for charge status functionality */
        .charge-status-content {
            grid-template-columns: 1fr !important;
            display: grid !important;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="mobile-header">
            <img src="icon.png" alt="Zendure">
            <h1>Zendure Schedule</h1>
        </div>

        <!-- Today's Schedule Section -->
        <div class="card">
            <h2>Today's Schedule</h2>
            <div class="helper-text" style="margin-bottom: 10px; color: #666; font-size: 0.9rem;">
                <?php echo date('Y-m-d'); ?>
            </div>
            <div class="schedule-list" id="today-schedule-grid">
                <?php
                $prevVal = null;
                $displayedSlots = [];
                foreach ($resolvedToday as $slot) {
                    $val = $slot['value'];
                    if ($prevVal !== null && $val === $prevVal) {
                        continue;
                    }
                    $prevVal = $val;
                    $displayedSlots[] = $slot;
                }

                $currentActiveTime = null;
                foreach ($displayedSlots as $slot) {
                    $time = $slot['time'];
                    if ($time <= $currentTime) {
                        if ($currentActiveTime === null || $time > $currentActiveTime) {
                            $currentActiveTime = $time;
                        }
                    }
                }

                foreach ($displayedSlots as $slot):
                    $val = $slot['value'];
                    // Request: don't show when there is no value
                    if ($val === null) {
                        continue;
                    }

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
                    <div class="schedule-item <?php echo $bgClass; ?> <?php echo $isCurrent ? 'slot-current' : ''; ?>"
                        style="<?php echo $isCurrent ? 'border: 2px solid #007bff; transform: scale(1.02);' : ''; ?>">
                        <div class="schedule-item-time"><?php echo substr($time, 0, 2) . ':' . substr($time, 2, 2); ?></div>
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

        <!-- Charge/Discharge Status Section -->
        <div class="status-wrapper">
            <?php
            // Include the existing partial. 
            // Note: The partial uses strict paths inside itself, but assumes it's being included from a place where 
            // it can find its dependencies. 
            // `charge_status.php` requires `charge_status_data.php`.
            // `charge_status_data.php` requires `../includes/formatters.php`.
            // If we include it from here (`mobile/index.php`), `__DIR__` in the partials will be `.../schedule/partials/`.
            // So `.../schedule/partials/../includes/` resolves to `.../schedule/includes/`. This is correct.
            include __DIR__ . '/../schedule/partials/charge_status.php';
            ?>
        </div>

    </div>

    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('service-worker.js')
                    .then(registration => {
                        console.log('ServiceWorker registration successful');
                    }, err => {
                        console.log('ServiceWorker registration failed: ', err);
                    });
            });
        }
    </script>
</body>

</html>