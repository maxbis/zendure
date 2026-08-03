/**
 * Edit Modal Module
 * Handles the edit/add dialog for schedule entries
 */
class EditModal {
    constructor(apiUrl) {
        this.apiUrl = apiUrl;
        this.currentOriginalKey = null;
        this.scheduleEntriesByKey = {};
        const sharedBounds = this.resolveSharedPowerBounds();
        this.limitMin = sharedBounds.min;
        this.limitMax = sharedBounds.max;
        this.limitStep = 100;
        this.modal = document.getElementById('edit-modal');
        this.confirmDialog = document.getElementById('confirm-dialog');
        this.powerRangeIndicator = document.getElementById('power-range-indicator');
        this.fixedWattsSlider = document.getElementById('fixed-watts-slider');
        this.fixedWattsSelectedRange = document.getElementById('fixed-watts-selected-range');
        this.fixedWattsRange = document.getElementById('fixed-watts-range');
        this.fixedWattsDisplay = document.getElementById('fixed-watts-display');
        this.fixedWattsIndicator = document.getElementById('fixed-watts-indicator');
        this.limitsSlider = document.getElementById('limits-slider');
        this.limitsSelectedRange = document.getElementById('limits-selected-range');
        this.limitsMinRange = document.getElementById('limits-min-range');
        this.limitsMaxRange = document.getElementById('limits-max-range');
        this.limitsMinDisplay = document.getElementById('limits-min-display');
        this.limitsMaxDisplay = document.getElementById('limits-max-display');
        this.limitsOff = document.getElementById('limits-off');
        this.limitsOn = document.getElementById('limits-on');
        this.confirmResolve = null;
        
        this.init();
    }

    isDynamicMode(mode) {
        return mode === 'netzero' || mode === 'netzero+' || mode === 'netzero-';
    }

    resolveSharedPowerBounds() {
        const fallbackMin = -1200;
        const fallbackMax = 2000;
        const globalMin = typeof GRID_MIN_POWER !== 'undefined' ? Number(GRID_MIN_POWER) : fallbackMin;
        const globalMax = typeof GRID_MAX_POWER !== 'undefined' ? Number(GRID_MAX_POWER) : fallbackMax;

        let min = Number.isFinite(globalMin) ? globalMin : fallbackMin;
        let max = Number.isFinite(globalMax) ? globalMax : fallbackMax;
        min = Math.min(min, 0);
        max = Math.max(max, 0);

        if (min > max) {
            return { min: fallbackMin, max: fallbackMax };
        }

        return { min, max };
    }

    init() {
        this.setScheduleEntries(this.collectEntriesFromTable());
        this.syncLimitRangeBounds();

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
        if (this.fixedWattsRange) {
            const syncFixedRange = () => this.updateWattsInputState({ source: 'slider' });
            this.fixedWattsRange.addEventListener('input', syncFixedRange);
            this.fixedWattsRange.addEventListener('change', syncFixedRange);
        }
        [minValueInput, maxValueInput].forEach((input) => {
            if (input) {
                input.addEventListener('input', () => this.updateConstraintInputState(input));
            }
        });
        if (this.limitsMinRange) {
            const syncMinRange = () => this.handleLimitRangeInput('min');
            this.limitsMinRange.addEventListener('input', syncMinRange);
            this.limitsMinRange.addEventListener('change', syncMinRange);
        }
        if (this.limitsMaxRange) {
            const syncMaxRange = () => this.handleLimitRangeInput('max');
            this.limitsMaxRange.addEventListener('input', syncMaxRange);
            this.limitsMaxRange.addEventListener('change', syncMaxRange);
        }
        if (this.limitsOff) {
            this.limitsOff.addEventListener('change', () => this.handleLimitsToggle(false));
        }
        if (this.limitsOn) {
            this.limitsOn.addEventListener('change', () => this.handleLimitsToggle(true));
        }
        
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
        this.syncConstraintSlider();
        this.updatePowerRangeIndicator();
    }

    getSelectedMode() {
        return document.querySelector('input[name="val-mode"]:checked')?.value || 'netzero';
    }

