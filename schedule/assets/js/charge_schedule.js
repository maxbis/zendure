/**
 * Charge Schedule Manager
 * Main application logic for rendering and managing schedule data
 * 
 * API_URL is injected from PHP (charge_schedule.php) which reads it from config.json
 * If not defined, fallback to old endpoint
 */

// API_URL is injected from PHP via inline script tag
// If not injected, use fallback (but PHP should always inject it)
if (typeof API_URL === 'undefined') {
    // Assign to window to avoid const redeclaration error
    window.API_URL = 'api/charge_schedule_api.php';
}

// Helper function to get label from radio button by value
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

// --- UI Rendering ---

function renderToday(resolved, currentHour, currentTime) {
    const container = document.getElementById('today-schedule-grid');
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

function renderMiniTimeline(resolved, currentTime) {
    const timeline = document.getElementById('mini-timeline');
    if (!timeline) return;

    timeline.innerHTML = '';

    // Build a map of all hours with their values
    const hourValues = {};
    let lastValue = null;
    resolved.forEach(slot => {
        const hour = parseInt(String(slot.time).substring(0, 2));
        const value = slot.value;
        if (value !== null) {
            lastValue = value;
        }
        hourValues[hour] = lastValue;
    });

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
        let catClass = 'neutral';
        if (value === 'netzero') {
            catClass = 'netzero';
        } else if (value === 'netzero+') {
            catClass = 'netzero-plus';
        } else if (typeof value === 'number') {
            catClass = (value > 0) ? 'charge' : ((value < 0) ? 'discharge' : 'neutral');
        }

        // Determine bar height/intensity based on value - very pronounced differences
        let barHeight = '10%';
        let barOpacity = 0.6;
        if (typeof value === 'number') {
            const absValue = Math.abs(value);
            if (absValue > 0) {
                // Very aggressive scaling: 10% base + (value/3) up to 100%
                // Examples: 100W = 43%, 200W = 77%, 300W = 100%
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
            // Find the schedule item that matches this hour
            const scheduleItems = document.querySelectorAll('#today-schedule-grid .schedule-item');
            scheduleItems.forEach(item => {
                const timeText = item.querySelector('.schedule-item-time')?.textContent.trim();
                if (timeText && timeText.startsWith(String(h).padStart(2, '0'))) {
                    item.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    // Add a temporary highlight
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

function renderEntries(entries) {
    const tbody = document.querySelector('#schedule-table tbody');
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

        tr.innerHTML = `
            <td style="color:#888;">${idx + 1}</td>
            <td style="font-family:monospace;">${keyStr}</td>
            <td class="${valClass}" style="font-weight:500;">${displayVal}</td>
            <td><span class="badge ${isWild ? 'badge-wildcard' : 'badge-exact'}">${isWild ? 'Wildcard' : 'Exact'}</span></td>
        `;
        tbody.appendChild(tr);
    });
}

function renderBarGraph(todayResolved, tomorrowResolved, currentTime, todayDate, tomorrowDate, scheduleEntries) {
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
    const buildHourMap = (resolved) => {
        const hourMap = {};
        let lastValue = null;
        resolved.forEach(slot => {
            const hour = parseInt(String(slot.time).substring(0, 2));
            const value = slot.value;
            if (value !== null) {
                lastValue = value;
            }
            hourMap[hour] = lastValue;
        });
        return hourMap;
    };

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
    const currentDate = now.getFullYear().toString() +
        String(now.getMonth() + 1).padStart(2, '0') +
        String(now.getDate()).padStart(2, '0');
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
            barDiv.addEventListener('click', () => {
                if (editModal) {
                    // Check if entry exists
                    const existingValue = scheduleMap[key];
                    // If existingValue is undefined, we pass key as the 3rd argument (prefillKey)
                    // and null as the 1st argument (key) to indicate "Add Mode"
                    if (existingValue !== undefined) {
                        editModal.open(key, existingValue);
                    } else {
                        editModal.open(null, null, key);
                    }
                }
            });

            container.appendChild(barDiv);
        }
    };

    // Render both rows
    renderBarRow(todayHourMap, todayDate, todayContainer, true);
    renderBarRow(tomorrowHourMap, tomorrowDate, tomorrowContainer, false);
}

async function refreshData() {
    try {
        // Get today and tomorrow dates in YYYYMMDD format
        const now = new Date();
        const today = now.getFullYear().toString() +
            String(now.getMonth() + 1).padStart(2, '0') +
            String(now.getDate()).padStart(2, '0');
        const tomorrowDate = new Date(now);
        tomorrowDate.setDate(tomorrowDate.getDate() + 1);
        const tomorrow = tomorrowDate.getFullYear().toString() +
            String(tomorrowDate.getMonth() + 1).padStart(2, '0') +
            String(tomorrowDate.getDate()).padStart(2, '0');

        // Fetch today's data
        const todayUrl = API_URL + (API_URL.includes('?') ? '&' : '?') + 'date=' + today;
        const todayRes = await fetch(todayUrl);
        if (!todayRes.ok) {
            throw new Error(`HTTP error! status: ${todayRes.status}`);
        }

        const todayContentType = todayRes.headers.get('content-type');
        if (!todayContentType || !todayContentType.includes('application/json')) {
            const text = await todayRes.text();
            console.error('Non-JSON response:', text.substring(0, 200));
            throw new Error('Server returned non-JSON response. Check console for details.');
        }

        const todayData = await todayRes.json();

        // Fetch tomorrow's data
        const tomorrowUrl = API_URL + (API_URL.includes('?') ? '&' : '?') + 'date=' + tomorrow;
        const tomorrowRes = await fetch(tomorrowUrl);
        if (!tomorrowRes.ok) {
            throw new Error(`HTTP error! status: ${tomorrowRes.status}`);
        }

        const tomorrowContentType = tomorrowRes.headers.get('content-type');
        if (!tomorrowContentType || !tomorrowContentType.includes('application/json')) {
            const text = await tomorrowRes.text();
            console.error('Non-JSON response:', text.substring(0, 200));
            throw new Error('Server returned non-JSON response. Check console for details.');
        }

        const tomorrowData = await tomorrowRes.json();

        if (todayData.success) {
            const currentTime = todayData.currentTime || todayData.currentHour || new Date().getHours().toString().padStart(2, '0') + '00';
            renderEntries(todayData.entries);
            renderToday(todayData.resolved, todayData.currentHour, currentTime);
            renderMiniTimeline(todayData.resolved, currentTime);
            document.getElementById('status-bar').innerHTML = `<span>${todayData.entries.length} entries loaded.</span>`;

            // Render bar graph with both today and tomorrow data
            if (todayData.success && tomorrowData.success) {
                renderBarGraph(
                    todayData.resolved || [],
                    tomorrowData.resolved || [],
                    currentTime,
                    today,
                    tomorrow,
                    todayData.entries || []
                );

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
        }
    } catch (e) {
        console.error(e);
        alert('Connection failed: ' + e.message);
    }
}

// Initialize application
let editModal;

document.addEventListener('DOMContentLoaded', () => {
    // Initialize edit modal with callback to refresh data after save/delete
    editModal = new EditModal(API_URL, refreshData);

    // Initial data load
    refreshData();
});

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

