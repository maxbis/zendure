/**
 * Charge/Discharge Status
 * Client-side logic for fetching and rendering charge/discharge status
 */

// Auto-refresh interval when page becomes visible (20 seconds in milliseconds)
const AUTO_REFRESH_INTERVAL = 20000;
const DEBUG_MODE = false;

// Track auto-refresh interval
let autoRefreshIntervalId = null;
let wasPageHidden = false;

/**
 * Hide the refresh button temporarily to indicate auto-refresh is happening
 */
function indicateAutoRefresh() {
    const refreshBtn = document.getElementById('automation-refresh-btn');
    if (refreshBtn) {
        // Hide button for 1 second (button uses inline-flex in CSS)
        refreshBtn.style.display = 'none';
        setTimeout(() => {
            refreshBtn.style.display = 'inline-flex';
        }, 1000);
    }
}

/**
 * Lightweight update of current time indicators in price graph and schedule bar graph
 * Updates the current hour indicator without full re-render of the graphs
 */
function updateGraphTimeIndicators() {
    const now = new Date();
    
    // Get current date in YYYYMMDD format (using the same logic as the graphs)
    let currentDate;
    if (typeof formatDateYYYYMMDD === 'function') {
        currentDate = formatDateYYYYMMDD(now);
    } else {
        // Fallback if formatDateYYYYMMDD is not available
        currentDate = now.getFullYear().toString() +
            String(now.getMonth() + 1).padStart(2, '0') +
            String(now.getDate()).padStart(2, '0');
    }
    
    const currentHour = now.getHours();
    
    // Update price graph bars (handle both price-graph-bar.price-current and price-bar.price-bar-current)
    // Remove current class from all price bars
    const priceCurrentBars = document.querySelectorAll('.price-graph-bar.price-current, .price-bar.price-bar-current');
    priceCurrentBars.forEach(bar => {
        bar.classList.remove('price-current', 'price-bar-current');
    });
    
    // Find and mark the current hour bar in price graph
    const priceBars = document.querySelectorAll('.price-graph-bar[data-date], .price-bar[data-date]');
    priceBars.forEach(bar => {
        const barDate = bar.dataset.date;
        const barHour = parseInt(bar.dataset.hour, 10);
        
        if (barDate === currentDate && barHour === currentHour) {
            // Add appropriate class based on which type of bar it is
            if (bar.classList.contains('price-graph-bar')) {
                bar.classList.add('price-current');
            } else if (bar.classList.contains('price-bar')) {
                bar.classList.add('price-bar-current');
            }
        }
    });
    
    // Update schedule bar graph (only if it exists - desktop only)
    const barGraphContainer = document.getElementById('bar-graph-today');
    if (barGraphContainer) {
        // Remove current class from all schedule bars
        const scheduleCurrentBars = document.querySelectorAll('.bar-graph-bar.bar-current');
        scheduleCurrentBars.forEach(bar => {
            bar.classList.remove('bar-current');
        });
        
        // Find and mark the current hour bar in schedule bar graph
        const scheduleBars = document.querySelectorAll('.bar-graph-bar[data-date]');
        scheduleBars.forEach(bar => {
            const barDate = bar.dataset.date;
            const barHour = parseInt(bar.dataset.hour, 10);
            
            if (barDate === currentDate && barHour === currentHour) {
                bar.classList.add('bar-current');
            }
        });
    }
}

/**
 * Refresh all status sections (Automation Status, Charge/Discharge, and System & Grid)
 * This unified function updates all three sections in one go
 */
