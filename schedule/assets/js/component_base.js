/**
 * Component Base Class
 * Base class for all UI components
 * Provides lifecycle methods, event handling, and state integration
 */
class Component {
    /**
     * Create a component instance
     * @param {HTMLElement|string} container - Container element or selector
     * @param {Object} options - Component options
     * @param {StateManager} options.stateManager - State manager instance
     * @param {ApiClient} options.apiClient - API client instance
     * @param {Object} options.config - Component configuration
     */
    constructor(container, options = {}) {
        this.container = typeof container === 'string' 
            ? document.querySelector(container) 
            : container;
        
        if (!this.container) {
            throw new Error(`Component: Container not found: ${container}`);
        }
        
        this.stateManager = options.stateManager || null;
        this.apiClient = options.apiClient || null;
        this.config = options.config || {};
        this.isMounted = false;
        this.listeners = [];
        this.stateSubscriptions = [];
        this.rendered = false;
    }
    
    /**
     * Initialize component (called after construction)
     * Override in subclasses
     */
    init() {
        // Override in subclasses
    }
    
    /**
     * Mount component to DOM
     * Sets up event listeners and subscribes to state
     */
    mount() {
        if (this.isMounted) {
            console.warn('Component: Already mounted');
            return;
        }
        
        this.setupEventListeners();
        this.subscribeToState();
        this.isMounted = true;
        this.onMount();
    }
    
    /**
     * Unmount component from DOM
     * Cleans up event listeners and state subscriptions
     */
    unmount() {
        if (!this.isMounted) {
            return;
        }
        
        this.cleanupEventListeners();
        this.unsubscribeFromState();
        this.isMounted = false;
        this.onUnmount();
    }
    
    /**
     * Render component
     * Override in subclasses
     */
    render() {
        // Override in subclasses
        this.rendered = true;
    }
    
    /**
     * Update component (re-render)
     * @param {Object} data - Optional data to update with
     */
    update(data = null) {
        if (data) {
            this.data = { ...this.data, ...data };
        }
        this.render();
    }
    
    /**
     * Setup event listeners
     * Override in subclasses
     * @protected
     */
    setupEventListeners() {
        // Override in subclasses
    }
    
    /**
     * Cleanup event listeners
     * @protected
     */
    cleanupEventListeners() {
        this.listeners.forEach(({ element, event, handler }) => {
            element.removeEventListener(event, handler);
        });
        this.listeners = [];
    }
    
    /**
     * Add event listener (automatically cleaned up on unmount)
     * @param {HTMLElement|string} element - Element or selector
     * @param {string} event - Event name
     * @param {Function} handler - Event handler
     * @param {Object} options - Event options
     */
    on(element, event, handler, options = {}) {
        const el = typeof element === 'string' 
            ? this.container.querySelector(element) 
            : element;
        
        if (!el) {
            console.warn(`Component: Element not found for event listener: ${element}`);
            return;
        }
        
        el.addEventListener(event, handler, options);
        this.listeners.push({ element: el, event, handler });
    }
    
    /**
     * Subscribe to state changes
     * Override in subclasses
     * @protected
     */
    subscribeToState() {
        if (!this.stateManager) {
            return;
        }
        
        // Override in subclasses to subscribe to specific state keys
    }
    
    /**
     * Unsubscribe from state changes
     * @protected
     */
    unsubscribeFromState() {
        this.stateSubscriptions.forEach(unsubscribe => unsubscribe());
        this.stateSubscriptions = [];
    }
    
    /**
     * Subscribe to state key
     * @param {string} key - State key to watch
     * @param {Function} callback - Callback function
     * @protected
     */
    subscribeToStateKey(key, callback) {
        if (!this.stateManager) {
            return;
        }
        
        const unsubscribe = this.stateManager.subscribe(key, callback);
        this.stateSubscriptions.push(unsubscribe);
    }
    
    /**
     * Lifecycle: Called after mount
     * Override in subclasses
     * @protected
     */
    onMount() {
        // Override in subclasses
    }
    
    /**
     * Lifecycle: Called before unmount
     * Override in subclasses
     * @protected
     */
    onUnmount() {
        // Override in subclasses
    }
    
    /**
     * Show loading state
     * @param {string} message - Optional loading message
     */
    showLoading(message = 'Loading...') {
        this.container.classList.add('loading');
        const loadingEl = this.container.querySelector('.component-loading');
        if (loadingEl) {
            loadingEl.textContent = message;
        } else {
            const loading = document.createElement('div');
            loading.className = 'component-loading';
            loading.textContent = message;
            this.container.appendChild(loading);
        }
    }
    
    /**
     * Hide loading state
     */
    hideLoading() {
        this.container.classList.remove('loading');
        const loadingEl = this.container.querySelector('.component-loading');
        if (loadingEl) {
            loadingEl.remove();
        }
    }
    
    /**
     * Show error state
     * @param {string} message - Error message
     */
    showError(message) {
        this.container.classList.add('error');
        const errorEl = this.container.querySelector('.component-error');
        if (errorEl) {
            errorEl.textContent = message;
        } else {
            const error = document.createElement('div');
            error.className = 'component-error';
            error.textContent = message;
            this.container.appendChild(error);
        }
    }
    
    /**
     * Hide error state
     */
    hideError() {
        this.container.classList.remove('error');
        const errorEl = this.container.querySelector('.component-error');
        if (errorEl) {
            errorEl.remove();
        }
    }
    
    /**
     * Query selector within container
     * @param {string} selector - CSS selector
     * @returns {HTMLElement|null} Found element or null
     */
    $(selector) {
        return this.container.querySelector(selector);
    }
    
    /**
     * Query selector all within container
     * @param {string} selector - CSS selector
     * @returns {NodeList} Found elements
     */
    $$(selector) {
        return this.container.querySelectorAll(selector);
    }
}

// Export for use in modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = Component;
}
