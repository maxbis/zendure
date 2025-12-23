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

