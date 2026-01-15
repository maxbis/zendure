/**
 * Price Graph Component
 * Manages the price overview bar graph display
 */
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
        
        if (todayContainer) {
            todayContainer.innerHTML = '<div class="empty-state">No price data available</div>';
        }
        if (tomorrowContainer) {
            tomorrowContainer.innerHTML = '';
        }
    }
    
    _renderPriceGraph(priceData, scheduleEntries, currentHour) {
        const todayContainer = this.$('#price-graph-today');
        const tomorrowContainer = this.$('#price-graph-tomorrow');
        
        if (!todayContainer) return;
        
        // Build schedule map for lookup
        const scheduleMap = {};
        if (scheduleEntries) {
            scheduleEntries.forEach(entry => {
                scheduleMap[entry.key] = entry.value;
            });
        }
        
        // Handle tomorrow container visibility
        if (tomorrowContainer) {
            const tomorrowCard = tomorrowContainer.closest('.card');
            if (tomorrowCard) {
                tomorrowCard.style.display = currentHour < 15 ? 'none' : '';
            }
        }
        
        // Extract price data
        const todayPrices = priceData.today || {};
        const tomorrowPrices = priceData.tomorrow || {};
        
        // Calculate min/max prices
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
        
        // Render tomorrow
        if (tomorrowContainer && currentHour >= 15) {
            const tomorrowDate = formatDateYYYYMMDD(new Date(now.getTime() + 24 * 60 * 60 * 1000));
            this._renderPriceRow(tomorrowPrices, tomorrowDate, tomorrowContainer, false, minPrice, maxPrice, scheduleMap, currentHour);
        }
    }
    
    _renderPriceRow(prices, dateStr, container, isToday, minPrice, maxPrice, scheduleMap, currentHour) {
        container.innerHTML = '';
        
        const now = new Date();
        const currentDate = formatDateYYYYMMDD(now);
        
        for (let h = 0; h < 24; h++) {
            const hourKey = String(h).padStart(2, '0');
            const price = prices[hourKey] !== undefined ? prices[hourKey] : null;
            const isCurrentHour = isToday && (h === now.getHours()) && (dateStr === currentDate);
            
            // Calculate bar height
            let barHeight = '4px';
            if (price !== null && price !== undefined && !isNaN(price)) {
                const priceRange = maxPrice - minPrice;
                if (priceRange > 0) {
                    const normalized = (price - minPrice) / priceRange;
                    barHeight = Math.max(4, normalized * 100) + '%';
                } else {
                    barHeight = '50%';
                }
            }
            
            // Get color
            const barColor = getPriceColor(price, minPrice, maxPrice);
            
            // Check for schedule entry
            const scheduleKey = dateStr + hourKey + '00';
            const hasSchedule = scheduleMap[scheduleKey] !== undefined;
            
            // Create bar element
            const bar = document.createElement('div');
            bar.className = `price-bar ${isCurrentHour ? 'price-bar-current' : ''} ${hasSchedule ? 'price-bar-scheduled' : ''}`;
            bar.style.height = barHeight;
            bar.style.backgroundColor = barColor;
            bar.title = `${hourKey}:00 - ${price !== null ? formatPrice(price) : 'N/A'}`;
            
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
    
    /**
     * Refresh price data
     */
    async refresh() {
        if (!this.apiClient || !this.config.priceApiUrl) {
            console.warn('PriceGraphComponent: No API client or price API URL available');
            return;
        }
        
        this.showLoading('Loading prices...');
        
        try {
            // Fetch price data (implementation depends on price API structure)
            // This is a placeholder - adjust based on actual price API
            const today = formatDateYYYYMMDD(new Date());
            const tomorrow = formatDateYYYYMMDD(new Date(Date.now() + 24 * 60 * 60 * 1000));
            
            // Assuming price API returns data in expected format
            // Adjust this based on actual API implementation
            const priceData = {
                today: {},
                tomorrow: {}
            };
            
            this.update({ prices: priceData });
            
            if (window.notifications) {
                window.notifications.success('Prices refreshed');
            }
        } catch (error) {
            console.error('PriceGraphComponent: Refresh error:', error);
            this.showError('Failed to load prices: ' + error.message);
            if (window.notifications) {
                window.notifications.error('Failed to refresh prices');
            }
        } finally {
            this.hideLoading();
        }
    }
}

// Export for use in modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PriceGraphComponent;
}
