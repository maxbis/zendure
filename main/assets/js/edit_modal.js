/**
 * Edit Modal Module
 * Handles the edit/add dialog for schedule entries
 */
class EditModal {
    constructor(apiUrl) {
        this.apiUrl = apiUrl;
        this.currentOriginalKey = null;
        this.scheduleEntriesByKey = {};
        this.modal = document.getElementById('edit-modal');
        this.confirmDialog = document.getElementById('confirm-dialog');
        this.powerRangeIndicator = document.getElementById('power-range-indicator');
        this.confirmResolve = null;
        
        this.init();
    }

    init() {
        this.setScheduleEntries(this.collectEntriesFromTable());

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

        // Row click to edit - use event delegation on the table wrapper
        const scheduleTable = document.querySelector('#schedule-table');
        if (scheduleTable) {
            scheduleTable.addEventListener('click', (e) => {
                const tr = e.target.closest('tr');
                if (tr && tr.dataset.key) {
                    this.open(tr.dataset.key, this.parseEntryDataset(tr));
                }
            });
        }

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
        const wattsInput = document.getElementById('inp-watts');
        const minValueInput = document.getElementById('inp-min-value');
        const maxValueInput = document.getElementById('inp-max-value');
        
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
        if (wattsInput) {
            wattsInput.addEventListener('input', () => this.updateWattsInputState());
        }
        [minValueInput, maxValueInput].forEach((input) => {
            if (input) {
                input.addEventListener('input', () => this.updateConstraintInputState(input));
            }
        });
        
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

    parseEntryDataset(row) {
        if (!row || !row.dataset) return null;
        if (row.dataset.entry) {
            try {
                const parsed = JSON.parse(row.dataset.entry);
                if (parsed && typeof parsed === 'object') {
                    return parsed;
                }
            } catch (error) {
                console.warn('Failed to parse schedule entry dataset:', error);
            }
        }
        if (!Object.prototype.hasOwnProperty.call(row.dataset, 'value')) {
            return null;
        }
        return { value: row.dataset.value };
    }

    getInitialEntry(valueOrEntry) {
        if (valueOrEntry && typeof valueOrEntry === 'object' && !Array.isArray(valueOrEntry)) {
            return { ...valueOrEntry };
        }
        if (typeof valueOrEntry === 'number') {
            return { value: valueOrEntry };
        }
        if (typeof valueOrEntry === 'string' && valueOrEntry !== '') {
            if (/^-?\d+$/.test(valueOrEntry)) {
                return { value: parseInt(valueOrEntry, 10) };
            }
            return { value: valueOrEntry };
        }
        return { value: null };
    }

    setScheduleEntries(entries) {
        const byKey = {};
        if (Array.isArray(entries)) {
            entries.forEach((item) => {
                if (!item || typeof item !== 'object' || !item.key) {
                    return;
                }
                if (item.entry && typeof item.entry === 'object' && !Array.isArray(item.entry)) {
                    byKey[String(item.key)] = { ...item.entry };
                }
            });
        }
        this.scheduleEntriesByKey = byKey;
    }

    collectEntriesFromTable() {
        const rows = Array.from(document.querySelectorAll('#schedule-table tbody tr[data-key]'));
        return rows.map((row) => ({
            key: row.dataset.key,
            entry: this.parseEntryDataset(row)
        }));
    }

    getEntryForKey(key, valueOrEntry = null) {
        const initialEntry = this.getInitialEntry(valueOrEntry);
        if (!key) {
            return initialEntry;
        }

        const canonicalEntry = this.scheduleEntriesByKey[String(key)];
        if (!canonicalEntry || typeof canonicalEntry !== 'object') {
            return initialEntry;
        }

        return { ...initialEntry, ...canonicalEntry };
    }

    clearConstraintInputs() {
        const minValueInput = document.getElementById('inp-min-value');
        const maxValueInput = document.getElementById('inp-max-value');
        if (minValueInput) {
            minValueInput.value = '';
            minValueInput.classList.remove('is-charging', 'is-discharging');
        }
        if (maxValueInput) {
            maxValueInput.value = '';
            maxValueInput.classList.remove('is-charging', 'is-discharging');
        }
        this.updatePowerRangeIndicator();
    }

    syncModeInputs(mode, options = {}) {
        const wattsGroup = document.getElementById('group-watts');
        const constraintsGroup = document.getElementById('group-constraints');
        const wattsInput = document.getElementById('inp-watts');
        const preserveConstraints = options.preserveConstraints === true;

        if (mode === 'fixed') {
            if (wattsGroup) wattsGroup.style.display = 'block';
            if (constraintsGroup) constraintsGroup.style.display = 'none';
            if (wattsInput) {
                wattsInput.disabled = false;
            }
            if (!preserveConstraints) {
                this.clearConstraintInputs();
            }
        } else if (mode === 'netzero' || mode === 'netzero+') {
            if (wattsGroup) wattsGroup.style.display = 'none';
            if (constraintsGroup) constraintsGroup.style.display = 'grid';
            if (wattsInput) {
                wattsInput.disabled = true;
                wattsInput.value = '';
                wattsInput.setAttribute('value', '');
            }
        } else {
            if (wattsGroup) wattsGroup.style.display = 'block';
            if (constraintsGroup) constraintsGroup.style.display = 'none';
            if (wattsInput) {
                wattsInput.disabled = true;
                wattsInput.value = mode === 'clear' ? '0' : '';
                wattsInput.setAttribute('value', wattsInput.value);
            }
            if (!preserveConstraints) {
                this.clearConstraintInputs();
            }
        }

        this.updateWattsInputState();
        this.updateConstraintInputState(document.getElementById('inp-min-value'));
        this.updateConstraintInputState(document.getElementById('inp-max-value'));
        this.updatePowerRangeIndicator();
    }

    open(key = null, valueOrEntry = null, prefillKey = null) {
        this.currentOriginalKey = key;
        const isAdd = (key === null);
        const clearModeInput = document.querySelector('input[name="val-mode"][value="clear"]');
        const entry = this.getEntryForKey(key, valueOrEntry);
        document.getElementById('modal-title').innerText = isAdd ? 'Add Schedule Entry' : 'Edit Schedule Entry';
        document.getElementById('btn-delete').style.display = isAdd ? 'none' : 'block';
        if (clearModeInput) {
            clearModeInput.disabled = isAdd;
        }

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
            this.clearConstraintInputs();
            document.querySelector('input[name="val-mode"][value="fixed"]').checked = true;
            this.syncModeInputs('fixed', { preserveConstraints: false });
        } else {
            document.getElementById('inp-date').value = key.substring(0, 8);
            document.getElementById('inp-time').value = key.substring(8, 12);

            const value = entry.value;
            const minValue = Object.prototype.hasOwnProperty.call(entry, 'min_power') ? entry.min_power : '';
            const maxValue = Object.prototype.hasOwnProperty.call(entry, 'max_power') ? entry.max_power : '';

            if (value === 'netzero') {
                document.querySelector('input[name="val-mode"][value="netzero"]').checked = true;
                document.getElementById('inp-min-value').value = minValue ?? '';
                document.getElementById('inp-max-value').value = maxValue ?? '';
                this.syncModeInputs('netzero', { preserveConstraints: true });
            } else if (value === 'netzero+') {
                document.querySelector('input[name="val-mode"][value="netzero+"]').checked = true;
                document.getElementById('inp-min-value').value = minValue ?? '';
                document.getElementById('inp-max-value').value = maxValue ?? '';
                this.syncModeInputs('netzero+', { preserveConstraints: true });
            } else if (value === 'auto') {
                document.querySelector('input[name="val-mode"][value="auto"]').checked = true;
                this.clearConstraintInputs();
                this.syncModeInputs('auto', { preserveConstraints: false });
            } else {
                document.querySelector('input[name="val-mode"][value="fixed"]').checked = true;
                document.getElementById('inp-watts').value = value ?? '';
                this.clearConstraintInputs();
                this.syncModeInputs('fixed', { preserveConstraints: false });
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
        this.syncModeInputs(mode, { preserveConstraints: false });
    }

    updateWattsInputState() {
        const wattsInput = document.getElementById('inp-watts');
        if (!wattsInput) return;

        wattsInput.classList.remove('is-charging', 'is-discharging');
        if (wattsInput.disabled) return;

        const rawValue = wattsInput.value.trim();
        if (rawValue === '') return;

        const numericValue = Number(rawValue);
        if (!Number.isFinite(numericValue) || numericValue === 0) return;

        wattsInput.classList.add(numericValue > 0 ? 'is-charging' : 'is-discharging');
    }

    updateConstraintInputState(input) {
        if (!input) return;

        input.classList.remove('is-charging', 'is-discharging');

        const rawValue = input.value.trim();
        if (rawValue === '') return;

        const numericValue = Number(rawValue);
        if (!Number.isFinite(numericValue) || numericValue === 0) return;

        input.classList.add(numericValue > 0 ? 'is-charging' : 'is-discharging');
        this.updatePowerRangeIndicator();
    }

    updatePowerRangeIndicator() {
        const indicator = this.powerRangeIndicator;
        if (!indicator) return;

        const minInput = document.getElementById('inp-min-value');
        const maxInput = document.getElementById('inp-max-value');
        const minRaw = minInput ? String(minInput.value || '').trim() : '';
        const maxRaw = maxInput ? String(maxInput.value || '').trim() : '';
        const mode = document.querySelector('input[name="val-mode"]:checked')?.value;
        const enabled = mode === 'netzero' || mode === 'netzero+';

        indicator.hidden = true;
        indicator.textContent = '';
        indicator.className = 'power-range-indicator';

        if (!enabled) return;

        if (minRaw === '' && maxRaw === '') {
            return;
        }

        const minValid = minRaw === '' || /^-?\d+$/.test(minRaw);
        const maxValid = maxRaw === '' || /^-?\d+$/.test(maxRaw);
        if (!minValid || !maxValid) {
            indicator.hidden = false;
            indicator.textContent = 'Invalid range';
            indicator.classList.add('is-invalid');
            return;
        }

        const minValue = minRaw === '' ? null : parseInt(minRaw, 10);
        const maxValue = maxRaw === '' ? null : parseInt(maxRaw, 10);

        if (minValue !== null && maxValue !== null && minValue > maxValue) {
            indicator.hidden = false;
            indicator.textContent = 'Invalid range';
            indicator.classList.add('is-invalid');
            return;
        }

        indicator.hidden = false;

        if (minValue === null || maxValue === null) {
            indicator.textContent = minValue !== null
                ? `Minimum bound only: ${minValue} W`
                : `Maximum bound only: ${maxValue} W`;
            indicator.classList.add('is-partial');
            return;
        }

        if (minValue < 0 && maxValue < 0) {
            indicator.textContent = `Discharge-only range: ${minValue} to ${maxValue} W`;
            indicator.classList.add('is-discharge');
            return;
        }

        if (minValue > 0 && maxValue > 0) {
            indicator.textContent = `Charge-only range: ${minValue} to ${maxValue} W`;
            indicator.classList.add('is-charge');
            return;
        }

        indicator.textContent = `Bidirectional range: ${minValue} to ${maxValue} W`;
        indicator.classList.add('is-bidirectional');
    }

    getOptionalBoundValue(inputId, label) {
        const input = document.getElementById(inputId);
        if (!input) return null;

        const rawValue = input.value.trim();
        if (rawValue === '') {
            return null;
        }

        if (!/^-?\d+$/.test(rawValue)) {
            throw new Error(`Invalid ${label} value`);
        }

        const parsed = parseInt(rawValue, 10);
        if (Number.isNaN(parsed)) {
            throw new Error(`Invalid ${label} value`);
        }
        return parsed;
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

        await this.deleteEntry(this.currentOriginalKey);
    }

    async deleteEntry(key, options = {}) {
        if (!key) return false;

        const closeAfterDelete = options.closeAfterDelete !== false;

        try {
            const res = await fetch(this.apiUrl, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ key })
            });
            
            if (!res.ok) {
                throw new Error(`HTTP error! status: ${res.status}`);
            }
            
            const json = await res.json();
            console.log('Delete response:', json);
            if (json.success) {
                if (closeAfterDelete) {
                    this.close();
                }
                console.log('Delete successful, refreshing data...');
                if (typeof window.refreshScheduleAndPricesImmediate === 'function') {
                    await window.refreshScheduleAndPricesImmediate();
                    console.log('Data refreshed successfully');
                } else {
                    console.warn('refreshScheduleAndPricesImmediate not available, reloading page');
                    window.location.reload();
                }
                return true;
            } else {
                alert(json.error || 'Delete failed');
            }
        } catch (e) {
            console.error(e);
            alert('Delete failed');
        }

