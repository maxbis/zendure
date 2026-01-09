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
        document.getElementById('edit-modal').onclick = (e) => {
            if (e.target === this.modal) this.close();
        };

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

        // Close confirmation dialog on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.confirmDialog.classList.contains('active')) {
                this.closeConfirmDialog(false);
            }
        });

        // Save handler
        document.getElementById('btn-save').onclick = () => this.handleSave();

        // Wildcard expansion handlers
        const dateInput = document.getElementById('inp-date');
        const timeInput = document.getElementById('inp-time');
        
        dateInput.addEventListener('input', (e) => this.handleWildcardExpansion(e, 8));
        timeInput.addEventListener('input', (e) => this.handleWildcardExpansion(e, 4));
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
            document.getElementById('inp-watts').value = '0';
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
                document.getElementById('inp-watts').value = value;
            }
        }
        this.modal.classList.add('active');
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
            if (wattsInput.value === '') {
                wattsInput.value = '0';
            }
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
                if (this.onSaveCallback) this.onSaveCallback();
            } else {
                alert(json.error || 'Delete failed');
            }
        } catch (e) {
            console.error(e);
            alert('Delete failed');
        }
    }

    async handleSave() {
        const d = document.getElementById('inp-date').value.trim();
        const t = document.getElementById('inp-time').value.trim();
        if (d.length !== 8 || t.length !== 4) return alert('Invalid Date/Time pattern length');

        const mode = document.querySelector('input[name="val-mode"]:checked').value;
        let val;
        if (mode === 'netzero') {
            val = 'netzero';
        } else if (mode === 'netzero+') {
            val = 'netzero+';
        } else {
            val = document.getElementById('inp-watts').value;
            if (val === '') return alert('Enter watts value');
            val = parseInt(val);
        }

        const key = d + t;
        const payload = { key, value: val };

        let method = 'POST';
        if (this.currentOriginalKey) {
            method = 'PUT';
            payload.originalKey = this.currentOriginalKey;
        }

        try {
            const res = await fetch(this.apiUrl, {
                method: method,
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
                if (this.onSaveCallback) this.onSaveCallback();
            } else {
                alert(json.error || 'Save failed');
            }
        } catch (e) {
            console.error(e);
            alert('Save failed: ' + e.message);
        }
    }
}

