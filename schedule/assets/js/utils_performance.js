/**
 * Performance Utilities
 * Utility functions for performance optimization
 */

/**
 * Debounce function calls
 * @param {Function} func - Function to debounce
 * @param {number} wait - Wait time in milliseconds
 * @param {boolean} immediate - If true, call immediately on first invocation
 * @returns {Function} Debounced function
 */
function debounce(func, wait, immediate = false) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            timeout = null;
            if (!immediate) func(...args);
        };
        const callNow = immediate && !timeout;
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
        if (callNow) func(...args);
    };
}

/**
 * Throttle function calls
 * @param {Function} func - Function to throttle
 * @param {number} limit - Time limit in milliseconds
 * @returns {Function} Throttled function
 */
function throttle(func, limit) {
    let inThrottle;
    return function(...args) {
        if (!inThrottle) {
            func.apply(this, args);
            inThrottle = true;
            setTimeout(() => inThrottle = false, limit);
        }
    };
}

/**
 * Request animation frame throttle
 * @param {Function} func - Function to throttle with RAF
 * @returns {Function} Throttled function
 */
function rafThrottle(func) {
    let rafId = null;
    return function(...args) {
        if (rafId === null) {
            rafId = requestAnimationFrame(() => {
                func.apply(this, args);
                rafId = null;
            });
        }
    };
}

/**
 * Lazy load images
 * @param {NodeList|Array} images - Image elements or selectors
 * @param {Object} options - Options
 * @param {string} options.rootMargin - Intersection observer root margin
 * @param {number} options.threshold - Intersection observer threshold
 */
function lazyLoadImages(images, options = {}) {
    if (!('IntersectionObserver' in window)) {
        // Fallback: load all images immediately
        images.forEach(img => {
            if (typeof img === 'string') {
                img = document.querySelector(img);
            }
            if (img && img.dataset.src) {
                img.src = img.dataset.src;
            }
        });
        return;
    }
    
    const imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                if (img.dataset.src) {
                    img.src = img.dataset.src;
                    img.removeAttribute('data-src');
                }
                observer.unobserve(img);
            }
        });
    }, {
        rootMargin: options.rootMargin || '50px',
        threshold: options.threshold || 0.01
    });
    
    images.forEach(img => {
        if (typeof img === 'string') {
            img = document.querySelector(img);
        }
        if (img) {
            imageObserver.observe(img);
        }
    });
}

/**
 * Lazy load component when it enters viewport
 * @param {HTMLElement|string} element - Element or selector
 * @param {Function} loader - Function to call when element is visible
 * @param {Object} options - Intersection observer options
 * @returns {IntersectionObserver} Observer instance
 */
function lazyLoadComponent(element, loader, options = {}) {
    const el = typeof element === 'string' ? document.querySelector(element) : element;
    
    if (!el) {
        console.warn('lazyLoadComponent: Element not found');
        return null;
    }
    
    if (!('IntersectionObserver' in window)) {
        // Fallback: load immediately
        loader();
        return null;
    }
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                loader();
                observer.disconnect();
            }
        });
    }, {
        rootMargin: options.rootMargin || '100px',
        threshold: options.threshold || 0.01,
        ...options
    });
    
    observer.observe(el);
    return observer;
}

/**
 * Batch DOM updates
 * @param {Function} updateFn - Function that performs DOM updates
 */
function batchDOMUpdates(updateFn) {
    if (typeof requestAnimationFrame !== 'undefined') {
        requestAnimationFrame(() => {
            updateFn();
        });
    } else {
        setTimeout(updateFn, 0);
    }
}

/**
 * Memoize function results
 * @param {Function} fn - Function to memoize
 * @param {Function} keyFn - Optional key function for cache keys
 * @returns {Function} Memoized function
 */
function memoize(fn, keyFn = null) {
    const cache = new Map();
    
    return function(...args) {
        const key = keyFn ? keyFn(...args) : JSON.stringify(args);
        
        if (cache.has(key)) {
            return cache.get(key);
        }
        
        const result = fn.apply(this, args);
        cache.set(key, result);
        return result;
    };
}

/**
 * Clear memoization cache
 * @param {Function} memoizedFn - Memoized function (if it exposes cache)
 */
function clearMemoCache(memoizedFn) {
    if (memoizedFn && memoizedFn.cache) {
        memoizedFn.cache.clear();
    }
}

// Export for use in modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        debounce,
        throttle,
        rafThrottle,
        lazyLoadImages,
        lazyLoadComponent,
        batchDOMUpdates,
        memoize,
        clearMemoCache
    };
}
