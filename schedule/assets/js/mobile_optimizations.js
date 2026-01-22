/**
 * Mobile Optimizations
 * Handles mobile-specific UI simplifications and interactions
 */

/**
 * Initialize mobile optimizations
 */
function initMobileOptimizations() {
    // Only run on mobile devices
    if (window.innerWidth > 768) {
        return;
    }
    
    // Initialize schedule entries toggle
    initScheduleEntriesToggle();
    
    // Initialize schedule simplification
    initScheduleSimplification();
    
    // Initialize automation status simplification
    initAutomationStatusSimplification();
    
    // Initialize price statistics simplification
    initPriceStatisticsSimplification();
    
    // Note: Tomorrow price graph visibility is now handled by price_overview_bar.js
    // based on data availability, not time. This function only affects desktop version.
    // Mobile version uses price-graph-tomorrow-mobile which is controlled separately.
    hideTomorrowPriceGraph();
}

/**
 * Hide tomorrow price graph on mobile (desktop version only)
 * Note: Mobile version (price-graph-tomorrow-mobile) visibility is controlled
 * by price_overview_bar.js based on data availability
 */
function hideTomorrowPriceGraph() {
    // Only hide desktop version, not mobile version
    const tomorrowContainer = document.getElementById('price-graph-tomorrow');
    if (tomorrowContainer) {
        const tomorrowCard = tomorrowContainer.closest('.card');
        if (tomorrowCard) {
            // Only hide if it's the desktop version (not mobile)
            // Mobile version uses price-graph-tomorrow-mobile which is handled separately
            const mobileContainer = document.getElementById('price-graph-tomorrow-mobile');
            if (!mobileContainer || !tomorrowCard.contains(mobileContainer)) {
                tomorrowCard.style.display = 'none';
            }
        }
    }
}

/**
 * Toggle schedule entries table visibility on mobile
 */
function initScheduleEntriesToggle() {
    const toggleBtn = document.getElementById('schedule-entries-toggle');
    const tableWrapper = document.querySelector('.table-wrapper');
    
    if (!toggleBtn || !tableWrapper) {
        return;
    }
    
    // Show toggle button on mobile
    if (window.innerWidth <= 768) {
        toggleBtn.style.display = 'block';
    }
    
    toggleBtn.addEventListener('click', () => {
        const isVisible = tableWrapper.style.display !== 'none' && 
                         tableWrapper.style.display !== '';
        
        if (isVisible) {
            tableWrapper.style.display = 'none';
            toggleBtn.textContent = 'View All Entries';
            toggleBtn.classList.remove('active');
        } else {
            tableWrapper.style.display = 'block';
            toggleBtn.textContent = 'Hide Entries';
            toggleBtn.classList.add('active');
        }
    });
}

/**
 * Simplify schedule display - show only next 4 hours on mobile
 */
function initScheduleSimplification() {
    if (window.innerWidth > 768) {
        return;
    }
    
    const scheduleItems = document.querySelectorAll('#today-schedule-grid .schedule-item');
    if (scheduleItems.length === 0) {
        return;
    }
    
    // Find current active item
    let currentIndex = -1;
    scheduleItems.forEach((item, index) => {
        if (item.classList.contains('slot-current')) {
            currentIndex = index;
        }
    });
    
    // Show current + next 3 items (4 total)
    const maxVisible = 4;
    scheduleItems.forEach((item, index) => {
        if (currentIndex >= 0) {
            const distance = index - currentIndex;
            if (distance < 0 || distance >= maxVisible) {
                item.classList.add('mobile-hidden');
            } else {
                item.classList.remove('mobile-hidden');
            }
        }
    });
    
    // Add "View More" link if there are hidden items
    const hiddenItems = document.querySelectorAll('.schedule-item.mobile-hidden');
    if (hiddenItems.length > 0) {
        let viewMoreLink = document.querySelector('.schedule-view-more');
        if (!viewMoreLink) {
            viewMoreLink = document.createElement('div');
            viewMoreLink.className = 'schedule-view-more';
            viewMoreLink.textContent = `View All (${scheduleItems.length} items)`;
            viewMoreLink.addEventListener('click', () => {
                scheduleItems.forEach(item => item.classList.remove('mobile-hidden'));
                viewMoreLink.style.display = 'none';
            });
            const scheduleGrid = document.getElementById('today-schedule-grid');
            if (scheduleGrid) {
                scheduleGrid.appendChild(viewMoreLink);
            }
        }
    }
}

/**
 * Simplify automation status - show only last 2 entries on mobile
 */
function initAutomationStatusSimplification() {
    if (window.innerWidth > 768) {
        return;
    }
    
    const automationEntries = document.querySelectorAll('.automation-entry');
    if (automationEntries.length <= 2) {
        return;
    }
    
    // Hide all except last 2
    automationEntries.forEach((entry, index) => {
        if (index < automationEntries.length - 2) {
            entry.classList.add('mobile-hidden');
        }
    });
    
    // Add "View All" link
    let viewAllLink = document.querySelector('.automation-view-all');
    if (!viewAllLink && automationEntries.length > 2) {
        viewAllLink = document.createElement('div');
        viewAllLink.className = 'automation-view-all';
        viewAllLink.textContent = `View All (${automationEntries.length} entries)`;
        viewAllLink.addEventListener('click', () => {
            automationEntries.forEach(entry => entry.classList.remove('mobile-hidden'));
            viewAllLink.style.display = 'none';
        });
        const automationContainer = document.querySelector('.automation-status-wrapper');
        if (automationContainer) {
            automationContainer.appendChild(viewAllLink);
        }
    }
}

/**
 * Simplify price statistics - show only current price on mobile
 */
function initPriceStatisticsSimplification() {
    if (window.innerWidth > 768) {
        return;
    }
    
    // Hide min, max, avg price cards (keep only current)
    const priceCards = document.querySelectorAll('.price-stat-card');
    priceCards.forEach(card => {
        const currentValue = card.querySelector('#price-stat-current-value');
        const minValue = card.querySelector('#price-stat-min-value');
        const maxValue = card.querySelector('#price-stat-max-value');
        const avgValue = card.querySelector('#price-stat-avg-value');
        
        // Hide if it's not the current price card
        if (!currentValue && (minValue || maxValue || avgValue)) {
            card.classList.add('mobile-hidden');
        }
    });
}

/**
 * Handle window resize
 */
function handleMobileResize() {
    if (window.innerWidth <= 768) {
        initMobileOptimizations();
    } else {
        // Restore desktop view
        document.querySelectorAll('.mobile-hidden').forEach(el => {
            el.classList.remove('mobile-hidden');
        });
        const scheduleEntriesToggle = document.getElementById('schedule-entries-toggle');
        if (scheduleEntriesToggle) {
            scheduleEntriesToggle.style.display = 'none';
        }
        const tableWrapper = document.querySelector('.table-wrapper');
        if (tableWrapper) {
            tableWrapper.style.display = '';
        }
    }
}

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    initMobileOptimizations();
    
    // Re-initialize on resize (with debouncing)
    let resizeTimeout;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(handleMobileResize, 250);
    });
});

// Also initialize after schedule data loads
if (typeof refreshData === 'function') {
    const originalRefreshData = refreshData;
    refreshData = function(...args) {
        const result = originalRefreshData.apply(this, args);
        // Re-initialize mobile optimizations after data refresh
        setTimeout(() => {
            if (window.innerWidth <= 768) {
                initScheduleSimplification();
            }
        }, 100);
        return result;
    };
}
