/**
 * Price Overview Bar Graph
 * Renders the bar graph visualization for today and tomorrow's electricity prices
 */

/** 24 cent – used for bar height when no price data; bars stay grey and tooltip says no data */
const PRICE_PROXY_NO_DATA = 0.24;

/**
 * Interpolates between two RGB colors
 * @param {number} r1 - Red component of first color (0-255)
 * @param {number} g1 - Green component of first color (0-255)
 * @param {number} b1 - Blue component of first color (0-255)
 * @param {number} r2 - Red component of second color (0-255)
 * @param {number} g2 - Green component of second color (0-255)
 * @param {number} b2 - Blue component of second color (0-255)
 * @param {number} factor - Interpolation factor (0.0 to 1.0)
 * @returns {string} RGB color string
 */
function interpolateColor(r1, g1, b1, r2, g2, b2, factor) {
    const r = Math.round(r1 + (r2 - r1) * factor);
    const g = Math.round(g1 + (g2 - g1) * factor);
    const b = Math.round(b1 + (b2 - b1) * factor);
    return `rgb(${r}, ${g}, ${b})`;
}

/**
 * Gets color for a price value based on min/max range
 * Green (low) to Red (high) gradient
 * @param {number|null} price - Price value or null
 * @param {number} minPrice - Minimum price in range
 * @param {number} maxPrice - Maximum price in range
 * @returns {string} RGB color string or gray for null
 */
function getPriceColor(price, minPrice, maxPrice) {
    if (price === null || price === undefined || isNaN(price)) {
        return '#606060'; // Gray for null values
    }
    
    if (minPrice === maxPrice) {
        // All prices are the same, use middle color (yellow)
        return 'rgb(255, 200, 0)';
    }
    
    // Normalize price to 0-1 range
    const normalized = (price - minPrice) / (maxPrice - minPrice);
    
    // Green (low) to Red (high)
    // Green: rgb(76, 175, 80) = #4CAF50
    // Red: rgb(244, 67, 54) = #F44336
    return interpolateColor(76, 175, 80, 244, 67, 54, normalized);

}

/**
 * Formats price for display
 * @param {number|null} price - Price value or null
 * @returns {string} Formatted price string
 */
function formatPrice(price) {
    if (price === null || price === undefined || isNaN(price)) {
        return 'N/A';
    }
    return '€' + price.toFixed(3);
}

/**
 * Formats price as cents (price * 100, rounded to 0 decimals)
 * @param {number|null} price - Price value or null
 * @returns {string} Price in cents as string, or empty string for null
 */
function formatPriceCents(price) {
    if (price === null || price === undefined || isNaN(price)) {
        return '';
    }
    return Math.round(price * 100).toString();
}

/**
 * Spot price (excl. tax) from price incl. 21% VAT: P_excl = (P_incl / 1.21) − 0.10880
 * @param {number|null} pIncl - Price incl. tax (€/kWh) or null
 * @returns {number|null} Spot price or null
 */
function spotPriceFromIncl(pIncl) {
    if (pIncl == null || typeof pIncl !== 'number' || Number.isNaN(pIncl)) return null;
    return (pIncl / 1.21) - 0.09;
}

let priceGraphPopup = null;
let priceGraphPopupActiveBar = null;
let priceGraphPopupActiveContainer = null;
const priceGraphPopupBoundContainers = new WeakSet();

function formatHourRange(hourValue) {
    const hour = Number.isFinite(hourValue) ? hourValue : NaN;
    if (Number.isNaN(hour)) return '';
    const startHour = String(hour).padStart(2, '0');
    const endHour = String((hour + 1) % 24).padStart(2, '0');
    return `${startHour}:00 - ${endHour}:00`;
}

function ensurePriceGraphPopup() {
    if (priceGraphPopup) return priceGraphPopup;

    const popup = document.createElement('div');
    popup.className = 'price-graph-popup';
    popup.innerHTML = `
        <div class="price-graph-popup-time"></div>
        <div class="price-graph-popup-price"></div>
        <div class="price-graph-popup-spot-price"></div>
        <div class="price-graph-popup-schedule"></div>
        <div class="price-graph-popup-source"></div>
    `;
    document.body.appendChild(popup);

    window.addEventListener('scroll', hidePriceGraphPopup, true);
    window.addEventListener('resize', hidePriceGraphPopup);

    priceGraphPopup = popup;
    return popup;
}

function hidePriceGraphPopup() {
    if (!priceGraphPopup) return;
    priceGraphPopup.style.display = 'none';
    priceGraphPopup.style.visibility = 'hidden';
    priceGraphPopupActiveBar = null;
    priceGraphPopupActiveContainer = null;
}

function bindPopupContainer(container) {
    if (!container || priceGraphPopupBoundContainers.has(container)) return;
    container.addEventListener('scroll', hidePriceGraphPopup, { passive: true });
    priceGraphPopupBoundContainers.add(container);
}

/**
 * Returns true when the price graph is shown in mobile context (mobile page or narrow viewport).
 */
function isPriceGraphMobile() {
    return document.body.classList.contains('mobile-dark') || window.innerWidth <= 768;
}

