/**
 * Schedule Panels
 * Rendering functions for Today's Schedule and Schedule Entries panels
 */

// Helper function to get label from value (shared with main script)
function getValueLabel(value) {
    if (value === null || typeof value === 'undefined') return '-';
    if (typeof value === 'number') return (value > 0 ? '+' : '') + value + ' W';

    // Try to find the radio button with matching value and get its label attribute
    const radio = document.querySelector(`input[name="val-mode"][value="${value}"]`);
    if (radio && radio.hasAttribute('label')) {
        return radio.getAttribute('label');
    }

    // Fallback to hardcoded values if modal not loaded yet
    if (value === 'netzero') return 'Net Zero';
    if (value === 'netzero+') return 'Solar Charge';

    return value + ' W';
}

/**
 * Renders the today's schedule list
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

        let bgClass = 'time-evening';
        if (h >= 22 || h < 6) bgClass = 'time-night';
        else if (h < 12) bgClass = 'time-morning';
        else if (h < 18) bgClass = 'time-afternoon';

        let valText = getValueLabel(val);
        let valClass = 'neutral';
        if (val === 'netzero') {
            valClass = 'netzero';
        } else if (val === 'netzero+') {
            valClass = 'netzero-plus';
        } else if (val !== null) {
            valClass = (val > 0) ? 'charge' : ((val < 0) ? 'discharge' : 'neutral');
        }

        const div = document.createElement('div');
        div.className = `schedule-item ${bgClass} ${isCurrent ? 'slot-current' : ''}`;
        div.innerHTML = `
            <div class="schedule-item-time">${time.substring(0, 2)}:${time.substring(2, 4)}</div>
            <div class="schedule-item-value ${valClass}">${valText}</div>
            ${slot.key ? `<div class="schedule-item-key">${slot.key}</div>` : ''}
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

    // Sort entries same as PHP: key asc
    entries.sort((a, b) => String(a.key).localeCompare(String(b.key)));

    entries.forEach((entry, idx) => {
        const tr = document.createElement('tr');
        tr.dataset.key = entry.key;
        tr.dataset.value = entry.value;

        // Ensure key is string (PHP might send int for numeric keys)
        const keyStr = String(entry.key);
        const isWild = keyStr.includes('*');
        let displayVal = getValueLabel(entry.value);
        let valClass = (entry.value === 'netzero') ? 'netzero' : (entry.value === 'netzero+' ? 'netzero-plus' : (entry.value > 0 ? 'charge' : (entry.value < 0 ? 'discharge' : 'neutral')));
        const keyBgColor = isWild ? '#fff9c4' : '#e8f5e9'; // Light yellow for wildcard, light green for exact

        tr.innerHTML = `
            <td style="color:#888;">${idx + 1}</td>
            <td style="font-family:monospace; background-color:${keyBgColor};">${keyStr}</td>
            <td class="${valClass}" style="font-weight:500;">${displayVal}</td>
        `;
        tbody.appendChild(tr);
    });
}
