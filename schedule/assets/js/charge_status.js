/**
 * Charge/Discharge Status
 * Refresh functionality for charge status display
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
    
    if (isCharging) {
        return {
            class: 'charging',
            icon: '🔵',
            text: 'Charging',
            subtitle: 'Battery is being charged',
            color: '#66bb6a'
        };
    } else if (isDischarging) {
        return {
            class: 'discharging',
            icon: '🔴',
            text: 'Discharging',
            subtitle: 'Battery is powering the home',
            color: '#ef5350'
        };
    } else {
        return {
            class: 'standby',
            icon: '⚪',
            text: 'Standby',
            subtitle: 'No active power flow',
            color: '#9e9e9e'
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

/**
 * Render charge status content
 * @param {Object} data Zendure data object
 * @param {number} lastUpdate Unix timestamp of last update
 */
function renderChargeStatus(data, lastUpdate) {
    const contentDiv = document.getElementById('charge-status-content');
    const errorDiv = document.getElementById('charge-status-error');
    const emptyDiv = document.getElementById('charge-status-empty');
    
    // Hide error and empty messages
    if (errorDiv) errorDiv.style.display = 'none';
    if (emptyDiv) emptyDiv.style.display = 'none';
    
    if (!data || !data.properties) {
        if (emptyDiv) {
            emptyDiv.style.display = 'block';
            emptyDiv.querySelector('p').textContent = 'No charge status data available';
        }
        return;
    }
    
    const properties = data.properties;
    const status = determineChargeStatus(properties);
    const chargeDischargeValue = calculateChargeDischargeValue(properties);
    const electricLevel = properties.electricLevel || 0;
    
    // Format power value and calculate time estimate
    let powerDisplay = '0 W';
    let powerColor = '#9e9e9e';
    let timeEstimate = '';
    
    // Get constants
    const totalCapacityKwh = typeof TOTAL_CAPACITY_KWH !== 'undefined' ? TOTAL_CAPACITY_KWH : 5.76;
    const minChargeLevel = typeof MIN_CHARGE_LEVEL !== 'undefined' ? MIN_CHARGE_LEVEL : 20;
    const maxChargeLevel = typeof MAX_CHARGE_LEVEL !== 'undefined' ? MAX_CHARGE_LEVEL : 90;
    
    if (chargeDischargeValue > 0) {
        // Charging - calculate time until max level
        powerDisplay = '+' + chargeDischargeValue.toLocaleString() + ' W';
        powerColor = '#66bb6a';
        const capacityToMaxKwh = ((maxChargeLevel - electricLevel) / 100) * totalCapacityKwh;
        const capacityToMaxWh = capacityToMaxKwh * 1000;
        if (chargeDischargeValue > 0 && capacityToMaxWh > 0) {
            const hoursToMax = capacityToMaxWh / chargeDischargeValue;
            if (hoursToMax < 0.0167) { // Less than 1 minute
                timeEstimate = '< 1m left';
            } else if (hoursToMax < 1) {
                const minutes = Math.round(hoursToMax * 60);
                timeEstimate = minutes + 'm left';
            } else {
                const hours = Math.floor(hoursToMax);
                let minutes = Math.round((hoursToMax - hours) * 60);
                if (minutes >= 60) {
                    minutes = 0;
                    hours++;
                }
                timeEstimate = hours + ':' + String(minutes).padStart(2, '0') + 'h left';
            }
        }
    } else if (chargeDischargeValue < 0) {
        // Discharging - calculate time until min level
        powerDisplay = chargeDischargeValue.toLocaleString() + ' W';
        powerColor = '#ef5350';
        const capacityToMinKwh = ((electricLevel - minChargeLevel) / 100) * totalCapacityKwh;
        const capacityToMinWh = capacityToMinKwh * 1000;
        const absPower = Math.abs(chargeDischargeValue);
        if (absPower > 0 && capacityToMinWh > 0) {
            const hoursToMin = capacityToMinWh / absPower;
            if (hoursToMin < 0.0167) { // Less than 1 minute
                timeEstimate = '< 1m left';
            } else if (hoursToMin < 1) {
                const minutes = Math.round(hoursToMin * 60);
                timeEstimate = minutes + 'm left';
            } else {
                const hours = Math.floor(hoursToMin);
                let minutes = Math.round((hoursToMin - hours) * 60);
                if (minutes >= 60) {
                    minutes = 0;
                    hours++;
                }
                timeEstimate = hours + ':' + String(minutes).padStart(2, '0') + 'h left';
            }
        }
    }
    
    // Calculate power bar width for -1200 to +1200 range
    const minPower = -1200;
    const maxPower = 1200;
    const clampedValue = Math.max(minPower, Math.min(maxPower, chargeDischargeValue));
    let barWidth = 0;
    let barClass = '';
    
    if (clampedValue > 0) {
        // Positive - bar extends right from center (green)
        barWidth = (Math.abs(clampedValue) / Math.abs(maxPower)) * 50; // 50% max (half container)
        barWidth = Math.max(6, barWidth); // Minimum 6% for visibility (increased from 2%)
        barClass = 'charging';
    } else if (clampedValue < 0) {
        // Negative - bar extends left from center (red)
        barWidth = (Math.abs(clampedValue) / Math.abs(minPower)) * 50; // 50% max (half container)
        barWidth = Math.max(6, barWidth); // Minimum 6% for visibility (increased from 2%)
        barClass = 'discharging';
    }
    
    // Build power bar HTML
    let powerBarFill = '';
    if (barWidth > 0) {
        powerBarFill = `<div class="charge-power-bar-fill ${barClass}" style="width: ${barWidth}%;"></div>`;
    }
    
    // Calculate battery capacity values (reuse constants already declared above at lines 174-176)
    const totalCapacityLeftKwh = (electricLevel / 100) * totalCapacityKwh;
    const usableCapacityAboveMinKwh = Math.max(0, ((electricLevel - minChargeLevel) / 100) * totalCapacityKwh);
    const batteryLevelDisplay = `${electricLevel}% (${totalCapacityLeftKwh.toFixed(2)} kWh/${usableCapacityAboveMinKwh.toFixed(2)} kWh)`;
    
    // Update last update display
    updateChargeLastUpdateDisplay(lastUpdate);
    
    // Render content
    if (contentDiv) {
        contentDiv.innerHTML = `
            <!-- Status Indicator -->
            <div class="charge-status-indicator ${status.class}">
                <div class="charge-status-icon">${status.icon}</div>
                <div class="charge-status-text">
                    <div class="charge-status-title">${escapeHtml(status.text)}</div>
                    <div class="charge-status-subtitle">${escapeHtml(status.subtitle)}</div>
                </div>
            </div>
            
            <!-- Power Value -->
            <div class="charge-power-display">
                <div class="charge-power-label-value">
                    <span class="charge-power-label">Power:</span>
                    <span class="charge-power-value" style="color: ${powerColor};">
                        ${escapeHtml(powerDisplay)}${timeEstimate ? ' <span class="charge-power-time">(' + escapeHtml(timeEstimate) + ')</span>' : ''}
                    </span>
                </div>
                <div class="charge-power-bar-container">
                    <div class="charge-power-bar-label left">-1200 W</div>
                    <div class="charge-power-bar-label center">0</div>
                    <div class="charge-power-bar-label right">1200 W</div>
                    <div class="charge-power-bar-center"></div>
                    ${powerBarFill}
                </div>
            </div>
            
            <!-- Battery Level -->
            <div class="charge-battery-display">
                <div class="charge-battery-label-value">
                    <span class="charge-battery-label">Battery Level:</span>
                    <span class="charge-battery-value">${escapeHtml(batteryLevelDisplay)}</span>
                </div>
                <div class="charge-battery-bar">
                    <div class="charge-battery-bar-marker min" style="left: ${(typeof MIN_CHARGE_LEVEL !== 'undefined' ? MIN_CHARGE_LEVEL : 20)}%;" title="Minimum: ${(typeof MIN_CHARGE_LEVEL !== 'undefined' ? MIN_CHARGE_LEVEL : 20)}%"></div>
                    <div class="charge-battery-bar-marker max" style="left: ${(typeof MAX_CHARGE_LEVEL !== 'undefined' ? MAX_CHARGE_LEVEL : 90)}%;" title="Maximum: ${(typeof MAX_CHARGE_LEVEL !== 'undefined' ? MAX_CHARGE_LEVEL : 90)}%"></div>
                    <div class="charge-battery-bar-fill" style="width: ${Math.min(100, Math.max(0, electricLevel))}%; background-color: ${status.color};"></div>
                </div>
            </div>
        `;
    }
}

