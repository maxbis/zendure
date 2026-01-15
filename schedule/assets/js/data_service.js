/**
 * Data Service
 * Centralized data fetching with caching and subscription support
 * 
 * Features:
 * - Automatic caching with TTL
 * - Subscriber pattern for reactive updates
 * - Request deduplication
 * - Stale-while-revalidate pattern
 */
class DataService {
    /**
     * Create a data service instance
     * @param {ApiClient} apiClient - API client instance
     * @param {Object} options - Configuration options
     * @param {number} options.defaultTTL - Default cache TTL in ms (default: 30000)
     * @param {boolean} options.enableStaleWhileRevalidate - Enable stale-while-revalidate (default: true)
     */
    constructor(apiClient, options = {}) {
        this.apiClient = apiClient;
        this.cache = new Map();
        this.subscribers = new Map();
        this.pendingRequests = new Map();
        this.defaultTTL = options.defaultTTL || 30000; // 30 seconds
        this.enableStaleWhileRevalidate = options.enableStaleWhileRevalidate !== false;
    }
    
    /**
     * Fetch data with caching
     * @param {string} key - Cache key
     * @param {Function} fetcher - Function that returns a promise
     * @param {Object} options - Options
     * @param {number} options.ttl - Cache TTL in ms
     * @param {boolean} options.forceRefresh - Force refresh (skip cache)
     * @returns {Promise<*>} Fetched data
     */
    async fetch(key, fetcher, options = {}) {
        const {
            ttl = this.defaultTTL,
            forceRefresh = false
        } = options;
        
        // Check cache first (unless force refresh)
        if (!forceRefresh && this.cache.has(key)) {
            const cached = this.cache.get(key);
            const age = Date.now() - cached.timestamp;
            
            if (age < ttl) {
                // Cache hit - return immediately
                return cached.data;
            }
            
            // Cache expired but still valid for stale-while-revalidate
            if (this.enableStaleWhileRevalidate && cached.data) {
                // Return stale data immediately, refresh in background
                this._refreshInBackground(key, fetcher, ttl);
                return cached.data;
            }
        }
        
        // Check if request is already pending (deduplication)
        if (this.pendingRequests.has(key)) {
            return this.pendingRequests.get(key);
        }
        
        // Fetch fresh data
        const requestPromise = this._fetchAndCache(key, fetcher, ttl);
        this.pendingRequests.set(key, requestPromise);
        
        try {
            const data = await requestPromise;
            return data;
        } finally {
            this.pendingRequests.delete(key);
        }
    }
    
    /**
     * Fetch and cache data
     * @param {string} key - Cache key
     * @param {Function} fetcher - Fetcher function
     * @param {number} ttl - Cache TTL
     * @returns {Promise<*>} Fetched data
     * @private
     */
    async _fetchAndCache(key, fetcher, ttl) {
        try {
            const data = await fetcher();
            
            // Cache the data
            this.cache.set(key, {
                data: data,
                timestamp: Date.now(),
                ttl: ttl
            });
            
            // Notify subscribers
            this._notifySubscribers(key, data);
            
            return data;
        } catch (error) {
            // Remove from pending on error
            this.pendingRequests.delete(key);
            throw error;
        }
    }
    
    /**
     * Refresh data in background
     * @param {string} key - Cache key
     * @param {Function} fetcher - Fetcher function
     * @param {number} ttl - Cache TTL
     * @private
     */
    _refreshInBackground(key, fetcher, ttl) {
        // Don't refresh if already pending
        if (this.pendingRequests.has(key)) {
            return;
        }
        
        // Refresh in background
        this._fetchAndCache(key, fetcher, ttl).catch(error => {
            console.warn(`DataService: Background refresh failed for ${key}:`, error);
        });
    }
    
    /**
     * Subscribe to data updates
     * @param {string} key - Cache key to subscribe to
     * @param {Function} callback - Callback function
     * @returns {Function} Unsubscribe function
     */
    subscribe(key, callback) {
        if (!this.subscribers.has(key)) {
            this.subscribers.set(key, []);
        }
        
        this.subscribers.get(key).push(callback);
        
        // Immediately call with current cached data if available
        if (this.cache.has(key)) {
            try {
                callback(this.cache.get(key).data);
            } catch (error) {
                console.error('DataService: Subscriber callback error:', error);
            }
        }
        
        // Return unsubscribe function
        return () => {
            const callbacks = this.subscribers.get(key);
            if (callbacks) {
                const index = callbacks.indexOf(callback);
                if (index > -1) {
                    callbacks.splice(index, 1);
                }
            }
        };
    }
    
    /**
     * Notify subscribers of data update
     * @param {string} key - Cache key
     * @param {*} data - New data
     * @private
     */
    _notifySubscribers(key, data) {
        const callbacks = this.subscribers.get(key) || [];
        callbacks.forEach(callback => {
            try {
                callback(data);
            } catch (error) {
                console.error('DataService: Subscriber callback error:', error);
            }
        });
    }
    
    /**
     * Invalidate cache entry
     * @param {string} key - Cache key to invalidate
     */
    invalidate(key) {
        this.cache.delete(key);
    }
    
    /**
     * Clear all cache
     */
    clearCache() {
        this.cache.clear();
    }
    
    /**
     * Get cached data (if available and not expired)
     * @param {string} key - Cache key
     * @returns {*|null} Cached data or null
     */
    getCached(key) {
        if (!this.cache.has(key)) {
            return null;
        }
        
        const cached = this.cache.get(key);
        const age = Date.now() - cached.timestamp;
        
        if (age < cached.ttl) {
            return cached.data;
        }
        
        return null;
    }
    
    /**
     * Prefetch data
     * @param {string} key - Cache key
     * @param {Function} fetcher - Fetcher function
     * @param {Object} options - Options
     */
    async prefetch(key, fetcher, options = {}) {
        return this.fetch(key, fetcher, { ...options, forceRefresh: false });
    }
}

// Export for use in modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = DataService;
}
