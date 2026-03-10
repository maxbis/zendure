/**
 * Charge/Discharge Status
 * Client-side logic for fetching and rendering charge/discharge status
 */

// Normal auto-refresh interval in milliseconds
const NORMAL_REFRESH_INTERVAL_MS = 20000;

// Temporary fast-refresh burst: 10 ticks at 6 seconds each
const BOOST_REFRESH_INTERVAL_MS = 5100;
const BOOST_TICK_COUNT = 8;

// Refresh schedule/Wh table & graphs on the same wall-clock cadence as before
const SCHEDULE_REFRESH_INTERVAL_MS = 20 * NORMAL_REFRESH_INTERVAL_MS;

const DEBUG_MODE = false;

// Track auto-refresh interval state
let autoRefreshIntervalId = null;
let wasPageHidden = false;
let activeRefreshIntervalMs = NORMAL_REFRESH_INTERVAL_MS;
let remainingBoostTicks = 0;
let nextScheduleRefreshAt = null;
window.chargeStatusDetailsExpanded = window.chargeStatusDetailsExpanded || false;

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

function updateGridFastIndicator() {
    const indicator = document.querySelector('[data-role="grid-fast-indicator"]');
    const trigger = document.querySelector('[data-role="grid-fast-refresh-trigger"]');
    if (!indicator) return;

    const isFastModeActive = !document.hidden && !noBackendDialogShown && remainingBoostTicks > 0 && activeRefreshIntervalMs === BOOST_REFRESH_INTERVAL_MS;
    indicator.classList.toggle('fast-refresh-active', isFastModeActive);
    if (trigger) {
        trigger.classList.toggle('fast-refresh-active', isFastModeActive);
    }
}

function bindGridFastRefreshTrigger() {
    const trigger = document.querySelector('[data-role="grid-fast-refresh-trigger"]');
    if (!trigger || trigger.dataset.fastRefreshBound === 'true') {
        return;
    }

    trigger.dataset.fastRefreshBound = 'true';
    trigger.addEventListener('click', () => {
        if (typeof window.restartFastRefreshBurst === 'function') {
            window.restartFastRefreshBurst(true);
        }
    });
}

function ensureNextScheduleRefreshAt() {
    if (nextScheduleRefreshAt === null) {
        nextScheduleRefreshAt = Date.now() + SCHEDULE_REFRESH_INTERVAL_MS;
    }
}

