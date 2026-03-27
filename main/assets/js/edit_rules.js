(function () {
    'use strict';

    const state = {
        rules: [],
        editIndex: null,
        initialRuleIndex: Number.isInteger(window.EDIT_RULES_INITIAL_RULE) ? window.EDIT_RULES_INITIAL_RULE - 1 : null,
        hasPendingImportedRules: false,
    };

    const els = {
        status: document.getElementById('status'),
        rulesTbody: document.getElementById('rules-tbody'),
        form: document.getElementById('rule-form'),
        editorTitle: document.getElementById('editor-title'),
        rawJsonCard: document.getElementById('raw-json-card'),
        rawJsonTextarea: document.getElementById('raw-json-textarea'),
        rulesFilePath: document.getElementById('rules-file-path'),
        importJsonInput: document.getElementById('import-json-input'),
        btnExportJson: document.getElementById('btn-export-json'),
        btnImportJson: document.getElementById('btn-import-json'),
        btnSaveImported: document.getElementById('btn-save-imported'),
        btnRawJson: document.getElementById('btn-raw-json'),
        btnCopyRawJson: document.getElementById('btn-copy-raw-json'),
        btnCopyFilePath: document.getElementById('btn-copy-file-path'),
        btnCloseRawJson: document.getElementById('btn-close-raw-json'),
        btnReload: document.getElementById('btn-reload'),
        btnNew: document.getElementById('btn-new'),
        btnAddCondition: document.getElementById('btn-add-condition'),
        btnCancel: document.getElementById('btn-cancel'),
        inpName: document.getElementById('inp-name'),
        inpValueMode: document.getElementById('inp-value-mode'),
        inpFixedValue: document.getElementById('inp-fixed-value'),
        inpMonth: document.getElementById('inp-month'),
        inpHour: document.getElementById('inp-hour'),
        inpMinTime: document.getElementById('inp-min-time'),
        inpMaxTime: document.getElementById('inp-max-time'),
        inpMinValue: document.getElementById('inp-min-value'),
        inpMaxValue: document.getElementById('inp-max-value'),
        inpFallbackValue: document.getElementById('inp-fallback-value'),
        powerRangeIndicator: document.getElementById('power-range-indicator'),
        conditionsList: document.getElementById('conditions-list'),
    };

    const conditionFields = [
        'price', 'ranking', 'min_price', 'max_price', 'min_price_hour', 'max_price_hour', 'spread_price',
        'sunrise_hour', 'sunset_hour', 'sunrise_offset_hour', 'sunset_offset_hour',
        'month', 'hour', 'min_time', 'max_time', 'electricity_level'
    ];
    const conditionOps = ['>', '>=', '<', '<=', '==', '!=', 'in'];
    const valueRefs = [
        'min_price', 'max_price', 'min_price_hour', 'max_price_hour', 'spread_price',
        'sunrise_hour', 'sunset_hour'
    ];
    const editorHelpTexts = {
        'inp-name': 'Rule name shown in the rules list and source labels.',
        'inp-value-mode': 'Select output mode: fixed watts, netzero, or netzero+.',
        'inp-fixed-value': 'Used only when Value Mode is Fixed. Positive = charge, negative = discharge.',
        'inp-month': 'Optional month filter. Comma-separated values 1-12 (e.g. 10,11,12,1,2,3).',
        'inp-hour': 'Optional hour filter. Comma-separated values 0-23 (e.g. 1,2,17,18).',
        'inp-min-time': 'Optional lower time bound in hour format (0-23).',
        'inp-max-time': 'Optional upper time bound in hour format (0-23).',
        'inp-min-value': 'Optional signed minimum power for netzero and netzero+ rules only. Negative = discharge, positive = charge. Spinner uses 100 W steps.',
        'inp-max-value': 'Optional signed maximum power for netzero and netzero+ rules only. Negative = discharge, positive = charge. Spinner uses 100 W steps.',
        'inp-fallback-value': 'Optional value when runtime conditions fail: number, netzero, or netzero+.',
    };
    const trackedFieldIds = [
        'inp-name',
        'inp-value-mode',
        'inp-fixed-value',
        'inp-month',
        'inp-hour',
        'inp-min-time',
        'inp-max-time',
        'inp-min-value',
        'inp-max-value',
        'inp-fallback-value',
    ];
    const optionalFieldIds = [
        'inp-month',
        'inp-hour',
        'inp-min-time',
        'inp-max-time',
        'inp-min-value',
        'inp-max-value',
        'inp-fallback-value',
    ];

    function cloneDeep(v) {
        return JSON.parse(JSON.stringify(v));
    }

    function setStatus(text, type) {
        els.status.className = 'status ' + (type || '');
        els.status.textContent = text || '';
    }

    function updatePendingImportState() {
        if (!els.btnSaveImported) return;
        els.btnSaveImported.hidden = !state.hasPendingImportedRules;
        els.btnSaveImported.disabled = !state.hasPendingImportedRules;
    }

    function renderRawJson() {
        if (!els.rawJsonTextarea) return;
        els.rawJsonTextarea.value = JSON.stringify(state.rules, null, 2);
    }

    function applyEditorHelpTooltips() {
        Object.entries(editorHelpTexts).forEach(function (entry) {
            const inputId = entry[0];
            const helpText = entry[1];
            const input = document.getElementById(inputId);
            if (!input) return;
            input.title = helpText;
            const label = document.querySelector('label[for="' + inputId + '"]');
            if (label) {
                label.title = helpText;
            }
        });
    }

    function normalizeRule(rule) {
        const out = {};
        out.name = String(rule.name || '').trim();
        out.value = rule.value;
        out.enabled = rule.enabled !== false;
        if (rule.key) out.key = String(rule.key);
        if (rule.month) out.month = String(rule.month);
        if (rule.hour) out.hour = String(rule.hour);
        if (rule.min_time !== undefined && rule.min_time !== null && rule.min_time !== '') {
            out.min_time = String(rule.min_time);
        }
        if (rule.max_time !== undefined && rule.max_time !== null && rule.max_time !== '') {
            out.max_time = String(rule.max_time);
        }
        if (rule.value === 'netzero' || rule.value === 'netzero+') {
            out.min_power = rule.min_power !== undefined && rule.min_power !== null && rule.min_power !== ''
                ? Number(rule.min_power)
                : null;
            out.max_power = rule.max_power !== undefined && rule.max_power !== null && rule.max_power !== ''
                ? Number(rule.max_power)
                : null;
        }
        if (rule.fallback_value !== undefined && rule.fallback_value !== null && rule.fallback_value !== '') {
            out.fallback_value = rule.fallback_value;
        }
        if (Array.isArray(rule.conditions)) {
            out.conditions = rule.conditions
                .filter(Boolean)
                .map((c) => {
                    const cc = { field: c.field, op: c.op };
                    if (c.value !== undefined) cc.value = c.value;
                    if (c.value_ref !== undefined && c.value_ref !== '') cc.value_ref = c.value_ref;
                    return cc;
                });
            if (out.conditions.length === 0) {
                delete out.conditions;
            }
        }
        return out;
    }

    function updateFieldState(inputId) {
        const input = document.getElementById(inputId);
        if (!input) return;
        const label = document.querySelector('label[for="' + inputId + '"]');
        const hasValue = String(input.value || '').trim() !== '';
        const isOptional = optionalFieldIds.includes(inputId);
        input.classList.toggle('is-field-filled', hasValue);
        if (label && isOptional) {
            label.classList.toggle('is-optional-filled', hasValue);
        }
    }

    function updateAllFieldStates() {
        trackedFieldIds.forEach(updateFieldState);
        updatePowerInputState(els.inpMinValue);
        updatePowerInputState(els.inpMaxValue);
        updatePowerRangeIndicator();
    }

    function updatePowerInputState(input) {
        if (!input) return;

        input.classList.remove('is-charging', 'is-discharging');

        const rawValue = String(input.value || '').trim();
        if (rawValue === '') return;

        const numericValue = Number(rawValue);
        if (!Number.isFinite(numericValue) || numericValue === 0) return;

        input.classList.add(numericValue > 0 ? 'is-charging' : 'is-discharging');
    }

    function updatePowerRangeIndicator() {
        if (!els.powerRangeIndicator) return;

        const indicator = els.powerRangeIndicator;
        const minRaw = String(els.inpMinValue?.value || '').trim();
        const maxRaw = String(els.inpMaxValue?.value || '').trim();
        const enabled = !els.inpMinValue.disabled && !els.inpMaxValue.disabled;

        indicator.hidden = true;
        indicator.textContent = '';
        indicator.className = 'power-range-indicator';

        if (!enabled) return;
        if (minRaw === '' && maxRaw === '') return;

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
            if (minValue !== null) {
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

    function renderTable() {
        els.rulesTbody.innerHTML = '';
        if (state.rules.length === 0) {
            const tr = document.createElement('tr');
            tr.innerHTML = '<td colspan="4" class="muted">No rules yet.</td>';
            els.rulesTbody.appendChild(tr);
            return;
        }

        state.rules.forEach((rule, idx) => {
            const hasMinLimit = rule.min_power !== undefined && rule.min_power !== null && rule.min_power !== '';
            const hasMaxLimit = rule.max_power !== undefined && rule.max_power !== null && rule.max_power !== '';
            const hasLimits = hasMinLimit || hasMaxLimit;
            const limitLabel = hasMinLimit && hasMaxLimit
                ? ('Limits: ' + rule.min_power + ' to ' + rule.max_power + ' W')
                : (hasMinLimit ? ('Limit: min ' + rule.min_power + ' W') : (hasMaxLimit ? ('Limit: max ' + rule.max_power + ' W') : ''));
            const indicatorHtml = hasLimits
                ? '<span class="rule-limit-indicator" aria-hidden="true"></span>'
                : '';
            const nameTitle = hasLimits
                ? ' title="' + escapeHtml(limitLabel) + '"'
                : '';
            const enabledAttr = rule.enabled === false ? '' : ' checked';
            const isFirst = idx === 0;
            const isLast = idx === state.rules.length - 1;
            const upDisabled = isFirst ? ' disabled aria-disabled="true" title="Already first rule"' : '';
            const downDisabled = isLast ? ' disabled aria-disabled="true" title="Already last rule"' : '';
            const tr = document.createElement('tr');
            tr.setAttribute('data-row-idx', String(idx));
            if (state.editIndex === idx) {
                tr.classList.add('is-selected');
            }
            tr.innerHTML = [
                '<td class="enabled-cell"><input type="checkbox" data-action="toggle-enabled" data-idx="' + idx + '"' + enabledAttr + ' aria-label="Enable rule ' + escapeHtml(rule.name || ('#' + (idx + 1))) + '"></td>',
                '<td>' + (idx + 1) + '</td>',
                '<td><button type="button" class="rule-name-button" data-action="edit" data-idx="' + idx + '"' + nameTitle + '>' + indicatorHtml + '<code>' + escapeHtml(rule.name || '(unnamed)') + '</code></button></td>',
                '<td class="table-actions">',
                '<div class="rule-actions-menu">',
                '<button type="button" class="rule-actions-toggle" data-menu-toggle aria-haspopup="true" aria-expanded="false" aria-label="Open actions for rule #' + (idx + 1) + '" title="More actions">⋯</button>',
                '<div class="rule-actions-popover" role="menu">',
                '<button type="button" data-action="edit" data-idx="' + idx + '" role="menuitem">Edit</button>',
                '<button type="button" data-action="dup" data-idx="' + idx + '" role="menuitem">Duplicate</button>',
                '<button type="button" data-action="up" data-idx="' + idx + '" role="menuitem"' + upDisabled + '>Move Up</button>',
                '<button type="button" data-action="down" data-idx="' + idx + '" role="menuitem"' + downDisabled + '>Move Down</button>',
                '<div class="rule-actions-separator" role="separator" aria-hidden="true"></div>',
                '<button type="button" data-action="del" data-idx="' + idx + '" class="danger" role="menuitem">Delete</button>',
                '</div>',
                '</div>',
                '</td>',
            ].join('');
            els.rulesTbody.appendChild(tr);
        });
        renderRawJson();
    }

    function closeActionMenus() {
        const menus = els.rulesTbody.querySelectorAll('.rule-actions-menu.open');
        menus.forEach(function (menu) {
            menu.classList.remove('open');
            const toggle = menu.querySelector('[data-menu-toggle]');
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    function escapeHtml(s) {
        const div = document.createElement('div');
        div.textContent = String(s);
        return div.innerHTML;
    }

    function createConditionRow(condition) {
        const row = document.createElement('div');
        row.className = 'condition-row';

        const fieldSel = document.createElement('select');
        conditionFields.forEach((f) => {
            const opt = document.createElement('option');
            opt.value = f;
            opt.textContent = f;
            fieldSel.appendChild(opt);
        });
        fieldSel.value = condition?.field || 'price';
        fieldSel.title = 'Condition field to evaluate (price, ranking, electricity_level, sun fields, etc.).';

        const opSel = document.createElement('select');
        conditionOps.forEach((o) => {
            const opt = document.createElement('option');
            opt.value = o;
            opt.textContent = o;
            opSel.appendChild(opt);
        });
        opSel.value = condition?.op || '>=';
        opSel.title = 'Comparison operator used for this condition.';

        const valueInp = document.createElement('input');
        valueInp.type = 'text';
        valueInp.placeholder = 'value (optional)';
        valueInp.value = condition?.value !== undefined ? String(condition.value) : '';
        valueInp.title = 'Static value to compare against. Leave empty when using value_ref.';

        const valueRefSel = document.createElement('select');
        const valueRefNone = document.createElement('option');
        valueRefNone.value = '';
        valueRefNone.textContent = 'value_ref (none)';
        valueRefSel.appendChild(valueRefNone);
        valueRefs.forEach((r) => {
            const opt = document.createElement('option');
            opt.value = r;
            opt.textContent = r;
            valueRefSel.appendChild(opt);
        });
        valueRefSel.value = condition?.value_ref || '';
        valueRefSel.title = 'Optional dynamic reference value (for example min_price or sunset_hour).';

        const delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'danger condition-remove-btn';
        delBtn.setAttribute('aria-label', 'Remove condition');
        delBtn.innerHTML = [
            '<svg class="condition-remove-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">',
            '<path d="M3 6h18" />',
            '<path d="M8 6V4h8v2" />',
            '<path d="M6 6l1 14h10l1-14" />',
            '<path d="M10 10v7" />',
            '<path d="M14 10v7" />',
            '</svg>',
        ].join('');
        delBtn.title = 'Remove this condition row.';
        delBtn.addEventListener('click', function () {
            row.remove();
        });

        row.appendChild(fieldSel);
        row.appendChild(opSel);
        row.appendChild(valueInp);
        row.appendChild(valueRefSel);
        row.appendChild(delBtn);
        return row;
    }

    function clearEditor() {
        state.editIndex = null;
        els.editorTitle.textContent = 'Rule Editor';
        els.form.reset();
        els.inpName.value = '';
        els.inpMonth.value = '';
        els.inpHour.value = '';
        els.inpMinTime.value = '';
        els.inpMaxTime.value = '';
        els.inpMinValue.value = '';
        els.inpMaxValue.value = '';
        els.inpFallbackValue.value = '';
        els.inpValueMode.value = 'fixed';
        els.inpFixedValue.disabled = false;
        els.inpMinValue.disabled = true;
        els.inpMaxValue.disabled = true;
        els.conditionsList.innerHTML = '';
        updateAllFieldStates();
        updatePowerRangeIndicator();
        renderTable();
    }

    function resetImportedFileInput() {
        if (els.importJsonInput) {
            els.importJsonInput.value = '';
        }
    }

    function updateValueModeFields() {
        const isFixed = els.inpValueMode.value === 'fixed';
        const allowBounds = !isFixed;
        els.inpFixedValue.disabled = !isFixed;
        if (!isFixed) {
            els.inpFixedValue.value = '';
        }
        els.inpMinValue.disabled = !allowBounds;
        els.inpMaxValue.disabled = !allowBounds;
        if (!allowBounds) {
            els.inpMinValue.value = '';
            els.inpMaxValue.value = '';
        }
        updateAllFieldStates();
        updatePowerRangeIndicator();
    }

    function fillEditor(rule, idx) {
        state.editIndex = idx;
        const safeName = String(rule?.name || '').trim();
        els.editorTitle.textContent = safeName ? ('Editing Rule #' + (idx + 1) + ' · ' + safeName) : ('Editing Rule #' + (idx + 1));
        els.inpName.value = rule.name || '';

        if (rule.value === 'netzero' || rule.value === 'netzero+') {
            els.inpValueMode.value = rule.value;
            els.inpFixedValue.value = '';
            els.inpFixedValue.disabled = true;
        } else {
            els.inpValueMode.value = 'fixed';
            els.inpFixedValue.value = String(rule.value);
        }
        els.inpMonth.value = rule.month || '';
        els.inpHour.value = rule.hour || '';
        els.inpMinTime.value = rule.min_time || '';
        els.inpMaxTime.value = rule.max_time || '';
        els.inpMinValue.value = rule.min_power !== undefined && rule.min_power !== null ? String(rule.min_power) : '';
        els.inpMaxValue.value = rule.max_power !== undefined && rule.max_power !== null ? String(rule.max_power) : '';
        els.inpFallbackValue.value = rule.fallback_value !== undefined ? String(rule.fallback_value) : '';
        updateValueModeFields();
        updateAllFieldStates();
        updatePowerRangeIndicator();

        els.conditionsList.innerHTML = '';
        (rule.conditions || []).forEach((condition) => {
            els.conditionsList.appendChild(createConditionRow(condition));
        });
        renderTable();
    }

    function applyInitialRuleSelection() {
        if (!Number.isInteger(state.initialRuleIndex)) return false;
        const idx = state.initialRuleIndex;
        state.initialRuleIndex = null;
        if (idx < 0 || idx >= state.rules.length || !state.rules[idx]) {
            setStatus('Requested rule not found.', 'error');
            return false;
        }
        fillEditor(state.rules[idx], idx);
        const row = els.rulesTbody.querySelector('tr[data-row-idx="' + idx + '"]');
        if (row && typeof row.scrollIntoView === 'function') {
            row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
        setStatus('Loaded ' + state.rules.length + ' rules. Focused rule #' + (idx + 1) + '.', 'ok');
        return true;
    }

    function readConditionRows() {
        const rows = Array.from(els.conditionsList.querySelectorAll('.condition-row'));
        const conditions = [];
        for (const row of rows) {
            const inputs = row.querySelectorAll('select, input');
            const field = inputs[0].value;
            const op = inputs[1].value;
            const valueRaw = inputs[2].value.trim();
            const valueRef = inputs[3].value;
            if (!valueRaw && !valueRef) {
                continue;
            }
            const condition = {
                field: field,
                op: op,
            };
            if (valueRaw) {
                const isNumber = /^-?\d+(\.\d+)?$/.test(valueRaw);
                condition.value = isNumber ? Number(valueRaw) : valueRaw;
            }
            if (valueRef) {
                condition.value_ref = valueRef;
            }
            conditions.push(condition);
        }
        return conditions;
    }

    function readRuleFromForm() {
        let value;
        const mode = els.inpValueMode.value;
        if (mode === 'netzero' || mode === 'netzero+') {
            value = mode;
        } else {
            const raw = els.inpFixedValue.value.trim();
            if (raw === '') {
                throw new Error('Fixed value is required when mode is "fixed".');
            }
            const n = Number(raw);
            if (!Number.isFinite(n)) {
                throw new Error('Fixed value must be numeric.');
            }
            value = Math.trunc(n);
        }

        const name = els.inpName.value.trim();
        if (!name) {
            throw new Error('Name is required.');
        }

        const rule = { name: name, value: value };

        const month = els.inpMonth.value.trim();
        if (month) rule.month = month;

        const hour = els.inpHour.value.trim();
        if (hour) rule.hour = hour;

        const minTime = els.inpMinTime.value.trim();
        if (minTime) rule.min_time = minTime;

        const maxTime = els.inpMaxTime.value.trim();
        if (maxTime) rule.max_time = maxTime;

        if (mode === 'netzero' || mode === 'netzero+') {
            const minValue = els.inpMinValue.value.trim();
            const maxValue = els.inpMaxValue.value.trim();
            rule.min_power = null;
            rule.max_power = null;
            if (minValue) {
                if (!/^-?\d+$/.test(minValue)) {
                    throw new Error('Min power must be an integer.');
                }
                rule.min_power = parseInt(minValue, 10);
            }
            if (maxValue) {
                if (!/^-?\d+$/.test(maxValue)) {
                    throw new Error('Max power must be an integer.');
                }
                rule.max_power = parseInt(maxValue, 10);
            }
            if (
                rule.min_power !== null &&
                rule.max_power !== null &&
                rule.min_power > rule.max_power
            ) {
                throw new Error('Min power cannot be greater than max power.');
            }
        }

        const fallbackRaw = els.inpFallbackValue.value.trim();
        if (fallbackRaw) {
            if (fallbackRaw === 'netzero' || fallbackRaw === 'netzero+') {
                rule.fallback_value = fallbackRaw;
            } else {
                const fallbackNumber = Number(fallbackRaw);
                if (!Number.isFinite(fallbackNumber)) {
                    throw new Error('Fallback value must be numeric, netzero, or netzero+.');
                }
                rule.fallback_value = Math.trunc(fallbackNumber);
            }
        }

        const conditions = readConditionRows();
        if (conditions.length > 0) {
            rule.conditions = conditions;
        }

        return normalizeRule(rule);
    }

    function moveRule(idx, delta) {
        const target = idx + delta;
        if (target < 0 || target >= state.rules.length) return;
        const tmp = state.rules[idx];
        state.rules[idx] = state.rules[target];
        state.rules[target] = tmp;
        renderTable();
    }

    async function apiGet() {
        const res = await fetch(window.EDIT_RULES_API_URL, { method: 'GET' });
        const data = await res.json();
        if (!res.ok || !data.success) {
            throw new Error(data.error || 'Failed to load rules.');
        }
        return data.rules || [];
    }

    async function apiSave() {
        const res = await fetch(window.EDIT_RULES_API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ rules: state.rules }),
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            throw new Error(data.error || 'Failed to save rules.');
        }
        return data;
    }

    async function saveRulesToFile(successMessage) {
        try {
            const result = await apiSave();
            state.hasPendingImportedRules = false;
            updatePendingImportState();
            setStatus(successMessage || (result.message + ' (' + result.count + ' rules)'), 'ok');
            return true;
        } catch (e) {
            setStatus(e.message, 'error');
            return false;
        }
    }

    async function mutateAndPersist(mutator, successMessage) {
        const before = cloneDeep(state.rules);
        mutator();
        renderTable();
        const ok = await saveRulesToFile(successMessage);
        if (!ok) {
            state.rules = before;
            renderTable();
        }
        return ok;
    }

    async function loadRules() {
        setStatus('Loading rules...', '');
        try {
            const rules = await apiGet();
            state.rules = rules.map(normalizeRule);
            state.hasPendingImportedRules = false;
            updatePendingImportState();
            resetImportedFileInput();
            renderTable();
            clearEditor();
            if (!applyInitialRuleSelection()) {
                setStatus('Loaded ' + state.rules.length + ' rules.', 'ok');
            }
        } catch (e) {
            setStatus(e.message, 'error');
        }
    }

    function getExportFilename() {
        const now = new Date();
        const parts = [
            now.getFullYear(),
            String(now.getMonth() + 1).padStart(2, '0'),
            String(now.getDate()).padStart(2, '0'),
            '-',
            String(now.getHours()).padStart(2, '0'),
            String(now.getMinutes()).padStart(2, '0'),
            String(now.getSeconds()).padStart(2, '0'),
        ];
        return 'charge_schedule_conditions-' + parts.join('') + '.json';
    }

    function downloadRulesJson() {
        const payload = JSON.stringify(state.rules, null, 2);
        const blob = new Blob([payload], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = getExportFilename();
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(function () {
            URL.revokeObjectURL(url);
        }, 0);
        setStatus('Exported ' + state.rules.length + ' rules to JSON.', 'ok');
    }

    function normalizeImportedRules(rawRules) {
        if (!Array.isArray(rawRules)) {
            throw new Error('Imported JSON must be an array of rules.');
        }
        const normalized = rawRules
            .filter(function (rule) {
                return !!rule && typeof rule === 'object';
            })
            .map(normalizeRule)
            .filter(function (rule) {
                if (!rule || !rule.name) return false;
                if (rule.value === 'netzero' || rule.value === 'netzero+') return true;
                return Number.isFinite(Number(rule.value));
            });
        return normalized;
    }

    function applyImportedRules(normalizedRules, originalCount) {
        state.rules = normalizedRules;
        state.hasPendingImportedRules = true;
        updatePendingImportState();
        clearEditor();
        resetImportedFileInput();
        const droppedCount = Math.max(0, originalCount - normalizedRules.length);
        const message = droppedCount > 0
            ? ('Imported ' + normalizedRules.length + ' of ' + originalCount + ' rules. ' + droppedCount + ' invalid entries were skipped. Save Imported Rules to persist.')
            : ('Imported ' + normalizedRules.length + ' rules locally. Click Save Imported Rules to persist.');
        setStatus(message, droppedCount > 0 ? 'error' : 'ok');
    }

    function readImportedFile(file) {
        return new Promise(function (resolve, reject) {
            const reader = new FileReader();
            reader.onload = function () {
                resolve(String(reader.result || ''));
            };
            reader.onerror = function () {
                reject(new Error('Failed to read the selected JSON file.'));
            };
            reader.readAsText(file);
        });
    }

    async function importRulesFromFile(file) {
        if (!file) return;
        const previousRules = cloneDeep(state.rules);
        const previousEditIndex = state.editIndex;
        const previousPending = state.hasPendingImportedRules;
        try {
            const rawText = await readImportedFile(file);
            let parsed;
            try {
                parsed = JSON.parse(rawText);
            } catch (e) {
                throw new Error('Imported file does not contain valid JSON.');
            }
            const originalCount = Array.isArray(parsed) ? parsed.length : 0;
            const normalizedRules = normalizeImportedRules(parsed);
            state.editIndex = previousEditIndex;
            applyImportedRules(normalizedRules, originalCount);
        } catch (e) {
            state.rules = previousRules;
            state.editIndex = previousEditIndex;
            state.hasPendingImportedRules = previousPending;
            updatePendingImportState();
            resetImportedFileInput();
            renderTable();
            setStatus(e.message || 'Failed to import rules JSON.', 'error');
        }
    }

    function attachEvents() {
        applyEditorHelpTooltips();
        updatePendingImportState();

        if (els.btnExportJson) {
            els.btnExportJson.addEventListener('click', function () {
                downloadRulesJson();
            });
        }

        if (els.btnImportJson && els.importJsonInput) {
            els.btnImportJson.addEventListener('click', function () {
                els.importJsonInput.click();
            });
            els.importJsonInput.addEventListener('change', function (e) {
                const file = e.target.files && e.target.files[0] ? e.target.files[0] : null;
                importRulesFromFile(file);
            });
        }

        if (els.btnSaveImported) {
            els.btnSaveImported.addEventListener('click', async function () {
                if (!state.hasPendingImportedRules) return;
                await saveRulesToFile('Imported rules saved.');
            });
        }

        els.btnRawJson.addEventListener('click', function () {
            renderRawJson();
            els.rawJsonCard.hidden = false;
        });

        els.btnCloseRawJson.addEventListener('click', function () {
            els.rawJsonCard.hidden = true;
        });

        els.btnCopyRawJson.addEventListener('click', async function () {
            try {
                await navigator.clipboard.writeText(els.rawJsonTextarea.value || '');
                setStatus('Raw JSON copied.', 'ok');
            } catch (e) {
                setStatus('Failed to copy raw JSON.', 'error');
            }
        });

        if (els.btnCopyFilePath && els.rulesFilePath) {
            els.btnCopyFilePath.addEventListener('click', async function () {
                const filePath = (els.rulesFilePath.textContent || '').trim();
                if (!filePath) return;
                try {
                    await navigator.clipboard.writeText(filePath);
                    setStatus('File path copied.', 'ok');
                } catch (e) {
                    setStatus('Failed to copy file path.', 'error');
                }
            });
        }

        els.btnReload.addEventListener('click', loadRules);

        els.btnNew.addEventListener('click', function () {
            clearEditor();
            els.editorTitle.textContent = 'New Rule';
        });

        els.inpValueMode.addEventListener('change', function () {
            updateValueModeFields();
        });

        trackedFieldIds.forEach(function (inputId) {
            const input = document.getElementById(inputId);
            if (!input) return;
            input.addEventListener('input', function () {
                updateFieldState(inputId);
                if (inputId === 'inp-min-value' || inputId === 'inp-max-value' || inputId === 'inp-value-mode') {
                    updatePowerInputState(input);
                    updatePowerRangeIndicator();
                }
            });
            input.addEventListener('change', function () {
                updateFieldState(inputId);
                if (inputId === 'inp-min-value' || inputId === 'inp-max-value' || inputId === 'inp-value-mode') {
                    updatePowerInputState(input);
                    updatePowerRangeIndicator();
                }
            });
        });

        els.btnAddCondition.addEventListener('click', function () {
            els.conditionsList.appendChild(createConditionRow());
        });

        els.btnCancel.addEventListener('click', function () {
            clearEditor();
        });

        els.rulesTbody.addEventListener('click', async function (e) {
            const toggleBtn = e.target.closest('button[data-menu-toggle]');
            if (toggleBtn) {
                const menu = toggleBtn.closest('.rule-actions-menu');
                const isOpen = menu && menu.classList.contains('open');
                closeActionMenus();
                if (menu && !isOpen) {
                    menu.classList.add('open');
                    toggleBtn.setAttribute('aria-expanded', 'true');
                }
                return;
            }

            const row = e.target.closest('tr[data-row-idx]');
            if (
                row &&
                !e.target.closest('button[data-action]') &&
                !e.target.closest('input[data-action="toggle-enabled"]') &&
                !e.target.closest('.rule-actions-popover')
            ) {
                const rowIdx = Number(row.getAttribute('data-row-idx'));
                if (Number.isInteger(rowIdx) && state.rules[rowIdx]) {
                    fillEditor(state.rules[rowIdx], rowIdx);
                    return;
                }
            }

            const btn = e.target.closest('button[data-action]');
            if (!btn) return;
            closeActionMenus();
            const action = btn.getAttribute('data-action');
            const idx = Number(btn.getAttribute('data-idx'));
            if (!Number.isInteger(idx)) return;

            if (action === 'edit') {
                fillEditor(state.rules[idx], idx);
                return;
            }
            if (action === 'dup') {
                await mutateAndPersist(function () {
                    state.rules.splice(idx + 1, 0, cloneDeep(state.rules[idx]));
                }, 'Rule duplicated and saved.');
                return;
            }
            if (action === 'del') {
                const ruleName = state.rules[idx]?.name ? (' "' + state.rules[idx].name + '"') : '';
                if (!window.confirm('Delete rule #' + (idx + 1) + ruleName + '?')) return;
                const ok = await mutateAndPersist(function () {
                    state.rules.splice(idx, 1);
                }, 'Rule deleted and saved.');
                if (ok) {
                    clearEditor();
                }
                return;
            }
            if (action === 'up') {
                await mutateAndPersist(function () {
                    moveRule(idx, -1);
                }, 'Rule order updated and saved.');
                return;
            }
            if (action === 'down') {
                await mutateAndPersist(function () {
                    moveRule(idx, 1);
                }, 'Rule order updated and saved.');
            }
        });

        els.rulesTbody.addEventListener('change', async function (e) {
            const toggle = e.target.closest('input[data-action="toggle-enabled"]');
            if (!toggle) return;
            const idx = Number(toggle.getAttribute('data-idx'));
            if (!Number.isInteger(idx)) return;
            const nextEnabled = !!toggle.checked;
            await mutateAndPersist(function () {
                if (!state.rules[idx]) return;
                state.rules[idx].enabled = nextEnabled;
            }, nextEnabled ? 'Rule enabled and saved.' : 'Rule disabled and saved.');
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('.rule-actions-menu')) {
                closeActionMenus();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeActionMenus();
            }
        });

        els.form.addEventListener('submit', async function (e) {
            e.preventDefault();
            try {
                const rule = readRuleFromForm();
                const isNew = state.editIndex === null;
                if (!isNew) {
                    const existing = state.rules[state.editIndex];
                    if (existing && existing.key) {
                        rule.key = existing.key;
                    }
                    if (existing) {
                        rule.enabled = existing.enabled !== false;
                    }
                }
                const ok = await mutateAndPersist(function () {
                    if (isNew) {
                        state.rules.push(rule);
                    } else {
                        state.rules[state.editIndex] = rule;
                    }
                }, isNew ? 'Rule added and saved.' : 'Rule updated and saved.');
                if (ok) {
                    if (isNew) {
                        clearEditor();
                    } else {
                        fillEditor(state.rules[state.editIndex], state.editIndex);
                    }
                }
            } catch (err) {
                setStatus(err.message || 'Invalid rule.', 'error');
            }
        });
    }

    attachEvents();
    updateValueModeFields();
    updateAllFieldStates();
    loadRules();
})();
