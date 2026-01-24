/**
 * Schedule API
 * Handles all API communication for the schedule application
 * Uses ApiClient for robust error handling and retry logic
 */

// Create API client instances (lazy initialization)
let scheduleApiClient = null;
let getApiClient = (baseUrl) => {
    if (!scheduleApiClient || scheduleApiClient.baseUrl !== baseUrl) {
        scheduleApiClient = new ApiClient(baseUrl, {
            timeout: 10000,
            retries: 3,
            retryDelay: 1000,
            onError: (error) => {
                console.error('API request failed:', error);
                if (window.notifications && error instanceof ApiError) {
                    if (error.isNetworkError()) {
                        window.notifications.error('Network error: Please check your connection');
                    } else if (error.isServerError()) {
                        window.notifications.error('Server error: Please try again later');
                    }
                }
            }
        });
    }
    return scheduleApiClient;
};

/**
 * Fetch schedule data for a specific date
 * @param {string} apiUrl - The API URL
 * @param {string} date - Date in YYYYMMDD format
 * @returns {Promise<Object>} - Promise resolving to schedule data
 */
async function fetchScheduleData(apiUrl, date) {
    try {
        // Parse existing query params from URL and merge with date
        const urlParts = apiUrl.split('?');
        const baseUrl = urlParts[0];
        const existingParamsStr = urlParts[1] || '';
        const existingParams = existingParamsStr ? Object.fromEntries(new URLSearchParams(existingParamsStr)) : {};
        
        // Merge existing params with date (date takes precedence if it exists in URL)
        const params = { ...existingParams, date: date };
        
        const client = getApiClient(baseUrl);
        return await client.get('', params);
    } catch (error) {
        // Fallback to original implementation if ApiClient not available
        if (typeof ApiClient === 'undefined') {
            const url = apiUrl + (apiUrl.includes('?') ? '&' : '?') + 'date=' + date;
            const response = await fetch(url);

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Non-JSON response:', text.substring(0, 200));
                throw new Error('Server returned non-JSON response. Check console for details.');
            }

            return await response.json();
        }
        throw error;
    }
}

/**
 * Clear old schedule entries
 * @param {string} apiUrl - The API URL
 * @param {boolean} simulate - If true, only simulate deletion (returns count)
 * @returns {Promise<Object>} - Promise resolving to result object
 */
async function clearOldEntries(apiUrl, simulate = true) {
    try {
        const client = getApiClient(apiUrl.split('?')[0]);
        return await client.post('', { action: simulate ? 'simulate' : 'delete' });
    } catch (error) {
        // Fallback to original implementation if ApiClient not available
        if (typeof ApiClient === 'undefined') {
            const action = simulate ? 'simulate' : 'delete';
            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ action: action })
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Non-JSON response:', text.substring(0, 200));
                throw new Error('Server returned non-JSON response. Check console for details.');
            }

            return await response.json();
        }
        throw error;
    }
}

/**
 * Calculate schedule automatically
 * @param {string} apiUrl - The calculate schedule API URL
 * @param {boolean} simulate - If true, only simulate calculation (returns count)
 * @returns {Promise<Object>} - Promise resolving to result object
 */
async function calculateSchedule(apiUrl, simulate = true) {
    try {
        const client = getApiClient(apiUrl.split('?')[0]);
        return await client.post('', { action: simulate ? 'simulate' : 'calculate' });
    } catch (error) {
        // Fallback to original implementation if ApiClient not available
        if (typeof ApiClient === 'undefined') {
            const action = simulate ? 'simulate' : 'calculate';
            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ action: action })
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Non-JSON response:', text.substring(0, 200));
                throw new Error('Server returned non-JSON response. Check console for details.');
            }

            return await response.json();
        }
        throw error;
    }
}

/**
 * Save or update a schedule entry
 * @param {string} apiUrl - The API URL
 * @param {string} key - Entry key
 * @param {*} value - Entry value
 * @param {string|null} originalKey - Original key if editing
 * @returns {Promise<Object>} - Promise resolving to result object
 */
