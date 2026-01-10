/**
 * Automation Status
 * Toggle functionality for automation entries list and refresh functionality
 */

// Automation entries toggle functionality
window.toggleAutomationEntries = function () {
    const entriesWrapper = document.getElementById('automation-entries-wrapper');
    const entriesList = document.getElementById('automation-entries-list');
    if (!entriesWrapper || !entriesList) return;

    if (entriesWrapper.classList.contains('expanded')) {
        entriesWrapper.classList.remove('expanded');
        entriesList.classList.remove('expanded');
    } else {
        entriesWrapper.classList.add('expanded');
        entriesList.classList.add('expanded');
    }
};

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
 * Format value for display (handles null, numeric, and special values)
 * @param {*} value Value to format
 * @return {string} Formatted value
 */
function formatAutomationValue(value) {
    if (value === null || value === undefined) {
        return '—';
    }
    if (typeof value === 'number') {
        return value + ' W';
    }
    return String(value);
}

/**
 * Format automation entry details based on type
 * @param {Object} entry Entry object with type, oldValue, newValue
 * @return {string} Formatted details text
 */
function formatAutomationEntryDetails(entry) {
    const type = entry.type || 'unknown';
    const oldValue = entry.oldValue ?? null;
    const newValue = entry.newValue ?? null;
    
    // Just display the type and any values
    const parts = [];
    if (oldValue !== null || newValue !== null) {
        if (oldValue !== null) {
            parts.push(formatAutomationValue(oldValue));
        }
        if (newValue !== null) {
            if (parts.length > 0) {
                parts.push('→');
            }
            parts.push(formatAutomationValue(newValue));
        }
    }
    const details = parts.join(' ');
    return type.charAt(0).toUpperCase() + type.slice(1) + (details ? ' (' + details + ')' : '');
}

/**
 * Get badge class for automation entry type
 * @param {string} type Entry type: 'start', 'stop', 'change', 'heartbeat'
 * @return {string} CSS class name
 */
function getAutomationEntryTypeClass(type) {
    const typeLower = String(type).toLowerCase();
    switch (typeLower) {
        case 'start': return 'automation-badge-start';
        case 'stop': return 'automation-badge-stop';
        case 'change': return 'automation-badge-change';
        case 'heartbeat': return 'automation-badge-heartbeat';
        default: return 'automation-badge-unknown';
    }
}

/**
 * Get label text for automation entry type
 * @param {string} type Entry type: 'start', 'stop', 'change', 'heartbeat'
 * @return {string} Label text
 */
function getAutomationEntryTypeLabel(type) {
    if (!type) return 'Unknown';
    return String(type).charAt(0).toUpperCase() + String(type).slice(1);
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
 * Render automation entries
 * @param {Array} entries Array of automation entries
 */
function renderAutomationEntries(entries) {
    const entriesList = document.getElementById('automation-entries-list');
    const entriesWrapper = document.getElementById('automation-entries-wrapper');
    const errorDiv = document.getElementById('automation-status-error');
    const emptyDiv = document.getElementById('automation-status-empty');
    
    // Hide error and empty messages
    if (errorDiv) errorDiv.style.display = 'none';
    if (emptyDiv) emptyDiv.style.display = 'none';
    
    if (!entries || entries.length === 0) {
        if (entriesList) entriesList.innerHTML = '';
        if (entriesWrapper) entriesWrapper.style.display = 'none';
        if (emptyDiv) {
            emptyDiv.style.display = 'block';
            emptyDiv.querySelector('p').textContent = 'No automation entries yet';
        }
        return;
    }
    
    // Show wrapper
    if (entriesWrapper) entriesWrapper.style.display = 'block';
    
    const totalEntries = entries.length;
    const hasMoreEntries = totalEntries > 1;
    
    // Update data attribute
    if (entriesList) entriesList.setAttribute('data-total-entries', totalEntries);
    
    // Render entries
    let html = '';
    entries.forEach((entry, index) => {
        const entryType = entry.type || 'unknown';
        const entryTimestamp = entry.timestamp || 0;
        const entryDetails = formatAutomationEntryDetails(entry);
        const badgeClass = getAutomationEntryTypeClass(entryType);
        const badgeLabel = getAutomationEntryTypeLabel(entryType);
        const isFirst = index === 0;
        const entryClass = 'automation-entry' + (isFirst ? ' automation-entry-first' : ' automation-entry-collapsed');
        const relativeTime = formatRelativeTime(entryTimestamp);
        const absoluteTime = formatAbsoluteTime(entryTimestamp);
        
        html += `
            <div class="${entryClass}" data-index="${index}" ${isFirst && hasMoreEntries ? 'onclick="toggleAutomationEntries()" style="cursor: pointer;"' : ''}>
                <span class="automation-entry-badge ${badgeClass}">
                    ${escapeHtml(badgeLabel)}
                </span>
                <span class="automation-entry-time">
                    ${escapeHtml(relativeTime)}
                    <span class="automation-entry-timestamp-full">(${escapeHtml(absoluteTime)})</span>
                </span>
                <span class="automation-entry-details">
                    ${escapeHtml(entryDetails)}
                </span>
                ${isFirst && hasMoreEntries ? '<span class="automation-entry-expand-icon">▼</span>' : ''}
            </div>
        `;
    });
    
    if (entriesList) {
        entriesList.innerHTML = html;
        // Reset expanded state
        entriesWrapper.classList.remove('expanded');
        entriesList.classList.remove('expanded');
    }
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
function updateLastUpdateDisplay(lastUpdate) {
    const lastUpdateSpan = document.getElementById('automation-last-update');
    if (!lastUpdateSpan) return;
    
    if (lastUpdate) {
        const relativeTime = formatRelativeTime(lastUpdate);
        const absoluteTime = formatAbsoluteTime(lastUpdate);
        lastUpdateSpan.innerHTML = `Last update: ${escapeHtml(relativeTime)} <span class="automation-timestamp-full">(${escapeHtml(absoluteTime)})</span>`;
    }
}

/**
 * Show error message
 * @param {string} errorMsg Error message to display
 */
function showAutomationError(errorMsg) {
    const errorDiv = document.getElementById('automation-status-error');
    const entriesWrapper = document.getElementById('automation-entries-wrapper');
    const emptyDiv = document.getElementById('automation-status-empty');
    
    if (entriesWrapper) entriesWrapper.style.display = 'none';
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
 * Refresh automation status from API
 */
async function refreshAutomationStatus() {
    const refreshBtn = document.getElementById('automation-refresh-btn');
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
        let apiUrl = typeof AUTOMATION_STATUS_API_URL !== 'undefined' 
            ? AUTOMATION_STATUS_API_URL 
            : 'api/automation_status_api.php?type=all&limit=20';
        
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
        
        const data = await response.json();
        
        if (data.success) {
            const entries = data.lastChanges || [];
            const lastUpdate = data.lastUpdate || null;
            
            // Update last update display
            updateLastUpdateDisplay(lastUpdate);
            
            // Render entries
            renderAutomationEntries(entries);
            
            // Hide any error
            const errorDiv = document.getElementById('automation-status-error');
            if (errorDiv) errorDiv.style.display = 'none';
        } else {
            const errorMsg = data.error || 'Unknown error';
            showAutomationError('Failed to load automation status: ' + errorMsg);
        }
    } catch (error) {
        console.error('Error refreshing automation status:', error);
        showAutomationError('Failed to refresh automation status: ' + error.message);
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
