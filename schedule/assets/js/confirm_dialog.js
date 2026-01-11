/**
 * Confirm Dialog Module
 * Reusable confirmation dialog component
 */
class ConfirmDialog {
    constructor() {
        this.dialog = document.getElementById('confirm-dialog-generic');
        this.titleEl = document.getElementById('confirm-dialog-title');
        this.messageEl = document.getElementById('confirm-dialog-message');
        this.confirmBtn = document.getElementById('confirm-dialog-confirm');
        this.cancelBtn = document.getElementById('confirm-dialog-cancel');
        this.closeBtn = document.getElementById('confirm-dialog-close');
        
        this.resolve = null;
        this.init();
    }

    init() {
        // Close button
        this.closeBtn.onclick = () => this.close(false);
        
        // Cancel button
        this.cancelBtn.onclick = () => this.close(false);
        
        // Confirm button
        this.confirmBtn.onclick = () => this.close(true);
        
        // Backdrop click to close
        this.dialog.onclick = (e) => {
            if (e.target === this.dialog) {
                this.close(false);
            }
        };
        
        // Escape key to close
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.dialog.classList.contains('active')) {
                this.close(false);
            }
        });
    }

    /**
     * Show confirmation dialog
     * @param {string} message - The message to display
     * @param {string} title - Optional title (default: "Confirm")
     * @param {string} confirmText - Optional confirm button text (default: "Confirm")
     * @param {string} confirmClass - Optional confirm button class (default: "btn-primary")
     * @returns {Promise<boolean>} - Promise that resolves to true if confirmed, false if cancelled
     */
    show(message, title = 'Confirm', confirmText = 'Confirm', confirmClass = 'btn-primary') {
        return new Promise((resolve) => {
            this.resolve = resolve;
            this.titleEl.textContent = title;
            this.messageEl.textContent = message;
            
            // Update confirm button text and class
            this.confirmBtn.textContent = confirmText;
            this.confirmBtn.className = `btn ${confirmClass}`;
            
            this.dialog.classList.add('active');
            
            // Focus the cancel button by default for safety
            this.cancelBtn.focus();
        });
    }

    close(confirmed) {
        this.dialog.classList.remove('active');
        if (this.resolve) {
            this.resolve(confirmed);
            this.resolve = null;
        }
    }
}

// Export for use in other modules
window.ConfirmDialog = ConfirmDialog;
