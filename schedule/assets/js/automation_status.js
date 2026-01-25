/**
 * Automation Status
 * Client-side logic for fetching and rendering automation status
 */

// Automation entries toggle functionality
window.toggleAutomationEntries = function () {
    const entriesWrapper = document.getElementById('automation-entries-wrapper');
    const entriesList = document.getElementById('automation-entries-list');
    if (!entriesWrapper || !entriesList) return;

    if (entriesWrapper.classList.contains('expanded')) {
        entriesWrapper.classList.remove('expanded');
        entriesList.classList.remove('expanded');
    } else {
        entriesWrapper.classList.add('expanded');
        entriesList.classList.add('expanded');
    }
};

/**
 * Refresh automation status from API
 */
async function refreshAutomationStatus() {
    if (typeof AUTOMATION_STATUS_API_URL === 'undefined' || !AUTOMATION_STATUS_API_URL) {
        console.error('AUTOMATION_STATUS_API_URL is not defined');
        return;
    }

    try {
        const data = await fetchAutomationStatus(AUTOMATION_STATUS_API_URL);
        renderAutomationStatus(data);
    } catch (error) {
        console.error('Failed to refresh automation status:', error);

        // Render error state
        renderAutomationStatus({
            success: false,
            error: error.message || 'Failed to load automation status'
        });
    }
}

/**
 * Perform normal refresh (partial refresh)
 */
async function performNormalRefresh() {
    const refreshBtn = document.getElementById('automation-refresh-btn');
    if (!refreshBtn) return;
    
    // Hide button for 1 second (same as auto-refresh)
    if (typeof indicateAutoRefresh === 'function') {
        indicateAutoRefresh();
    }
    
    refreshBtn.disabled = true;
    refreshBtn.style.opacity = '0.5';

    // Refresh all sections (Automation Status, Charge/Discharge, and System & Grid)
    if (typeof refreshAllStatus === 'function') {
        await refreshAllStatus();
    } else {
        // Fallback to just automation status if unified function not available
        await refreshAutomationStatus();
    }

    refreshBtn.disabled = false;
    refreshBtn.style.opacity = '1';
}

/**
 * Check if device is mobile (touch device)
 */
function isMobileDevice() {
    // Check if body has mobile class (most reliable for mobile page)
    const hasMobileClass = document.body.classList.contains('mobile-dark');
    if (hasMobileClass) {
        return true;
    }
    
    // Check for touch support
    const hasTouch = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
    // Check for mobile viewport
    const isSmallScreen = window.innerWidth <= 768;
    
    return hasTouch || isSmallScreen;
}

/**
 * Show full-screen reload overlay
 */
function showReloadOverlay() {
    // Remove existing overlay if any
    const existingOverlay = document.getElementById('reload-overlay');
    if (existingOverlay) {
        existingOverlay.remove();
    }
    
    // Create overlay element
    const overlay = document.createElement('div');
    overlay.id = 'reload-overlay';
    overlay.className = 'reload-overlay';
    overlay.innerHTML = '<div class="reload-overlay-content">Reloading....</div>';
    
    // Add to body
    document.body.appendChild(overlay);
    
    // Trigger animation by adding active class
    requestAnimationFrame(() => {
        overlay.classList.add('active');
    });
}

/**
 * Setup long-press handler for mobile refresh button
 * Long-press reloads page directly without dialog
 */
