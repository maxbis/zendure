/**
 * Charge Schedule Manager
 * Main application logic for rendering and managing schedule data
 *
 * Dependencies:
 * - schedule_utils.js - Utility functions
 * - schedule_api.js - API communication
 * - schedule_renderer.js - DOM rendering
 * - state_manager.js - State management
 * - component_base.js - Component base class
 * - utils_performance.js - Performance utilities
 *
 * API_URL is injected from the PHP schedule page (mobile canonical entrypoint)
 */

// API_URL is injected from PHP via inline script tag
// If not injected, use fallback (but PHP should always inject it)
if (typeof API_URL === 'undefined') {
    // Assign to window to avoid const redeclaration error
    window.API_URL = 'api/charge_schedule_api.php';
}

// Initialize global services
let appState = null;
let apiClient = null;
let schedulePanelComponent = null;
let priceGraphComponent = null;

/**
 * Internal refresh function that does the actual work (schedule + prices)
 */
async function _refreshScheduleAndPricesInternal() {
    try {
        console.log('Refreshing schedule data...');
        // Get today and tomorrow dates in YYYYMMDD format
        const now = new Date();
        const today = formatDateYYYYMMDD(now);

        const tomorrowDate = new Date(now);
        tomorrowDate.setDate(tomorrowDate.getDate() + 1);
        const tomorrow = formatDateYYYYMMDD(tomorrowDate);

        // Update loading state
        if (appState) {
            appState.setState({ loading: { schedule: true } });
        }

        // Always fetch fresh data directly
        console.log('Fetching schedule data for today:', today);
        const todayData = await fetchScheduleData(API_URL, today);
        console.log('Fetching schedule data for tomorrow:', tomorrow);
        const tomorrowData = await fetchScheduleData(API_URL, tomorrow);

        console.log('Schedule data fetched:', { 
            today: todayData.success, 
            tomorrow: tomorrowData.success,
            entriesCount: todayData.entries?.length || 0 
        });

        if (editModal && typeof editModal.setScheduleEntries === 'function') {
            editModal.setScheduleEntries(todayData.entries || []);
        }

        if (todayData.success) {
            const currentTime = todayData.currentTime || todayData.currentHour || new Date().getHours().toString().padStart(2, '0') + '00';

            const scheduleData = {
                entries: todayData.entries || [],
                resolved: todayData.resolved || [],
                currentTime: currentTime,
                currentHour: todayData.currentHour
            };

            // Update state
            if (appState) {
                console.log('Updating app state...');
                appState.setState({
                    schedule: scheduleData,
                    scheduleTomorrow: {
                        resolved: tomorrowData.resolved || []
                    },
                    loading: { schedule: false }
                });
            }

            // Update components if using component architecture
            if (schedulePanelComponent) {
                console.log('Updating schedule panel component...');
                schedulePanelComponent.update({
                    ...scheduleData,
                    resolvedTomorrow: tomorrowData.resolved || []
                });
            } else {
                console.log('Using fallback rendering...');
                // Fallback to direct rendering
                const entries = todayData.entries || [];
                renderEntries(entries);
                renderToday(todayData.resolved || [], todayData.currentHour, currentTime);
                if (typeof renderTomorrow === 'function') {
                    renderTomorrow(tomorrowData.resolved || []);
                }
                renderMiniTimeline(todayData.resolved || [], currentTime);

                const statusBar = document.getElementById('status-bar');
                if (statusBar) {
                    statusBar.innerHTML = `<span>${entries.length} entries loaded.</span>`;
                }
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
                fetchAndRenderPrices(PRICE_API_URL, {
                    entries: todayData.entries || [],
                    resolvedToday: todayData.resolved || [],
                    resolvedTomorrow: tomorrowData.resolved || [],
                    todayDate: today,
                    tomorrowDate: tomorrow
                }, editModal);
            }

            // Update schedule calculator with today's and tomorrow's data (if available)
            if (todayData.success && tomorrowData.success && typeof renderScheduleCalculator === 'function') {
                renderScheduleCalculator(
                    todayData.resolved || [],
                    tomorrowData.resolved || [],
                    currentTime
                );
            }
            // Refresh Watt-hours per hour partial (chart + daily table)
            if (typeof window.refreshEnergyGraph === 'function') {
                await window.refreshEnergyGraph();
            }
            console.log('Schedule data refresh completed successfully');
        } else {
            throw new Error(todayData.error || 'Failed to fetch schedule data');
        }
    } catch (e) {
        console.error('Error refreshing data:', e);
        if (appState) {
            appState.setState({ 
                loading: { schedule: false },
                errors: { schedule: e.message }
            });
        }
        if (window.notifications) {
            window.notifications.error('Failed to refresh schedule data: ' + e.message);
        } else {
            alert('Connection failed: ' + e.message);
        }
        throw e; // Re-throw so caller knows it failed
    }
}

/**
 * Refresh schedule and prices (debounced). Use for initial load.
 */
const refreshScheduleAndPrices = debounce(async function() {
    await _refreshScheduleAndPricesInternal();
}, 300); // Debounce for 300ms

/**
 * Refresh schedule and prices immediately (no debounce). Use after save/delete/Clear/Auto or when tab becomes visible.
 */
async function refreshScheduleAndPricesImmediate() {
    await _refreshScheduleAndPricesInternal();
}

// Make globally accessible for edit_modal and visibility-triggered refresh
window.refreshScheduleAndPricesImmediate = refreshScheduleAndPricesImmediate;


/**
 * Handle auto button click
 */
