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
