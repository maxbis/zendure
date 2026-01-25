/**
 * Schedule Renderer
 * All DOM rendering functions for the schedule application
 * Uses utility functions from schedule_utils.js
 */

/**
 * Render today's schedule list
 * @param {Array} resolved - Resolved schedule slots
 * @param {string} currentHour - Current hour in HHmm format
 * @param {string} currentTime - Current time in HHmm format
 */
function renderToday(resolved, currentHour, currentTime) {
    const container = document.getElementById('today-schedule-grid');
    if (!container) return;

    container.innerHTML = '';

    let prevVal = null;

    // First pass: collect displayed slots to find the active one
    const displayedSlots = [];
    resolved.forEach(slot => {
        const val = slot.value;
        // Filter logic: Only show changes or first item
        if (prevVal !== null &&
            ((val === prevVal) ||
                (val === 'netzero' && prevVal === 'netzero') ||
                (val === 'netzero+' && prevVal === 'netzero+'))) {
            return;
        }
        prevVal = val;
        displayedSlots.push(slot);
    });

    // Find the current active entry from displayed slots (closest to current time but not larger)
    let currentActiveTime = null;
    displayedSlots.forEach(slot => {
        const time = String(slot.time);
        if (time <= currentTime) {
            if (currentActiveTime === null || time > currentActiveTime) {
                currentActiveTime = time;
            }
        }
    });

    // Second pass: render the displayed slots
    prevVal = null;
    resolved.forEach(slot => {
        const val = slot.value;
        // Filter logic: Only show changes or first item
        if (prevVal !== null &&
            ((val === prevVal) ||
                (val === 'netzero' && prevVal === 'netzero') ||
                (val === 'netzero+' && prevVal === 'netzero+'))) {
            return;
        }
        prevVal = val;

        const time = String(slot.time);
        const h = parseInt(time.substring(0, 2));
        const isCurrent = (time === currentActiveTime);

        const bgClass = getTimeClass(h);
        const valDisplay = getValueLabel(val);
        const valClass = getValueClass(val);

        const div = document.createElement('div');
        div.className = `schedule-item ${bgClass} ${isCurrent ? 'slot-current' : ''}`;
        div.innerHTML = `
            <div class="schedule-item-time">${formatTime(time)}</div>
            <div class="schedule-item-value ${valClass}">${valDisplay}</div>
            ${slot.key ? `<div class="schedule-item-key">${slot.key}</div>` : ''}
        `;
        container.appendChild(div);
    });
}

/**
 * Render tomorrow's schedule list (no current-time highlight)
 * @param {Array} resolved - Resolved schedule slots for tomorrow
 */
function renderTomorrow(resolved) {
    const container = document.getElementById('tomorrow-schedule-grid');
    if (!container) return;

    container.innerHTML = '';

    if (!resolved || resolved.length === 0) {
        container.innerHTML = '<div class="empty-state">No schedule data available</div>';
        return;
    }

    let prevVal = null;
    const displayedSlots = [];

    resolved.forEach(slot => {
        const val = slot.value;
        if (prevVal !== null && val === prevVal) return;
        prevVal = val;
        displayedSlots.push(slot);
    });

    displayedSlots.forEach(slot => {
        const time = String(slot.time);
        const h = parseInt(time.substring(0, 2), 10);
        const bgClass = getTimeClass(h);
        const valDisplay = getValueLabel(slot.value);
        const valClass = getValueClass(slot.value);

        const div = document.createElement('div');
        div.className = `schedule-item ${bgClass}`;
        div.innerHTML = `
            <div class="schedule-item-time">${formatTime(time)}</div>
            <div class="schedule-item-value ${valClass}">${valDisplay}</div>
        `;
        container.appendChild(div);
    });
}

/**
 * Renders the schedule entries table
 * @param {Array} entries - Array of schedule entries
 */
function renderEntries(entries) {
    const tbody = document.querySelector('#schedule-table tbody');
    if (!tbody) return;

    tbody.innerHTML = '';

    // Sort entries by key ascending
    entries.sort((a, b) => String(a.key).localeCompare(String(b.key)));

    entries.forEach((entry, idx) => {
        const tr = document.createElement('tr');
        tr.dataset.key = entry.key;
        tr.dataset.value = entry.value;

        // Ensure key is string
        const keyStr = String(entry.key);
        const isWild = keyStr.includes('*');
        const displayVal = getValueLabel(entry.value);
        const valClass = getValueClass(entry.value);
        const keyBgColor = isWild ? '#fff9c4' : '#e8f5e9';

        tr.innerHTML = `
            <td style="color:#888;">${idx + 1}</td>
            <td style="font-family:monospace; background-color:${keyBgColor};">${keyStr}</td>
            <td class="${valClass}" style="font-weight:500;">${displayVal}</td>
        `;
        tbody.appendChild(tr);
    });
}

/**
 * Renders the mini timeline visualization
 * @param {Array} resolved - Resolved schedule slots
 * @param {string} currentTime - Current time in HHmm format
 */
