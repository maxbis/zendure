/**
 * Automation Status
 * Client-side logic for fetching and rendering automation status
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
 * Refresh automation status from API
 */
async function refreshAutomationStatus() {
    if (typeof AUTOMATION_STATUS_API_URL === 'undefined' || !AUTOMATION_STATUS_API_URL) {
        console.error('AUTOMATION_STATUS_API_URL is not defined');
        return;
    }

    try {
        const data = await fetchAutomationStatus(AUTOMATION_STATUS_API_URL);
        renderAutomationStatus(data);
    } catch (error) {
        console.error('Failed to refresh automation status:', error);

        // Render error state
        renderAutomationStatus({
            success: false,
            error: error.message || 'Failed to load automation status'
        });
    }
}

// Initialize automation status refresh button
// This button now refreshes all sections: Automation Status, Charge/Discharge, and System & Grid
document.addEventListener('DOMContentLoaded', () => {
    const refreshBtn = document.getElementById('automation-refresh-btn');
    if (refreshBtn) {
        // Remove old onclick handler
        refreshBtn.onclick = null;

        // Add new event listener - calls unified refresh function
        refreshBtn.addEventListener('click', async () => {
            // Hide button for 1 second (same as auto-refresh)
            if (typeof indicateAutoRefresh === 'function') {
                indicateAutoRefresh();
            }
            
            refreshBtn.disabled = true;
            refreshBtn.style.opacity = '0.5';

            // Refresh all sections (Automation Status, Charge/Discharge, and System & Grid)
            if (typeof refreshAllStatus === 'function') {
                await refreshAllStatus();
            } else {
                // Fallback to just automation status if unified function not available
                await refreshAutomationStatus();
            }

            refreshBtn.disabled = false;
            refreshBtn.style.opacity = '1';
        });
    }
});
