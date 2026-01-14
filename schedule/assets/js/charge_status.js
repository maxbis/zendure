/**
 * Charge/Discharge Status
 * Client-side logic for fetching and rendering charge/discharge status
 */

/**
 * Refresh charge status from API
 */
async function refreshChargeStatus() {
    if (typeof CHARGE_STATUS_ZENDURE_API_URL === 'undefined' || !CHARGE_STATUS_ZENDURE_API_URL) {
        console.error('CHARGE_STATUS_ZENDURE_API_URL is not defined');
        return;
    }

    try {
        const p1ApiUrl = (typeof CHARGE_STATUS_P1_API_URL !== 'undefined') ? CHARGE_STATUS_P1_API_URL : null;
        const { zendureData, p1Data } = await fetchChargeStatus(CHARGE_STATUS_ZENDURE_API_URL, p1ApiUrl);
        renderChargeStatus(zendureData, p1Data);

        // Also render the details section (System & Grid) if the render function exists
        if (typeof renderChargeStatusDetails === 'function') {
            renderChargeStatusDetails(zendureData, p1Data);
        }
    } catch (error) {
        console.error('Failed to refresh charge status:', error);

        // Render error state
        renderChargeStatus({
            success: false,
            error: error.message || 'Failed to load charge status'
        });
    }
}

/**
 * Toggle the collapsible section in charge status details
 * Shows/hides rows 2-3 (Battery 1 & 2 levels and temps)
 */
function toggleChargeStatusDetails() {
    const collapsibleSection = document.getElementById('charge-status-details-collapsible');
    const toggleButton = document.getElementById('charge-details-toggle');
    const toggleText = toggleButton?.querySelector('.charge-details-toggle-text');

    if (!collapsibleSection || !toggleButton) {
        return;
    }

    // Toggle expanded class on collapsible section
    const isExpanded = collapsibleSection.classList.toggle('expanded');

    // Update toggle button appearance and text
    if (isExpanded) {
        toggleButton.classList.add('expanded');
        if (toggleText) {
            toggleText.textContent = 'Show less';
        }
    } else {
        toggleButton.classList.remove('expanded');
        if (toggleText) {
            toggleText.textContent = 'Show more';
        }
    }
}

// Initialize charge status refresh button
document.addEventListener('DOMContentLoaded', () => {
    const refreshBtn = document.getElementById('charge-refresh-btn');
    if (refreshBtn) {
        // Remove old onclick handler
        refreshBtn.onclick = null;

        // Add new event listener
        refreshBtn.addEventListener('click', async () => {
            refreshBtn.disabled = true;
            refreshBtn.style.opacity = '0.5';

            await refreshChargeStatus();

            refreshBtn.disabled = false;
            refreshBtn.style.opacity = '1';
        });
    }
});