function renderMiniTimeline(resolved, currentTime) {
    const timeline = document.getElementById('mini-timeline');
    if (!timeline) return;

    timeline.innerHTML = '';

    // Build hour map
    const hourValues = buildHourMap(resolved);

    // Get current hour
    const now = new Date();
    const currentHourInt = now.getHours();
    const currentMinute = now.getMinutes();
    const currentTimeInt = currentHourInt * 100 + (currentMinute >= 30 ? 30 : 0);

    // Find current active value
    let currentActiveValue = null;
    let currentActiveTime = null;
    resolved.forEach(slot => {
        const time = parseInt(String(slot.time));
        if (time <= currentTimeInt) {
            if (currentActiveTime === null || time > currentActiveTime) {
                currentActiveTime = time;
                currentActiveValue = slot.value;
            }
        }
    });

    // Render all 24 hours
    for (let h = 0; h < 24; h++) {
        const hourTime = String(h).padStart(2, '0') + '00';
        const value = hourValues[h] !== undefined ? hourValues[h] : null;
        const isCurrentHour = (h === currentHourInt);

        const valDisplay = getValueLabel(value);
        const catClass = getValueClass(value);

        // Determine bar height/intensity based on value
        let barHeight = '10%';
        let barOpacity = 0.6;
        if (typeof value === 'number') {
            const absValue = Math.abs(value);
            if (absValue > 0) {
                barHeight = Math.min(100, 10 + (absValue / 3)) + '%';
                barOpacity = Math.min(1.0, 0.6 + (absValue / 400));
            }
        } else if (value === 'netzero' || value === 'netzero+') {
            barHeight = '80%';
            barOpacity = 0.9;
        }

        const hourDiv = document.createElement('div');
        hourDiv.className = `mini-timeline-hour ${isCurrentHour ? 'timeline-current' : ''}`;
        hourDiv.dataset.hour = h;
        hourDiv.dataset.time = hourTime;
        hourDiv.title = `${String(h).padStart(2, '0')}:00 - ${valDisplay}`;

        const barDiv = document.createElement('div');
        barDiv.className = `mini-timeline-bar ${catClass}`;
        barDiv.style.height = barHeight;
        barDiv.style.opacity = barOpacity;
        barDiv.setAttribute('data-height', barHeight);

        const labelDiv = document.createElement('div');
        labelDiv.className = 'mini-timeline-label-hour';
        labelDiv.textContent = String(h).padStart(2, '0');

        hourDiv.appendChild(barDiv);
        hourDiv.appendChild(labelDiv);

        // Add click handler to scroll to corresponding schedule item
        hourDiv.addEventListener('click', () => {
            const scheduleItems = document.querySelectorAll('#today-schedule-grid .schedule-item');
            scheduleItems.forEach(item => {
                const timeText = item.querySelector('.schedule-item-time')?.textContent.trim();
                if (timeText && timeText.startsWith(String(h).padStart(2, '0'))) {
                    item.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    item.style.transition = 'all 0.3s';
                    item.style.boxShadow = '0 0 0 3px rgba(100, 181, 246, 0.5)';
                    setTimeout(() => {
                        item.style.boxShadow = '';
                    }, 1000);
                }
            });
        });

        timeline.appendChild(hourDiv);
    }
}

/**
 * Render the bar graph for today and tomorrow
 * @param {Array} todayResolved - Resolved schedule slots for today
 * @param {Array} tomorrowResolved - Resolved schedule slots for tomorrow
 * @param {string} currentTime - Current time in HHmm format
 * @param {string} todayDate - Today's date in YYYYMMDD format
 * @param {string} tomorrowDate - Tomorrow's date in YYYYMMDD format
 * @param {Array} scheduleEntries - Array of schedule entries for lookup
 * @param {Object} editModal - Edit modal instance for click handlers
 */