function maybeRefreshScheduleData() {
    ensureNextScheduleRefreshAt();

    if (Date.now() < nextScheduleRefreshAt) {
        return;
    }

    nextScheduleRefreshAt = Date.now() + SCHEDULE_REFRESH_INTERVAL_MS;
    console.log('⏱️ Schedule/Wh refresh triggered');

    if (typeof window.refreshScheduleAndPricesImmediate === 'function') {
        window.refreshScheduleAndPricesImmediate();
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

    // Wait for both to complete (they run in parallel), then cross-check for pending state
    const [automationData] = await Promise.all([automationPromise, chargePromise]);
    applyPendingPowerState(automationData);

    // Update current time indicators in graphs during auto-refresh
    if (isAutoRefresh) {
        updateGraphTimeIndicators();
    }

    const timestamp = new Date().toLocaleTimeString();
    console.log(`✅ Refresh completed [${timestamp}]`);
}

// ─── Pending power state ────────────────────────────────────────────────────

/**
 * How long (seconds) after a command is issued we consider the bar "pending".
 * Worst case lag is roughly one automation loop plus one browser refresh cycle.
 * Keep a small buffer so pending state does not clear before the device catches up.
 */
const PENDING_WINDOW_SECONDS = 35;

/**
 * Tolerance (W) when comparing commanded vs actual power.
 * Prevents flicker on netzero modes where the exact watt value varies each cycle.
 */
const PENDING_MATCH_TOLERANCE_W = 50;

/**
 * Parse an automation newValue (number, string-number, 'netzero', 'netzero+')
 * into a numeric watt value for comparison.
 * Returns null if the value cannot be compared numerically.
 */
function parsePendingCommandedPower(newValue) {
    if (newValue === null || newValue === undefined) return null;

    // netzero modes: use the same validation thresholds as automate_www.py
    if (newValue === 'netzero') {
        // Validation power is -250 W (POWER_MODE_NETZERO_VALIDATION_W)
        return -250;
    }
    if (newValue === 'netzero+') {
        // Validation power is +250 W (POWER_MODE_NETZERO_PLUS_VALIDATION_W)
        return 250;
    }

    const parsed = Number(newValue);
    return Number.isFinite(parsed) ? parsed : null;
}

/**
 * Cross-check the latest automation status change against the actual device
 * reading rendered in the power bar.  When a command was issued recently and
 * the device hasn't yet confirmed the new level, we add the 'power-pending'
 * class (animated stripes) to the bar fill and show a small "⏳ Pending…" label
 * next to the power value.  When the device confirms, both are removed.
 *
 * @param {Object|null} automationData - Data returned by fetchAutomationStatus()
 */
function applyPendingPowerState(automationData) {
    // ── helpers ───────────────────────────────────────────────────────────────

    /** Remove pending visual from bar fill and label. */
    function clearPendingState() {
        const fill = document.getElementById('charge-power-bar-fill');
        if (fill) fill.classList.remove('power-pending');

        const label = document.querySelector('.charge-power-bar-pending-label');
        if (label) label.remove();
    }

    /** Apply pending visual to bar fill and insert/update label next to power value. */
    function setPendingState() {
        // Stripe the bar fill
        const fill = document.getElementById('charge-power-bar-fill');
        if (fill) fill.classList.add('power-pending');

        // Label: insert after the .charge-power-value span if not already present
        const powerValueEl = document.querySelector('.charge-power-value');
        if (powerValueEl && !powerValueEl.querySelector('.charge-power-bar-pending-label')) {
            const lbl = document.createElement('span');
            lbl.className = 'charge-power-bar-pending-label';
            lbl.textContent = '⏳ Pending…';
            powerValueEl.appendChild(lbl);
        }
    }

    // ── guard: no automation data ────────────────────────────────────────────
    if (!automationData || !automationData.success) {
        clearPendingState();
        return;
    }

    // ── find latest 'change' entry ────────────────────────────────────────────
    const changes = (automationData.lastChanges || []).filter(e => e.type === 'change');
    if (changes.length === 0) {
        clearPendingState();
        return;
    }

    // lastChanges is already sorted newest-first by the backend
    const latest = changes[0];
    const entryAge = Math.floor(Date.now() / 1000) - (latest.timestamp || 0);

    if (entryAge > PENDING_WINDOW_SECONDS) {
        // Command is old enough that the device must have responded (or failed)
        clearPendingState();
        return;
    }

    // ── parse commanded power ─────────────────────────────────────────────────
    const commandedW = parsePendingCommandedPower(latest.newValue);
    if (commandedW === null) {
        // Cannot compare (e.g. unknown string), don't show pending
        clearPendingState();
        return;
    }

    // ── read actual power from DOM (set by renderChargeStatus via data-actual-power) ───
    const contentEl = document.getElementById('charge-status-content');
    if (!contentEl) {
        clearPendingState();
        return;
    }
    const actualW = Number(contentEl.dataset.actualPower);
    if (!Number.isFinite(actualW)) {
        clearPendingState();
        return;
    }

    // ── compare with tolerance ────────────────────────────────────────────────
    const withinTolerance = Math.abs(commandedW - actualW) <= PENDING_MATCH_TOLERANCE_W;

    if (withinTolerance) {
        // Device has confirmed the new power level — remove pending state
        if (DEBUG_MODE) {
            console.log(`✅ Power confirmed: commanded=${commandedW} W, actual=${actualW} W`);
        }
        clearPendingState();
    } else {
        // Device hasn't caught up yet — show pending state
        if (DEBUG_MODE) {
            console.log(`⏳ Power pending: commanded=${commandedW} W, actual=${actualW} W, age=${entryAge}s`);
        }
        setPendingState();
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
    window.chargeStatusDetailsExpanded = isExpanded;

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
 * Start auto-refresh interval with the current cadence (if page is visible)
 */
function startAutoRefresh() {
    if (noBackendDialogShown) {
        updateGridFastIndicator();
        return;
    }

    // Clear any existing interval first
    if (autoRefreshIntervalId !== null) {
        clearInterval(autoRefreshIntervalId);
        autoRefreshIntervalId = null;
    }

    // Only start interval if page is visible
    if (!document.hidden) {
        ensureNextScheduleRefreshAt();
        updateGridFastIndicator();

        autoRefreshIntervalId = setInterval(() => {
            // Double-check page is still visible before refreshing
            if (!document.hidden && typeof refreshStatus === 'function') {
                if (DEBUG_MODE) {
                    console.log('⏰ Auto-refresh interval triggered');
                }

                if (remainingBoostTicks > 0) {
                    console.log('⏱️ Fast auto-refresh tick ' + (BOOST_TICK_COUNT - remainingBoostTicks + 1) + '/' + BOOST_TICK_COUNT);
                }

                indicateAutoRefresh();
                refreshStatus(true);
                maybeRefreshScheduleData();

                if (remainingBoostTicks > 0) {
                    remainingBoostTicks -= 1;
                    if (remainingBoostTicks === 0) {
                        activeRefreshIntervalMs = NORMAL_REFRESH_INTERVAL_MS;
                        console.log('⏱️ Fast refresh burst completed; returning to 20-second interval');
                        updateGridFastIndicator();
                        startAutoRefresh();
                    }
                }
            } else if (document.hidden) {
                if (DEBUG_MODE) {
                    console.log('⏰ Auto-refresh skipped (page hidden)');
                }
            }
        }, activeRefreshIntervalMs);

        console.log('⏰ Auto-refresh interval started (every ' + (activeRefreshIntervalMs / 1000) + ' seconds)');
    } else {
        updateGridFastIndicator();
    }
}

/**
 * Restart the 10-tick fast-refresh burst and optionally refresh immediately.
 * @param {boolean} immediateRefresh
 */
function restartFastRefreshBurst(immediateRefresh = false) {
    remainingBoostTicks = BOOST_TICK_COUNT;
    activeRefreshIntervalMs = BOOST_REFRESH_INTERVAL_MS;
    updateGridFastIndicator();

    if (document.hidden || noBackendDialogShown) {
        return;
    }

    if (immediateRefresh && typeof refreshStatus === 'function') {
        indicateAutoRefresh();
        refreshStatus(true);
        maybeRefreshScheduleData();
    }

    startAutoRefresh();
}

window.restartFastRefreshBurst = restartFastRefreshBurst;

/**
 * Stop auto-refresh interval
 */
function stopAutoRefresh() {
    if (autoRefreshIntervalId !== null) {
        clearInterval(autoRefreshIntervalId);
        autoRefreshIntervalId = null;
        console.log('⏸️ Auto-refresh interval stopped');
    }
    updateGridFastIndicator();
}

/**
 * Auto-refresh only when the page is visible.
 * Reload and foreground events restart a 10-tick fast-refresh burst.
 */
document.addEventListener('DOMContentLoaded', () => {
    // Wire "Back-end not running" dialog Retry button
    const noBackendRetryBtn = document.getElementById('no-backend-retry-btn');
    if (noBackendRetryBtn) {
        noBackendRetryBtn.addEventListener('click', () => { location.reload(); });
    }

    bindGridFastRefreshTrigger();
    updateGridFastIndicator();

    // Track initial state
    wasPageHidden = document.hidden;

    // Start auto-refresh if page is visible on initial load
    if (!document.hidden) {
        restartFastRefreshBurst(true);
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
                // Page became visible after being hidden - restart fast-refresh burst
                restartFastRefreshBurst(true);
                wasPageHidden = false;
            }
        }
    });
});