    getLimitBounds(mode = this.getSelectedMode()) {
        if (mode === 'netzero+') {
            return { min: 0, max: this.limitMax };
        }
        if (mode === 'netzero-') {
            return { min: this.limitMin, max: 0 };
        }
        return { min: this.limitMin, max: this.limitMax };
    }

    syncLimitRangeBounds(mode = this.getSelectedMode()) {
        const bounds = this.getLimitBounds(mode);
        [this.limitsMinRange, this.limitsMaxRange].forEach((input, index) => {
            if (!input) return;
            input.min = String(bounds.min);
            input.max = String(bounds.max);
            input.step = String(this.limitStep);
            const fallbackValue = index === 0 ? bounds.min : bounds.max;
            input.value = String(this.snapLimitValue(input.value, mode, fallbackValue));
        });
        return bounds;
    }

    snapLimitValue(rawValue, mode = this.getSelectedMode(), fallbackValue = 0) {
        const numericValue = Number(rawValue);
        if (!Number.isFinite(numericValue)) return fallbackValue;
        const snapped = Math.round(numericValue / this.limitStep) * this.limitStep;
        const bounds = this.getLimitBounds(mode);
        return Math.min(bounds.max, Math.max(bounds.min, snapped));
    }

    normalizeConstraintPair(minValue, maxValue, mode = this.getSelectedMode(), options = {}) {
        const bounds = this.getLimitBounds(mode);
        let normalizedMin = minValue === null ? null : this.snapLimitValue(minValue, mode, bounds.min);
        let normalizedMax = maxValue === null ? null : this.snapLimitValue(maxValue, mode, bounds.max);

        if (normalizedMin !== null && normalizedMax !== null && normalizedMin > normalizedMax) {
            if (options.activeThumb === 'min') {
                normalizedMax = normalizedMin;
            } else if (options.activeThumb === 'max') {
                normalizedMin = normalizedMax;
            } else {
                normalizedMin = normalizedMax;
            }
        }

        return {
            minValue: normalizedMin,
            maxValue: normalizedMax,
            bounds,
        };
    }

    formatLimitValue(value, mode = this.getSelectedMode()) {
        const numericValue = this.snapLimitValue(value, mode);
        return numericValue > 0 ? `+${numericValue} W` : `${numericValue} W`;
    }

    updateIndicatorTone(element, tone = 'neutral') {
        if (!element) return;
        element.classList.remove('is-charge', 'is-discharge', 'is-bidirectional', 'is-partial', 'is-invalid', 'is-neutral');
        element.classList.add(`is-${tone}`);
    }

    getConstraintInput(inputId) {
        return document.getElementById(inputId);
    }

    getStoredLimitValue(inputId, mode = this.getSelectedMode()) {
        const input = this.getConstraintInput(inputId);
        if (!input) return null;
        const rawValue = String(input.value || '').trim();
        if (rawValue === '') return null;
        const bounds = this.getLimitBounds(mode);
        return this.snapLimitValue(rawValue, mode, inputId === 'inp-min-value' ? bounds.min : bounds.max);
    }

    setConstraintValue(inputId, value, mode = this.getSelectedMode()) {
        const input = this.getConstraintInput(inputId);
        if (!input) return;
        const bounds = this.getLimitBounds(mode);
        input.value = value === null || value === undefined || value === ''
            ? ''
            : String(this.snapLimitValue(value, mode, inputId === 'inp-min-value' ? bounds.min : bounds.max));
        this.updateConstraintInputState(input);
    }

    areLimitsEnabled() {
        return !!this.limitsOn?.checked;
    }

