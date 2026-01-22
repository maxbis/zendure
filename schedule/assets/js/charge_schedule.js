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
 * API_URL is injected from PHP (charge_schedule.php) which reads it from config.json
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
 * Internal refresh function that does the actual work
 */
async function _refreshDataInternal() {
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
 * Refresh all schedule data and update UI
 * Uses debouncing and state management for better performance
 */
const refreshData = debounce(async function() {
    await _refreshDataInternal();
}, 300); // Debounce for 300ms

/**
 * Refresh data immediately without debounce (for use after delete/save operations)
 */
async function refreshDataImmediate() {
    await _refreshDataInternal();
}

// Make refreshDataImmediate globally accessible
window.refreshDataImmediate = refreshDataImmediate;

/**
 * Handle clear button click
 */
async function handleClearClick() {
    try {
        // First, simulate to get count
        const result = await clearOldEntries(API_URL, true);

        if (!result.success) {
            const errorMsg = result.error || 'Failed to check old entries';
            if (window.notifications) {
                window.notifications.error(errorMsg);
            } else {
                alert('Error: ' + errorMsg);
            }
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
                const errorMsg = deleteResult.error || 'Failed to delete old entries';
                if (window.notifications) {
                    window.notifications.error(errorMsg);
                } else {
                    alert('Error: ' + errorMsg);
                }
                return;
            }
            
            // Show success notification
            if (window.notifications) {
                window.notifications.success(`Deleted ${count} outdated entries`);
            }

            // Refresh data immediately to show updated entries
            await refreshDataImmediate();
        }
    } catch (error) {
        console.error('Error in clear button handler:', error);
        if (window.notifications) {
            window.notifications.error('Error clearing entries: ' + error.message);
        } else {
            alert('Error: ' + error.message);
        }
    }
}

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
            await refreshDataImmediate();
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

    // Initialize edit modal with callback to refresh data after save/delete
    editModal = new EditModal(API_URL, refreshData);

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

    // Add click handler for Clear button (with debouncing)
    const clearBtn = document.getElementById('clear-entry-btn');
    if (clearBtn) {
        clearBtn.addEventListener('click', debounce(handleClearClick, 500));
    }

    // Add click handler for Auto button (with debouncing)
    const autoBtn = document.getElementById('auto-entry-btn');
    if (autoBtn) {
        autoBtn.addEventListener('click', debounce(handleAutoClick, 500));
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
    refreshData();
});