async function refreshAllStatus(isAutoRefresh = false) {
    // Log refresh operation
    if (DEBUG_MODE) {
        console.log('🔄 Refreshing all status sections...', isAutoRefresh ? '(Auto-refresh)' : '(Manual)');
    }
    
    const apisCalled = [];
    
    // Fetch both automation status and charge status in parallel

    // Fetch automation status
    let automationPromise = Promise.resolve(null);
    if (typeof AUTOMATION_STATUS_API_URL !== 'undefined' && AUTOMATION_STATUS_API_URL) {
        // Detect config key based on URL pattern (localhost = local, otherwise remote)
        const isLocalUrl = AUTOMATION_STATUS_API_URL.includes('localhost') || AUTOMATION_STATUS_API_URL.includes('127.0.0.1');
        const statusConfigKey = 'statusApiUrl' + (isLocalUrl ? '-local' : '');
        
        apisCalled.push({
            name: 'Automation Status API',
            url: AUTOMATION_STATUS_API_URL,
            configKey: statusConfigKey
        });
        
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
        // Detect config key based on URL pattern (localhost = local, otherwise remote)
        const isLocalUrl = CHARGE_STATUS_ZENDURE_API_URL.includes('localhost') || CHARGE_STATUS_ZENDURE_API_URL.includes('127.0.0.1');
        const dataConfigKey = 'dataApiUrl' + (isLocalUrl ? '-local' : '');
        
        apisCalled.push({
            name: 'Charge Status API (Zendure)',
            url: CHARGE_STATUS_ZENDURE_API_URL,
            configKey: dataConfigKey
        });
        
        const p1ApiUrl = (typeof CHARGE_STATUS_P1_API_URL !== 'undefined') ? CHARGE_STATUS_P1_API_URL : null;
        if (p1ApiUrl) {
            apisCalled.push({
                name: 'Charge Status API (P1 Meter)',
                url: p1ApiUrl,
                configKey: dataConfigKey // P1 uses same base URL with ?type=zendure_p1
            });
        }
        
        chargePromise = (async () => {
            try {
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

    // Log APIs being called
    if (apisCalled.length > 0 && DEBUG_MODE) {
        console.log('📡 APIs being called:');
        apisCalled.forEach(api => {
            console.log(`  - ${api.name}:`, api.url);
            console.log(`    Config key: ${api.configKey}`);
        });
    }
    
    // Wait for both to complete (they run in parallel)
    await Promise.all([automationPromise, chargePromise]);
    
    // Update current time indicators in graphs during auto-refresh
    if (isAutoRefresh) {
        updateGraphTimeIndicators();
    }
    
    const timestamp = new Date().toLocaleTimeString();
    console.log(`✅ Refresh completed [${timestamp}]`);
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

/**
 * Start auto-refresh interval (if page is visible)
 */
function startAutoRefresh() {
    // Clear any existing interval first
    if (autoRefreshIntervalId !== null) {
        clearInterval(autoRefreshIntervalId);
        autoRefreshIntervalId = null;
    }
    
    // Only start interval if page is visible
    if (!document.hidden) {
        // Do immediate refresh first
        if (typeof refreshAllStatus === 'function') {
            indicateAutoRefresh();
            refreshAllStatus(true);
        }
        
        // Then set up interval for periodic refresh
        autoRefreshIntervalId = setInterval(() => {
            // Double-check page is still visible before refreshing
            if (!document.hidden && typeof refreshAllStatus === 'function') {
                if (DEBUG_MODE) {
                    console.log('⏰ Auto-refresh interval triggered');
                }
                indicateAutoRefresh();
                refreshAllStatus(true);
            } else if (document.hidden) {
                if (DEBUG_MODE) {
                    console.log('⏰ Auto-refresh skipped (page hidden)');
                }
            }
        }, AUTO_REFRESH_INTERVAL);
        
        console.log('⏰ Auto-refresh interval started (every ' + (AUTO_REFRESH_INTERVAL / 1000) + ' seconds)');
    }
}

/**
 * Stop auto-refresh interval
 */
function stopAutoRefresh() {
    if (autoRefreshIntervalId !== null) {
        clearInterval(autoRefreshIntervalId);
        autoRefreshIntervalId = null;
        console.log('⏸️ Auto-refresh interval stopped');
    }
}

/**
 * Auto-refresh when page becomes visible after being hidden
 * Refreshes every 20 seconds when the tab is visible
 */
document.addEventListener('DOMContentLoaded', () => {
    // Track initial state
    wasPageHidden = document.hidden;
    
    // Start auto-refresh if page is visible on initial load
    if (!document.hidden) {
        startAutoRefresh();
    }
    
    // Handle visibility changes
    document.addEventListener('visibilitychange', () => {
        const isHidden = document.hidden;
        
        if (isHidden) {
            // Page became hidden - stop auto-refresh
            stopAutoRefresh();
            wasPageHidden = true;
        } else {
            // Page became visible
            if (wasPageHidden) {
                // Page became visible after being hidden - start auto-refresh
                startAutoRefresh();
                wasPageHidden = false;
            }
        }
    });
});
