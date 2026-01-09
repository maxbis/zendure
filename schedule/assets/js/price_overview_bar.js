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
 * Renders the price graph for today and tomorrow
 * @param {Object} priceData - Price data from API
 * @param {number} currentHour - Current hour (0-23)
 * @param {Array} scheduleEntries - Array of schedule entries for lookup
 * @param {Object} editModal - Edit modal instance for click handlers
 */
function renderPriceGraph(priceData, currentHour, scheduleEntries, editModal) {
    const todayContainer = document.getElementById('price-graph-today');
    const tomorrowContainer = document.getElementById('price-graph-tomorrow');
    
    if (!todayContainer) return;
    
    // Build a map of schedule entries for quick lookup
    const scheduleMap = {};
    if (scheduleEntries) {
        scheduleEntries.forEach(entry => {
            scheduleMap[entry.key] = entry.value;
        });
    }
    
    // Handle tomorrow container visibility
    if (tomorrowContainer) {
        const tomorrowDayElement = tomorrowContainer.closest('.price-graph-day');
        if (currentHour < 15) {
            if (tomorrowDayElement) {
                tomorrowDayElement.style.display = 'none';
            }
        } else {
            if (tomorrowDayElement) {
                tomorrowDayElement.style.display = 'flex';
            }
        }
    }
    
    // Extract price data
    const todayPrices = priceData?.today || {};
    const tomorrowPrices = priceData?.tomorrow || {};
    
    // Collect all prices to calculate min/max
    const allPrices = [];
    for (let h = 0; h < 24; h++) {
        const hourKey = String(h).padStart(2, '0');
        if (todayPrices[hourKey] !== null && todayPrices[hourKey] !== undefined) {
            allPrices.push(todayPrices[hourKey]);
        }
        if (tomorrowPrices[hourKey] !== null && tomorrowPrices[hourKey] !== undefined) {
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
    const currentDate = now.getFullYear().toString() +
        String(now.getMonth() + 1).padStart(2, '0') +
        String(now.getDate()).padStart(2, '0');
    
    // Helper function to render a row of price bars
    const renderPriceRow = (prices, dateStr, container, isToday) => {
        container.innerHTML = '';
        
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
            
            // Create bar element
            const barDiv = document.createElement('div');
            barDiv.className = `price-graph-bar ${isCurrentHour ? 'price-current' : ''}`;
            barDiv.dataset.date = dateStr;
            barDiv.dataset.hour = h;
            barDiv.dataset.time = hourTime;
            barDiv.dataset.key = key;
            barDiv.dataset.price = price !== null ? price : '';
            barDiv.title = `${hourKey}:00 - ${priceDisplay}`;
            
            const barInner = document.createElement('div');
            barInner.className = `price-graph-bar-inner ${price === null ? 'price-null' : ''}`;
            barInner.style.height = barHeight;
            barInner.style.backgroundColor = barColor;
            
            const barLabel = document.createElement('div');
            barLabel.className = 'price-graph-bar-label';
            barLabel.textContent = hourKey;
            
            barDiv.appendChild(barInner);
            barDiv.appendChild(barLabel);
            
            // Add click handler (same as schedule overview bars)
            barDiv.addEventListener('click', () => {
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
    
    // Render today
    renderPriceRow(todayPrices, currentDate, todayContainer, true);
    
    // Render tomorrow if available and current hour >= 15
    if (currentHour >= 15 && tomorrowContainer) {
        const tomorrowDate = new Date(now);
        tomorrowDate.setDate(tomorrowDate.getDate() + 1);
        const tomorrowDateStr = tomorrowDate.getFullYear().toString() +
            String(tomorrowDate.getMonth() + 1).padStart(2, '0') +
            String(tomorrowDate.getDate()).padStart(2, '0');
        renderPriceRow(tomorrowPrices, tomorrowDateStr, tomorrowContainer, false);
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
