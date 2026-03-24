/**
 * Price Graph Component
 * Manages the price overview bar graph display
 * Uses same proxy value as price_overview_bar.js (0.24) but local name to avoid duplicate global.
 */
const PRICE_PROXY_CENTS = (() => {
    const value = window.PRICE_OVERVIEW_CONFIG ? window.PRICE_OVERVIEW_CONFIG.priceProxyNoData : undefined;
    return typeof value === 'number' && Number.isFinite(value) ? value : 0.24;
})();

class PriceGraphComponent extends Component {
    constructor(container, options = {}) {
        super(container, options);
        this.data = {
            prices: null,
            scheduleEntries: [],
            currentHour: new Date().getHours()
        };
    }
    
    init() {
        this.mount();
    }
    
    setupEventListeners() {
        // Listen for price data updates
        // Could add refresh button here if needed
    }
    
    subscribeToState() {
        if (!this.stateManager) return;
        
        // Subscribe to price data changes
        this.subscribeToStateKey('prices', (newState, prevState) => {
            if (newState.prices !== prevState.prices) {
                this.update({ prices: newState.prices });
            }
        });
        
        // Subscribe to schedule entries changes
        this.subscribeToStateKey('schedule', (newState, prevState) => {
            if (newState.schedule && newState.schedule.entries) {
                this.update({ scheduleEntries: newState.schedule.entries });
            }
        });
    }
    
    /**
     * Update component with new data
     * @param {Object} data - Update data
     */
    update(data) {
        this.data = { ...this.data, ...data };
        this.render();
    }
    
    render() {
        const { prices, scheduleEntries, currentHour } = this.data;
        
        if (!prices) {
            this._renderEmpty();
            return;
        }
        
        this._renderPriceGraph(prices, scheduleEntries, currentHour);
    }
    
    _renderEmpty() {
        const todayContainer = this.$('#price-graph-today');
        const tomorrowContainer = this.$('#price-graph-tomorrow');
        const tomorrowContainerMobile = this.$('#price-graph-tomorrow-mobile');
        
        if (todayContainer) {
            todayContainer.innerHTML = '<div class="empty-state">No price data available</div>';
        }
        if (tomorrowContainer) {
            tomorrowContainer.innerHTML = '';
        }
        if (tomorrowContainerMobile) {
            tomorrowContainerMobile.innerHTML = '';
        }
    }
    