let priceGraphMobilePopup = null;
let priceGraphMobilePopupEscapeHandler = null;
let priceGraphMobilePopupResizeHandler = null;

function ensurePriceGraphMobilePopup() {
    if (priceGraphMobilePopup) return priceGraphMobilePopup;

    const backdrop = document.createElement('div');
    backdrop.className = 'price-graph-mobile-popup';
    backdrop.setAttribute('id', 'price-graph-mobile-popup');
    backdrop.innerHTML = `
        <div class="price-graph-mobile-popup-dialog">
            <div class="price-graph-mobile-popup-header">
                <span class="price-graph-mobile-popup-title"></span>
                <button type="button" class="modal-close price-graph-mobile-popup-close" aria-label="Close">&times;</button>
            </div>
            <div class="price-graph-mobile-popup-body">
                <div class="price-graph-popup-price"></div>
                <div class="price-graph-popup-spot-price"></div>
                <div class="price-graph-popup-schedule"></div>
                <div class="price-graph-popup-source"></div>
            </div>
            <div class="price-graph-mobile-popup-footer">
                <div style="display:flex; gap:8px; align-items:center;">
                    <button type="button"
                            class="btn btn-outline price-graph-mobile-popup-netzero">
                        🔌&nbsp;netZero
                    </button>
                    <button type="button"
                            class="btn btn-outline price-graph-mobile-popup-netzero-plus">
                        ☀️&nbsp;netZero+
                    </button>
                </div>              
                <button type="button" class="btn btn-primary price-graph-mobile-popup-edit">Edit Schedule</button>
            </div>
        </div>
    `;
    document.body.appendChild(backdrop);

    priceGraphMobilePopup = backdrop;
    return backdrop;
}

function hidePriceGraphMobilePopup() {
    if (!priceGraphMobilePopup) return;
    priceGraphMobilePopup.classList.remove('active');
    if (priceGraphMobilePopupEscapeHandler) {
        document.removeEventListener('keydown', priceGraphMobilePopupEscapeHandler);
        priceGraphMobilePopupEscapeHandler = null;
    }
    if (priceGraphMobilePopupResizeHandler) {
        window.removeEventListener('resize', priceGraphMobilePopupResizeHandler);
        priceGraphMobilePopupResizeHandler = null;
    }
}

function showPriceGraphMobilePopup(bar, editModal, scheduleMap, key) {
    const popup = ensurePriceGraphMobilePopup();
    if (!bar || !popup) return;

    const titleEl = popup.querySelector('.price-graph-mobile-popup-title');
    const priceEl = popup.querySelector('.price-graph-popup-price');
    const spotPriceEl = popup.querySelector('.price-graph-popup-spot-price');
    const scheduleEl = popup.querySelector('.price-graph-popup-schedule');
    const sourceEl = popup.querySelector('.price-graph-popup-source');

    const hourValue = parseInt(bar.dataset.hour, 10);
    const timeRange = formatHourRange(hourValue);

    const rawPrice = bar.dataset.price;
    const isProxy = bar.dataset.proxy === 'true';
    const priceValue = rawPrice === '' || rawPrice === undefined ? null : Number(rawPrice);
    const priceDisplay = isProxy ? 'No price data available' : (priceValue === null || Number.isNaN(priceValue) ? 'N/A' : formatPrice(priceValue));

    const spotPriceValue = spotPriceFromIncl(priceValue);
    const spotPriceDisplay = spotPriceValue != null ? `Spot price: ${formatPrice(spotPriceValue)}` : '';

    const scheduleValue = bar.dataset.scheduleValue;
    const scheduleDisplay = scheduleValue !== undefined && scheduleValue !== '' ? scheduleValue : '—';

    const scheduleSource = bar.dataset.scheduleSource;
    const hasSource = scheduleSource !== undefined &&
        scheduleSource !== null &&
        scheduleSource !== '' &&
        scheduleSource !== 'null' &&
        scheduleSource !== 'undefined';
    const normalizedScheduleSource = hasSource ? String(scheduleSource).trim().toLowerCase() : '';
    const hasRuntimeCondition = bar.dataset.runtimeCondition === 'true';
    const ruleNameRaw = bar.dataset.ruleName;
    const hasRuleName = ruleNameRaw !== undefined &&
        ruleNameRaw !== null &&
        String(ruleNameRaw).trim() !== '';
    const ruleIndexRaw = bar.dataset.ruleIndex;
    const hasRuleIndex = ruleIndexRaw !== undefined &&
        ruleIndexRaw !== null &&
        String(ruleIndexRaw).trim() !== '';
    const ruleLabel = hasRuleName
        ? `${hasRuleIndex ? ('#' + String(ruleIndexRaw).trim() + ' ') : ''}${String(ruleNameRaw).trim()}`
        : '';
    const plainSourceLabel = (hasSource && normalizedScheduleSource !== 'condition') ? String(scheduleSource).trim() : '';
    const sourceLabel = hasRuntimeCondition
        ? (ruleLabel ? `runtime condition (${ruleLabel})` : 'runtime condition')
        : (ruleLabel ? ruleLabel : plainSourceLabel);

    titleEl.textContent = `Time slot ${timeRange || '—'}`;
    priceEl.textContent = priceDisplay;
    spotPriceEl.textContent = spotPriceDisplay;
    scheduleEl.textContent = `Schedule: ${scheduleDisplay}`;
    if (sourceEl) {
        sourceEl.textContent = sourceLabel ? `Source: ${sourceLabel}` : '';
    }

    const close = () => {
        hidePriceGraphMobilePopup();
    };

    const netZeroBtn = popup.querySelector('.price-graph-mobile-popup-netzero');
    const netZeroPlusBtn = popup.querySelector('.price-graph-mobile-popup-netzero-plus');
    const editBtn = popup.querySelector('.price-graph-mobile-popup-edit');
    const closeBtn = popup.querySelector('.price-graph-mobile-popup-close');

    const hasExistingEntry = scheduleMap && Object.prototype.hasOwnProperty.call(scheduleMap, key);
    const existingValue = hasExistingEntry ? scheduleMap[key] : undefined;

    const applyQuickMode = async (mode) => {
        close();
        if (editModal && typeof editModal.applyQuickMode === 'function') {
            await editModal.applyQuickMode(key, mode, {
                originalKey: hasExistingEntry ? key : null
            });

            // Ensure schedule panels and price graph are refreshed after a quick change,
            // especially on the mobile page where the user expects immediate feedback.
            if (typeof window !== 'undefined' &&
                typeof window.refreshScheduleAndPricesImmediate === 'function') {
                try {
                    await window.refreshScheduleAndPricesImmediate();
                } catch (e) {
                    console.error('Failed to refresh schedule after quick mode change:', e);
                }
            }
        } else if (editModal) {
            if (existingValue !== undefined) {
                editModal.open(key, existingValue);
            } else {
                editModal.open(null, null, key);
            }
        }
    };

    netZeroBtn.onclick = () => applyQuickMode('netzero');
    netZeroPlusBtn.onclick = () => applyQuickMode('netzero+');

    editBtn.onclick = () => {
        close();
        if (editModal) {
            if (existingValue !== undefined) {
                editModal.open(key, existingValue);
            } else {
                editModal.open(null, null, key);
            }
        }
    };

    closeBtn.onclick = close;

    popup.onclick = (e) => {
        if (e.target === popup) close();
    };
    const dialog = popup.querySelector('.price-graph-mobile-popup-dialog');
    if (dialog) {
        dialog.onclick = (e) => e.stopPropagation();
    }

    priceGraphMobilePopupEscapeHandler = (e) => {
        if (e.key === 'Escape') close();
    };
    document.addEventListener('keydown', priceGraphMobilePopupEscapeHandler);

    priceGraphMobilePopupResizeHandler = close;
    window.addEventListener('resize', priceGraphMobilePopupResizeHandler);

    popup.classList.add('active');
}

