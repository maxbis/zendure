/**
 * Notification Service
 * Unified error handling and user feedback system
 * Provides toast-style notifications for success, error, warning, and info messages
 */
class NotificationService {
    constructor() {
        this.container = null;
        this.notifications = [];
        this.maxNotifications = 5;
        this._init();
    }
    
    /**
     * Initialize the notification container
     * @private
     */
    _init() {
        // Create container if it doesn't exist
        if (!document.getElementById('notification-container')) {
            this.container = document.createElement('div');
            this.container.id = 'notification-container';
            this.container.className = 'notification-container';
            document.body.appendChild(this.container);
            
            // Add styles if not already present
            this._injectStyles();
        } else {
            this.container = document.getElementById('notification-container');
        }
    }
    
    /**
     * Show a notification
     * @param {string} message - Notification message
     * @param {string} type - Notification type: 'success', 'error', 'warning', 'info'
     * @param {number} duration - Auto-dismiss duration in ms (0 = no auto-dismiss)
     * @returns {HTMLElement} The notification element
     */
    show(message, type = 'info', duration = 5000) {
        if (!message) {
            console.warn('NotificationService.show() called without message');
            return null;
        }
        
        const notification = this._createNotification(message, type);
        this.container.appendChild(notification);
        this.notifications.push(notification);
        
        // Limit number of visible notifications
        if (this.notifications.length > this.maxNotifications) {
            const oldest = this.notifications.shift();
            this.dismiss(oldest);
        }
        
        // Trigger animation
        requestAnimationFrame(() => {
            notification.classList.add('show');
        });
        
        // Auto-dismiss (unless duration is 0)
        if (duration > 0) {
            setTimeout(() => {
                this.dismiss(notification);
            }, duration);
        }
        
        return notification;
    }
    
    /**
     * Show success notification
     * @param {string} message - Success message
     * @param {number} duration - Auto-dismiss duration (default: 3000ms)
     * @returns {HTMLElement} The notification element
     */
    success(message, duration = 3000) {
        return this.show(message, 'success', duration);
    }
    
    /**
     * Show error notification (no auto-dismiss by default)
     * @param {string} message - Error message
     * @param {number} duration - Auto-dismiss duration (default: 0 = no auto-dismiss)
     * @returns {HTMLElement} The notification element
     */
    error(message, duration = 0) {
        return this.show(message, 'error', duration);
    }
    
    /**
     * Show warning notification
     * @param {string} message - Warning message
     * @param {number} duration - Auto-dismiss duration (default: 5000ms)
     * @returns {HTMLElement} The notification element
     */
    warning(message, duration = 5000) {
        return this.show(message, 'warning', duration);
    }
    
    /**
     * Show info notification
     * @param {string} message - Info message
     * @param {number} duration - Auto-dismiss duration (default: 5000ms)
     * @returns {HTMLElement} The notification element
     */
    info(message, duration = 5000) {
        return this.show(message, 'info', duration);
    }
    
    /**
     * Dismiss a notification
     * @param {HTMLElement} notification - The notification element to dismiss
     */
    dismiss(notification) {
        if (!notification || !notification.parentNode) {
            return;
        }
        
        notification.classList.add('dismissing');
        
        // Remove from array
        const index = this.notifications.indexOf(notification);
        if (index > -1) {
            this.notifications.splice(index, 1);
        }
        
        // Remove from DOM after animation
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 300);
    }
    
    /**
     * Dismiss all notifications
     */
    dismissAll() {
        const notifications = [...this.notifications];
        notifications.forEach(notification => this.dismiss(notification));
    }
    
    /**
     * Create a notification element
     * @param {string} message - Notification message
     * @param {string} type - Notification type
     * @returns {HTMLElement} The notification element
     * @private
     */
    _createNotification(message, type) {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        
        // Icon based on type
        const icons = {
            success: '✓',
            error: '✕',
            warning: '⚠',
            info: 'ℹ'
        };
        
        notification.innerHTML = `
            <div class="notification-content">
                <span class="notification-icon">${icons[type] || 'ℹ'}</span>
                <span class="notification-message">${this._escapeHtml(message)}</span>
                <button class="notification-close" aria-label="Close">×</button>
            </div>
        `;
        
        // Add close button handler
        const closeBtn = notification.querySelector('.notification-close');
        closeBtn.addEventListener('click', () => this.dismiss(notification));
        
        return notification;
    }
    
    /**
     * Escape HTML to prevent XSS
     * @param {string} text - Text to escape
     * @returns {string} Escaped text
     * @private
     */
    _escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * Inject CSS styles for notifications
     * @private
     */
    _injectStyles() {
        if (document.getElementById('notification-styles')) {
            return; // Styles already injected
        }
        
        const style = document.createElement('style');
        style.id = 'notification-styles';
        style.textContent = `
            .notification-container {
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 10000;
                display: flex;
                flex-direction: column;
                gap: 10px;
                max-width: 400px;
                pointer-events: none;
            }
            
            .notification {
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                padding: 12px 16px;
                min-width: 300px;
                max-width: 400px;
                opacity: 0;
                transform: translateX(100%);
                transition: all 0.3s ease;
                pointer-events: auto;
                border-left: 4px solid;
            }
            
            .notification.show {
                opacity: 1;
                transform: translateX(0);
            }
            
            .notification.dismissing {
                opacity: 0;
                transform: translateX(100%);
            }
            
            .notification-success {
                border-left-color: #4caf50;
                background: #f1f8f4;
            }
            
            .notification-error {
                border-left-color: #f44336;
                background: #fff5f5;
            }
            
            .notification-warning {
                border-left-color: #ff9800;
                background: #fffbf0;
            }
            
            .notification-info {
                border-left-color: #2196f3;
                background: #f0f7ff;
            }
            
            .notification-content {
                display: flex;
                align-items: center;
                gap: 12px;
            }
            
            .notification-icon {
                font-size: 20px;
                font-weight: bold;
                flex-shrink: 0;
            }
            
            .notification-success .notification-icon {
                color: #4caf50;
            }
            
            .notification-error .notification-icon {
                color: #f44336;
            }
            
            .notification-warning .notification-icon {
                color: #ff9800;
            }
            
            .notification-info .notification-icon {
                color: #2196f3;
            }
            
            .notification-message {
                flex: 1;
                font-size: 14px;
                line-height: 1.4;
                color: #333;
            }
            
            .notification-close {
                background: none;
                border: none;
                font-size: 24px;
                line-height: 1;
                color: #999;
                cursor: pointer;
                padding: 0;
                width: 24px;
                height: 24px;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
                transition: color 0.2s;
            }
            
            .notification-close:hover {
                color: #333;
            }
            
            @media (max-width: 768px) {
                .notification-container {
                    top: 10px;
                    right: 10px;
                    left: 10px;
                    max-width: none;
                }
                
                .notification {
                    min-width: auto;
                    max-width: none;
                }
            }
        `;
        document.head.appendChild(style);
    }
}

// Create global instance
if (typeof window !== 'undefined') {
    window.notifications = new NotificationService();
}
