/**
 * Charge/Discharge Status
 * Client-side logic for fetching and rendering charge/discharge status
 */

/**
 * Refresh all status sections (Automation Status, Charge/Discharge, and System & Grid)
 * This unified function updates all three sections in one go
 */
async function refreshAllStatus() {
    // Fetch both automation status and charge status in parallel

    // Fetch automation status
    let automationPromise = Promise.resolve(null);
    if (typeof AUTOMATION_STATUS_API_URL !== 'undefined' && AUTOMATION_STATUS_API_URL) {
        automationPromise = fetchAutomationStatus(AUTOMATION_STATUS_API_URL)
            .then(data => {
                renderAutomationStatus(data);
                return data;
            })
            .catch(error => {
                console.error('Failed to refresh automation status:', error);
                renderAutomationStatus({
                    success: false,
                    error: error.message || 'Failed to load automation status'
                });
                return null;
            });
    }

    // Fetch charge status
    let chargePromise = Promise.resolve(null);
    if (typeof CHARGE_STATUS_ZENDURE_API_URL !== 'undefined' && CHARGE_STATUS_ZENDURE_API_URL) {
        chargePromise = (async () => {
            try {
                const p1ApiUrl = (typeof CHARGE_STATUS_P1_API_URL !== 'undefined') ? CHARGE_STATUS_P1_API_URL : null;
                const { zendureData, p1Data } = await fetchChargeStatus(CHARGE_STATUS_ZENDURE_API_URL, p1ApiUrl);
                renderChargeStatus(zendureData, p1Data);

                // Also render the details section (System & Grid) if the render function exists
                if (typeof renderChargeStatusDetails === 'function') {
                    renderChargeStatusDetails(zendureData, p1Data);
                }
                return { zendureData, p1Data };
            } catch (error) {
                console.error('Failed to refresh charge status:', error);
                renderChargeStatus({
                    success: false,
                    error: error.message || 'Failed to load charge status'
                });
                return null;
            }
        })();
    }

    // Wait for both to complete (they run in parallel)
    await Promise.all([automationPromise, chargePromise]);
}

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

// Charge/Discharge refresh button removed - use Automation Status refresh button instead
// which calls refreshAllStatus() to update all sections