function renderBarGraph(todayResolved, tomorrowResolved, currentTime, todayDate, tomorrowDate, scheduleEntries, editModal) {
    const todayContainer = document.getElementById('bar-graph-today');
    const tomorrowContainer = document.getElementById('bar-graph-tomorrow');

    if (!todayContainer || !tomorrowContainer) return;

    // Build a map of schedule entries for quick lookup
    const scheduleMap = {};
    if (scheduleEntries) {
        scheduleEntries.forEach(entry => {
            scheduleMap[entry.key] = entry.value;
        });
    }

    // Build hour-value maps for today and tomorrow
    const todayHourMap = buildHourMap(todayResolved);
    const tomorrowHourMap = buildHourMap(tomorrowResolved);

    // Calculate maximum absolute value for height scaling
    let maxAbsValue = 0;
    const allValues = [...todayResolved, ...tomorrowResolved].map(slot => slot.value);
    allValues.forEach(val => {
        if (val === 'netzero' || val === 'netzero+') {
            maxAbsValue = Math.max(maxAbsValue, 250);
        } else if (typeof val === 'number') {
            maxAbsValue = Math.max(maxAbsValue, Math.abs(val));
        }
    });
    // Ensure minimum max value for proper scaling
    if (maxAbsValue === 0) maxAbsValue = 250;

    // Get current date and hour
    const now = new Date();
    const currentDate = formatDateYYYYMMDD(now);
    const currentHour = now.getHours();

    // Helper function to render a row of bars
    const renderBarRow = (hourMap, dateStr, container, isToday) => {
        container.innerHTML = '';

        for (let h = 0; h < 24; h++) {
            const value = hourMap[h] !== undefined ? hourMap[h] : null;
            const hourTime = String(h).padStart(2, '0') + '00';
            const key = dateStr + hourTime;

            // Determine if this is the current hour
            const isCurrentHour = isToday && (h === currentHour) && (dateStr === currentDate);

            // Determine bar color class
            let barClass = 'bar-neutral';
            if (value === 'netzero') {
                barClass = 'bar-netzero';
            } else if (value === 'netzero+') {
                barClass = 'bar-netzero-plus';
            } else if (typeof value === 'number') {
                barClass = (value > 0) ? 'bar-charge' : ((value < 0) ? 'bar-discharge' : 'bar-neutral');
            }

            // Calculate bar height
            let barHeight = '4px'; // Minimum height
            if (value === 'netzero' || value === 'netzero+') {
                // Use 250 as proxy value
                barHeight = Math.max(4, (250 / maxAbsValue) * 100) + '%';
            } else if (typeof value === 'number' && value !== 0) {
                barHeight = Math.max(4, (Math.abs(value) / maxAbsValue) * 100) + '%';
            }

            // Get display label
            const valDisplay = getValueLabel(value);

            // Create bar element
            const barDiv = document.createElement('div');
            barDiv.className = `bar-graph-bar ${isCurrentHour ? 'bar-current' : ''}`;
            barDiv.dataset.date = dateStr;
            barDiv.dataset.hour = h;
            barDiv.dataset.time = hourTime;
            barDiv.dataset.key = key;
            barDiv.title = `${String(h).padStart(2, '0')}:00 - ${valDisplay}`;

            const barInner = document.createElement('div');
            barInner.className = `bar-graph-bar-inner ${barClass}`;
            barInner.style.height = barHeight;

            const barLabel = document.createElement('div');
            barLabel.className = 'bar-graph-bar-label';
            barLabel.textContent = String(h).padStart(2, '0');

            barDiv.appendChild(barInner);
            barDiv.appendChild(barLabel);

            // Add click handler
            barDiv.addEventListener('click', (e) => {
                if (editModal) {
                    const clickedKey = e.currentTarget.dataset.key;
                    const existingValue = scheduleMap[clickedKey];
                    if (existingValue !== undefined) {
                        editModal.open(clickedKey, existingValue);
                    } else {
                        editModal.open(null, null, clickedKey);
                    }
                }
            });

            container.appendChild(barDiv);
        }
    };

    // Render both rows
    renderBarRow(todayHourMap, todayDate, todayContainer, true);
    renderBarRow(tomorrowHourMap, tomorrowDate, tomorrowContainer, false);

    // Auto-scroll to current time (center it)
    setTimeout(() => {
        const currentBar = document.querySelector('.bar-graph-bar.bar-current');
        const container = document.querySelector('.bar-graph-container');
        if (currentBar && container) {
            const containerWidth = container.clientWidth;
            const barLeft = currentBar.offsetLeft;
            const barWidth = currentBar.clientWidth;

            // Calculate scroll position to center the bar
            const scrollPos = barLeft - (containerWidth / 2) + (barWidth / 2);
            container.scrollTo({
                left: scrollPos,
                behavior: 'smooth'
            });
        }
    }, 100);
}

/**
 * Render automation status section
 * @param {Object} data - Automation status data from API
 */
function renderAutomationStatus(data) {
    const errorDiv = document.getElementById('automation-status-error');
    const emptyDiv = document.getElementById('automation-status-empty');
    const wrapperDiv = document.getElementById('automation-entries-wrapper');

    // Clear existing content
    if (errorDiv) errorDiv.remove();
    if (emptyDiv) emptyDiv.remove();
    if (wrapperDiv) wrapperDiv.remove();

    const container = document.querySelector('.automation-status-wrapper .metric-section');
    if (!container) return;

    // Handle error
    if (!data.success) {
        const errorEl = document.createElement('div');
        errorEl.id = 'automation-status-error';
        errorEl.className = 'automation-status-error';
        errorEl.innerHTML = `<p>${escapeHtml(data.error || 'Failed to load automation status')}</p>`;
        container.appendChild(errorEl);
        return;
    }

    const entries = data.lastChanges || [];

    // Handle empty state
    if (entries.length === 0) {
        const emptyEl = document.createElement('div');
        emptyEl.id = 'automation-status-empty';
        emptyEl.className = 'automation-status-empty';
        emptyEl.innerHTML = '<p>No automation entries yet</p>';
        container.appendChild(emptyEl);
        return;
    }

    // Render entries
    const totalEntries = entries.length;
    const hasMoreEntries = totalEntries > 1;

    const wrapperEl = document.createElement('div');
    wrapperEl.id = 'automation-entries-wrapper';
    wrapperEl.className = 'automation-entries-wrapper';

    const listEl = document.createElement('div');
    listEl.id = 'automation-entries-list';
    listEl.className = 'automation-entries-list';
    listEl.dataset.totalEntries = totalEntries;

    entries.forEach((entry, index) => {
        const entryType = entry.type || 'unknown';
        const entryTimestamp = entry.timestamp || 0;
        const entryDetails = formatAutomationEntryDetails(entry);
        const badgeClass = getAutomationEntryTypeClass(entryType);
        const badgeLabel = getAutomationEntryTypeLabel(entryType);
        const isFirst = index === 0;
        const entryClass = 'automation-entry' + (isFirst ? ' automation-entry-first' : ' automation-entry-collapsed');

        const entryEl = document.createElement('div');
        entryEl.className = entryClass;
        entryEl.dataset.index = index;

        if (isFirst && hasMoreEntries) {
            entryEl.style.cursor = 'pointer';
            entryEl.onclick = toggleAutomationEntries;
        }

        entryEl.innerHTML = `
            <span class="automation-entry-badge ${badgeClass}">
                ${escapeHtml(badgeLabel)}
            </span>
            <span class="automation-entry-time">
                ${escapeHtml(formatRelativeTimeJS(entryTimestamp))}
                <span class="automation-entry-timestamp-full">(${escapeHtml(formatAbsoluteTimeJS(entryTimestamp))})</span>
            </span>
            <span class="automation-entry-details">
                ${escapeHtml(entryDetails)}
            </span>
            ${isFirst && hasMoreEntries ? '<span class="automation-entry-expand-icon">▼</span>' : ''}
        `;

        listEl.appendChild(entryEl);
    });

    wrapperEl.appendChild(listEl);
    container.appendChild(wrapperEl);
}

