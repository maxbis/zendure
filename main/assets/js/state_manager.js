/**
 * State Manager
 * Centralized state management for the application
 * Provides reactive updates when state changes
 * 
 * Features:
 * - Immutable state updates
 * - Subscriber pattern for reactive updates
 * - State history (optional)
 * - State validation
 */
class StateManager {
    /**
     * Create a state manager instance
     * @param {Object} initialState - Initial state object
     * @param {Object} options - Configuration options
     * @param {boolean} options.enableHistory - Enable state history tracking (default: false)
     * @param {number} options.maxHistorySize - Maximum history entries (default: 50)
     * @param {Function} options.validator - State validation function
     */
    constructor(initialState = {}, options = {}) {
        this.state = { ...initialState };
        this.listeners = [];
        this.history = [];
        this.enableHistory = options.enableHistory || false;
        this.maxHistorySize = options.maxHistorySize || 50;
        this.validator = options.validator || null;
        this.isUpdating = false;
    }
    
    /**
     * Get current state
     * @param {string|null} key - Optional key to get specific state property
     * @returns {*} Current state or specific property value
     */
    getState(key = null) {
        if (key === null) {
            return { ...this.state }; // Return copy to prevent direct mutation
        }
        return this.state[key];
    }
    
    /**
     * Set state (immutable update)
     * @param {Object|Function} updates - State updates object or updater function
     * @param {boolean} silent - If true, don't notify listeners
     * @returns {Object} Previous state
     */
    setState(updates, silent = false) {
        if (this.isUpdating) {
            console.warn('StateManager: setState called during state update. This may cause issues.');
        }
        
        this.isUpdating = true;
        const prevState = { ...this.state };
        
        // Handle function updater
        if (typeof updates === 'function') {
            updates = updates(prevState);
        }
        
        // Validate updates
        if (this.validator) {
            const validation = this.validator(updates, prevState);
            if (validation !== true) {
                console.error('StateManager: State validation failed:', validation);
                this.isUpdating = false;
                throw new Error(`State validation failed: ${validation}`);
            }
        }
        
        // Merge updates into state (shallow merge)
        this.state = { ...this.state, ...updates };
        
        // Add to history if enabled
        if (this.enableHistory) {
            this.history.push({
                timestamp: Date.now(),
                prevState: prevState,
                newState: { ...this.state },
                updates: updates
            });
            
            // Limit history size
            if (this.history.length > this.maxHistorySize) {
                this.history.shift();
            }
        }
        
        this.isUpdating = false;
        
        // Notify listeners
        if (!silent) {
            this._notifyListeners(prevState, this.state, updates);
        }
        
        return prevState;
    }
    
    /**
     * Subscribe to state changes
     * @param {Function|string} listenerOrKey - Listener function or state key to watch
     * @param {Function} listener - Listener function (if first param is key)
     * @returns {Function} Unsubscribe function
     */
    subscribe(listenerOrKey, listener = null) {
        let actualListener;
        let keyFilter = null;
        
        if (typeof listenerOrKey === 'string') {
            // Subscribe to specific key
            keyFilter = listenerOrKey;
            actualListener = listener;
        } else {
            // Subscribe to all changes
            actualListener = listenerOrKey;
        }
        
        const wrappedListener = (newState, prevState, updates) => {
            // If filtering by key, only notify if that key changed
            if (keyFilter !== null) {
                if (!(keyFilter in updates) && !(keyFilter in prevState) && !(keyFilter in newState)) {
                    return; // Key not in updates and didn't exist before or after
                }
                if (prevState[keyFilter] === newState[keyFilter]) {
                    return; // Key value didn't change
                }
            }
            
            try {
                actualListener(newState, prevState, updates);
            } catch (error) {
                console.error('StateManager: Listener error:', error);
            }
        };
        
        this.listeners.push(wrappedListener);
        
        // Return unsubscribe function
        return () => {
            const index = this.listeners.indexOf(wrappedListener);
            if (index > -1) {
                this.listeners.splice(index, 1);
            }
        };
    }
    
    /**
     * Unsubscribe all listeners
     */
    unsubscribeAll() {
        this.listeners = [];
    }
    
    /**
     * Get state history
     * @param {number} limit - Maximum number of history entries to return
     * @returns {Array} State history entries
     */
    getHistory(limit = null) {
        if (!this.enableHistory) {
            return [];
        }
        
        const history = [...this.history];
        return limit ? history.slice(-limit) : history;
    }
    
    /**
     * Reset state to initial state
     * @param {Object} newInitialState - Optional new initial state
     */
    reset(newInitialState = null) {
        if (newInitialState !== null) {
            this.state = { ...newInitialState };
        } else {
            // Reset to empty state
            this.state = {};
        }
        
        if (this.enableHistory) {
            this.history = [];
        }
        
        this._notifyListeners({}, this.state, this.state);
    }
    
    /**
     * Notify all listeners of state change
     * @param {Object} prevState - Previous state
     * @param {Object} newState - New state
     * @param {Object} updates - Updates that were applied
     * @private
     */
    _notifyListeners(prevState, newState, updates) {
        // Create immutable copies for listeners
        const prevStateCopy = { ...prevState };
        const newStateCopy = { ...newState };
        const updatesCopy = { ...updates };
        
        this.listeners.forEach(listener => {
            try {
                listener(newStateCopy, prevStateCopy, updatesCopy);
            } catch (error) {
                console.error('StateManager: Error in listener:', error);
            }
        });
    }
    
    /**
     * Check if state has a specific key
     * @param {string} key - State key to check
     * @returns {boolean} True if key exists
     */
    has(key) {
        return key in this.state;
    }
    
    /**
     * Delete a state key
     * @param {string} key - State key to delete
     * @returns {boolean} True if key was deleted
     */
    delete(key) {
        if (!(key in this.state)) {
            return false;
        }
        
        const prevState = { ...this.state };
        delete this.state[key];
        this._notifyListeners(prevState, this.state, { [key]: undefined });
        return true;
    }
}

// Export for use in modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = StateManager;
}