/**
 * Show error message
 * @param {string} errorMsg Error message to display
 */
function showChargeError(errorMsg) {
    const errorDiv = document.getElementById('charge-status-error');
    const contentDiv = document.getElementById('charge-status-content');
    const emptyDiv = document.getElementById('charge-status-empty');
    
    if (contentDiv) contentDiv.style.display = 'none';
    if (emptyDiv) emptyDiv.style.display = 'none';
    
    if (errorDiv) {
        errorDiv.style.display = 'block';
        const p = errorDiv.querySelector('p');
        if (p) {
            p.textContent = errorMsg;
        } else {
            errorDiv.innerHTML = '<p>' + escapeHtml(errorMsg) + '</p>';
        }
    }
}

/**
 * Refresh charge status from API
 */
async function refreshChargeStatus() {
    const refreshBtn = document.getElementById('charge-refresh-btn');
    const refreshIcon = refreshBtn?.querySelector('.refresh-icon');
    
    // Show loading state
    if (refreshBtn) {
        refreshBtn.disabled = true;
        refreshBtn.classList.add('refreshing');
    }
    if (refreshIcon) {
        refreshIcon.style.animation = 'spin 1s linear infinite';
    }
    
    try {
        // Use the API URL from the PHP constant, or build it
        let apiUrl = typeof CHARGE_STATUS_API_URL !== 'undefined' 
            ? CHARGE_STATUS_API_URL 
            : '../data/api/data_api.php?type=zendure';
        
        const response = await fetch(apiUrl);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await response.text();
            console.error('Non-JSON response:', text.substring(0, 200));
            throw new Error('Server returned non-JSON response. Check console for details.');
        }
        
        const apiResponse = await response.json();
        
        if (apiResponse.success && apiResponse.data) {
            const zendureData = apiResponse.data;
            // Get timestamp from API response or data
            let lastUpdate = null;
            if (apiResponse.timestamp) {
                lastUpdate = typeof apiResponse.timestamp === 'number' 
                    ? apiResponse.timestamp 
                    : Math.floor(new Date(apiResponse.timestamp).getTime() / 1000);
            } else if (zendureData.timestamp) {
                lastUpdate = typeof zendureData.timestamp === 'number' 
                    ? zendureData.timestamp 
                    : Math.floor(new Date(zendureData.timestamp).getTime() / 1000);
            }
            
            // Render status
            renderChargeStatus(zendureData, lastUpdate);
            
            // Hide any error
            const errorDiv = document.getElementById('charge-status-error');
            if (errorDiv) errorDiv.style.display = 'none';
        } else {
            const errorMsg = apiResponse.error || 'Unknown error';
            showChargeError('Failed to load charge status: ' + errorMsg);
        }
    } catch (error) {
        console.error('Error refreshing charge status:', error);
        showChargeError('Failed to refresh charge status: ' + error.message);
    } finally {
        // Remove loading state
        if (refreshBtn) {
            refreshBtn.disabled = false;
            refreshBtn.classList.remove('refreshing');
        }
        if (refreshIcon) {
            refreshIcon.style.animation = '';
        }
    }
}
