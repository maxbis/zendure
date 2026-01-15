/**
 * Schedule Utilities
 * Shared utility functions used across the schedule application
 */

/**
 * Get display label for a schedule value
 * @param {*} value - The schedule value (number, 'netzero', 'netzero+', or null)
 * @returns {string} - Display label
 */
function getValueLabel(value) {
    if (value === null || typeof value === 'undefined') return '-';
    if (typeof value === 'number') return (value > 0 ? '+' : '') + value + ' W';

    // Try to find the radio button with matching value and get its label attribute
    const radio = document.querySelector(`input[name="val-mode"][value="${value}"]`);
    if (radio && radio.hasAttribute('label')) {
        return radio.getAttribute('label');
    }

    // Fallback to hardcoded values if modal not loaded yet
    if (value === 'netzero') return 'Net Zero';
    if (value === 'netzero+') return 'Solar Charge';

    return value + ' W';
}

/**
 * Get CSS class for time-of-day styling
 * @param {number} hour - Hour of day (0-23)
 * @returns {string} - CSS class name
 */
function getTimeClass(hour) {
    if (hour >= 22 || hour < 6) return 'time-night';
    if (hour < 12) return 'time-morning';
    if (hour < 18) return 'time-afternoon';
    return 'time-evening';
}

/**
 * Get CSS class for value category
 * @param {*} value - The schedule value
 * @returns {string} - CSS class name
 */
function getValueClass(value) {
    if (value === 'netzero') return 'netzero';
    if (value === 'netzero+') return 'netzero-plus';
    if (typeof value === 'number') {
        return (value > 0) ? 'charge' : ((value < 0) ? 'discharge' : 'neutral');
    }
    return 'neutral';
}

/**
 * Format date as YYYYMMDD
 * @param {Date} date - Date object
 * @returns {string} - Formatted date string
 */
function formatDateYYYYMMDD(date) {
    return date.getFullYear().toString() +
        String(date.getMonth() + 1).padStart(2, '0') +
        String(date.getDate()).padStart(2, '0');
}

/**
 * Format time as HH:mm from HHmm
 * @param {string} time - Time in HHmm format
 * @returns {string} - Time in HH:mm format
 */
function formatTime(time) {
    const timeStr = String(time).padStart(4, '0');
    return timeStr.substring(0, 2) + ':' + timeStr.substring(2, 4);
}

/**
 * Build a map of hour -> value from resolved schedule
 * @param {Array} resolved - Resolved schedule slots
 * @returns {Object} - Map of hour (0-23) to value
 */
function buildHourMap(resolved) {
    const hourMap = {};
    let lastValue = null;
    resolved.forEach(slot => {
        const hour = parseInt(String(slot.time).substring(0, 2));
        const value = slot.value;
        if (value !== null) {
            lastValue = value;
        }
        hourMap[hour] = lastValue;
    });
    return hourMap;
}