    setLimitsEnabled(enabled, options = {}) {
        const shouldResetToDefaults = options.resetToDefaults === true;
        const mode = options.mode || this.getSelectedMode();
        const bounds = this.getLimitBounds(mode);
        if (this.limitsOn) {
            this.limitsOn.checked = !!enabled;
        }
        if (this.limitsOff) {
            this.limitsOff.checked = !enabled;
        }

        if (enabled) {
            if (shouldResetToDefaults) {
                this.setConstraintValue('inp-min-value', bounds.min, mode);
                this.setConstraintValue('inp-max-value', bounds.max, mode);
            } else {
                const minStored = this.getStoredLimitValue('inp-min-value', mode);
                const maxStored = this.getStoredLimitValue('inp-max-value', mode);
                const normalized = this.normalizeConstraintPair(minStored, maxStored, mode);
                if (minStored === null) {
                    this.setConstraintValue('inp-min-value', bounds.min, mode);
                } else {
                    this.setConstraintValue('inp-min-value', normalized.minValue, mode);
                }
                if (maxStored === null) {
                    this.setConstraintValue('inp-max-value', bounds.max, mode);
                } else {
                    this.setConstraintValue('inp-max-value', normalized.maxValue, mode);
                }
            }
        } else {
            this.setConstraintValue('inp-min-value', null, mode);
            this.setConstraintValue('inp-max-value', null, mode);
        }

        this.syncConstraintSlider({ mode });
        this.updatePowerRangeIndicator();
    }

    handleLimitsToggle(enabled) {
        this.setLimitsEnabled(enabled, { resetToDefaults: enabled });
    }

    updateLimitValueTone(element, value, isUnset = false) {
        if (!element) return;
        element.classList.remove('is-negative', 'is-positive', 'is-neutral', 'is-unset');
        if (isUnset) {
            element.classList.add('is-unset');
            return;
        }
        if (value < 0) {
            element.classList.add('is-negative');
            return;
        }
        if (value > 0) {
            element.classList.add('is-positive');
            return;
        }
        element.classList.add('is-neutral');
    }

    updateLimitThumbTone(input, value) {
        if (!input) return;
        input.classList.remove('is-negative', 'is-positive', 'is-neutral');
        if (value < 0) {
            input.classList.add('is-negative');
            return;
        }
        if (value > 0) {
            input.classList.add('is-positive');
            return;
        }
        input.classList.add('is-neutral');
    }

    syncConstraintSlider(options = {}) {
        if (!this.limitsMinRange || !this.limitsMaxRange || !this.limitsSelectedRange) {
            return;
        }

        const mode = options.mode || this.getSelectedMode();
        const bounds = this.syncLimitRangeBounds(mode);
        const minStored = this.getStoredLimitValue('inp-min-value', mode);
        const maxStored = this.getStoredLimitValue('inp-max-value', mode);
        const limitsEnabled = this.areLimitsEnabled();

        let minSliderValue = minStored === null ? bounds.min : minStored;
        let maxSliderValue = maxStored === null ? bounds.max : maxStored;

        if (options.activeThumb === 'min') {
            minSliderValue = this.snapLimitValue(this.limitsMinRange.value, mode, bounds.min);
        }
        if (options.activeThumb === 'max') {
            maxSliderValue = this.snapLimitValue(this.limitsMaxRange.value, mode, bounds.max);
        }

        const normalized = this.normalizeConstraintPair(minSliderValue, maxSliderValue, mode, options);
        minSliderValue = normalized.minValue ?? bounds.min;
        maxSliderValue = normalized.maxValue ?? bounds.max;

        this.limitsMinRange.value = String(minSliderValue);
        this.limitsMaxRange.value = String(maxSliderValue);

        const totalRange = bounds.max - bounds.min;
        const startPercent = totalRange === 0 ? 0 : ((minSliderValue - bounds.min) / totalRange) * 100;
        const endPercent = totalRange === 0 ? 100 : ((maxSliderValue - bounds.min) / totalRange) * 100;
        this.limitsSelectedRange.style.left = `${startPercent}%`;
        this.limitsSelectedRange.style.width = `${Math.max(0, endPercent - startPercent)}%`;
        if (this.limitsSlider) {
            this.limitsSlider.style.setProperty('--limits-start', `${startPercent}%`);
            this.limitsSlider.style.setProperty('--limits-end', `${endPercent}%`);
        }

        if (this.limitsMinDisplay) {
            this.limitsMinDisplay.textContent = minStored === null ? 'Unset' : this.formatLimitValue(minStored, mode);
            this.updateLimitValueTone(this.limitsMinDisplay, minSliderValue, minStored === null);
        }
        if (this.limitsMaxDisplay) {
            this.limitsMaxDisplay.textContent = maxStored === null ? 'Unset' : this.formatLimitValue(maxStored, mode);
            this.updateLimitValueTone(this.limitsMaxDisplay, maxSliderValue, maxStored === null);
        }

        this.updateLimitThumbTone(this.limitsMinRange, minSliderValue);
        this.updateLimitThumbTone(this.limitsMaxRange, maxSliderValue);

        this.limitsMinRange.disabled = !limitsEnabled;
        this.limitsMaxRange.disabled = !limitsEnabled;
        if (this.limitsSlider) {
            this.limitsSlider.classList.toggle('is-disabled', !limitsEnabled);
        }
        if (this.limitsSelectedRange) {
            this.limitsSelectedRange.hidden = !limitsEnabled;
        }
    }