/**
 * Render charge/discharge status section
 * @param {Object} zendureData - Zendure data from API
 * @param {Object} p1Data - P1 data from API (optional)
 */
function renderChargeStatus(zendureData, p1Data = null) {
    const errorDiv = document.getElementById('charge-status-error');
    const emptyDiv = document.getElementById('charge-status-empty');
    const contentDiv = document.getElementById('charge-status-content');

    // Clear existing content
    if (errorDiv) errorDiv.remove();
    if (emptyDiv) emptyDiv.remove();
    if (contentDiv) contentDiv.remove();

    const container = document.querySelector('.charge-status-wrapper .metric-section');
    if (!container) return;

    // Handle error
    if (!zendureData || !zendureData.success) {
        const errorEl = document.createElement('div');
        errorEl.id = 'charge-status-error';
        errorEl.className = 'charge-status-error';
        errorEl.innerHTML = `<p>${escapeHtml(zendureData?.error || 'Failed to load charge status')}</p>`;
        container.appendChild(errorEl);
        return;
    }
    // Properties can be at zendureData.data.properties or zendureData.properties
    const properties = zendureData.data?.properties || zendureData.properties;
    
    if (!properties) {
        const emptyEl = document.createElement('div');
        emptyEl.id = 'charge-status-empty';
        emptyEl.className = 'charge-status-empty';
        emptyEl.innerHTML = '<p>No charge status data available</p>';
        container.appendChild(emptyEl);
        return;
    }

    // Extract properties
    const acMode = properties.acMode || 0;
    const outputPackPower = properties.outputPackPower || 0;
    const outputHomePower = properties.outputHomePower || 0;
    const acStatus = properties.acStatus || 0;
    const electricLevel = properties.electricLevel || 0;
    const solarInputPower = properties.solarInputPower || 0;

    // Calculate charge/discharge value
    const chargeDischargeValue = (outputPackPower > 0) ? outputPackPower : ((outputHomePower > 0) ? -outputHomePower : 0);

    // Get system status
    const systemStatus = getSystemStatusInfoJS(acMode, outputPackPower, outputHomePower, solarInputPower, electricLevel);

    // Determine power color
    let powerColor;
    if (chargeDischargeValue > 0) {
        powerColor = '#66bb6a'; // Green for charging
    } else if (chargeDischargeValue < 0) {
        powerColor = '#ef5350'; // Red for discharging
    } else {
        powerColor = '#9e9e9e'; // Gray for standby
    }

    // Calculate power display and time estimate
    const MIN_CHARGE_LEVEL_RAW = (typeof CHARGE_STATUS_MIN_CHARGE_LEVEL !== 'undefined')
        ? Number(CHARGE_STATUS_MIN_CHARGE_LEVEL)
        : 20;
    const MAX_CHARGE_LEVEL_RAW = (typeof CHARGE_STATUS_MAX_CHARGE_LEVEL !== 'undefined')
        ? Number(CHARGE_STATUS_MAX_CHARGE_LEVEL)
        : 90;
    const MIN_CHARGE_LEVEL = Math.max(0, Math.min(100, MIN_CHARGE_LEVEL_RAW));
    const MAX_CHARGE_LEVEL = Math.max(MIN_CHARGE_LEVEL, Math.min(100, MAX_CHARGE_LEVEL_RAW));
    const TOTAL_CAPACITY_KWH = 5.76;

    let powerDisplay = '0 W';
    let timeEstimate = '';

    if (chargeDischargeValue > 0) {
        // Charging
        powerDisplay = '+' + chargeDischargeValue.toLocaleString() + ' W';
        const capacityToMaxKwh = ((MAX_CHARGE_LEVEL - electricLevel) / 100) * TOTAL_CAPACITY_KWH;
        const capacityToMaxWh = capacityToMaxKwh * 1000;
        if (chargeDischargeValue > 0 && capacityToMaxWh > 0) {
            const hoursToMax = capacityToMaxWh / chargeDischargeValue;
            timeEstimate = formatTimeEstimate(hoursToMax);
        }
    } else if (chargeDischargeValue < 0) {
        // Discharging
        powerDisplay = chargeDischargeValue.toLocaleString() + ' W';
        const capacityToMinKwh = ((electricLevel - MIN_CHARGE_LEVEL) / 100) * TOTAL_CAPACITY_KWH;
        const capacityToMinWh = capacityToMinKwh * 1000;
        const absPower = Math.abs(chargeDischargeValue);
        if (absPower > 0 && capacityToMinWh > 0) {
            const hoursToMin = capacityToMinWh / absPower;
            timeEstimate = formatTimeEstimate(hoursToMin);
        }
    }

    // Calculate bar width
    const minPower = -1200;
    const maxPower = 1200;
    const clampedValue = Math.max(minPower, Math.min(maxPower, chargeDischargeValue));
    let barWidth = 0;
    let barClass = '';

    if (clampedValue > 0) {
        barWidth = Math.max(6, (Math.abs(clampedValue) / Math.abs(maxPower)) * 50);
        barClass = 'charging';
    } else if (clampedValue < 0) {
        barWidth = Math.max(6, (Math.abs(clampedValue) / Math.abs(minPower)) * 50);
        barClass = 'discharging';
    }

    // Calculate battery capacity
    const usableNetKwh = Math.max(0, ((electricLevel - MIN_CHARGE_LEVEL) / 100) * TOTAL_CAPACITY_KWH);
    const roomToChargeKwh = Math.max(0, ((MAX_CHARGE_LEVEL - electricLevel) / 100) * TOTAL_CAPACITY_KWH);

    // Build content HTML
    const contentEl = document.createElement('div');
    contentEl.id = 'charge-status-content';
    contentEl.className = 'charge-status-content';
    contentEl.innerHTML = `
        <!-- Status Indicator -->
        <div class="charge-status-indicator ${systemStatus.class}">
            <div class="charge-status-icon">${systemStatus.icon}</div>
            <div class="charge-status-text">
                <div class="charge-status-title">${escapeHtml(systemStatus.title)}</div>
                <div class="charge-status-subtitle">${escapeHtml(systemStatus.subtitle)}</div>
            </div>
        </div>

        <!-- Power Value -->
        <div class="charge-power-display">
            <div class="charge-power-label-value">
                <span class="charge-power-label">Power:</span>
                <span class="charge-power-value" style="color: ${escapeHtml(powerColor)};">
                    ${escapeHtml(powerDisplay)}
                    ${timeEstimate ? `<span class="charge-power-time">(${escapeHtml(timeEstimate)})</span>` : ''}
                </span>
            </div>
            <div class="charge-power-bar-container">
                <div class="charge-power-bar-label left">-1200 W</div>
                <div class="charge-power-bar-label center">0</div>
                <div class="charge-power-bar-label right">1200 W</div>
                <div class="charge-power-bar-center"></div>
                ${barWidth > 0 ? `<div class="charge-power-bar-fill ${barClass}" style="width: ${barWidth}%;"></div>` : ''}
            </div>
        </div>

        <!-- Battery Level -->
        <div class="charge-battery-display">
            <div class="charge-battery-label-value">
                <span class="charge-battery-label">Battery Level:</span>
                <span class="charge-battery-value">
                    ${electricLevel}% (${usableNetKwh.toFixed(2)} kWh - ${roomToChargeKwh.toFixed(2)} kWh)
                </span>
            </div>
            <div class="charge-battery-bar">
                <div class="charge-battery-bar-marker min" style="left: ${MIN_CHARGE_LEVEL}%;" title="Minimum: ${MIN_CHARGE_LEVEL}%"></div>
                <div class="charge-battery-bar-marker max" style="left: ${MAX_CHARGE_LEVEL}%;" title="Maximum: ${MAX_CHARGE_LEVEL}%"></div>
                <div class="charge-battery-bar-fill" style="width: ${Math.min(100, Math.max(0, electricLevel))}%; background-color: ${escapeHtml(systemStatus.color)};"></div>
            </div>
        </div>
    `;

    container.appendChild(contentEl);

    // Update or create last update time element
    // Remove any existing last update element to ensure correct order
    const existingLastUpdateDiv = container.querySelector('.charge-status-header');
    if (existingLastUpdateDiv) {
        existingLastUpdateDiv.remove();
    }

    if (zendureData.timestamp) {
        const relativeTime = formatRelativeTimeJS(zendureData.timestamp);
        const absoluteTime = formatAbsoluteTimeJS(zendureData.timestamp);

        // Create new element and append to container (after content)
        const newLastUpdateDiv = document.createElement('div');
        newLastUpdateDiv.className = 'charge-status-header';
        newLastUpdateDiv.innerHTML = `
            <span class="charge-last-update" id="charge-last-update">
                Last update: ${escapeHtml(relativeTime)}
                <span class="charge-timestamp-full">(${escapeHtml(absoluteTime)})</span>
            </span>
        `;
        container.appendChild(newLastUpdateDiv);
    }
}

