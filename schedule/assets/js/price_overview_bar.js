/**
 * Price Overview Bar Graph
 * Renders the bar graph visualization for today and tomorrow's electricity prices
 */

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
        return '#757575'; // Gray for null values
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

let priceGraphPopup = null;
let priceGraphPopupActiveBar = null;
let priceGraphPopupActiveContainer = null;
let priceGraphPopupSuppressClickUntil = 0;
let priceGraphPopupLastTouchBar = null;
const priceGraphPopupBoundContainers = new WeakSet();

function isTouchDevice() {
    return 'ontouchstart' in window || navigator.maxTouchPoints > 0;
}

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
        <div class="price-graph-popup-schedule"></div>
    `;
    document.body.appendChild(popup);

    const hideOnOutsideTouch = (event) => {
        if (!priceGraphPopup) return;
        if (event.target.closest('.price-graph-bar')) return;
        hidePriceGraphPopup();
    };

    document.addEventListener('touchstart', hideOnOutsideTouch, { passive: true });
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

function showPriceGraphPopup(bar, container) {
    const popup = ensurePriceGraphPopup();
    if (!bar || !container || !popup) return;

    const timeEl = popup.querySelector('.price-graph-popup-time');
    const priceEl = popup.querySelector('.price-graph-popup-price');
    const scheduleEl = popup.querySelector('.price-graph-popup-schedule');

    const hourValue = parseInt(bar.dataset.hour, 10);
    const timeRange = formatHourRange(hourValue);

    const rawPrice = bar.dataset.price;
    const priceValue = rawPrice === '' || rawPrice === undefined ? null : Number(rawPrice);
    const priceDisplay = priceValue === null || Number.isNaN(priceValue) ? 'N/A' : formatPrice(priceValue);

    const scheduleValue = bar.dataset.scheduleValue;
    const scheduleDisplay = scheduleValue !== undefined && scheduleValue !== '' ? scheduleValue : '—';

    timeEl.textContent = timeRange || '—';
    priceEl.textContent = priceDisplay;
    scheduleEl.textContent = `Schedule: ${scheduleDisplay}`;

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

    const scheduleHourMapByDate = {};

    // Build a map of schedule entries for quick lookup
    const scheduleMap = {};
    if (scheduleEntryList) {
        scheduleEntryList.forEach(entry => {
            scheduleMap[entry.key] = entry.value;
        });
    }
    
    const getScheduleType = (value) => {
        if (value === 'netzero') {
            return 'discharge';
        }
        if (value === 'netzero+') {
            return 'charge';
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
                return 'discharge';
            }
            if (normalized === 'netzero+' || normalized === 'solar charge') {
                return 'charge';
            }
            if (normalized.includes('only')) {
                return 'charge';
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

    const getActiveScheduleValue = (dateStr, hourKey) => {
        const hourMap = scheduleHourMapByDate[dateStr];
        if (hourMap) {
            const hourIndex = parseInt(hourKey, 10);
            return hourMap[hourIndex];
        }
        const entries = scheduleByDate[dateStr];
        if (!entries || entries.length === 0) return undefined;
        let activeValue;
        for (const entry of entries) {
            const entryHour = entry.key.slice(8, 10);
            if (entryHour <= hourKey) {
                activeValue = entry.value;
            } else {
                break;
            }
        }
        return activeValue;
    };

    // Extract price data
    const todayPrices = priceData?.today || {};
    const tomorrowPrices = priceData?.tomorrow || null;
    
    // Check if tomorrow's data is available (not null and has data)
    // Handle both null and empty object cases
    const tomorrowAvailable = tomorrowPrices !== null && 
                              tomorrowPrices !== undefined && 
                              typeof tomorrowPrices === 'object' &&
                              Object.keys(tomorrowPrices).length > 0 &&
                              Object.values(tomorrowPrices).some(price => price !== null && price !== undefined && !isNaN(price));
    
    // Handle tomorrow container visibility (desktop - data-based: show only if data exists)
    if (tomorrowContainer) {
        const tomorrowCard = tomorrowContainer.closest('.card');
        if (tomorrowCard) {
            tomorrowCard.style.display = tomorrowAvailable ? '' : 'none';
        }
    }
    
    // Handle tomorrow container visibility (mobile - data-based)
    if (tomorrowContainerMobile) {
        const tomorrowCardMobile = document.getElementById('tomorrow-price-card-mobile');
        if (tomorrowCardMobile) {
            if (tomorrowAvailable) {
                tomorrowCardMobile.style.display = '';
            } else {
                tomorrowCardMobile.style.display = 'none';
            }
        }
    }
    
    // Collect all prices to calculate min/max
    const allPrices = [];
    for (let h = 0; h < 24; h++) {
        const hourKey = String(h).padStart(2, '0');
        if (todayPrices[hourKey] !== null && todayPrices[hourKey] !== undefined) {
            allPrices.push(todayPrices[hourKey]);
        }
        if (tomorrowPrices !== null && tomorrowPrices !== undefined && tomorrowPrices[hourKey] !== null && tomorrowPrices[hourKey] !== undefined) {
            allPrices.push(tomorrowPrices[hourKey]);
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
            
            // Determine if this is the current hour
            const isCurrentHour = isToday && (h === now.getHours()) && (dateStr === currentDate);
            
            // Calculate bar height (based on price relative to min/max)
            let barHeight = '4px'; // Minimum height
            if (price !== null && price !== undefined && !isNaN(price)) {
                const priceRange = maxPrice - minPrice;
                if (priceRange > 0) {
                    const normalized = (price - minPrice) / priceRange;
                    barHeight = Math.max(4, normalized * 100) + '%';
                } else {
                    barHeight = '50%'; // Middle height if all prices are same
                }
            }
            
            // Get color for price
            const barColor = getPriceColor(price, minPrice, maxPrice);
            
            // Format display text
            const priceDisplay = formatPrice(price);
            
            // Create key for schedule lookup (YYYYMMDDHHmm format)
            const hourTime = hourKey + '00';
            const key = dateStr + hourTime;
            
            const scheduledValue = getActiveScheduleValue(dateStr, hourKey);

            // Create bar element
            const barDiv = document.createElement('div');
            barDiv.className = `price-graph-bar ${isCurrentHour ? 'price-current' : ''}`;
            barDiv.dataset.date = dateStr;
            barDiv.dataset.hour = h;
            barDiv.dataset.time = hourTime;
            barDiv.dataset.key = key;
            barDiv.dataset.price = price !== null ? price : '';
            const scheduleType = scheduledValue !== undefined ? getScheduleType(scheduledValue) : '';
            if (scheduleType) {
                barDiv.classList.add('has-schedule');
                barDiv.dataset.scheduleType = scheduleType;
                barDiv.dataset.scheduleValue = scheduledValue;
            }
            barDiv.setAttribute('aria-label', `${hourKey}:00 - ${priceDisplay}`);
            
            const barInner = document.createElement('div');
            barInner.className = `price-graph-bar-inner ${price === null ? 'price-null' : ''}`;
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
            
            const showPopup = () => showPriceGraphPopup(barDiv, container);
            const hidePopup = () => {
                if (!isTouchDevice()) {
                    hidePriceGraphPopup();
                }
            };

            barDiv.addEventListener('mouseenter', showPopup);
            barDiv.addEventListener('mouseleave', hidePopup);
            barDiv.addEventListener('focus', showPopup);
            barDiv.addEventListener('blur', hidePopup);
            barDiv.addEventListener('touchstart', () => {
                if (!isTouchDevice()) return;
                if (priceGraphPopupActiveBar === barDiv) {
                    hidePriceGraphPopup();
                    priceGraphPopupSuppressClickUntil = Date.now() + 600;
                    priceGraphPopupLastTouchBar = barDiv;
                    return;
                }
                showPopup();
                priceGraphPopupSuppressClickUntil = Date.now() + 600;
                priceGraphPopupLastTouchBar = barDiv;
            }, { passive: true });

            // Add click handler (same as schedule overview bars)
            barDiv.addEventListener('click', (event) => {
                if (isTouchDevice() &&
                    priceGraphPopupLastTouchBar === barDiv &&
                    Date.now() < priceGraphPopupSuppressClickUntil) {
                    event.preventDefault();
                    event.stopPropagation();
                    return;
                }
                if (editModal) {
                    // Check if entry exists
                    const existingValue = scheduleMap[key];
                    // If existingValue is undefined, we pass key as the 3rd argument (prefillKey)
                    // and null as the 1st argument (key) to indicate "Add Mode"
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
    
    // Render today
    renderPriceRow(todayPrices, currentDate, todayContainer, true);
    
    // Render tomorrow in desktop container if available (data-based, not time-based)
    if (tomorrowContainer && tomorrowAvailable) {
        renderPriceRow(tomorrowPrices, tomorrowDateStr, tomorrowContainer, false);
    } else if (tomorrowContainer) {
        // Clear desktop container when tomorrow is not available
        tomorrowContainer.innerHTML = '';
    }
    
    // Render tomorrow in mobile container if available (data-based, not time-based)
    if (tomorrowContainerMobile && tomorrowAvailable) {
        // Use tomorrowPrices (already checked for availability above)
        const pricesToRender = tomorrowPrices || {};
        renderPriceRow(pricesToRender, tomorrowDateStr, tomorrowContainerMobile, false);
    } else if (tomorrowContainerMobile) {
        // Clear mobile container when tomorrow is not available
        tomorrowContainerMobile.innerHTML = '';
    }
    
    // Auto-scroll to current time (center it)
    setTimeout(() => {
        const currentBar = document.querySelector('.price-graph-bar.price-current');
        const container = document.querySelector('.price-graph-container');
        if (currentBar && container) {
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
 * @param {string} priceApiUrl - URL to price API endpoint
 * @param {Array} scheduleEntries - Array of schedule entries for lookup
 * @param {Object} editModal - Edit modal instance for click handlers
 */
async function fetchAndRenderPrices(priceApiUrl, scheduleEntries, editModal) {
    try {
        const response = await fetch(priceApiUrl);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await response.text();
            console.error('Non-JSON response from price API:', text.substring(0, 200));
            throw new Error('Server returned non-JSON response. Check console for details.');
        }
        
        const priceData = await response.json();
        
        // Get current hour
        const now = new Date();
        const currentHour = now.getHours();
        
        // Render the price graph
        renderPriceGraph(priceData, currentHour, scheduleEntries, editModal);
    } catch (e) {
        console.error('Failed to fetch prices:', e);
        // Render with null values on error
        const now = new Date();
        const currentHour = now.getHours();
        renderPriceGraph({ today: {}, tomorrow: {} }, currentHour, scheduleEntries, editModal);
    }
}