        return false;
    }

    async handleSave() {
        console.log('[EditModal] handleSave called');
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
        let minValue = null;
        let maxValue = null;
        if (mode === 'netzero') {
            val = 'netzero';
            minValue = this.getOptionalBoundValue('inp-min-value', 'minimum power limit');
            maxValue = this.getOptionalBoundValue('inp-max-value', 'maximum power limit');
        } else if (mode === 'netzero+') {
            val = 'netzero+';
            minValue = this.getOptionalBoundValue('inp-min-value', 'minimum power limit');
            maxValue = this.getOptionalBoundValue('inp-max-value', 'maximum power limit');
        } else if (mode === 'auto') {
            val = 'auto';
        } else if (mode === 'clear') {
            if (!this.currentOriginalKey) {
                return;
            }
            await this.deleteEntry(this.currentOriginalKey);
            return;
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

        if (minValue !== null && maxValue !== null && minValue > maxValue) {
            return alert('Minimum power limit cannot be greater than maximum power limit');
        }

        const key = d + t;
        const payload = { key, entry: { value: val } };
        if (minValue !== null) {
            payload.entry.min_power = minValue;
        }
        if (maxValue !== null) {
            payload.entry.max_power = maxValue;
        }

        // Use PUT for both add and edit (originalKey is optional).
        if (this.currentOriginalKey) {
            payload.originalKey = this.currentOriginalKey;
        }

        console.log('[EditModal] handleSave payload:', payload);
        await this.submitPayload(payload);
    }

    async applyQuickMode(key, mode, options = {}) {
        if (!key || typeof key !== 'string') return;
        let val;
        if (mode === 'netzero') {
            val = 'netzero';
        } else if (mode === 'netzero+') {
            val = 'netzero+';
        } else if (mode === 'auto') {
            val = 'auto';
        } else if (mode === 'clear') {
            if (options.originalKey) {
                await this.deleteEntry(options.originalKey, { closeAfterDelete: false });
            }
            return;
        } else {
            return;
        }

        const payload = { key, entry: { value: val } };
        if (options.originalKey) {
            payload.originalKey = options.originalKey;
        }

        console.log('[EditModal] applyQuickMode payload:', payload);
        await this.submitPayload(payload);
    }

    async submitPayload(payload) {
        try {
            console.log('[EditModal] submitPayload PUT to', this.apiUrl, 'payload:', payload);
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
            console.log('Save response:', json);
            if (json.success) {
                this.close();
                console.log('Save successful, refreshing data...');
                if (typeof window.refreshScheduleAndPricesImmediate === 'function') {
                    await window.refreshScheduleAndPricesImmediate();
                    console.log('Data refreshed successfully');
                } else {
                    console.warn('refreshScheduleAndPricesImmediate not available, reloading page');
                    window.location.reload();
                }
            } else {
                alert(json.error || 'Save failed');
            }
        } catch (e) {
            console.error('Save error:', e);
            alert('Save failed: ' + e.message);
        }
    }
}
