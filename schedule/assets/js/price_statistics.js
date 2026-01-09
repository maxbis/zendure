/**
 * Price Statistics
 * Fetches price data from API, calculates statistics, and renders them in cards
 */

/**
 * Formats price for display
 * @param {number|null} price - Price value or null
 * @returns {string} Formatted price string (€X.XXXX)
 */
function formatPrice(price) {
    if (price === null || price === undefined || isNaN(price)) {
        return 'N/A';
    }
    return '€' + price.toFixed(4);
}

/**
 * Calculates price statistics from price data
 * @param {Object} priceData - Price data from API with today and tomorrow
 * @returns {Object} Statistics object with min, max, avg, current, delta, percentile
 */
function calculatePriceStatistics(priceData) {
    const todayPrices = priceData?.today || {};
    const tomorrowPrices = priceData?.tomorrow || {};
    
    // Collect all prices from today and tomorrow
    const allPrices = [];
    for (let h = 0; h < 24; h++) {
        const hourKey = String(h).padStart(2, '0');
        if (todayPrices[hourKey] !== null && todayPrices[hourKey] !== undefined && !isNaN(todayPrices[hourKey])) {
            allPrices.push(parseFloat(todayPrices[hourKey]));
        }
        if (tomorrowPrices[hourKey] !== null && tomorrowPrices[hourKey] !== undefined && !isNaN(tomorrowPrices[hourKey])) {
            allPrices.push(parseFloat(tomorrowPrices[hourKey]));
        }
    }
    
    if (allPrices.length === 0) {
        return {
            minPrice: null,
            maxPrice: null,
            avgPrice: null,
            currentPrice: null,
            delta: null,
            percentile: null
        };
    }
    
    // Calculate statistics
    const minPrice = Math.min(...allPrices);
    const maxPrice = Math.max(...allPrices);
    const avgPrice = allPrices.reduce((a, b) => a + b, 0) / allPrices.length;
    const delta = maxPrice - minPrice;
    
    // Get current price (from current hour of today)
    const now = new Date();
    const currentHour = String(now.getHours()).padStart(2, '0');
    const currentPrice = todayPrices[currentHour] !== null && todayPrices[currentHour] !== undefined 
        ? parseFloat(todayPrices[currentHour]) 
        : null;
    
    // Calculate percentile
    let percentile = null;
    if (currentPrice !== null && maxPrice > minPrice) {
        percentile = ((currentPrice - minPrice) / (maxPrice - minPrice)) * 100;
    } else if (currentPrice !== null && maxPrice === minPrice) {
        // All prices are the same
        percentile = 50.0;
    }
    
    return {
        minPrice,
        maxPrice,
        avgPrice,
        currentPrice,
        delta,
        percentile
    };
}

/**
 * Renders price statistics into the cards
 * @param {Object} stats - Statistics object from calculatePriceStatistics
 */
function renderPriceStatistics(stats) {
    // Minimum Price
    const minValueEl = document.getElementById('price-stat-min-value');
    const minDetailEl = document.getElementById('price-stat-min-detail');
    if (minValueEl) {
        minValueEl.textContent = formatPrice(stats.minPrice);
    }
    if (minDetailEl && stats.delta !== null) {
        minDetailEl.textContent = `Delta ${formatPrice(stats.delta)}`;
    }
    
    // Maximum Price
    const maxValueEl = document.getElementById('price-stat-max-value');
    const maxDetailEl = document.getElementById('price-stat-max-detail');
    if (maxValueEl) {
        maxValueEl.textContent = formatPrice(stats.maxPrice);
    }
    if (maxDetailEl && stats.delta !== null) {
        maxDetailEl.textContent = `Delta ${formatPrice(stats.delta)}`;
    }
    
    // Average Price
    const avgValueEl = document.getElementById('price-stat-avg-value');
    if (avgValueEl) {
        avgValueEl.textContent = formatPrice(stats.avgPrice);
    }
    
    // Current Price
    const currentValueEl = document.getElementById('price-stat-current-value');
    const currentDetailEl = document.getElementById('price-stat-current-detail');
    if (currentValueEl) {
        currentValueEl.textContent = formatPrice(stats.currentPrice);
    }
    if (currentDetailEl && stats.percentile !== null) {
        currentDetailEl.textContent = `${stats.percentile.toFixed(1)}% percentile`;
    } else if (currentDetailEl) {
        currentDetailEl.textContent = '-';
    }
}

/**
 * Fetches price data from API and renders statistics
 * @param {string} priceApiUrl - URL to price API endpoint
 */
async function fetchAndRenderPriceStatistics(priceApiUrl) {
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
        
        // Calculate statistics
        const stats = calculatePriceStatistics(priceData);
        
        // Render statistics
        renderPriceStatistics(stats);
    } catch (e) {
        console.error('Failed to fetch price statistics:', e);
        // Render with null values on error
        renderPriceStatistics({
            minPrice: null,
            maxPrice: null,
            avgPrice: null,
            currentPrice: null,
            delta: null,
            percentile: null
        });
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Fetch and render price statistics if PRICE_API_URL is available
    if (typeof PRICE_API_URL !== 'undefined' && PRICE_API_URL) {
        fetchAndRenderPriceStatistics(PRICE_API_URL);
    } else {
        console.warn('PRICE_API_URL not defined, cannot fetch price statistics');
    }
});
