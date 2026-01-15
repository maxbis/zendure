/**
 * Schedule API
 * Handles all API communication for the schedule application
 */

/**
 * Fetch schedule data for a specific date
 * @param {string} apiUrl - The API URL
 * @param {string} date - Date in YYYYMMDD format
 * @returns {Promise<Object>} - Promise resolving to schedule data
 */
async function fetchScheduleData(apiUrl, date) {
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

/**
 * Clear old schedule entries
 * @param {string} apiUrl - The API URL
 * @param {boolean} simulate - If true, only simulate deletion (returns count)
 * @returns {Promise<Object>} - Promise resolving to result object
 */
async function clearOldEntries(apiUrl, simulate = true) {
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

/**
 * Calculate schedule automatically
 * @param {string} apiUrl - The calculate schedule API URL
 * @param {boolean} simulate - If true, only simulate calculation (returns count)
 * @returns {Promise<Object>} - Promise resolving to result object
 */
async function calculateSchedule(apiUrl, simulate = true) {
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

/**
 * Save or update a schedule entry
 * @param {string} apiUrl - The API URL
 * @param {string} key - Entry key
 * @param {*} value - Entry value
 * @param {string|null} originalKey - Original key if editing
 * @returns {Promise<Object>} - Promise resolving to result object
 */
async function saveScheduleEntry(apiUrl, key, value, originalKey = null) {
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

/**
 * Delete a schedule entry
 * @param {string} apiUrl - The API URL
 * @param {string} key - Entry key to delete
 * @returns {Promise<Object>} - Promise resolving to result object
 */
async function deleteScheduleEntry(apiUrl, key) {
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

/**
 * Fetch automation status data
 * @param {string} apiUrl - The automation status API URL
 * @returns {Promise<Object>} - Promise resolving to automation status data
 */
async function fetchAutomationStatus(apiUrl) {
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

/**
 * Fetch charge/discharge status data
 * @param {string} zendureApiUrl - The Zendure data API URL
 * @param {string} p1ApiUrl - The P1 data API URL (optional)
 * @returns {Promise<Object>} - Promise resolving to charge status data
 */
async function fetchChargeStatus(zendureApiUrl, p1ApiUrl = null) {
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
