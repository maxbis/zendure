/**
 * Schedule Calculator
 * Calculates and displays charge/discharge sums for today and tomorrow
 * Updates automatically when schedule changes
 */

// Constants for netzero evaluation
const NETZERO_VALUE = -350;      // netzero evaluates to -350 watts (discharge)
const NETZERO_PLUS_VALUE = 350;  // netzero+ evaluates to +350 watts (charge)

/**
 * Parse time string to minutes since midnight
 * Handles both string "0000" and numeric 1000 formats
 */
function timeToMinutes(timeStr) {
    // Handle numeric format
    if (typeof timeStr === 'number') {
        timeStr = String(timeStr).padStart(4, '0');
    } else {
        timeStr = String(timeStr).padStart(4, '0');
    }
    
    const hours = parseInt(timeStr.substring(0, 2), 10);
    const minutes = parseInt(timeStr.substring(2, 4), 10);
    return hours * 60 + minutes;
}

/**
 * Resolve netzero values to numeric equivalents
 */
function resolveNetzeroValue(value) {
    if (value === 'netzero') {
        return NETZERO_VALUE;
    } else if (value === 'netzero+') {
        return NETZERO_PLUS_VALUE;
    } else if (typeof value === 'number') {
        return parseFloat(value);
    } else {
        return 0;
    }
}

/**
 * Calculate sums from resolved array (full day)
 * Each value applies until the next time slot, so we multiply by duration
 */
function calculateSums(resolved) {
    let total = 0;
    let positive = 0;
    let negative = 0;
    
    if (!resolved || resolved.length === 0) {
        return {
            total: 0,
            positive: 0,
            negative: 0
        };
    }
    
    // Sort entries by time to ensure correct order
    const sorted = [...resolved].sort((a, b) => {
        const timeA = a.time || '';
        const timeB = b.time || '';
        const minutesA = timeToMinutes(timeA);
        const minutesB = timeToMinutes(timeB);
        return minutesA - minutesB;
    });
    
    // Calculate duration for each entry
    for (let i = 0; i < sorted.length; i++) {
        const entry = sorted[i];
        let value = entry.value ?? 0;
        
        // Resolve netzero values to numeric equivalents
        value = resolveNetzeroValue(value);
        
        // Calculate duration until next entry (or end of day)
        const currentTime = entry.time || '';
        const currentMinutes = timeToMinutes(currentTime);
        
        let durationHours;
        if (i < sorted.length - 1) {
            // Duration until next entry
            const nextTime = sorted[i + 1].time || '';
            const nextMinutes = timeToMinutes(nextTime);
            durationHours = (nextMinutes - currentMinutes) / 60.0;
        } else {
            // Last entry: duration until end of day (24:00)
            durationHours = (24 * 60 - currentMinutes) / 60.0;
        }
        
        // Multiply value by duration (in hours) to get total energy
        const energy = value * durationHours;
        
        total += energy;
        if (value > 0) {
            positive += energy;
        } else if (value < 0) {
            negative += energy;
        }
    }
    
    return {
        total: total,
        positive: positive,
        negative: negative
    };
}

/**
 * Calculate sums from a specific start time
 * This handles the case where the current time is in the middle of a time slot
 */
