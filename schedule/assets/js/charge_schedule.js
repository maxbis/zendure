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
// renderToday() and renderEntries() are now in schedule_panels.js

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
            if (typeof renderEntries === 'function') {
                renderEntries(todayData.entries);
            }
            if (typeof renderToday === 'function') {
                renderToday(todayData.resolved, todayData.currentHour, currentTime);
            }
            renderMiniTimeline(todayData.resolved, currentTime);
            const statusBar = document.getElementById('status-bar');
            if (statusBar) {
                statusBar.innerHTML = `<span>${todayData.entries.length} entries loaded.</span>`;
            }

            // Render bar graph with both today and tomorrow data
            if (todayData.success && tomorrowData.success && typeof renderBarGraph === 'function') {
                renderBarGraph(
                    todayData.resolved || [],
                    tomorrowData.resolved || [],
                    currentTime,
                    today,
                    tomorrow,
                    todayData.entries || [],
                    editModal
                );
            }
            
            // Fetch and render price data
            if (typeof PRICE_API_URL !== 'undefined' && PRICE_API_URL && typeof fetchAndRenderPrices === 'function') {
                fetchAndRenderPrices(PRICE_API_URL, todayData.entries || [], editModal);
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