    _renderPriceGraph(priceData, scheduleEntries, currentHour) {
        const todayContainer = this.$('#price-graph-today');
        const tomorrowContainer = this.$('#price-graph-tomorrow');
        const tomorrowContainerMobile = this.$('#price-graph-tomorrow-mobile');
        
        if (!todayContainer) return;
        
        // Build schedule map for lookup
        const scheduleMap = {};
        if (scheduleEntries) {
            scheduleEntries.forEach(entry => {
                scheduleMap[entry.key] = getRawScheduleEntryValue(entry);
            });
        }
        
        // Extract price data
        const todayPrices = priceData.today || {};
        const tomorrowPrices = priceData.tomorrow || null;
        
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
        
        // Calculate min/max prices (use proxy when missing so scale is sensible)
        const allPrices = [];
        for (let h = 0; h < 24; h++) {
            const hourKey = String(h).padStart(2, '0');
            const todayVal = todayPrices[hourKey];
            const tomorrowVal = tomorrowPrices !== null && tomorrowPrices !== undefined ? tomorrowPrices[hourKey] : undefined;
            if (todayVal !== null && todayVal !== undefined && !isNaN(todayVal)) {
                allPrices.push(todayVal);
            } else {
                allPrices.push(PRICE_PROXY_CENTS);
            }
            if (tomorrowPrices !== null && tomorrowPrices !== undefined) {
                if (tomorrowVal !== null && tomorrowVal !== undefined && !isNaN(tomorrowVal)) {
                    allPrices.push(tomorrowVal);
                } else {
                    allPrices.push(PRICE_PROXY_CENTS);
                }
            }
        }
        
        let minPrice = 0;
        let maxPrice = 0.5;
        if (allPrices.length > 0) {
            minPrice = Math.min(...allPrices);
            maxPrice = Math.max(...allPrices);
            const range = maxPrice - minPrice;
            if (range > 0) {
                minPrice -= range * 0.1;
                maxPrice += range * 0.1;
            } else {
                minPrice -= 0.01;
                maxPrice += 0.01;
            }
        }
        
        // Get current date
        const now = new Date();
        const currentDate = formatDateYYYYMMDD(now);
        
        // Render today
        if (todayContainer) {
            this._renderPriceRow(todayPrices, currentDate, todayContainer, true, minPrice, maxPrice, scheduleMap, currentHour);
        }
        
        // Calculate tomorrow's date string
        const tomorrowDate = formatDateYYYYMMDD(new Date(now.getTime() + 24 * 60 * 60 * 1000));
        
        // Render tomorrow in desktop container if available (data-based, not time-based)
        if (tomorrowContainer && tomorrowAvailable) {
            this._renderPriceRow(tomorrowPrices, tomorrowDate, tomorrowContainer, false, minPrice, maxPrice, scheduleMap, currentHour);
        } else if (tomorrowContainer) {
            // Clear desktop container when tomorrow is not available
            tomorrowContainer.innerHTML = '';
        }
        
        // Render tomorrow in mobile container if available (data-based, not time-based)
        if (tomorrowContainerMobile && tomorrowAvailable) {
            // Use tomorrowPrices (already checked for availability above)
            const pricesToRender = tomorrowPrices || {};
            this._renderPriceRow(pricesToRender, tomorrowDate, tomorrowContainerMobile, false, minPrice, maxPrice, scheduleMap, currentHour);
        } else if (tomorrowContainerMobile) {
            // Clear mobile container when tomorrow is not available
            tomorrowContainerMobile.innerHTML = '';
        }
    }
    
    _renderPriceRow(prices, dateStr, container, isToday, minPrice, maxPrice, scheduleMap, currentHour) {
        container.innerHTML = '';
        
        const now = new Date();
        const currentDate = formatDateYYYYMMDD(now);
        
        for (let h = 0; h < 24; h++) {
            const hourKey = String(h).padStart(2, '0');
            const price = prices[hourKey] !== undefined ? prices[hourKey] : null;
            const hasRealPrice = price !== null && price !== undefined && !isNaN(price);
            const priceForHeight = hasRealPrice ? price : PRICE_PROXY_CENTS;
            const isCurrentHour = isToday && (h === now.getHours()) && (dateStr === currentDate);
            
            // Calculate bar height (use proxy for missing data)
            const priceRange = maxPrice - minPrice;
            let barHeight = '4px';
            if (priceRange > 0) {
                const normalized = (priceForHeight - minPrice) / priceRange;
                barHeight = Math.max(4, normalized * 100) + '%';
            } else {
                barHeight = '50%';
            }
            
            // Get color (grey when no real data)
            const barColor = getPriceColor(price, minPrice, maxPrice);
            
            // Check for schedule entry
            const scheduleKey = dateStr + hourKey + '00';
            const hasSchedule = scheduleMap[scheduleKey] !== undefined;
            
            // Create bar element
            const bar = document.createElement('div');
            bar.className = `price-bar ${isCurrentHour ? 'price-bar-current' : ''} ${hasSchedule ? 'price-bar-scheduled' : ''}`;
            bar.style.height = barHeight;
            bar.style.backgroundColor = barColor;
            bar.title = `${hourKey}:00 - ${hasRealPrice ? formatPrice(price) : 'No price data available'}`;
            
            // Add click handler if editModal is available
            if (this.config.editModal) {
                bar.style.cursor = 'pointer';
                bar.addEventListener('click', () => {
                    this.config.editModal.open(null, scheduleKey, null);
                });
            }
            
            container.appendChild(bar);
        }
    }
}

// Export for use in modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PriceGraphComponent;
}