function showPriceGraphPopup(bar, container) {
    const popup = ensurePriceGraphPopup();
    if (!bar || !container || !popup) return;

    const timeEl = popup.querySelector('.price-graph-popup-time');
    const priceEl = popup.querySelector('.price-graph-popup-price');
    const spotPriceEl = popup.querySelector('.price-graph-popup-spot-price');
    const scheduleEl = popup.querySelector('.price-graph-popup-schedule');
    const sourceEl = popup.querySelector('.price-graph-popup-source');

    const hourValue = parseInt(bar.dataset.hour, 10);
    const timeRange = formatHourRange(hourValue);

    const rawPrice = bar.dataset.price;
    const isProxy = bar.dataset.proxy === 'true';
    const priceValue = rawPrice === '' || rawPrice === undefined ? null : Number(rawPrice);
    const priceDisplay = isProxy ? 'No price data available' : (priceValue === null || Number.isNaN(priceValue) ? 'N/A' : formatPrice(priceValue));

    const spotPriceValue = spotPriceFromIncl(priceValue);
    const spotPriceDisplay = spotPriceValue != null ? `Spot price: ${formatPrice(spotPriceValue)}` : '';

    const scheduleValue = bar.dataset.scheduleValue;
    const scheduleDisplay = scheduleValue !== undefined && scheduleValue !== '' ? scheduleValue : '—';

    const scheduleSource = bar.dataset.scheduleSource;
    const hasSource = scheduleSource !== undefined &&
        scheduleSource !== null &&
        scheduleSource !== '' &&
        scheduleSource !== 'null' &&
        scheduleSource !== 'undefined';
    const normalizedScheduleSource = hasSource ? String(scheduleSource).trim().toLowerCase() : '';
    const hasRuntimeCondition = bar.dataset.runtimeCondition === 'true';
    const ruleNameRaw = bar.dataset.ruleName;
    const hasRuleName = ruleNameRaw !== undefined &&
        ruleNameRaw !== null &&
        String(ruleNameRaw).trim() !== '';
    const ruleIndexRaw = bar.dataset.ruleIndex;
    const hasRuleIndex = ruleIndexRaw !== undefined &&
        ruleIndexRaw !== null &&
        String(ruleIndexRaw).trim() !== '';
    const ruleLabel = hasRuleName
        ? `${hasRuleIndex ? ('#' + String(ruleIndexRaw).trim() + ' ') : ''}${String(ruleNameRaw).trim()}`
        : '';
    const plainSourceLabel = (hasSource && normalizedScheduleSource !== 'condition') ? String(scheduleSource).trim() : '';
    const sourceLabel = hasRuntimeCondition
        ? (ruleLabel ? `runtime condition (${ruleLabel})` : 'runtime condition')
        : (ruleLabel ? ruleLabel : plainSourceLabel);

    timeEl.textContent = timeRange || '—';
    priceEl.textContent = priceDisplay;
    spotPriceEl.textContent = spotPriceDisplay;
    scheduleEl.textContent = `Schedule: ${scheduleDisplay}`;
    if (sourceEl) {
        sourceEl.textContent = sourceLabel ? `Source: ${sourceLabel}` : '';
    }

    popup.style.display = 'block';
    popup.style.visibility = 'hidden';

    const barRect = bar.getBoundingClientRect();
    const containerRect = container.getBoundingClientRect();

    requestAnimationFrame(() => {
        const popupRect = popup.getBoundingClientRect();
        let left = barRect.left + barRect.width / 2 - popupRect.width / 2;
        const minLeft = containerRect.left + 4;
        const maxLeft = containerRect.right - popupRect.width - 4;
        left = Math.max(minLeft, Math.min(left, maxLeft));

        let top = barRect.top - popupRect.height - 8;
        if (top < containerRect.top + 4) {
            top = barRect.bottom + 8;
        }

        popup.style.left = `${Math.round(left)}px`;
        popup.style.top = `${Math.round(top)}px`;
        popup.style.visibility = 'visible';
    });

    priceGraphPopupActiveBar = bar;
    priceGraphPopupActiveContainer = container;
}

