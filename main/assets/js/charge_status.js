/**
 * Charge/Discharge Status
 * Client-side logic for fetching and rendering charge/discharge status
 */

// Auto-refresh interval when page becomes visible (20 seconds in milliseconds)
const AUTO_REFRESH_INTERVAL = 20000;

// Refresh schedule/Wh table & graphs every N auto-refresh ticks
const SCHEDULE_REFRESH_TICK_INTERVAL = 20;

const DEBUG_MODE = false;

// Track auto-refresh interval
let autoRefreshIntervalId = null;
let wasPageHidden = false;
let scheduleRefreshTickCount = 0;

// Track if "back-end not running" dialog was already shown (avoid duplicate modals)
let noBackendDialogShown = false;

/**
 * Return true if the error indicates the back-end (proxy upstream) is unavailable (502).
 * @param {Error|ApiError} error
 * @returns {boolean}
 */
function isBackendUnavailableError(error) {
    if (!error) return false;
    if (typeof ApiError !== 'undefined' && error instanceof ApiError) {
        return error.status === 502;
    }
    const msg = (error.message || String(error)) || '';
    return msg.includes('502') || msg.includes('Bad Gateway');
}

/**
 * Show the "Back-end not running" modal, stop auto-refresh, and wire Retry to reload.
 * Idempotent: only shows once per page load.
 */
function showNoBackendDialog() {
    if (noBackendDialogShown) return;
    noBackendDialogShown = true;
    stopAutoRefresh();
    const dialog = document.getElementById('no-backend-dialog');
    if (dialog) {
        dialog.classList.add('active');
        dialog.setAttribute('aria-hidden', 'false');
    }
}

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
 * Normalize /api/all response to the shape expected by renderers
 * @param {Object} allData - Unified API response
 * @returns {{zendureData: Object, p1Data: Object|null}}
 */
function normalizeChargeStatusAll(allData) {
    const zendureReadings = allData?.zendure?.readings || allData?.zendure?.data || null;
    const p1Readings = allData?.p1?.readings || allData?.p1?.data || null;

    if (!zendureReadings) {
        return {
            zendureData: {
                success: false,
                error: 'Invalid response from unified API'
            },
            p1Data: null
        };
    }

    return {
        zendureData: {
            success: true,
            data: {
                properties: zendureReadings.properties || {},
                packData: zendureReadings.packData || []
            },
            timestamp: allData?.zendure?.timestamp || null
        },
        p1Data: p1Readings ? { total_power: p1Readings.total_power || 0 } : null
    };
}

/**
 * Refresh all status sections (Automation Status, Charge/Discharge, and System & Grid)
 * This unified function updates all three sections in one go
 */
async function refreshStatus(isAutoRefresh = false) {
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
                if (isBackendUnavailableError(error)) {
                    showNoBackendDialog();
                }
                renderAutomationStatus({
                    success: false,
                    error: error.message || 'Failed to load automation status'
                });
                return null;
            });
    }

    // Fetch charge status
    let chargePromise = Promise.resolve(null);
    if (typeof CHARGE_STATUS_ALL_API_URL !== 'undefined' && CHARGE_STATUS_ALL_API_URL) {
        // Unified API (P1 + Zendure + status)
        const isLocalUrl = CHARGE_STATUS_ALL_API_URL.includes('localhost') || CHARGE_STATUS_ALL_API_URL.includes('127.0.0.1');
        const dataConfigKey = 'dataApiUrl' + (isLocalUrl ? '-local' : '');

        apisCalled.push({
            name: 'Charge Status API (Unified)',
            url: CHARGE_STATUS_ALL_API_URL,
            configKey: dataConfigKey
        });

        chargePromise = (async () => {
            try {
                const allData = await fetchChargeStatusAll(CHARGE_STATUS_ALL_API_URL);
                const { zendureData, p1Data } = normalizeChargeStatusAll(allData);
                renderChargeStatus(zendureData, p1Data);

                // Also render the details section (System & Grid) if the render function exists
                if (typeof renderChargeStatusDetails === 'function') {
                    renderChargeStatusDetails(zendureData, p1Data);
                }
                return { zendureData, p1Data };
            } catch (error) {
                console.error('Failed to refresh charge status (unified):', error);
                if (isBackendUnavailableError(error)) {
                    showNoBackendDialog();
                }
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
// which calls refreshStatus() to update all sections

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
        if (typeof refreshStatus === 'function') {
            indicateAutoRefresh();
            refreshStatus(true);
        }
        
        // Then set up interval for periodic refresh
        autoRefreshIntervalId = setInterval(() => {
            // Double-check page is still visible before refreshing
            if (!document.hidden && typeof refreshStatus === 'function') {
                if (DEBUG_MODE) {
                    console.log('⏰ Auto-refresh interval triggered');
                }
                console.log('⏱️ Auto-refresh tick ' + (scheduleRefreshTickCount + 1) + '/' + SCHEDULE_REFRESH_TICK_INTERVAL);
                indicateAutoRefresh();
                refreshStatus(true);

                scheduleRefreshTickCount += 1;
                if (scheduleRefreshTickCount >= SCHEDULE_REFRESH_TICK_INTERVAL) {
                    scheduleRefreshTickCount = 0;
                    console.log('⏱️ Schedule/Wh refresh triggered (every ' + SCHEDULE_REFRESH_TICK_INTERVAL + ' auto-refresh ticks)');
                    if (typeof window.refreshScheduleAndPricesImmediate === 'function') {
                        window.refreshScheduleAndPricesImmediate();
                    }
                }
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
    // Wire "Back-end not running" dialog Retry button
    const noBackendRetryBtn = document.getElementById('no-backend-retry-btn');
    if (noBackendRetryBtn) {
        noBackendRetryBtn.addEventListener('click', () => { location.reload(); });
    }

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