// Helper functions for rendering

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatRelativeTimeJS(timestamp) {
    if (!timestamp) return 'Unknown';

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
        return formatAbsoluteTimeJS(timestamp);
    }
}

function formatAbsoluteTimeJS(timestamp) {
    if (!timestamp) return '';
    const date = new Date(timestamp * 1000);
    return date.getFullYear() + '-' +
        String(date.getMonth() + 1).padStart(2, '0') + '-' +
        String(date.getDate()).padStart(2, '0') + ' ' +
        String(date.getHours()).padStart(2, '0') + ':' +
        String(date.getMinutes()).padStart(2, '0') + ':' +
        String(date.getSeconds()).padStart(2, '0');
}

function formatAutomationEntryDetails(entry) {
    const type = entry.type || 'unknown';
    const oldValue = entry.oldValue;
    const newValue = entry.newValue;

    const parts = [];
    if (oldValue !== null && oldValue !== undefined) {
        parts.push(formatAutomationValue(oldValue));
    }
    if (newValue !== null && newValue !== undefined) {
        if (parts.length > 0) {
            parts.push('→');
        }
        parts.push(formatAutomationValue(newValue));
    }
    const details = parts.join(' ');
    return type.charAt(0).toUpperCase() + type.slice(1) + (details ? ' (' + details + ')' : '');
}