/**
 * Renders the price graph for today and tomorrow
 * @param {Object} priceData - Price data from API
 * @param {number} currentHour - Current hour (0-23)
 * @param {Array|Object} scheduleEntries - Array of schedule entries or schedule context
 * @param {Object} editModal - Edit modal instance for click handlers
 */
function renderPriceGraph(priceData, currentHour, scheduleEntries, editModal) {
    const todayContainer = document.getElementById('price-graph-today');
    const tomorrowContainer = document.getElementById('price-graph-tomorrow');
    const tomorrowContainerMobile = document.getElementById('price-graph-tomorrow-mobile');
    
    if (!todayContainer) return;
    
    const scheduleContext = Array.isArray(scheduleEntries) ? null : scheduleEntries;
    const scheduleEntryList = Array.isArray(scheduleEntries) ? scheduleEntries : (scheduleContext?.entries || []);
    const resolvedToday = scheduleContext?.resolvedToday || [];
    const resolvedTomorrow = scheduleContext?.resolvedTomorrow || [];

    const buildExpandedHourMap = (resolved) => {
        if (!Array.isArray(resolved) || resolved.length === 0) return null;
        const baseMap = typeof buildHourMap === 'function' ? buildHourMap(resolved) : null;
        if (!baseMap) return null;
        const expanded = {};
        let lastValue;
        for (let h = 0; h < 24; h++) {
            if (baseMap[h] !== undefined) {
                lastValue = baseMap[h];
            }
            if (lastValue !== undefined) {
                expanded[h] = lastValue;
            }
        }
        return expanded;
    };

    /**
     * Build an expanded hour -> source map from resolved schedule slots.
     * Propagates the last non-null source forward across the day.
     */
    const buildExpandedSourceMap = (resolved) => {
        if (!Array.isArray(resolved) || resolved.length === 0) return null;

        const baseSourceMap = {};
        let lastSource = undefined;

        resolved.forEach((slot) => {
            if (!slot || slot.time === undefined) return;
            const hour = parseInt(String(slot.time).substring(0, 2), 10);
            if (Number.isNaN(hour)) return;

            if (Object.prototype.hasOwnProperty.call(slot, 'source')) {
                if (slot.source !== undefined && slot.source !== null && String(slot.source).trim() !== '') {
                    lastSource = slot.source;
                } else {
                    lastSource = undefined;
                }
            }
            baseSourceMap[hour] = lastSource;
        });

        if (Object.keys(baseSourceMap).length === 0) {
            return null;
        }

        const expanded = {};
        let currentSource = undefined;
        for (let h = 0; h < 24; h++) {
            if (Object.prototype.hasOwnProperty.call(baseSourceMap, h)) {
                currentSource = baseSourceMap[h];
            }
            expanded[h] = currentSource;
        }

        return expanded;
    };

    /**
     * Build an expanded hour -> hasRuntimeCondition map from resolved schedule slots.
     * Propagates the last known runtime-condition state forward across the day.
     */
    const buildExpandedRuntimeConditionMap = (resolved) => {
        if (!Array.isArray(resolved) || resolved.length === 0) return null;

        const baseRuntimeMap = {};
        let lastHasRuntimeCondition = undefined;

        resolved.forEach((slot) => {
            if (!slot || slot.time === undefined) return;
            const hour = parseInt(String(slot.time).substring(0, 2), 10);
            if (Number.isNaN(hour)) return;

            if (Object.prototype.hasOwnProperty.call(slot, 'runtime_conditions')) {
                const runtimeConditions = slot.runtime_conditions;
                lastHasRuntimeCondition = Array.isArray(runtimeConditions) && runtimeConditions.length > 0;
            } else {
                lastHasRuntimeCondition = false;
            }
            baseRuntimeMap[hour] = lastHasRuntimeCondition;
        });

        if (Object.keys(baseRuntimeMap).length === 0) {
            return null;
        }

        const expanded = {};
        let currentState = undefined;
        for (let h = 0; h < 24; h++) {
            if (Object.prototype.hasOwnProperty.call(baseRuntimeMap, h)) {
                currentState = baseRuntimeMap[h];
            }
            if (currentState !== undefined) {
                expanded[h] = currentState;
            }
        }
        return expanded;
    };

    /**
     * Build an expanded hour -> rule metadata map from resolved schedule slots.
     * Propagates last known rule_name/rule_index forward across the day.
     */
    const buildExpandedRuleMetaMap = (resolved) => {
        if (!Array.isArray(resolved) || resolved.length === 0) return null;

        const baseRuleMap = {};
        let lastRuleMeta = undefined;

        resolved.forEach((slot) => {
            if (!slot || slot.time === undefined) return;
            const hour = parseInt(String(slot.time).substring(0, 2), 10);
            if (Number.isNaN(hour)) return;

            const hasRuleName = Object.prototype.hasOwnProperty.call(slot, 'rule_name') &&
                slot.rule_name !== undefined &&
                slot.rule_name !== null &&
                String(slot.rule_name).trim() !== '';
            const hasRuleIndex = Object.prototype.hasOwnProperty.call(slot, 'rule_index') &&
                slot.rule_index !== undefined &&
                slot.rule_index !== null &&
                !Number.isNaN(Number(slot.rule_index));

            if (hasRuleName || hasRuleIndex) {
                lastRuleMeta = {
                    ruleName: hasRuleName ? String(slot.rule_name).trim() : undefined,
                    ruleIndex: hasRuleIndex ? String(parseInt(slot.rule_index, 10)) : undefined
                };
            } else {
                lastRuleMeta = undefined;
            }
            baseRuleMap[hour] = lastRuleMeta;
        });

        if (Object.keys(baseRuleMap).length === 0) {
            return null;
        }

        const expanded = {};
        let currentMeta = undefined;
        for (let h = 0; h < 24; h++) {
            if (Object.prototype.hasOwnProperty.call(baseRuleMap, h)) {
                currentMeta = baseRuleMap[h];
            }
            expanded[h] = currentMeta;
        }
        return expanded;
    };

    const scheduleHourMapByDate = {};
    const scheduleSourceMapByDate = {};
    const scheduleRuntimeConditionMapByDate = {};
    const scheduleRuleMetaMapByDate = {};

    // Build a map of schedule entries for quick lookup
    const scheduleMap = {};
    if (scheduleEntryList) {
        scheduleEntryList.forEach(entry => {
            scheduleMap[entry.key] = entry.value;
        });
    }
    
    const getScheduleType = (value) => {
        if (value === 'netzero') {
            return 'netzero';
        }
        if (value === 'netzero+') {
            return 'netzero-plus';
        }
        if (value === null || value === undefined || value === '') {
            return '';
        }
        if (typeof value === 'string') {
            const trimmed = value.trim();
            if (trimmed === '') return '';
            const normalized = trimmed
                .toLowerCase()
                .replace(/[^a-z0-9+.\- ]/g, '')
                .replace(/\s+/g, ' ')
                .trim();
            if (normalized === 'net zero' || normalized === 'netzero') {
                return 'netzero';
            }
            if (normalized === 'netzero+' || normalized === 'solar charge' || normalized.includes('only')) {
                return 'netzero-plus';
            }
            const match = trimmed.match(/[-+]?\d+(?:\.\d+)?/);
            if (match) {
                const parsed = Number(match[0]);
                if (!Number.isNaN(parsed)) {
                    if (parsed > 0) return 'charge';
                    if (parsed < 0) return 'discharge';
                    return '';
                }
            }
        }
        const numericValue = typeof value === 'number' ? value : Number(value);
        if (!Number.isNaN(numericValue)) {
            if (numericValue > 0) return 'charge';
            if (numericValue < 0) return 'discharge';
            return '';
        }
        return '';
    };

    const scheduleByDate = {};
    if (scheduleEntryList) {
        scheduleEntryList.forEach((entry) => {
            if (!entry || !entry.key) return;
            const dateKey = entry.key.slice(0, 8);
            if (!scheduleByDate[dateKey]) {
                scheduleByDate[dateKey] = [];
            }
            scheduleByDate[dateKey].push({
                key: entry.key,
                value: entry.value
            });
        });
        Object.values(scheduleByDate).forEach((entries) => {
            entries.sort((a, b) => a.key.localeCompare(b.key));
        });
    }

    const getActiveScheduleInfo = (dateStr, hourKey) => {
        const hourMap = scheduleHourMapByDate[dateStr];
        const sourceMap = scheduleSourceMapByDate[dateStr];
        const runtimeConditionMap = scheduleRuntimeConditionMapByDate[dateStr];
        const ruleMetaMap = scheduleRuleMetaMapByDate[dateStr];
        const hourIndex = parseInt(hourKey, 10);

        let value;
        let source;
        let hasRuntimeCondition;
        let ruleName;
        let ruleIndex;

        if (hourMap) {
            value = hourMap[hourIndex];
        }
        if (sourceMap) {
            source = sourceMap[hourIndex];
        }
        if (runtimeConditionMap) {
            hasRuntimeCondition = runtimeConditionMap[hourIndex];
        }
        if (ruleMetaMap && Object.prototype.hasOwnProperty.call(ruleMetaMap, hourIndex) && ruleMetaMap[hourIndex]) {
            ruleName = ruleMetaMap[hourIndex].ruleName;
            ruleIndex = ruleMetaMap[hourIndex].ruleIndex;
        }

        if (value !== undefined || source !== undefined || hasRuntimeCondition !== undefined || ruleName !== undefined || ruleIndex !== undefined) {
            return { value, source, hasRuntimeCondition, ruleName, ruleIndex };
        }

        const entries = scheduleByDate[dateStr];
        if (!entries || entries.length === 0) {
            return { value: undefined, source: undefined, hasRuntimeCondition: undefined, ruleName: undefined, ruleIndex: undefined };
        }

        let activeValue = undefined;
        for (const entry of entries) {
            const entryHour = entry.key.slice(8, 10);
            if (entryHour <= hourKey) {
                activeValue = entry.value;
            } else {
                break;
            }
        }
        return { value: activeValue, source: undefined, hasRuntimeCondition: undefined, ruleName: undefined, ruleIndex: undefined };
    };

    // Extract price data
    const todayPrices = priceData?.today || {};
    // Always treat tomorrow prices as an object; when empty we will render proxy (grey) bars
    const tomorrowPrices = priceData?.tomorrow || {};
    
    // Always show the tomorrow cards; when there is no real data the graph will use proxy prices
    if (tomorrowContainer) {
        const tomorrowCard = tomorrowContainer.closest('.card');
        if (tomorrowCard) {
            tomorrowCard.style.display = '';
        }
    }
    
    if (tomorrowContainerMobile) {
        const tomorrowCardMobile = document.getElementById('tomorrow-price-card-mobile');
        if (tomorrowCardMobile) {
            tomorrowCardMobile.style.display = '';
        }
    }
    
    // Collect all prices to calculate min/max (use proxy when missing so scale is sensible)
    const allPrices = [];
    for (let h = 0; h < 24; h++) {
        const hourKey = String(h).padStart(2, '0');
        const todayVal = todayPrices[hourKey];
        const tomorrowVal = tomorrowPrices !== null && tomorrowPrices !== undefined ? tomorrowPrices[hourKey] : undefined;
        if (todayVal !== null && todayVal !== undefined && !isNaN(todayVal)) {
            allPrices.push(todayVal);
        } else {
            allPrices.push(PRICE_PROXY_NO_DATA);
        }
        if (tomorrowPrices !== null && tomorrowPrices !== undefined) {
            if (tomorrowVal !== null && tomorrowVal !== undefined && !isNaN(tomorrowVal)) {
                allPrices.push(tomorrowVal);
            } else {
                allPrices.push(PRICE_PROXY_NO_DATA);
            }
        }
    }
    
    // Calculate min and max prices
    let minPrice = 0;
    let maxPrice = 0.5; // Default max if no prices
    if (allPrices.length > 0) {
        minPrice = Math.min(...allPrices);
        maxPrice = Math.max(...allPrices);
        // Add some padding to the range for better visualization
        const range = maxPrice - minPrice;
        if (range > 0) {
            minPrice -= range * 0.1;
            maxPrice += range * 0.1;
        } else {
            // All prices are the same, add small padding
            minPrice -= 0.01;
            maxPrice += 0.01;
        }
    }
    
    // Get current date
    const now = new Date();
    const currentDate = (scheduleContext?.todayDate || (now.getFullYear().toString() +
        String(now.getMonth() + 1).padStart(2, '0') +
        String(now.getDate()).padStart(2, '0')));
    
    // Helper function to render a row of price bars
    const renderPriceRow = (prices, dateStr, container, isToday) => {
        container.innerHTML = '';
        bindPopupContainer(container);
        
        for (let h = 0; h < 24; h++) {
            const hourKey = String(h).padStart(2, '0');
            const price = prices[hourKey] !== undefined ? prices[hourKey] : null;
            const hasRealPrice = price !== null && price !== undefined && !isNaN(price);
            const priceForHeight = hasRealPrice ? price : PRICE_PROXY_NO_DATA;
            
            // Determine if this is the current hour
            const isCurrentHour = isToday && (h === now.getHours()) && (dateStr === currentDate);
            
            // Calculate bar height (based on price relative to min/max; use proxy for missing data)
            let barHeight = '4px'; // Minimum height
            const priceRange = maxPrice - minPrice;
            if (priceRange > 0) {
                const normalized = (priceForHeight - minPrice) / priceRange;
                barHeight = Math.max(4, normalized * 100) + '%';
            } else {
                barHeight = '50%'; // Middle height if all prices are same
            }
            
            // Get color for price (grey when no real data)
            const barColor = getPriceColor(price, minPrice, maxPrice);
            
            // Format display text
            const priceDisplay = formatPrice(price);
            
            // Create key for schedule lookup (YYYYMMDDHHmm format)
            const hourTime = hourKey + '00';
            const key = dateStr + hourTime;
            
            const { value: scheduledValue, source: scheduledSource, hasRuntimeCondition, ruleName, ruleIndex } = getActiveScheduleInfo(dateStr, hourKey);

            // Create bar element
            const barDiv = document.createElement('div');
            barDiv.className = `price-graph-bar ${isCurrentHour ? 'price-current' : ''}`;
            barDiv.dataset.date = dateStr;
            barDiv.dataset.hour = h;
            barDiv.dataset.time = hourTime;
            barDiv.dataset.key = key;
            barDiv.dataset.price = hasRealPrice ? price : '';
            barDiv.dataset.proxy = hasRealPrice ? '' : 'true';
            const scheduleType = scheduledValue !== undefined ? getScheduleType(scheduledValue) : '';
            if (scheduleType) {
                barDiv.classList.add('has-schedule');
                barDiv.dataset.scheduleType = scheduleType;
                barDiv.dataset.scheduleValue = scheduledValue;
                if (hasRuntimeCondition === true) {
                    barDiv.classList.add('has-runtime-condition');
                    barDiv.dataset.runtimeCondition = 'true';
                }
            }
            if (scheduledSource !== undefined && scheduledSource !== null && scheduledSource !== '') {
                barDiv.dataset.scheduleSource = String(scheduledSource);
            }
            if (ruleName !== undefined && ruleName !== null && String(ruleName).trim() !== '') {
                barDiv.dataset.ruleName = String(ruleName);
            }
            if (ruleIndex !== undefined && ruleIndex !== null && String(ruleIndex).trim() !== '') {
                barDiv.dataset.ruleIndex = String(ruleIndex);
            }
            barDiv.setAttribute('aria-label', `${hourKey}:00 - ${hasRealPrice ? priceDisplay : 'No price data available'}`);
            
            const barInner = document.createElement('div');
            barInner.className = `price-graph-bar-inner ${!hasRealPrice ? 'price-null' : ''}`;
            barInner.style.height = barHeight;
            barInner.style.backgroundColor = barColor;
            
            const barLabel = document.createElement('div');
            barLabel.className = 'price-graph-bar-label';
            barLabel.textContent = hourKey;
            
            // Create price label element
            const priceLabel = document.createElement('div');
            priceLabel.className = 'price-graph-bar-price';
            priceLabel.textContent = formatPriceCents(price);
            
            barDiv.appendChild(barInner);
            barDiv.appendChild(barLabel);
            barDiv.appendChild(priceLabel);
            
            // On mobile: no hover/focus tooltip (only click opens the mobile popup)
            if (!isPriceGraphMobile()) {
                const showPopup = () => showPriceGraphPopup(barDiv, container);
                const hidePopup = () => hidePriceGraphPopup();
                barDiv.addEventListener('mouseenter', showPopup);
                barDiv.addEventListener('mouseleave', hidePopup);
                barDiv.addEventListener('focus', showPopup);
                barDiv.addEventListener('blur', hidePopup);
            }
            // Add click handler: on mobile show info popup first; on desktop open edit modal directly
            barDiv.addEventListener('click', () => {
                if (isPriceGraphMobile()) {
                    showPriceGraphMobilePopup(barDiv, editModal, scheduleMap, key);
                } else if (editModal) {
                    const existingValue = scheduleMap[key];
                    if (existingValue !== undefined) {
                        editModal.open(key, existingValue);
                    } else {
                        editModal.open(null, null, key);
                    }
                }
            });
            
            container.appendChild(barDiv);
        }
    };
    
    // Calculate tomorrow's date string
    const tomorrowDateStr = scheduleContext?.tomorrowDate || (() => {
        const tomorrowDate = new Date(now);
        tomorrowDate.setDate(tomorrowDate.getDate() + 1);
        return tomorrowDate.getFullYear().toString() +
            String(tomorrowDate.getMonth() + 1).padStart(2, '0') +
            String(tomorrowDate.getDate()).padStart(2, '0');
    })();

    const todayHourMap = buildExpandedHourMap(resolvedToday);
    const tomorrowHourMap = buildExpandedHourMap(resolvedTomorrow);
    if (todayHourMap) {
        scheduleHourMapByDate[currentDate] = todayHourMap;
    }
    if (tomorrowHourMap) {
        scheduleHourMapByDate[tomorrowDateStr] = tomorrowHourMap;
    }
    const todaySourceMap = buildExpandedSourceMap(resolvedToday);
    const tomorrowSourceMap = buildExpandedSourceMap(resolvedTomorrow);
    const todayRuntimeConditionMap = buildExpandedRuntimeConditionMap(resolvedToday);
    const tomorrowRuntimeConditionMap = buildExpandedRuntimeConditionMap(resolvedTomorrow);
    const todayRuleMetaMap = buildExpandedRuleMetaMap(resolvedToday);
    const tomorrowRuleMetaMap = buildExpandedRuleMetaMap(resolvedTomorrow);
    if (todaySourceMap) {
        scheduleSourceMapByDate[currentDate] = todaySourceMap;
    }
    if (tomorrowSourceMap) {
        scheduleSourceMapByDate[tomorrowDateStr] = tomorrowSourceMap;
    }
    if (todayRuntimeConditionMap) {
        scheduleRuntimeConditionMapByDate[currentDate] = todayRuntimeConditionMap;
    }
    if (tomorrowRuntimeConditionMap) {
        scheduleRuntimeConditionMapByDate[tomorrowDateStr] = tomorrowRuntimeConditionMap;
    }
    if (todayRuleMetaMap) {
        scheduleRuleMetaMapByDate[currentDate] = todayRuleMetaMap;
    }
    if (tomorrowRuleMetaMap) {
        scheduleRuleMetaMapByDate[tomorrowDateStr] = tomorrowRuleMetaMap;
    }
    
    // Render today
    renderPriceRow(todayPrices, currentDate, todayContainer, true);
    if (priceData && priceData.error) {
        const errEl = document.createElement('div');
        errEl.className = 'price-graph-api-error';
        errEl.textContent = priceData.error;
        todayContainer.prepend(errEl);
    }
    
    // Render tomorrow in desktop container (will use proxy prices when no real data)
    if (tomorrowContainer) {
        renderPriceRow(tomorrowPrices, tomorrowDateStr, tomorrowContainer, false);
    }
    
    // Render tomorrow in mobile container (will use proxy prices when no real data)
    if (tomorrowContainerMobile) {
        const pricesToRender = tomorrowPrices || {};
        renderPriceRow(pricesToRender, tomorrowDateStr, tomorrowContainerMobile, false);
    }
    
    // Auto-scroll to current time (center it) for desktop view.
    // Use the today container and its nearest price-graph-container instead of the first match on the page.
    setTimeout(() => {
        if (!todayContainer) {
            return;
        }

        const currentBar = todayContainer.querySelector('.price-graph-bar.price-current');
        const container =
            todayContainer.closest('.price-graph-container') || todayContainer;

        if (currentBar && container && typeof container.scrollTo === 'function') {
            const containerWidth = container.clientWidth;
            const barLeft = currentBar.offsetLeft;
            const barWidth = currentBar.clientWidth;

            // Calculate scroll position to center the bar
            const scrollPos = barLeft - (containerWidth / 2) + (barWidth / 2);
            container.scrollTo({
                left: scrollPos,
                behavior: 'smooth'
            });
        }
    }, 100);
}

