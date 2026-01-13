/**
 * Charge/Discharge Status
 * Client-side logic simplified: server renders status; refresh button reloads page.
 * This file is retained for backwards-compatibility; no AJAX refresh is performed anymore.
 */

/**
 * Format relative time (e.g., "2 minutes ago", "1 hour ago")
 * @param {number} timestamp Unix timestamp
 * @return {string} Formatted relative time or absolute time if > 24 hours
 */
function formatRelativeTime(timestamp) {
    if (!timestamp) {
        return 'Unknown';
    }
    
    const now = Math.floor(Date.now() / 1000);
    const diff = now - timestamp;
    
    if (diff < 60) {
        return 'Just now';
    } else if (diff < 3600) {
        const minutes = Math.floor(diff / 60);
        return minutes + ' minute' + (minutes > 1 ? 's' : '') + ' ago';
    } else if (diff < 86400) {
        const hours = Math.floor(diff / 3600);
        return hours + ' hour' + (hours > 1 ? 's' : '') + ' ago';
    } else {
        // For times > 24 hours, show absolute time
        const date = new Date(timestamp * 1000);
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        const seconds = String(date.getSeconds()).padStart(2, '0');
        return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
    }
}

/**
 * Format absolute timestamp for display
 * @param {number} timestamp Unix timestamp
 * @return {string} Formatted timestamp
 */
function formatAbsoluteTime(timestamp) {
    if (!timestamp) return '';
    const date = new Date(timestamp * 1000);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const seconds = String(date.getSeconds()).padStart(2, '0');
    return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
}

/**
 * Escape HTML to prevent XSS
 * @param {string} text Text to escape
 * @return {string} Escaped text
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Get CSS variable value from :root
 * @param {string} variableName CSS variable name (e.g., '--charge-status-charging')
 * @param {string} fallback Fallback value if variable is not found
 * @return {string} CSS variable value or fallback
 */
function getCSSVariable(variableName, fallback) {
    if (typeof document === 'undefined') {
        return fallback;
    }
    const value = getComputedStyle(document.documentElement).getPropertyValue(variableName).trim();
    return value || fallback;
}

/**
 * Update last update display
 * @param {number} lastUpdate Unix timestamp
 */
function updateChargeLastUpdateDisplay(lastUpdate) {
    const lastUpdateSpan = document.getElementById('charge-last-update');
    if (!lastUpdateSpan) return;
    
    if (lastUpdate) {
        const relativeTime = formatRelativeTime(lastUpdate);
        const absoluteTime = formatAbsoluteTime(lastUpdate);
        lastUpdateSpan.innerHTML = `Last update: ${escapeHtml(relativeTime)} <span class="charge-timestamp-full">(${escapeHtml(absoluteTime)})</span>`;
    }
}

/**
 * Determine charge/discharge status from properties
 * @param {Object} properties Zendure properties object
 * @return {Object} Status object with class, icon, text, and color
 */
function determineChargeStatus(properties) {
    const packState = properties.packState || 0;
    const outputPackPower = properties.outputPackPower || 0;
    const outputHomePower = properties.outputHomePower || 0;
    const acStatus = properties.acStatus || 0;
    
    const isCharging = (acStatus == 2 || packState == 1 || outputPackPower > 0);
    const isDischarging = (packState == 2 || outputHomePower > 0);
    
    // Read colors from CSS variables to ensure consistency with CSS
    const chargingColor = getCSSVariable('--charge-status-charging', '#66bb6a');
    const dischargingColor = getCSSVariable('--charge-status-discharging', '#ef5350');
    const standbyColor = getCSSVariable('--charge-status-standby', '#9e9e9e');
    
    if (isCharging) {
        return {
            class: 'charging',
            icon: '🔵',
            text: 'Charging',
            subtitle: 'Battery is being charged',
            color: chargingColor
        };
    } else if (isDischarging) {
        return {
            class: 'discharging',
            icon: '🔴',
            text: 'Discharging',
            subtitle: 'Battery is powering the home',
            color: dischargingColor
        };
    } else {
        return {
            class: 'standby',
            icon: '⚪',
            text: 'Standby',
            subtitle: 'No active power flow',
            color: standbyColor
        };
    }
}

/**
 * Calculate charge/discharge value
 * @param {Object} properties Zendure properties object
 * @return {number} Charge/discharge value (positive = charging, negative = discharging)
 */
function calculateChargeDischargeValue(properties) {
    const outputPackPower = properties.outputPackPower || 0;
    const outputHomePower = properties.outputHomePower || 0;
    
    if (outputPackPower > 0) {
        return outputPackPower;
    } else if (outputHomePower > 0) {
        return -outputHomePower;
    }
    return 0;
}

// No-op stub retained only for backward compatibility; the PHP button now reloads the page.
function refreshChargeStatus() {
    window.location.reload();
}

/**
 * Toggle the collapsible section in charge status details
 * Shows/hides rows 2-3 (Battery 1 & 2 levels and temps)
 */
function toggleChargeStatusDetails() {
    const collapsibleSection = document.getElementById('charge-status-details-collapsible');
    const toggleButton = document.getElementById('charge-details-toggle');
    const toggleText = toggleButton?.querySelector('.charge-details-toggle-text');
    
    if (!collapsibleSection || !toggleButton) {
        return;
    }
    
    // Toggle expanded class on collapsible section
    const isExpanded = collapsibleSection.classList.toggle('expanded');
    
    // Update toggle button appearance and text
    if (isExpanded) {
        toggleButton.classList.add('expanded');
        if (toggleText) {
            toggleText.textContent = 'Show less';
        }
    } else {
        toggleButton.classList.remove('expanded');
        if (toggleText) {
            toggleText.textContent = 'Show more';
        }
    }
}