function setupMobileLongPressHandler(button) {
    let longPressTimer = null;
    let progressInterval = null;
    let longPressThreshold = 900; // 900ms threshold
    let hasLongPressTriggered = false;
    let touchStartTime = 0;
    let textChanged = false;
    
    // Get text element
    const textElement = button.querySelector('.refresh-text');
    const originalText = textElement ? textElement.textContent : 'Refresh';
    
    // Track if we should prevent the normal click
    let shouldPreventClick = false;
    
    /**
     * Update progress ring and text
     */
    function updateProgress(elapsed) {
        const progress = Math.min((elapsed / longPressThreshold) * 100, 100);
        
        // Update CSS custom property for progress ring
        button.style.setProperty('--progress', `${progress}%`);
        
        // Change text at 50% (450ms)
        if (progress >= 50 && !textChanged && textElement) {
            textElement.textContent = 'Reloading...';
            textChanged = true;
        }
    }
    
    /**
     * Start long-press detection
     */
    function handleLongPressStart(e) {
        // Reset state
        hasLongPressTriggered = false;
        shouldPreventClick = false;
        textChanged = false;
        touchStartTime = Date.now();
        
        // Reset text
        if (textElement) {
            textElement.textContent = originalText;
        }
        
        // Prevent context menu, text selection, and default touch behavior
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        
        // Prevent iOS text selection
        if (e.type === 'touchstart') {
            // Disable text selection on the button
            button.style.webkitUserSelect = 'none';
            button.style.userSelect = 'none';
            
            // Prevent iOS callout menu
            document.body.style.webkitTouchCallout = 'none';
        }
        
        // Add visual feedback class immediately
        button.classList.add('long-pressing');
        button.style.setProperty('--progress', '0%');
        
        // Start progress animation
        const startTime = Date.now();
        progressInterval = setInterval(() => {
            const elapsed = Date.now() - startTime;
            updateProgress(elapsed);
            
            if (elapsed >= longPressThreshold) {
                clearInterval(progressInterval);
            }
        }, 16); // ~60fps
        
        // Start timer
        longPressTimer = setTimeout(() => {
            hasLongPressTriggered = true;
            shouldPreventClick = true;
            
            // Clear progress interval
            if (progressInterval) {
                clearInterval(progressInterval);
                progressInterval = null;
            }
            
            // Remove visual feedback before showing reload overlay
            button.classList.remove('long-pressing');
            button.style.removeProperty('--progress');
            
            // Show reload overlay
            showReloadOverlay();
            
            // Reload page after a short delay to show the overlay
            setTimeout(() => {
                window.location.reload();
            }, 300);
        }, longPressThreshold);
    }
    
    /**
     * End long-press detection
     */
    function handleLongPressEnd(e) {
        const touchDuration = Date.now() - touchStartTime;
        
        // Prevent default to stop iOS context menu
        e.preventDefault();
        e.stopPropagation();
        
        // Clear timer and progress interval
        if (longPressTimer) {
            clearTimeout(longPressTimer);
            longPressTimer = null;
        }
        
        if (progressInterval) {
            clearInterval(progressInterval);
            progressInterval = null;
        }
        
        // Reset text
        if (textElement) {
            textElement.textContent = originalText;
        }
        
        // Remove visual feedback
        button.classList.remove('long-pressing');
        button.style.removeProperty('--progress');
        
        // Restore iOS callout (if we disabled it)
        if (e.type === 'touchend' || e.type === 'touchcancel') {
            document.body.style.webkitTouchCallout = '';
        }
        
        // If long-press was triggered, prevent normal click
        if (hasLongPressTriggered) {
            shouldPreventClick = true;
            hasLongPressTriggered = false;
            return;
        }
        
        // If touch was too short, allow normal click to proceed
        if (touchDuration < longPressThreshold) {
            // Don't prevent click - let it fire normally
        }
    }
    
    /**
     * Handle click event (fires after touchend on mobile)
     */
    function handleClick(e) {
        // Always prevent default click during long-press handling
        // We'll manually trigger refresh if needed
        e.preventDefault();
        e.stopPropagation();
        
        // If long-press was detected, don't do normal refresh
        if (shouldPreventClick || hasLongPressTriggered) {
            shouldPreventClick = false;
            hasLongPressTriggered = false;
            return;
        }
        
        // Small delay to ensure touch events have processed
        setTimeout(() => {
            if (!shouldPreventClick && !hasLongPressTriggered) {
                performNormalRefresh();
            }
        }, 100);
    }
    
    // Touch events for mobile - use capture phase to ensure we get them first
    button.addEventListener('touchstart', handleLongPressStart, { passive: false, capture: true });
    button.addEventListener('touchend', handleLongPressEnd, { passive: false, capture: true });
    button.addEventListener('touchcancel', handleLongPressEnd, { passive: false, capture: true });
    button.addEventListener('touchmove', (e) => {
        // Prevent default to stop iOS text selection
        e.preventDefault();
        
        // Cancel if user moves finger significantly
        if (longPressTimer) {
            clearTimeout(longPressTimer);
            longPressTimer = null;
        }
        if (progressInterval) {
            clearInterval(progressInterval);
            progressInterval = null;
        }
        button.classList.remove('long-pressing');
        button.style.removeProperty('--progress');
        if (textElement) {
            textElement.textContent = originalText;
        }
        hasLongPressTriggered = false;
        shouldPreventClick = false;
        textChanged = false;
        
        // Restore iOS callout
        document.body.style.webkitTouchCallout = '';
    }, { passive: false, capture: true });
    
    // Also support mouse events for testing on desktop browsers (only on mobile page)
    // This allows testing long-press functionality even without touch
    const handleMouseDown = (e) => {
        if (e.button === 0) { // Left mouse button only
            handleLongPressStart(e);
        }
    };
    
    const handleMouseUp = (e) => {
        if (e.button === 0) {
            handleLongPressEnd(e);
        }
    };
    
    const handleMouseLeave = () => {
        if (longPressTimer) {
            clearTimeout(longPressTimer);
            longPressTimer = null;
        }
        if (progressInterval) {
            clearInterval(progressInterval);
            progressInterval = null;
        }
        button.classList.remove('long-pressing');
        button.style.removeProperty('--progress');
        if (textElement) {
            textElement.textContent = originalText;
        }
        hasLongPressTriggered = false;
        shouldPreventClick = false;
        textChanged = false;
    };
    
    button.addEventListener('mousedown', handleMouseDown, { passive: false, capture: true });
    button.addEventListener('mouseup', handleMouseUp, { passive: false, capture: true });
    button.addEventListener('mouseleave', handleMouseLeave, { capture: true });
    
    // Click event (normal refresh) - use capture to handle before other handlers
    // Delay adding click handler to avoid conflicts
    setTimeout(() => {
        button.addEventListener('click', handleClick, { capture: true });
    }, 0);
}

// Initialize automation status refresh button
// Desktop: Normal click only (no long-press)
// Mobile: Long-press triggers full page reload (no dialog)
document.addEventListener('DOMContentLoaded', () => {
    const refreshBtn = document.getElementById('automation-refresh-btn');
    if (refreshBtn) {
        // Remove old onclick handler
        refreshBtn.onclick = null;
        
        const isMobile = isMobileDevice();
        
        if (isMobile) {
            // Mobile: Setup long-press handler
            setupMobileLongPressHandler(refreshBtn);
        } else {
            // Desktop: Simple click handler only
            refreshBtn.addEventListener('click', async () => {
                await performNormalRefresh();
            });
        }
    }
});