    handleLimitRangeInput(which) {
        if (!this.areLimitsEnabled()) return;
        const mode = this.getSelectedMode();
        const bounds = this.getLimitBounds(mode);
        if (which === 'min') {
            const minValue = this.limitsMinRange ? this.snapLimitValue(this.limitsMinRange.value, mode, bounds.min) : bounds.min;
            const maxStored = this.getStoredLimitValue('inp-max-value', mode);
            this.setConstraintValue('inp-min-value', minValue, mode);
            if (maxStored !== null && minValue > maxStored) {
                this.setConstraintValue('inp-max-value', minValue, mode);
            }
            this.syncConstraintSlider({ activeThumb: 'min', mode });
            return;
        }

        const maxValue = this.limitsMaxRange ? this.snapLimitValue(this.limitsMaxRange.value, mode, bounds.max) : bounds.max;
        const minStored = this.getStoredLimitValue('inp-min-value', mode);
        this.setConstraintValue('inp-max-value', maxValue, mode);
        if (minStored !== null && maxValue < minStored) {
            this.setConstraintValue('inp-min-value', maxValue, mode);
        }
        this.syncConstraintSlider({ activeThumb: 'max', mode });
    }

    syncModeInputs(mode, options = {}) {
        const wattsGroup = document.getElementById('group-watts');
        const constraintsGroup = document.getElementById('group-constraints');
        const wattsInput = document.getElementById('inp-watts');
        const preserveConstraints = options.preserveConstraints === true;
        this.syncLimitRangeBounds(mode);

        if (mode === 'fixed') {
            if (wattsGroup) wattsGroup.style.display = 'block';
            if (constraintsGroup) constraintsGroup.style.display = 'none';
            if (wattsInput) {
                wattsInput.disabled = false;
            }
            if (!preserveConstraints) {
                this.clearConstraintInputs();
            }
        } else if (this.isDynamicMode(mode)) {
            if (wattsGroup) wattsGroup.style.display = 'none';
            if (constraintsGroup) constraintsGroup.style.display = 'grid';
            if (wattsInput) {
                wattsInput.disabled = true;
                wattsInput.value = '';
                wattsInput.setAttribute('value', '');
            }
            if (!preserveConstraints) {
                if (this.limitsOff) this.limitsOff.checked = true;
                if (this.limitsOn) this.limitsOn.checked = false;
            } else {
                const normalized = this.normalizeConstraintPair(
                    this.getStoredLimitValue('inp-min-value', mode),
                    this.getStoredLimitValue('inp-max-value', mode),
                    mode
                );
                this.setConstraintValue('inp-min-value', normalized.minValue, mode);
                this.setConstraintValue('inp-max-value', normalized.maxValue, mode);
                if (normalized.minValue === null && normalized.maxValue === null) {
                    if (this.limitsOff) this.limitsOff.checked = true;
                    if (this.limitsOn) this.limitsOn.checked = false;
                } else {
                    if (this.limitsOn) this.limitsOn.checked = true;
                    if (this.limitsOff) this.limitsOff.checked = false;
                }
            }
            if (!preserveConstraints) {
                this.setLimitsEnabled(false, { mode });
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
        this.syncConstraintSlider({ mode });
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
            if (this.limitsOn) this.limitsOn.checked = false;
            if (this.limitsOff) this.limitsOff.checked = true;
            document.querySelector('input[name="val-mode"][value="netzero"]').checked = true;
            this.syncModeInputs('netzero', { preserveConstraints: false });
        } else {
            document.getElementById('inp-date').value = key.substring(0, 8);
            document.getElementById('inp-time').value = key.substring(8, 12);

            const value = entry.value;
            const minValue = Object.prototype.hasOwnProperty.call(entry, 'min_power') ? entry.min_power : '';
            const maxValue = Object.prototype.hasOwnProperty.call(entry, 'max_power') ? entry.max_power : '';

            if (value === 'netzero') {
                document.querySelector('input[name="val-mode"][value="netzero"]').checked = true;
                this.setConstraintValue('inp-min-value', minValue ?? '', 'netzero');
                this.setConstraintValue('inp-max-value', maxValue ?? '', 'netzero');
                if (minValue === '' && maxValue === '') {
                    if (this.limitsOff) this.limitsOff.checked = true;
                    if (this.limitsOn) this.limitsOn.checked = false;
                } else {
                    if (this.limitsOn) this.limitsOn.checked = true;
                    if (this.limitsOff) this.limitsOff.checked = false;
                }
                this.syncModeInputs('netzero', { preserveConstraints: true });
            } else if (value === 'netzero-') {
                document.querySelector('input[name="val-mode"][value="netzero-"]').checked = true;
                this.setConstraintValue('inp-min-value', minValue ?? '', 'netzero-');
                this.setConstraintValue('inp-max-value', maxValue ?? '', 'netzero-');
                if (minValue === '' && maxValue === '') {
                    if (this.limitsOff) this.limitsOff.checked = true;
                    if (this.limitsOn) this.limitsOn.checked = false;
                } else {
                    if (this.limitsOn) this.limitsOn.checked = true;
                    if (this.limitsOff) this.limitsOff.checked = false;
                }
                this.syncModeInputs('netzero-', { preserveConstraints: true });
            } else if (value === 'netzero+') {
                document.querySelector('input[name="val-mode"][value="netzero+"]').checked = true;
                this.setConstraintValue('inp-min-value', minValue ?? '', 'netzero+');
                this.setConstraintValue('inp-max-value', maxValue ?? '', 'netzero+');
                if (minValue === '' && maxValue === '') {
                    if (this.limitsOff) this.limitsOff.checked = true;
                    if (this.limitsOn) this.limitsOn.checked = false;
                } else {
                    if (this.limitsOn) this.limitsOn.checked = true;
                    if (this.limitsOff) this.limitsOff.checked = false;
                }
                this.syncModeInputs('netzero+', { preserveConstraints: true });
            } else if (value === 'auto') {
                document.querySelector('input[name="val-mode"][value="auto"]').checked = true;
                this.clearConstraintInputs();
                if (this.limitsOn) this.limitsOn.checked = true;
                if (this.limitsOff) this.limitsOff.checked = false;
                this.syncModeInputs('auto', { preserveConstraints: false });
            } else {
                document.querySelector('input[name="val-mode"][value="fixed"]').checked = true;
                document.getElementById('inp-watts').value = value ?? '';
                this.clearConstraintInputs();
                if (this.limitsOn) this.limitsOn.checked = true;
                if (this.limitsOff) this.limitsOff.checked = false;
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
        this.syncModeInputs(mode, { preserveConstraints: this.isDynamicMode(mode) && this.areLimitsEnabled() });
    }

    updateWattsInputState(options = {}) {
        const wattsInput = document.getElementById('inp-watts');
        if (!wattsInput) return;

        if (options.source === 'slider' && this.fixedWattsRange && !wattsInput.disabled) {
            const sliderValue = this.snapLimitValue(this.fixedWattsRange.value, 'fixed', 0);
            wattsInput.value = String(sliderValue);
            wattsInput.setAttribute('value', wattsInput.value);
        }

        wattsInput.classList.remove('is-charging', 'is-discharging');
        const rawValue = wattsInput.value.trim();
        const numericValue = Number(rawValue);
        const hasFiniteValue = Number.isFinite(numericValue);
        if (!wattsInput.disabled && hasFiniteValue && numericValue !== 0) {
            wattsInput.classList.add(numericValue > 0 ? 'is-charging' : 'is-discharging');
        }

        if (!this.fixedWattsRange || !this.fixedWattsSlider || !this.fixedWattsSelectedRange || !this.fixedWattsDisplay || !this.fixedWattsIndicator) {
            return;
        }

        const sliderValue = hasFiniteValue ? this.snapLimitValue(numericValue, 'fixed', 0) : 0;
        this.fixedWattsRange.min = String(this.limitMin);
        this.fixedWattsRange.max = String(this.limitMax);
        this.fixedWattsRange.step = String(this.limitStep);
        this.fixedWattsRange.value = String(sliderValue);
        this.fixedWattsRange.disabled = wattsInput.disabled;
        this.fixedWattsSlider.classList.toggle('is-disabled', wattsInput.disabled);
        this.updateLimitThumbTone(this.fixedWattsRange, sliderValue);

        const totalRange = this.limitMax - this.limitMin;
        const centerPercent = totalRange === 0 ? 50 : ((0 - this.limitMin) / totalRange) * 100;
        const valuePercent = totalRange === 0 ? 50 : ((sliderValue - this.limitMin) / totalRange) * 100;
        const startPercent = Math.min(centerPercent, valuePercent);
        const endPercent = Math.max(centerPercent, valuePercent);
        this.fixedWattsSelectedRange.style.left = `${startPercent}%`;
        this.fixedWattsSelectedRange.style.width = `${Math.max(0, endPercent - startPercent)}%`;
        this.fixedWattsSlider.style.setProperty('--fixed-center', `${centerPercent}%`);

        const displayValue = hasFiniteValue ? numericValue : 0;
        const sliderTone = sliderValue < 0 ? 'negative' : (sliderValue > 0 ? 'positive' : 'neutral');
        const indicatorTone = displayValue < 0 ? 'discharge' : (displayValue > 0 ? 'charge' : 'neutral');

        this.fixedWattsSelectedRange.classList.remove('is-negative', 'is-positive', 'is-neutral');
        this.fixedWattsSelectedRange.classList.add(`is-${sliderTone}`);

        this.fixedWattsDisplay.textContent = this.formatLimitValue(displayValue, 'fixed');
        this.updateLimitValueTone(this.fixedWattsDisplay, displayValue, false);

        let indicatorText = `Idle: ${this.formatLimitValue(0, 'fixed')}`;
        if (displayValue < 0) {
            indicatorText = `Fixed discharge: ${this.formatLimitValue(displayValue, 'fixed')}`;
        } else if (displayValue > 0) {
            indicatorText = `Fixed charge: ${this.formatLimitValue(displayValue, 'fixed')}`;
        }
        this.fixedWattsIndicator.textContent = indicatorText;
        this.updateIndicatorTone(this.fixedWattsIndicator, indicatorTone);
    }

    updateConstraintInputState(input) {
        if (!input) return;

        input.classList.remove('is-charging', 'is-discharging');

        const rawValue = input.value.trim();
        if (rawValue === '') {
            this.updatePowerRangeIndicator();
            return;
        }

        const numericValue = Number(rawValue);
        if (!Number.isFinite(numericValue) || numericValue === 0) {
            this.updatePowerRangeIndicator();
            return;
        }

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
        const mode = this.getSelectedMode();
        const enabled = this.isDynamicMode(mode);
        const bounds = this.getLimitBounds(mode);

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

        const outOfBounds = (
            (minValue !== null && (minValue < bounds.min || minValue > bounds.max)) ||
            (maxValue !== null && (maxValue < bounds.min || maxValue > bounds.max))
        );

        if (outOfBounds || (minValue !== null && maxValue !== null && minValue > maxValue)) {
            indicator.hidden = false;
            indicator.textContent = 'Invalid range';
            indicator.classList.add('is-invalid');
            return;
        }

        indicator.hidden = false;

        if (minValue === null || maxValue === null) {
            if (minValue !== null) {
                if (mode === 'netzero+') {
                    indicator.textContent = `Charge-only from ${minValue} W`;
                    indicator.classList.add('is-charge');
                    return;
                }
                if (mode === 'netzero-') {
                    indicator.textContent = `Discharge floor: ${minValue} W`;
                    indicator.classList.add('is-discharge');
                    return;
                }
                if (minValue > 0) {
                    indicator.textContent = `Charge-only from ${minValue} W`;
                    indicator.classList.add('is-charge');
                    return;
                }
                if (minValue < 0) {
                    indicator.textContent = `Open range from ${minValue} W upward`;
                    indicator.classList.add('is-partial');
                    return;
                }
                indicator.textContent = 'Minimum bound only: 0 W';
                indicator.classList.add('is-partial');
                return;
            }

            if (maxValue !== null) {
                if (mode === 'netzero+') {
                    indicator.textContent = `Charge cap: ${maxValue} W`;
                    indicator.classList.add('is-charge');
                    return;
                }
                if (mode === 'netzero-') {
                    indicator.textContent = `Discharge-only up to ${maxValue} W`;
                    indicator.classList.add('is-discharge');
                    return;
                }
                if (maxValue < 0) {
                    indicator.textContent = `Discharge-only up to ${maxValue} W`;
                    indicator.classList.add('is-discharge');
                    return;
                }
                if (maxValue > 0) {
                    indicator.textContent = `Open range up to ${maxValue} W`;
                    indicator.classList.add('is-partial');
                    return;
                }
                indicator.textContent = 'Maximum bound only: 0 W';
                indicator.classList.add('is-partial');
                return;
            }

            return;
        }

        if (mode === 'netzero+') {
            indicator.textContent = minValue === 0
                ? `Idle-to-charge range: ${minValue} to ${maxValue} W`
                : `Charge-only range: ${minValue} to ${maxValue} W`;
            indicator.classList.add('is-charge');
            return;
        }

        if (mode === 'netzero-') {
            indicator.textContent = maxValue === 0
                ? `Discharge-to-idle range: ${minValue} to ${maxValue} W`
                : `Discharge-only range: ${minValue} to ${maxValue} W`;
            indicator.classList.add('is-discharge');
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

        if (minValue < 0 && maxValue === 0) {
            indicator.textContent = `Discharge-to-idle range: ${minValue} to ${maxValue} W`;
            indicator.classList.add('is-discharge');
            return;
        }

        if (minValue === 0 && maxValue > 0) {
            indicator.textContent = `Idle-to-charge range: ${minValue} to ${maxValue} W`;
            indicator.classList.add('is-charge');
            return;
        }

        if (minValue === 0 && maxValue === 0) {
            indicator.textContent = 'Idle only: 0 W';
            indicator.classList.add('is-partial');
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
        } else if (mode === 'netzero-') {
            val = 'netzero-';
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

        if (this.isDynamicMode(mode)) {
            const normalized = this.normalizeConstraintPair(minValue, maxValue, mode);
            minValue = normalized.minValue;
            maxValue = normalized.maxValue;
            this.setConstraintValue('inp-min-value', minValue, mode);
            this.setConstraintValue('inp-max-value', maxValue, mode);
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
        } else if (mode === 'netzero-') {
            val = 'netzero-';
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
                console.log('Save successful, forcing backend schedule refresh...');

                try {
                    if (typeof window.requestScheduleReloadBeforePageReload === 'function') {
                        await window.requestScheduleReloadBeforePageReload();
                        console.log('Backend schedule refreshed successfully');
                    } else {
                        console.warn('requestScheduleReloadBeforePageReload not available');
                    }
                } catch (refreshError) {
                    console.error('Override saved, but backend schedule refresh failed:', refreshError);
                    if (window.notifications) {
                        window.notifications.error('Override saved, but the backend schedule refresh failed');
                    }
                }

                console.log('Refreshing displayed schedule data...');
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