/**
 * Fetches price data from API and renders the graph
 * @param {string|string[]} priceApiUrl - URL or list of URLs to price API endpoint(s)
 * @param {Array} scheduleEntries - Array of schedule entries for lookup
 * @param {Object} editModal - Edit modal instance for click handlers
 */
async function fetchAndRenderPrices(priceApiUrl, scheduleEntries, editModal) {
    const normalizeUrls = (input) => {
        if (Array.isArray(input)) {
            return input
                .filter((u) => typeof u === 'string')
                .map((u) => u.trim())
                .filter((u) => u.length > 0);
        }
        if (typeof input === 'string' && input.trim().length > 0) {
            return [input.trim()];
        }
        return [];
    };

    const shuffle = (arr) => {
        const copy = arr.slice();
        for (let i = copy.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            const tmp = copy[i];
            copy[i] = copy[j];
            copy[j] = tmp;
        }
        return copy;
    };

    const candidateUrls = shuffle(normalizeUrls(priceApiUrl));

    try {
        if (candidateUrls.length === 0) {
            throw new Error('No price API URL configured');
        }

        let lastError = null;
        let priceData = null;

        for (const url of candidateUrls) {
            try {
                const response = await fetch(url);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status} from ${url}`);
                }

                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    console.error('Non-JSON response from price API:', url, text.substring(0, 200));
                    throw new Error(`Non-JSON response from ${url}`);
                }

                const parsed = await response.json();
                if (!parsed || typeof parsed !== 'object' || !('today' in parsed) || !('tomorrow' in parsed)) {
                    throw new Error(`Invalid price payload from ${url}`);
                }

                priceData = parsed;
                break;
            } catch (err) {
                lastError = err;
                console.warn('Price API candidate failed:', url, err);
            }
        }

        if (!priceData) {
            throw (lastError || new Error('All price API URLs failed'));
        }
        
        // Get current hour
        const now = new Date();
        const currentHour = now.getHours();
        
        // Render the price graph
        renderPriceGraph(priceData, currentHour, scheduleEntries, editModal);
    } catch (e) {
        console.error('Failed to fetch prices:', e);
        const errorMessage = e && (e.message || String(e));
        const now = new Date();
        const currentHour = now.getHours();
        renderPriceGraph({ today: {}, tomorrow: {}, error: errorMessage }, currentHour, scheduleEntries, editModal);
    }
}
