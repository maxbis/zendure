/**
 * Edit Modal Module
 * Handles the edit/add dialog for schedule entries
 */
class EditModal {
    constructor(apiUrl, onSaveCallback) {
        this.apiUrl = apiUrl;
        this.onSaveCallback = onSaveCallback;
        this.currentOriginalKey = null;
        this.modal = document.getElementById('edit-modal');
        this.confirmDialog = document.getElementById('confirm-dialog');
        this.confirmResolve = null;
        
        this.init();
    }

    init() {
        // Event Listeners
        document.getElementById('add-entry-btn').onclick = () => this.open();
        document.getElementById('modal-close').onclick = () => this.close();
        document.getElementById('btn-cancel').onclick = () => this.close();
        // Removed backdrop click to close - dialog only closes via explicit buttons

        // Mode toggle
        document.querySelectorAll('input[name="val-mode"]').forEach(r => {
            r.onchange = () => this.handleModeChange(r.value);
            r.onclick = () => this.handleModeChange(r.value);
        });

        // Row click to edit
        document.querySelector('#schedule-table tbody').addEventListener('click', (e) => {
            const tr = e.target.closest('tr');
            if (tr && tr.dataset.key) {
                this.open(tr.dataset.key, tr.dataset.value);
            }
        });

        // Delete handler
        document.getElementById('btn-delete').onclick = () => this.handleDelete();

        // Confirmation dialog handlers
        document.getElementById('confirm-cancel').onclick = () => this.closeConfirmDialog(false);
        document.getElementById('confirm-delete').onclick = () => this.closeConfirmDialog(true);
        this.confirmDialog.onclick = (e) => {
            if (e.target === this.confirmDialog) this.closeConfirmDialog(false);
        };

        // Close dialogs on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (this.confirmDialog.classList.contains('active')) {
                    this.closeConfirmDialog(false);
                } else if (this.modal.classList.contains('active')) {
                    this.close();
                }
            }
        });

        // Save handler
        document.getElementById('btn-save').onclick = () => this.handleSave();

        // Wildcard expansion handlers
        const dateInput = document.getElementById('inp-date');
        const timeInput = document.getElementById('inp-time');
        
        dateInput.addEventListener('input', (e) => {
            this.handleWildcardExpansion(e, 8);
            // Auto-advance to time field when date is complete
            if (dateInput.value.length === 8 && !dateInput.value.includes('*')) {
                timeInput.focus();
                timeInput.select();
            }
        });
        dateInput.addEventListener('blur', (e) => this.handleEmptyToWildcard(e, 8));
        timeInput.addEventListener('input', (e) => {
            this.handleWildcardExpansion(e, 4);
            // Auto-advance to watts field when time is complete
            if (timeInput.value.length === 4 && !timeInput.value.includes('*')) {
                const wattsInput = document.getElementById('inp-watts');
                if (!wattsInput.disabled) {
                    wattsInput.focus();
                    wattsInput.select();
                }
            }
        });
        timeInput.addEventListener('blur', (e) => this.handleEmptyToWildcard(e, 4));
        
        // Enter key to save
        this.modal.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
                if (!this.confirmDialog.classList.contains('active')) {
                    e.preventDefault();
                    this.handleSave();
                }
            }
        });
    }

    handleWildcardExpansion(event, maxLength) {
        const input = event.target;
        const value = input.value;
        const cursorPos = input.selectionStart;
        
        // Check if the current input contains a *
        const asteriskIndex = value.indexOf('*');
        
        if (asteriskIndex !== -1) {
            // Found a *, fill the rest with *s
            const beforeAsterisk = value.substring(0, asteriskIndex);
            const remaining = maxLength - beforeAsterisk.length;
            const newValue = beforeAsterisk + '*'.repeat(remaining);
            
            input.value = newValue;
            
            // Restore cursor position (adjust if it was after the asterisk)
            const newCursorPos = Math.min(cursorPos, asteriskIndex + 1);
            input.setSelectionRange(newCursorPos, newCursorPos);
        }
    }
    
    handleEmptyToWildcard(event, maxLength) {
        const input = event.target;
        const value = input.value.trim();
        
        // If date/time is empty or cleared, fill with wildcards
        if (value === '') {
            input.value = '*'.repeat(maxLength);
        }
    }

    open(key = null, value = null, prefillKey = null) {
        this.currentOriginalKey = key;
        const isAdd = (key === null);
        document.getElementById('modal-title').innerText = isAdd ? 'Add Schedule Entry' : 'Edit Schedule Entry';
        document.getElementById('btn-delete').style.display = isAdd ? 'none' : 'block';

        if (isAdd) {
            let dateStr, timeStr;
            
            if (prefillKey) {
                // Use the provided prefill key (YYYYMMDDHHmm)
                dateStr = prefillKey.substring(0, 8);
                timeStr = prefillKey.substring(8, 12);
            } else {
                // Calculation for default next full hour
                const now = new Date();
                const nextHour = new Date(now);
                nextHour.setHours(now.getHours() + 1, 0, 0, 0);
                
                // Format date as YYYYMMDD
                const year = nextHour.getFullYear();
                const month = String(nextHour.getMonth() + 1).padStart(2, '0');
                const day = String(nextHour.getDate()).padStart(2, '0');
                dateStr = `${year}${month}${day}`;
                
                // Format time as HHmm
                const hours = String(nextHour.getHours()).padStart(2, '0');
                timeStr = `${hours}00`;
            }
            
            document.getElementById('inp-date').value = dateStr;
            document.getElementById('inp-time').value = timeStr;
            document.getElementById('inp-watts').value = '';
            document.querySelector('input[name="val-mode"][value="fixed"]').checked = true;
            document.getElementById('group-watts').style.display = 'block';
            document.getElementById('inp-watts').disabled = false;
        } else {
            document.getElementById('inp-date').value = key.substring(0, 8);
            document.getElementById('inp-time').value = key.substring(8, 12);

            if (value === 'netzero') {
                document.querySelector('input[name="val-mode"][value="netzero"]').checked = true;
                document.getElementById('group-watts').style.display = 'block';
                document.getElementById('inp-watts').disabled = true;
                document.getElementById('inp-watts').value = '';
            } else if (value === 'netzero+') {
                document.querySelector('input[name="val-mode"][value="netzero+"]').checked = true;
                document.getElementById('group-watts').style.display = 'block';
                document.getElementById('inp-watts').disabled = true;
                document.getElementById('inp-watts').value = '';
            } else {
                document.querySelector('input[name="val-mode"][value="fixed"]').checked = true;
                document.getElementById('group-watts').style.display = 'block';
                document.getElementById('inp-watts').disabled = false;
                document.getElementById('inp-watts').value = value || '';
            }
        }
        this.modal.classList.add('active');
        
        // Auto-focus on first input for quicker editing
        setTimeout(() => {
            document.getElementById('inp-date').focus();
            document.getElementById('inp-date').select();
        }, 100);
    }

    close() {
        this.modal.classList.remove('active');
    }

    handleModeChange(mode) {
        const wattsInput = document.getElementById('inp-watts');
        if (mode === 'netzero' || mode === 'netzero+') {
            wattsInput.disabled = true;
            wattsInput.value = '';
            wattsInput.setAttribute('value', '');
        } else {
            wattsInput.disabled = false;
            // Leave watts empty when switching to fixed mode - user can enter value
        }
    }

    showConfirmDialog(message) {
        return new Promise((resolve) => {
            this.confirmResolve = resolve;
            document.getElementById('confirm-message').innerText = message;
            this.confirmDialog.classList.add('active');
        });
    }

    closeConfirmDialog(confirmed) {
        this.confirmDialog.classList.remove('active');
        if (this.confirmResolve) {
            this.confirmResolve(confirmed);
            this.confirmResolve = null;
        }
    }

    async handleDelete() {
        if (!this.currentOriginalKey) return;
        
        const confirmed = await this.showConfirmDialog('Are you sure you want to delete this entry?');
        if (!confirmed) return;

        try {
            const res = await fetch(this.apiUrl, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ key: this.currentOriginalKey })
            });
            
            if (!res.ok) {
                throw new Error(`HTTP error! status: ${res.status}`);
            }
            
            const json = await res.json();
            if (json.success) {
                this.close();
                // Call refreshData immediately without debounce to ensure UI updates
                if (typeof refreshDataImmediate !== 'undefined') {
                    await refreshDataImmediate();
                } else if (this.onSaveCallback) {
                    // Fallback to callback if immediate refresh not available
                    this.onSaveCallback();
                }
            } else {
                alert(json.error || 'Delete failed');
            }
        } catch (e) {
            console.error(e);
            alert('Delete failed');
        }
    }

    async handleSave() {
        let d = document.getElementById('inp-date').value.trim();
        let t = document.getElementById('inp-time').value.trim();
        
        // If date/time is empty, use full wildcard
        if (d === '') {
            d = '********';
        }
        if (t === '') {
            t = '****';
        }
        
        if (d.length !== 8 || t.length !== 4) return alert('Invalid Date/Time pattern length');

        const mode = document.querySelector('input[name="val-mode"]:checked').value;
        let val;
        if (mode === 'netzero') {
            val = 'netzero';
        } else if (mode === 'netzero+') {
            val = 'netzero+';
        } else {
            val = document.getElementById('inp-watts').value.trim();
            // If watts is empty or none, use 0
            if (val === '' || val === null || val === undefined) {
                val = '0';
            }
            val = parseInt(val, 10);
            if (isNaN(val)) {
                return alert('Invalid watts value');
            }
        }

        const key = d + t;
        const payload = { key, value: val };

        // Use PUT for both add and edit (originalKey is optional)
        // POST is still supported on the backend for backward compatibility
        if (this.currentOriginalKey) {
            payload.originalKey = this.currentOriginalKey;
        }

        try {
            const res = await fetch(this.apiUrl, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            });
            
            if (!res.ok) {
                throw new Error(`HTTP error! status: ${res.status}`);
            }
            
            const contentType = res.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await res.text();
                console.error('Non-JSON response:', text.substring(0, 200));
                throw new Error('Server returned non-JSON response. Check console for details.');
            }
            
            const json = await res.json();
            if (json.success) {
                this.close();
                // Call refreshData immediately without debounce to ensure UI updates
                if (typeof refreshDataImmediate !== 'undefined') {
                    await refreshDataImmediate();
                } else if (this.onSaveCallback) {
                    // Fallback to callback if immediate refresh not available
                    this.onSaveCallback();
                }
            } else {
                alert(json.error || 'Save failed');
            }
        } catch (e) {
            console.error(e);
            alert('Save failed: ' + e.message);
        }
    }
}