async function handleAutoClick() {
    try {
        // Check if API URL is defined
        if (!CALCULATE_SCHEDULE_API_URL) {
            const errorMsg = 'Calculate schedule API URL is not configured.';
            if (window.notifications) {
                window.notifications.error(errorMsg);
            } else {
                alert('Error: ' + errorMsg);
            }
            return;
        }

        // First, simulate to get count
        const simulateData = await calculateSchedule(CALCULATE_SCHEDULE_API_URL, true);

        if (!simulateData.success) {
            const errorMsg = simulateData.error || 'Failed to simulate schedule calculation';
            if (window.notifications) {
                window.notifications.error(errorMsg);
            } else {
                alert('Error: ' + errorMsg);
            }
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
                const errorMsg = calculateData.error || 'Failed to calculate schedule';
                if (window.notifications) {
                    window.notifications.error(errorMsg);
                } else {
                    alert('Error: ' + errorMsg);
                }
                return;
            }

            // Show success notification
            if (window.notifications) {
                window.notifications.success(`Added ${entriesCount} schedule entries`);
            }

            // Refresh data immediately to show updated entries
            await refreshScheduleAndPricesImmediate();
        }
    } catch (error) {
        console.error('Error in auto button handler:', error);
        if (window.notifications) {
            window.notifications.error('Error calculating schedule: ' + error.message);
        } else {
            alert('Error: ' + error.message);
        }
    }
}

/**
 * Handle clear button click (clear non-wildcard entries only)
 */
async function handleClearClick() {
    try {
        const confirmed = await confirmDialog.show(
            "This clears all schedule entries without a wildcard. Any key containing '*' will stay. Continue?",
            'Clear Schedule Entries',
            'OK',
            'btn-danger'
        );

        if (!confirmed) {
            return;
        }

        const clearData = await clearNonWildcardScheduleEntries(API_URL);
        if (!clearData.success) {
            throw new Error(clearData.error || 'Failed to clear non-wildcard entries');
        }

        const removed = Number.isFinite(clearData.removed) ? clearData.removed : 0;
        if (window.notifications) {
            window.notifications.success(`Cleared ${removed} non-wildcard entries`);
        }

        await refreshScheduleAndPricesImmediate();
    } catch (error) {
        console.error('Error in clear button handler:', error);
        if (window.notifications) {
            window.notifications.error('Error clearing schedule entries: ' + error.message);
        } else {
            alert('Error: ' + error.message);
        }
    }
}

// Global instances
let editModal;
let confirmDialog;

/**
 * Initialize application with state management and components
 */
document.addEventListener('DOMContentLoaded', () => {
    // Initialize state manager
    appState = new StateManager({
        schedule: null,
        prices: null,
        automationStatus: null,
        chargeStatus: null,
        loading: {},
        errors: {}
    });

    // Initialize API client
    if (typeof ApiClient !== 'undefined') {
        apiClient = new ApiClient(API_URL, {
            timeout: 10000,
            retries: 3,
            retryDelay: 1000
        });
    }

    // Initialize edit modal (uses window.refreshScheduleAndPricesImmediate after save/delete)
    editModal = new EditModal(API_URL);

    // Initialize confirm dialog
    confirmDialog = new ConfirmDialog();

    // Initialize components (if available)
    if (typeof SchedulePanelComponent !== 'undefined') {
        const scheduleContainer = document.querySelector('.layout');
        if (scheduleContainer) {
            schedulePanelComponent = new SchedulePanelComponent(scheduleContainer, {
                stateManager: appState,
                apiClient: apiClient,
                config: { editModal: editModal }
            });
            schedulePanelComponent.init();
        }
    }

    if (typeof PriceGraphComponent !== 'undefined') {
        const priceContainer = document.querySelector('.price-graph-wrapper');
        if (priceContainer) {
            priceGraphComponent = new PriceGraphComponent(priceContainer, {
                stateManager: appState,
                apiClient: apiClient,
                config: { 
                    editModal: editModal,
                    priceApiUrl: typeof PRICE_API_URL !== 'undefined' ? PRICE_API_URL : null
                }
            });
            priceGraphComponent.init();
        }
    }

    // Add click handler for Auto button (with debouncing)
    const autoBtn = document.getElementById('auto-entry-btn');
    if (autoBtn) {
        autoBtn.addEventListener('click', debounce(handleAutoClick, 500));
    }

    // Add click handler for Clear button
    const clearBtn = document.getElementById('clear-entry-btn');
    if (clearBtn) {
        clearBtn.addEventListener('click', debounce(handleClearClick, 500));
    }

    // Lazy load heavy sections
    if (typeof lazyLoadComponent !== 'undefined') {
        // Lazy load charge status details
        const chargeDetailsSection = document.querySelector('.charge-status-wrapper:last-of-type');
        if (chargeDetailsSection) {
            lazyLoadComponent(chargeDetailsSection, () => {
                // Charge status details will load when scrolled into view
                console.log('Charge status details section loaded');
            }, { rootMargin: '200px' });
        }

        // Lazy load automation status if it's far down the page
        const automationSection = document.querySelector('.automation-status-wrapper');
        if (automationSection) {
            lazyLoadComponent(automationSection, () => {
                // Automation status will load when scrolled into view
                console.log('Automation status section loaded');
            }, { rootMargin: '200px' });
        }
    }

    // Initial data load
    refreshScheduleAndPrices();

    // Refresh schedule and prices when tab/page becomes visible (e.g. user returns from another app)
    let wasSchedulePageHidden = document.hidden;
    document.addEventListener('visibilitychange', () => {
        const isHidden = document.hidden;
        if (isHidden) {
            wasSchedulePageHidden = true;
        } else {
            if (wasSchedulePageHidden) {
                wasSchedulePageHidden = false;
                refreshScheduleAndPricesImmediate();
            }
        }
    });
});
