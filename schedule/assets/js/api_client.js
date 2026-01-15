/**
 * API Client
 * Robust API client with retry logic, timeout handling, and consistent error handling
 * 
 * Features:
 * - Automatic retry on failure
 * - Request timeout handling
 * - Consistent error responses
 * - JSON validation
 * - Abort signal support
 */
class ApiClient {
    /**
     * Create an API client instance
     * @param {string} baseUrl - Base API URL
     * @param {Object} options - Configuration options
     * @param {number} options.timeout - Request timeout in ms (default: 10000)
     * @param {number} options.retries - Number of retry attempts (default: 3)
     * @param {number} options.retryDelay - Base delay between retries in ms (default: 1000)
     * @param {Function} options.onError - Error callback function
     */
    constructor(baseUrl, options = {}) {
        this.baseUrl = baseUrl;
        this.timeout = options.timeout || 10000;
        this.retries = options.retries || 3;
        this.retryDelay = options.retryDelay || 1000;
        this.onError = options.onError || null;
    }
    
    /**
     * Make an API request
     * @param {string} endpoint - API endpoint (relative to baseUrl)
     * @param {Object} options - Request options
     * @param {string} options.method - HTTP method (default: 'GET')
     * @param {Object} options.params - Query parameters (for GET requests)
     * @param {Object} options.body - Request body (for POST/PUT/DELETE)
     * @param {Object} options.headers - Additional headers
     * @param {boolean} options.retry - Whether to retry on failure (default: true)
     * @returns {Promise<Object>} Response data
     * @throws {ApiError} On API errors
     */
    async request(endpoint, options = {}) {
        const {
            method = 'GET',
            params = null,
            body = null,
            headers = {},
            retry = true
        } = options;
        
        // Build URL with query parameters
        let url = this.baseUrl;
        if (endpoint) {
            url += (endpoint.startsWith('/') ? '' : '/') + endpoint;
        }
        
        if (params && method === 'GET') {
            const queryString = new URLSearchParams(params).toString();
            url += (url.includes('?') ? '&' : '?') + queryString;
        }
        
        // Prepare request config
        const config = {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                ...headers
            },
            signal: AbortSignal.timeout(this.timeout)
        };
        
        // Add body for non-GET requests
        if (body && (method === 'POST' || method === 'PUT' || method === 'DELETE')) {
            config.body = JSON.stringify(body);
        }
        
        // Attempt request with retries
        let lastError = null;
        const maxAttempts = retry ? this.retries : 1;
        
        for (let attempt = 0; attempt < maxAttempts; attempt++) {
            try {
                const response = await fetch(url, config);
                
                // Check if response is OK
                if (!response.ok) {
                    const errorText = await response.text().catch(() => 'Unknown error');
                    throw new ApiError(
                        `HTTP ${response.status}: ${response.statusText}`,
                        response.status,
                        errorText
                    );
                }
                
                // Validate content type
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    console.error('Non-JSON response:', text.substring(0, 200));
                    throw new ApiError(
                        'Server returned non-JSON response',
                        0,
                        text.substring(0, 200)
                    );
                }
                
                // Parse JSON
                const data = await response.json();
                
                // Check for API-level errors
                if (data.success === false && data.error) {
                    throw new ApiError(data.error, response.status, data);
                }
                
                return data;
                
            } catch (error) {
                lastError = error;
                
                // Don't retry on certain errors
                if (error instanceof ApiError) {
                    // Don't retry on 4xx errors (client errors)
                    if (error.status >= 400 && error.status < 500) {
                        throw error;
                    }
                }
                
                // Handle abort/timeout
                if (error.name === 'AbortError' || error.name === 'TimeoutError') {
                    if (attempt < maxAttempts - 1) {
                        // Retry timeout errors
                        await this._delay(this.retryDelay * (attempt + 1));
                        continue;
                    }
                    throw new ApiError('Request timeout', 408);
                }
                
                // Retry on network errors or 5xx errors
                if (attempt < maxAttempts - 1) {
                    const delay = this.retryDelay * Math.pow(2, attempt); // Exponential backoff
                    await this._delay(delay);
                    continue;
                }
            }
        }
        
        // All retries failed
        if (this.onError) {
            this.onError(lastError);
        }
        throw lastError;
    }
    
    /**
     * GET request
     * @param {string} endpoint - API endpoint
     * @param {Object} params - Query parameters
     * @param {Object} options - Additional options
     * @returns {Promise<Object>} Response data
     */
    async get(endpoint, params = null, options = {}) {
        return this.request(endpoint, {
            method: 'GET',
            params: params,
            ...options
        });
    }
    
    /**
     * POST request
     * @param {string} endpoint - API endpoint
     * @param {Object} body - Request body
     * @param {Object} options - Additional options
     * @returns {Promise<Object>} Response data
     */
    async post(endpoint, body = null, options = {}) {
        return this.request(endpoint, {
            method: 'POST',
            body: body,
            ...options
        });
    }
    
    /**
     * PUT request
     * @param {string} endpoint - API endpoint
     * @param {Object} body - Request body
     * @param {Object} options - Additional options
     * @returns {Promise<Object>} Response data
     */
    async put(endpoint, body = null, options = {}) {
        return this.request(endpoint, {
            method: 'PUT',
            body: body,
            ...options
        });
    }
    
    /**
     * DELETE request
     * @param {string} endpoint - API endpoint
     * @param {Object} body - Request body (optional)
     * @param {Object} options - Additional options
     * @returns {Promise<Object>} Response data
     */
    async delete(endpoint, body = null, options = {}) {
        return this.request(endpoint, {
            method: 'DELETE',
            body: body,
            ...options
        });
    }
    
    /**
     * Delay helper for retries
     * @param {number} ms - Milliseconds to delay
     * @returns {Promise} Promise that resolves after delay
     * @private
     */
    _delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
}

/**
 * API Error class
 * Custom error class for API-related errors
 */
class ApiError extends Error {
    /**
     * Create an API error
     * @param {string} message - Error message
     * @param {number} status - HTTP status code (if applicable)
     * @param {*} details - Additional error details
     */
    constructor(message, status = null, details = null) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.details = details;
        
        // Maintain proper stack trace
        if (Error.captureStackTrace) {
            Error.captureStackTrace(this, ApiError);
        }
    }
    
    /**
     * Check if error is a client error (4xx)
     * @returns {boolean} True if 4xx error
     */
    isClientError() {
        return this.status >= 400 && this.status < 500;
    }
    
    /**
     * Check if error is a server error (5xx)
     * @returns {boolean} True if 5xx error
     */
    isServerError() {
        return this.status >= 500 && this.status < 600;
    }
    
    /**
     * Check if error is a network/timeout error
     * @returns {boolean} True if network error
     */
    isNetworkError() {
        return this.status === null || this.status === 0 || this.status === 408;
    }
}

// Export for use in modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { ApiClient, ApiError };
}
