<?php
/**
 * Status, Icon, and Text Functions
 * Functions for getting status information, icons, and text labels
 */

/**
 * Get emoji icon for battery state
 * @param int $state 0=Stand-by, 1=Charging, 2=Discharging
 */
function getBatteryStateIcon($state) {
    switch ($state) {
        case 0: return '⚪'; // Stand-by
        case 1: return '🔵'; // Charging
        case 2: return '🔴'; // Discharging
        default: return '❓';
    }
}

/**
 * Get text label for battery state
 * @param int $state 0=Stand-by, 1=Charging, 2=Discharging
 */
function getBatteryStateText($state) {
    switch ($state) {
        case 0: return 'Stand-by';
        case 1: return 'Charging';
        case 2: return 'Discharging';
        default: return 'Unknown';
    }
}

/**
 * Get emoji icon for AC status
 * @param int $status 0=Stopped, 1=Grid-connected, 2=Charging
 */
function getAcStatusIcon($status) {
    switch ($status) {
        case 0: return '⏸️';
        case 1: return '🔌';
        case 2: return '🔋';
        default: return '❓';
    }
}

/**
 * Get text label for AC status
 * @param int $status 0=Stopped, 1=Grid-connected, 2=Charging
 */
function getAcStatusText($status) {
    switch ($status) {
        case 0: return 'Stopped';
        case 1: return 'Grid-connected';
        case 2: return 'Charging';
        default: return 'Unknown';
    }
}

/**
 * Determine overall system status based on packState and power values
 * Returns array with status information including class, icon, title, subtitle, and color
 */
function getSystemStatusInfo($packState, $outputPackPower, $outputHomePower, $solarInputPower, $electricLevel) {
    $status = [
        'state' => $packState,
        'class' => 'standby',
        'icon' => '⚪',
        'title' => 'Standby',
        'subtitle' => 'No active power flow',
        'color' => '#9e9e9e'
    ];

    // Determine state based on packState and actual power values
    if ($packState == 1 || $outputPackPower > 0) {
        // Charging - either from packState or if outputPackPower is active
        $status['class'] = 'charging';
        $status['icon'] = '🔵';
        $status['title'] = 'Charging';
        $status['subtitle'] = 'Battery is being charged';
        $status['color'] = '#64b5f6';
    } elseif ($packState == 2 || $outputHomePower > 0) {
        // Discharging - either from packState or if outputHomePower is active
        $status['class'] = 'discharging';
        $status['icon'] = '🔴';
        $status['title'] = 'Discharging';
        $status['subtitle'] = 'Battery is powering the home';
        $status['color'] = '#ef5350';
    }

    return $status;
}

/**
 * Get badge class for automation entry type
 * @param string $type Entry type: 'start', 'stop', 'change', 'heartbeat'
 * @return string CSS class name
 */
function getAutomationEntryTypeClass($type) {
    switch ($type) {
        case 'start': return 'automation-badge-start';
        case 'stop': return 'automation-badge-stop';
        case 'change': return 'automation-badge-change';
        case 'heartbeat': return 'automation-badge-heartbeat';
        default: return 'automation-badge-unknown';
    }
}

/**
 * Get label text for automation entry type
 * @param string $type Entry type: 'start', 'stop', 'change', 'heartbeat'
 * @return string Label text
 */
function getAutomationEntryTypeLabel($type) {
    return ucfirst($type);
}

/**
 * Format relative time (e.g., "2 minutes ago", "1 hour ago")
 * @param int $timestamp Unix timestamp
 * @return string Formatted relative time or absolute time if > 24 hours
 */
function formatRelativeTime($timestamp) {
    if (!$timestamp) {
        return 'Unknown';
    }
    
    $now = time();
    $diff = $now - $timestamp;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } else {
        // For times > 24 hours, show absolute time
        return date('Y-m-d H:i:s', $timestamp);
    }
}

/**
 * Format value for display (handles null, numeric, and special values)
 * @param mixed $value Value to format
 * @return string Formatted value
 */
function formatAutomationValue($value) {
    if ($value === null) {
        return '—';
    }
    if (is_numeric($value)) {
        return $value . ' W';
    }
    return (string)$value;
}

/**
 * Format automation entry details based on type
 * @param array $entry Entry array with type, oldValue, newValue
 * @return string Formatted details text
 */
function formatAutomationEntryDetails($entry) {
    $type = $entry['type'] ?? 'unknown';
    $oldValue = $entry['oldValue'] ?? null;
    $newValue = $entry['newValue'] ?? null;
    
    switch ($type) {
        case 'change':
            $oldFormatted = formatAutomationValue($oldValue);
            $newFormatted = formatAutomationValue($newValue);
            return $oldFormatted . ' → ' . $newFormatted;
        case 'start':
            return 'Automation started';
        case 'stop':
            return 'Automation stopped';
        case 'heartbeat':
            return 'Heartbeat';
        default:
            return 'Unknown event';
    }
}