async function saveScheduleEntry(apiUrl, key, value, originalKey = null) {
    try {
        const client = getApiClient(apiUrl.split('?')[0]);
        const body = { key, value };
        if (originalKey !== null && originalKey !== key) {
            body.originalKey = originalKey;
        }
        return await client.post('', body);
    } catch (error) {
        // Fallback to original implementation if ApiClient not available
        if (typeof ApiClient === 'undefined') {
            const body = { key, value };
            if (originalKey !== null && originalKey !== key) {
                body.originalKey = originalKey;
            }

            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(body)
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Non-JSON response:', text.substring(0, 200));
                throw new Error('Server returned non-JSON response. Check console for details.');
            }

            return await response.json();
        }
        throw error;
    }
}

/**
 * Delete a schedule entry
 * @param {string} apiUrl - The API URL
 * @param {string} key - Entry key to delete
 * @returns {Promise<Object>} - Promise resolving to result object
 */
async function deleteScheduleEntry(apiUrl, key) {
    try {
        const client = getApiClient(apiUrl.split('?')[0]);
        return await client.delete('', { key });
    } catch (error) {
        // Fallback to original implementation if ApiClient not available
        if (typeof ApiClient === 'undefined') {
            const response = await fetch(apiUrl, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ key })
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Non-JSON response:', text.substring(0, 200));
                throw new Error('Server returned non-JSON response. Check console for details.');
            }

            return await response.json();
        }
        throw error;
    }
}

/**
 * Fetch automation status data
 * @param {string} apiUrl - The automation status API URL
 * @returns {Promise<Object>} - Promise resolving to automation status data
 */
async function fetchAutomationStatus(apiUrl) {
    try {
        const baseUrl = apiUrl.split('?')[0];
        const params = new URLSearchParams(apiUrl.split('?')[1] || '');
        const client = getApiClient(baseUrl);
        return await client.get('', Object.fromEntries(params));
    } catch (error) {
        // Fallback to original implementation if ApiClient not available
        if (typeof ApiClient === 'undefined') {
            const response = await fetch(apiUrl);

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Non-JSON response:', text.substring(0, 200));
                throw new Error('Server returned non-JSON response. Check console for details.');
            }

            return await response.json();
        }
        throw error;
    }
}

/**
 * Fetch charge/discharge status data
 * @param {string} zendureApiUrl - The Zendure data API URL
 * @param {string} p1ApiUrl - The P1 data API URL (optional)
 * @returns {Promise<Object>} - Promise resolving to charge status data
 */
async function fetchChargeStatus(zendureApiUrl, p1ApiUrl = null) {
    try {
        // Parse URLs to extract base URL and params
        const zendureUrlParts = zendureApiUrl.split('?');
        const zendureBaseUrl = zendureUrlParts[0];
        const zendureParams = new URLSearchParams(zendureUrlParts[1] || '');
        
        const client = getApiClient(zendureBaseUrl);
        const zendureData = await client.get('', Object.fromEntries(zendureParams));

        // Optionally fetch P1 data if URL provided
        let p1Data = null;
        if (p1ApiUrl) {
            try {
                const p1UrlParts = p1ApiUrl.split('?');
                const p1BaseUrl = p1UrlParts[0];
                const p1Params = new URLSearchParams(p1UrlParts[1] || '');
                const p1Client = getApiClient(p1BaseUrl);
                p1Data = await p1Client.get('', Object.fromEntries(p1Params));
            } catch (error) {
                console.warn('Failed to fetch P1 data:', error);
                // Continue without P1 data
            }
        }

        return { zendureData, p1Data };
    } catch (error) {
        // Fallback to original implementation if ApiClient not available
        if (typeof ApiClient === 'undefined') {
            const response = await fetch(zendureApiUrl);

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Non-JSON response:', text.substring(0, 200));
                throw new Error('Server returned non-JSON response. Check console for details.');
            }

            const zendureData = await response.json();

            // Optionally fetch P1 data if URL provided
            let p1Data = null;
            if (p1ApiUrl) {
                try {
                    const p1Response = await fetch(p1ApiUrl);
                    if (p1Response.ok) {
                        const p1ContentType = p1Response.headers.get('content-type');
                        if (p1ContentType && p1ContentType.includes('application/json')) {
                            p1Data = await p1Response.json();
                        }
                    }
                } catch (error) {
                    console.warn('Failed to fetch P1 data:', error);
                    // Continue without P1 data
                }
            }

            return { zendureData, p1Data };
        }
        throw error;
    }
}
