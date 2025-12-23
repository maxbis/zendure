/**
 * Charge Schedule Manager
 * Main application logic for rendering and managing schedule data
 */

const API_URL = 'api/charge_schedule_api.php';

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

function renderToday(resolved, currentHour) {
    const container = document.getElementById('today-schedule-grid');
    container.innerHTML = '';

    let prevVal = null;

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
        const isCurrent = (time === currentHour);

        let bgClass = 'time-evening';
        if (h >= 22 || h < 6) bgClass = 'time-night';
        else if (h < 12) bgClass = 'time-morning';
        else if (h < 18) bgClass = 'time-afternoon';

        let valText = getValueLabel(val);
        let valClass = 'neutral';
        if (val === 'netzero' || val === 'netzero+') {
            valClass = 'netzero';
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
        let valClass = (entry.value === 'netzero' || entry.value === 'netzero+') ? 'netzero' : (entry.value > 0 ? 'charge' : (entry.value < 0 ? 'discharge' : 'neutral'));

        tr.innerHTML = `
            <td style="color:#888;">${idx + 1}</td>
            <td style="font-family:monospace;">${keyStr}</td>
            <td class="${valClass}" style="font-weight:500;">${displayVal}</td>
            <td><span class="badge ${isWild ? 'badge-wildcard' : 'badge-exact'}">${isWild ? 'Wildcard' : 'Exact'}</span></td>
        `;
        tbody.appendChild(tr);
    });
}

async function refreshData() {
    try {
        const res = await fetch(API_URL);
        if (!res.ok) {
            throw new Error(`HTTP error! status: ${res.status}`);
        }
        
        const contentType = res.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await res.text();
            console.error('Non-JSON response:', text.substring(0, 200));
            throw new Error('Server returned non-JSON response. Check console for details.');
        }
        
        const data = await res.json();
        if (data.success) {
            renderEntries(data.entries);
            renderToday(data.resolved, data.currentHour);
            document.getElementById('status-bar').innerHTML = `<span>${data.entries.length} entries loaded.</span>`;
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

