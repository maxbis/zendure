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