function formatAutomationValue(value) {
    if (value === null || value === undefined) {
        return '—';
    }
    if (typeof value === 'number') {
        return value + ' W';
    }
    return String(value);
}

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

function getAutomationEntryTypeLabel(type) {
    return String(type).charAt(0).toUpperCase() + String(type).slice(1);
}

function getSystemStatusInfoJS(acMode, outputPackPower, outputHomePower, solarInputPower, electricLevel) {
    const status = {
        state: acMode,
        class: 'standby',
        icon: '⚪',
        title: 'Standby',
        subtitle: 'No active power flow',
        color: '#9e9e9e'
    };

    if (acMode == 1) {
        status.class = 'charging';
        status.icon = '🔵';
        status.title = 'Charging';
        status.subtitle = 'Battery is being charged';
        status.color = '#66bb6a';
    } else if (acMode == 2) {
        status.class = 'discharging';
        status.icon = '🔴';
        status.title = 'Discharging';
        status.subtitle = 'Battery is powering the home';
        status.color = '#ef5350';
    }

    return status;
}

function formatTimeEstimate(hours) {
    if (hours < 0.0167) { // Less than 1 minute
        return '< 1m left';
    } else if (hours < 1) {
        const minutes = Math.round(hours * 60);
        return minutes + 'm left';
    } else {
        let h = Math.floor(hours);
        let minutes = Math.round((hours - h) * 60);
        if (minutes >= 60) {
            h++;
            minutes = 0;
        }
        return h + ':' + String(minutes).padStart(2, '0') + 'h left';
    }
}

/**
 * Convert hyperTmp from one-tenth Kelvin to Celsius
 * Formula: (hyperTmp - 2731) / 10.0
 */
function convertHyperTmpJS(hyperTmp) {
    return (hyperTmp - 2731) / 10.0;
}

/**
 * Get color for temperature display with enhanced gradient
 * Blue (cold) -> Light yellow -> Yellow -> Green -> Orange -> Red (hot)
 * Scale: -10 to +40 degrees Celsius
 */
function getTempColorEnhancedJS(temp) {
    // Clamp temperature to range
    temp = Math.max(-10, Math.min(40, temp));
    
    if (temp <= 0) {
        return '#4fc3f7'; // Blue
    } else if (temp <= 5) {
        return '#fff176'; // Light yellow
    } else if (temp <= 15) {
        return '#ffe500'; // Yellow
    } else if (temp <= 25) {
        return '#81c784'; // Green
    } else if (temp <= 30) {
        return '#ff9800'; // Orange
    } else {
        return '#e57373'; // Red
    }
}

/**
 * Find element by label text within a container
 */
function findElementByLabel(container, labelText) {
    const labels = container.querySelectorAll('.charge-battery-label');
    for (const label of labels) {
        if (label.textContent.trim().startsWith(labelText)) {
            return label.closest('.charge-battery-display');
        }
    }
    return null;
}

/**
 * Render charge status details section (System & Grid)
 * Updates all System & Grid values dynamically
 * @param {Object} zendureData - Zendure data from API
 * @param {Object} p1Data - P1 data from API (optional)
 */