function calculateSumsFromTime(resolved, startTime) {
    let total = 0;
    let positive = 0;
    let negative = 0;
    
    if (!resolved || resolved.length === 0) {
        return {
            total: 0,
            positive: 0,
            negative: 0
        };
    }
    
    // Sort entries by time to ensure correct order
    const sorted = [...resolved].sort((a, b) => {
        const timeA = a.time || '';
        const timeB = b.time || '';
        const minutesA = timeToMinutes(timeA);
        const minutesB = timeToMinutes(timeB);
        return minutesA - minutesB;
    });
    
    const startMinutes = timeToMinutes(startTime);
    
    // Find the entry that is active at the start time (most recent entry with time <= startTime)
    let activeEntry = null;
    let activeIndex = -1;
    for (let i = 0; i < sorted.length; i++) {
        const entryTime = sorted[i].time || '';
        const entryMinutes = timeToMinutes(entryTime);
        if (entryMinutes <= startMinutes) {
            activeEntry = sorted[i];
            activeIndex = i;
        } else {
            break;
        }
    }
    
    let startIndex;
    // If we found an active entry, calculate from start time to next entry (or end of day)
    if (activeEntry !== null) {
        let value = activeEntry.value ?? 0;
        // Resolve netzero values to numeric equivalents
        value = resolveNetzeroValue(value);
        
        if (activeIndex < sorted.length - 1) {
            // There's a next entry, calculate from start time to next entry
            const nextTime = sorted[activeIndex + 1].time || '';
            const nextMinutes = timeToMinutes(nextTime);
            const durationHours = (nextMinutes - startMinutes) / 60.0;
            
            if (durationHours > 0) {
                const energy = value * durationHours;
                total += energy;
                if (value > 0) {
                    positive += energy;
                } else if (value < 0) {
                    negative += energy;
                }
            }
            
            // Continue from the next entry
            startIndex = activeIndex + 1;
        } else {
            // This is the last entry, calculate from start time to end of day (24:00)
            const endOfDayMinutes = 24 * 60; // 24:00 = 1440 minutes
            const durationHours = (endOfDayMinutes - startMinutes) / 60.0;
            
            if (durationHours > 0) {
                const energy = value * durationHours;
                total += energy;
                if (value > 0) {
                    positive += energy;
                } else if (value < 0) {
                    negative += energy;
                }
            }
            
            // No more entries to process after this
            startIndex = sorted.length; // Set to length so loop doesn't execute
        }
    } else {
        // No active entry found, start from first entry after start time
        startIndex = sorted.length; // Default: no entries to process
        for (let i = 0; i < sorted.length; i++) {
            const entryTime = sorted[i].time || '';
            const entryMinutes = timeToMinutes(entryTime);
            if (entryMinutes > startMinutes) {
                startIndex = i;
                break;
            }
        }
    }
    
    // Calculate duration for remaining entries
    for (let i = startIndex; i < sorted.length; i++) {
        const entry = sorted[i];
        let value = entry.value ?? 0;
        
        // Resolve netzero values to numeric equivalents
        value = resolveNetzeroValue(value);
        
        // Calculate duration until next entry (or end of day)
        const currentTime = entry.time || '';
        const currentMinutes = timeToMinutes(currentTime);
        
        let durationHours;
        if (i < sorted.length - 1) {
            // Duration until next entry
            const nextTime = sorted[i + 1].time || '';
            const nextMinutes = timeToMinutes(nextTime);
            durationHours = (nextMinutes - currentMinutes) / 60.0;
        } else {
            // Last entry: duration until end of day (24:00)
            durationHours = (24 * 60 - currentMinutes) / 60.0;
        }
        
        // Multiply value by duration (in hours) to get total energy
        const energy = value * durationHours;
        
        total += energy;
        if (value > 0) {
            positive += energy;
        } else if (value < 0) {
            negative += energy;
        }
    }
    
    return {
        total: total,
        positive: positive,
        negative: negative
    };
}

/**
 * Format a number with commas and no decimals
 */
function formatNumber(num) {
    return Math.round(num).toLocaleString('en-US');
}

/**
 * Get CSS class based on value (charge, discharge, or neutral)
 */
function getValueClass(value) {
    if (value > 0) return 'charge';
    if (value < 0) return 'discharge';
    return 'neutral';
}

/**
 * Update a calculator card with calculated sums
 */
function updateCalculatorCard(cardPrefix, sums) {
    const totalCell = document.getElementById(`${cardPrefix}-total`);
    const positiveCell = document.getElementById(`${cardPrefix}-positive`);
    const negativeCell = document.getElementById(`${cardPrefix}-negative`);
    
    if (totalCell) {
        totalCell.textContent = formatNumber(sums.total) + ' Wh';
        // Update class - preserve calculate-value, update charge/discharge/neutral
        totalCell.className = 'calculate-value ' + getValueClass(sums.total);
    }
    
    if (positiveCell) {
        positiveCell.textContent = formatNumber(sums.positive) + ' Wh';
    }
    
    if (negativeCell) {
        negativeCell.textContent = formatNumber(sums.negative) + ' Wh';
    }
}

/**
 * Update current time display in calculator header
 */
function updateCalculatorTime(currentTime) {
    const timeDisplay = document.getElementById('calculator-current-time');
    if (timeDisplay) {
        const now = new Date();
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const seconds = String(now.getSeconds()).padStart(2, '0');
        const dateStr = now.getFullYear() + '-' + 
                       String(now.getMonth() + 1).padStart(2, '0') + '-' + 
                       String(now.getDate()).padStart(2, '0');
        timeDisplay.textContent = `Current Time: ${dateStr} ${hours}:${minutes}:${seconds} (${currentTime})`;
    }
}

/**
 * Render/update the schedule calculator with today's and tomorrow's data
 * @param {Array} todayResolved - Resolved schedule slots for today
 * @param {Array} tomorrowResolved - Resolved schedule slots for tomorrow
 * @param {string} currentTime - Current time in HHmm format
 */
function renderScheduleCalculator(todayResolved, tomorrowResolved, currentTime) {
    if (!todayResolved || !tomorrowResolved) {
        return;
    }
    
    // Calculate today's sums (full day)
    const todaySums = calculateSums(todayResolved);
    
    // Calculate today's sums (from current time onwards)
    const todayFromNowSums = calculateSumsFromTime(todayResolved, currentTime);
    
    // Calculate tomorrow's sums (full day)
    const tomorrowSums = calculateSums(tomorrowResolved);
    
    // Update calculator cards
    updateCalculatorCard('calc-today-full', todaySums);
    updateCalculatorCard('calc-today-from-now', todayFromNowSums);
    updateCalculatorCard('calc-tomorrow-full', tomorrowSums);
    
    // Update current time display
    updateCalculatorTime(currentTime);
}