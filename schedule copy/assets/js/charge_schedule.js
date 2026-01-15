/**
 * Charge Schedule Manager
 * Main application logic for rendering and managing schedule data
 *
 * Dependencies:
 * - schedule_utils.js - Utility functions
 * - schedule_api.js - API communication
 * - schedule_renderer.js - DOM rendering
 *
 * API_URL is injected from PHP (charge_schedule.php) which reads it from config.json
 */

// API_URL is injected from PHP via inline script tag
// If not injected, use fallback (but PHP should always inject it)
if (typeof API_URL === 'undefined') {
    // Assign to window to avoid const redeclaration error
    window.API_URL = 'api/charge_schedule_api.php';
}

/**
 * Refresh all schedule data and update UI
 */
async function refreshData() {
    try {
        // Get today and tomorrow dates in YYYYMMDD format
        const now = new Date();
        const today = formatDateYYYYMMDD(now);

        const tomorrowDate = new Date(now);
        tomorrowDate.setDate(tomorrowDate.getDate() + 1);
        const tomorrow = formatDateYYYYMMDD(tomorrowDate);

        // Fetch today's data
        const todayData = await fetchScheduleData(API_URL, today);

        // Fetch tomorrow's data
        const tomorrowData = await fetchScheduleData(API_URL, tomorrow);

        if (todayData.success) {
            const currentTime = todayData.currentTime || todayData.currentHour || new Date().getHours().toString().padStart(2, '0') + '00';

            // Render all UI components
            renderEntries(todayData.entries);
            renderToday(todayData.resolved, todayData.currentHour, currentTime);
            renderMiniTimeline(todayData.resolved, currentTime);

            const statusBar = document.getElementById('status-bar');
            if (statusBar) {
                statusBar.innerHTML = `<span>${todayData.entries.length} entries loaded.</span>`;
            }

            // Render bar graph with both today and tomorrow data
            if (todayData.success && tomorrowData.success) {
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

            // Fetch and render price data (if available)
            if (typeof PRICE_API_URL !== 'undefined' && PRICE_API_URL && typeof fetchAndRenderPrices === 'function') {
                fetchAndRenderPrices(PRICE_API_URL, todayData.entries || [], editModal);
            }

            // Update schedule calculator with today's and tomorrow's data (if available)
            if (todayData.success && tomorrowData.success && typeof renderScheduleCalculator === 'function') {
                renderScheduleCalculator(
                    todayData.resolved || [],
                    tomorrowData.resolved || [],
                    currentTime
                );
            }
        }
    } catch (e) {
        console.error(e);
        alert('Connection failed: ' + e.message);
    }
}

/**
 * Handle clear button click
 */
async function handleClearClick() {
    try {
        // First, simulate to get count
        const result = await clearOldEntries(API_URL, true);

        if (!result.success) {
            alert('Error: ' + (result.error || 'Failed to check old entries'));
            return;
        }

        const count = result.count || 0;

        if (count === 0) {
            await confirmDialog.alert(
                'No outdated schedule entries to delete.',
                'No Entries to Clear',
                'OK',
                'btn-primary'
            );
            return;
        }

        // Show confirmation dialog
        const confirmed = await confirmDialog.show(
            `Are you sure you want to delete ${count} outdated schedule entries?`,
            'Clear Old Entries',
            'Delete',
            'btn-danger'
        );

        if (confirmed) {
            // Perform actual deletion
            const deleteResult = await clearOldEntries(API_URL, false);

            if (!deleteResult.success) {
                alert('Error: ' + (deleteResult.error || 'Failed to delete old entries'));
                return;
            }

            // Refresh data to show updated entries
            await refreshData();
        }
    } catch (error) {
        console.error('Error in clear button handler:', error);
        alert('Error: ' + error.message);
    }
}

/**
 * Handle auto button click
 */
async function handleAutoClick() {
    try {
        // Check if API URL is defined
        if (!CALCULATE_SCHEDULE_API_URL) {
            alert('Error: Calculate schedule API URL is not configured.');
            return;
        }

        // First, simulate to get count
        const simulateData = await calculateSchedule(CALCULATE_SCHEDULE_API_URL, true);

        if (!simulateData.success) {
            alert('Error: ' + (simulateData.error || 'Failed to simulate schedule calculation'));
            return;
        }

        const pairsCount = simulateData.count || 0;
        const entriesCount = simulateData.entries_added || 0;

        if (pairsCount === 0) {
            await confirmDialog.alert(
                'No schedule entries can be added based on current price differences.',
                'No Entries to Add',
                'OK',
                'btn-primary'
            );
            return;
        }

        // Show confirmation dialog
        const confirmed = await confirmDialog.show(
            `Are you sure you want to add ${entriesCount} schedule entries (${pairsCount} charge/discharge pairs)?`,
            'Auto Calculate Schedule',
            'Add',
            'btn-auto'
        );

        if (confirmed) {
            // Perform actual calculation
            const calculateData = await calculateSchedule(CALCULATE_SCHEDULE_API_URL, false);

            if (!calculateData.success) {
                alert('Error: ' + (calculateData.error || 'Failed to calculate schedule'));
                return;
            }

            // Refresh data to show updated entries
            await refreshData();
        }
    } catch (error) {
        console.error('Error in auto button handler:', error);
        alert('Error: ' + error.message);
    }
}

// Global instances
let editModal;
let confirmDialog;

/**
 * Initialize application
 */
document.addEventListener('DOMContentLoaded', () => {
    // Initialize edit modal with callback to refresh data after save/delete
    editModal = new EditModal(API_URL, refreshData);

    // Initialize confirm dialog
    confirmDialog = new ConfirmDialog();

    // Add click handler for Clear button
    const clearBtn = document.getElementById('clear-entry-btn');
    if (clearBtn) {
        clearBtn.addEventListener('click', handleClearClick);
    }

    // Add click handler for Auto button
    const autoBtn = document.getElementById('auto-entry-btn');
    if (autoBtn) {
        autoBtn.addEventListener('click', handleAutoClick);
    }

    // Initial data load
    refreshData();
});
