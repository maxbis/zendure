/**
 * Schedule Overview Bar Graph
 * Renders the bar graph visualization for today and tomorrow's schedule
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
 * Renders the bar graph for today and tomorrow
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
                    // Get key from the clicked element's dataset to avoid closure issues
                    const clickedKey = e.currentTarget.dataset.key;
                    // Check if entry exists
                    const existingValue = scheduleMap[clickedKey];
                    // If existingValue is undefined, we pass clickedKey as the 3rd argument (prefillKey)
                    // and null as the 1st argument (key) to indicate "Add Mode"
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