function renderChargeStatusDetails(zendureData, p1Data = null) {
    const detailsContainer = document.getElementById('charge-status-details-content');
    if (!detailsContainer) {
        // Container not found - this is expected in mobile version where details section is not included
        return;
    }

    // Constants
    const MIN_CHARGE_LEVEL_RAW = (typeof CHARGE_STATUS_MIN_CHARGE_LEVEL !== 'undefined')
        ? Number(CHARGE_STATUS_MIN_CHARGE_LEVEL)
        : 20;
    const MAX_CHARGE_LEVEL_RAW = (typeof CHARGE_STATUS_MAX_CHARGE_LEVEL !== 'undefined')
        ? Number(CHARGE_STATUS_MAX_CHARGE_LEVEL)
        : 90;
    const MIN_CHARGE_LEVEL = Math.max(0, Math.min(100, MIN_CHARGE_LEVEL_RAW));
    const MAX_CHARGE_LEVEL = Math.max(MIN_CHARGE_LEVEL, Math.min(100, MAX_CHARGE_LEVEL_RAW));
    const TOTAL_CAPACITY_KWH = 5.76;
    const minTemp = -10;
    const maxTemp = 40;

    // Extract Zendure data
    const properties = zendureData?.data?.properties || zendureData?.properties || {};
    const packData = zendureData?.data?.packData || zendureData?.packData || [];
    const packNum = properties.packNum || Math.max(1, packData.length);
    const packCapacityKwh = TOTAL_CAPACITY_KWH / Math.max(1, packNum);

    // Update Grid power
    const gridValueSpan = document.querySelector('.charge-power-box .charge-power-value');
    const gridBarFill = document.querySelector('.charge-grid-bar-fill');
    const gridBarContainer = document.querySelector('.charge-grid-bar-container');

    if (gridValueSpan && gridBarContainer) {
        // P1 data can be flat or nested - handle both cases
        const p1TotalPower = p1Data?.total_power || p1Data?.data?.total_power || 0;
        const gridPowerDisplay = p1TotalPower.toLocaleString() + ' W';

        gridValueSpan.textContent = gridPowerDisplay;

        // Calculate bar width for -1200 to +1200 range
        const minGridPower = -1200;
        const maxGridPower = 1200;
        const clampedGridValue = Math.max(minGridPower, Math.min(maxGridPower, p1TotalPower));

        let gridBarWidth = 0;
        let gridBarClass = '';
        const isAboveMax = p1TotalPower > maxGridPower;
        const isBelowMin = p1TotalPower < minGridPower;

        if (clampedGridValue > 0) {
            gridBarWidth = Math.max(6, (Math.abs(clampedGridValue) / Math.abs(maxGridPower)) * 50);
            gridBarClass = 'positive';
            if (isAboveMax) {
                gridBarClass += ' overflow';
            }
        } else if (clampedGridValue < 0) {
            gridBarWidth = Math.max(6, (Math.abs(clampedGridValue) / Math.abs(minGridPower)) * 50);
            gridBarClass = 'negative';
            if (isBelowMin) {
                gridBarClass += ' overflow';
            }
        }

        // Update or create the bar fill
        if (gridBarFill) {
            if (gridBarWidth > 0) {
                gridBarFill.className = 'charge-grid-bar-fill ' + gridBarClass;
                gridBarFill.style.width = gridBarWidth + '%';
            } else {
                gridBarFill.remove();
            }
        } else if (gridBarWidth > 0) {
            const newBarFill = document.createElement('div');
            newBarFill.className = 'charge-grid-bar-fill ' + gridBarClass;
            newBarFill.style.width = gridBarWidth + '%';
            gridBarContainer.appendChild(newBarFill);
        }
    }

    // Update WiFi Signal (RSSI)
    const wifiDisplay = findElementByLabel(detailsContainer, 'WiFi Signal:');
    if (wifiDisplay) {
        const rssi = properties.rssi ?? -90;
        const minRssi = -90;
        const maxRssi = -30;
        let rssiScore = ((rssi - minRssi) / (maxRssi - minRssi)) * 10;
        rssiScore = Math.max(0, Math.min(10, rssiScore));

        let rssiColor = '#e57373'; // Default: Red
        if (rssiScore >= 8) {
            rssiColor = '#81c784'; // Green
        } else if (rssiScore >= 5) {
            rssiColor = '#fff176'; // Yellow
        } else if (rssiScore >= 3) {
            rssiColor = '#ff9800'; // Orange
        }

        const wifiValue = wifiDisplay.querySelector('.charge-battery-value');
        const wifiBarFill = wifiDisplay.querySelector('.charge-battery-bar-fill');
        
        if (wifiValue) {
            wifiValue.textContent = `${rssiScore.toFixed(1)}/10 (${rssi.toLocaleString()} dBm)`;
        }
        if (wifiBarFill) {
            wifiBarFill.style.width = `${Math.min(100, Math.max(0, rssiScore * 10))}%`;
            wifiBarFill.style.backgroundColor = rssiColor;
        }
    }

    // Update System Temperature
    const systemTempDisplay = findElementByLabel(detailsContainer, 'System Temp:');
    if (systemTempDisplay) {
        const hyperTmp = properties.hyperTmp ?? 2731;
        const systemTempCelsius = convertHyperTmpJS(hyperTmp);
        const systemHeatState = properties.heatState ?? 0;
        const systemTempColor = getTempColorEnhancedJS(systemTempCelsius);
        const systemTempPercent = ((systemTempCelsius - minTemp) / (maxTemp - minTemp)) * 100;
        const clampedSystemTempPercent = Math.max(0, Math.min(100, systemTempPercent));

        const systemTempValue = systemTempDisplay.querySelector('.charge-battery-value');
        const systemTempBarFill = systemTempDisplay.querySelector('.charge-battery-bar-fill');
        
        if (systemTempValue) {
            const systemHeatIcon = systemHeatState == 1 ? '🔥' : '❄️';
            systemTempValue.textContent = `${systemTempCelsius.toFixed(1)}°C ${systemHeatIcon}`;
        }
        if (systemTempBarFill) {
            systemTempBarFill.style.width = `${clampedSystemTempPercent}%`;
            systemTempBarFill.style.backgroundColor = systemTempColor;
        }
    }

    // Update Battery 1 Level
    const battery1LevelDisplay = findElementByLabel(detailsContainer, 'Battery 1 Level:');
    if (battery1LevelDisplay) {
        const pack1Soc = packData[0]?.socLevel ?? 0;
        const pack1UsableNetKwh = Math.max(0, ((pack1Soc - MIN_CHARGE_LEVEL) / 100) * packCapacityKwh);
        const pack1RoomToChargeKwh = Math.max(0, ((MAX_CHARGE_LEVEL - pack1Soc) / 100) * packCapacityKwh);

        const battery1Value = battery1LevelDisplay.querySelector('.charge-battery-value');
        const battery1BarFill = battery1LevelDisplay.querySelector('.charge-battery-bar-fill');
        
        if (battery1Value) {
            battery1Value.textContent = `${pack1Soc.toFixed(0)}% (${pack1UsableNetKwh.toFixed(2)} kWh - ${pack1RoomToChargeKwh.toFixed(2)} kWh)`;
        }
        if (battery1BarFill) {
            battery1BarFill.style.width = `${Math.min(100, Math.max(0, pack1Soc))}%`;
        }
    }

    // Update Battery 1 Temperature
    const battery1TempDisplay = findElementByLabel(detailsContainer, 'Battery 1 Temp:');
    if (battery1TempDisplay) {
        const pack1MaxTemp = packData[0]?.maxTemp ?? 2731;
        const pack1TempCelsius = convertHyperTmpJS(pack1MaxTemp);
        const pack1HeatState = packData[0]?.heatState ?? 0;
        const pack1TempColor = getTempColorEnhancedJS(pack1TempCelsius);
        const pack1TempPercent = ((pack1TempCelsius - minTemp) / (maxTemp - minTemp)) * 100;
        const clampedPack1TempPercent = Math.max(0, Math.min(100, pack1TempPercent));

        const battery1TempValue = battery1TempDisplay.querySelector('.charge-battery-value');
        const battery1TempBarFill = battery1TempDisplay.querySelector('.charge-battery-bar-fill');
        
        if (battery1TempValue) {
            const pack1HeatIcon = pack1HeatState == 1 ? '🔥' : '❄️';
            battery1TempValue.textContent = `${pack1TempCelsius.toFixed(1)}°C ${pack1HeatIcon}`;
        }
        if (battery1TempBarFill) {
            battery1TempBarFill.style.width = `${clampedPack1TempPercent}%`;
            battery1TempBarFill.style.backgroundColor = pack1TempColor;
        }
    }

    // Update Battery 2 Level
    const battery2LevelDisplay = findElementByLabel(detailsContainer, 'Battery 2 Level:');
    if (battery2LevelDisplay) {
        const pack2Soc = packData[1]?.socLevel ?? 0;
        const pack2UsableNetKwh = Math.max(0, ((pack2Soc - MIN_CHARGE_LEVEL) / 100) * packCapacityKwh);
        const pack2RoomToChargeKwh = Math.max(0, ((MAX_CHARGE_LEVEL - pack2Soc) / 100) * packCapacityKwh);

        const battery2Value = battery2LevelDisplay.querySelector('.charge-battery-value');
        const battery2BarFill = battery2LevelDisplay.querySelector('.charge-battery-bar-fill');
        
        if (battery2Value) {
            battery2Value.textContent = `${pack2Soc.toFixed(0)}% (${pack2UsableNetKwh.toFixed(2)} kWh - ${pack2RoomToChargeKwh.toFixed(2)} kWh)`;
        }
        if (battery2BarFill) {
            battery2BarFill.style.width = `${Math.min(100, Math.max(0, pack2Soc))}%`;
        }
    }

    // Update Battery 2 Temperature
    const battery2TempDisplay = findElementByLabel(detailsContainer, 'Battery 2 Temp:');
    if (battery2TempDisplay) {
        const pack2MaxTemp = packData[1]?.maxTemp ?? 2731;
        const pack2TempCelsius = convertHyperTmpJS(pack2MaxTemp);
        const pack2HeatState = packData[1]?.heatState ?? 0;
        const pack2TempColor = getTempColorEnhancedJS(pack2TempCelsius);
        const pack2TempPercent = ((pack2TempCelsius - minTemp) / (maxTemp - minTemp)) * 100;
        const clampedPack2TempPercent = Math.max(0, Math.min(100, pack2TempPercent));

        const battery2TempValue = battery2TempDisplay.querySelector('.charge-battery-value');
        const battery2TempBarFill = battery2TempDisplay.querySelector('.charge-battery-bar-fill');
        
        if (battery2TempValue) {
            const pack2HeatIcon = pack2HeatState == 1 ? '🔥' : '❄️';
            battery2TempValue.textContent = `${pack2TempCelsius.toFixed(1)}°C ${pack2HeatIcon}`;
        }
        if (battery2TempBarFill) {
            battery2TempBarFill.style.width = `${clampedPack2TempPercent}%`;
            battery2TempBarFill.style.backgroundColor = pack2TempColor;
        }
    }
}
