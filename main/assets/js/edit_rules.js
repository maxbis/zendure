(function () {
    'use strict';

    const state = {
        rules: [],
        editIndex: null,
    };

    const els = {
        status: document.getElementById('status'),
        rulesTbody: document.getElementById('rules-tbody'),
        form: document.getElementById('rule-form'),
        editorTitle: document.getElementById('editor-title'),
        btnReload: document.getElementById('btn-reload'),
        btnSaveFile: document.getElementById('btn-save-file'),
        btnNew: document.getElementById('btn-new'),
        btnAddCondition: document.getElementById('btn-add-condition'),
        btnCancel: document.getElementById('btn-cancel'),
        inpKey: document.getElementById('inp-key'),
        inpValueMode: document.getElementById('inp-value-mode'),
        inpFixedValue: document.getElementById('inp-fixed-value'),
        inpMonth: document.getElementById('inp-month'),
        inpHour: document.getElementById('inp-hour'),
        inpMinTime: document.getElementById('inp-min-time'),
        inpMaxTime: document.getElementById('inp-max-time'),
        conditionsList: document.getElementById('conditions-list'),
    };

    const conditionFields = ['price', 'month', 'hour', 'min_time', 'max_time'];
    const conditionOps = ['>', '>=', '<', '<=', '==', '!=', 'in'];

    function cloneDeep(v) {
        return JSON.parse(JSON.stringify(v));
    }

    function setStatus(text, type) {
        els.status.className = 'status ' + (type || '');
        els.status.textContent = text || '';
    }

    function normalizeRule(rule) {
        const out = {};
        out.value = rule.value;
        if (rule.key) out.key = String(rule.key);
        if (rule.month) out.month = String(rule.month);
        if (rule.hour) out.hour = String(rule.hour);
        if (rule.min_time !== undefined && rule.min_time !== null && rule.min_time !== '') {
            out.min_time = String(rule.min_time);
        }
        if (rule.max_time !== undefined && rule.max_time !== null && rule.max_time !== '') {
            out.max_time = String(rule.max_time);
        }
        if (Array.isArray(rule.conditions)) {
            out.conditions = rule.conditions
                .filter(Boolean)
                .map((c) => ({ field: c.field, op: c.op, value: c.value }));
            if (out.conditions.length === 0) {
                delete out.conditions;
            }
        }
        return out;
    }

    function ruleSummary(rule) {
        const parts = [];
        parts.push('value=' + rule.value);
        if (rule.key) parts.push('key=' + rule.key);
        if (rule.month) parts.push('month=' + rule.month);
        if (rule.hour) parts.push('hour=' + rule.hour);
        if (rule.min_time) parts.push('min_time=' + rule.min_time);
        if (rule.max_time) parts.push('max_time=' + rule.max_time);
        const condCount = Array.isArray(rule.conditions) ? rule.conditions.length : 0;
        parts.push('conditions=' + condCount);
        return parts.join(' | ');
    }

    function renderTable() {
        els.rulesTbody.innerHTML = '';
        if (state.rules.length === 0) {
            const tr = document.createElement('tr');
            tr.innerHTML = '<td colspan="3" class="muted">No rules yet.</td>';
            els.rulesTbody.appendChild(tr);
            return;
        }

        state.rules.forEach((rule, idx) => {
            const tr = document.createElement('tr');
            tr.innerHTML = [
                '<td>' + (idx + 1) + '</td>',
                '<td><code>' + escapeHtml(ruleSummary(rule)) + '</code></td>',
                '<td class="table-actions">',
                '<button type="button" data-action="edit" data-idx="' + idx + '">Edit</button>',
                '<button type="button" data-action="dup" data-idx="' + idx + '">Duplicate</button>',
                '<button type="button" data-action="up" data-idx="' + idx + '">Up</button>',
                '<button type="button" data-action="down" data-idx="' + idx + '">Down</button>',
                '<button type="button" data-action="del" data-idx="' + idx + '" class="danger">Delete</button>',
                '</td>',
            ].join('');
            els.rulesTbody.appendChild(tr);
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

        const opSel = document.createElement('select');
        conditionOps.forEach((o) => {
            const opt = document.createElement('option');
            opt.value = o;
            opt.textContent = o;
            opSel.appendChild(opt);
        });
        opSel.value = condition?.op || '>=';

        const valueInp = document.createElement('input');
        valueInp.type = 'text';
        valueInp.placeholder = 'value';
        valueInp.value = condition?.value !== undefined ? String(condition.value) : '';

        const delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'danger';
        delBtn.textContent = 'Remove';
        delBtn.addEventListener('click', function () {
            row.remove();
        });

        row.appendChild(fieldSel);
        row.appendChild(opSel);
        row.appendChild(valueInp);
        row.appendChild(delBtn);
        return row;
    }

    function clearEditor() {
        state.editIndex = null;
        els.editorTitle.textContent = 'Rule Editor';
        els.form.reset();
        els.inpKey.value = '';
        els.inpMonth.value = '';
        els.inpHour.value = '';
        els.inpMinTime.value = '';
        els.inpMaxTime.value = '';
        els.inpValueMode.value = 'fixed';
        els.inpFixedValue.disabled = false;
        els.conditionsList.innerHTML = '';
    }

    function fillEditor(rule, idx) {
        state.editIndex = idx;
        els.editorTitle.textContent = 'Editing Rule #' + (idx + 1);

        els.inpKey.value = rule.key || '';
        if (rule.value === 'netzero' || rule.value === 'netzero+') {
            els.inpValueMode.value = rule.value;
            els.inpFixedValue.value = '';
            els.inpFixedValue.disabled = true;
        } else {
            els.inpValueMode.value = 'fixed';
            els.inpFixedValue.value = String(rule.value);
            els.inpFixedValue.disabled = false;
        }
        els.inpMonth.value = rule.month || '';
        els.inpHour.value = rule.hour || '';
        els.inpMinTime.value = rule.min_time || '';
        els.inpMaxTime.value = rule.max_time || '';

        els.conditionsList.innerHTML = '';
        (rule.conditions || []).forEach((condition) => {
            els.conditionsList.appendChild(createConditionRow(condition));
        });
    }

    function readConditionRows() {
        const rows = Array.from(els.conditionsList.querySelectorAll('.condition-row'));
        const conditions = [];
        for (const row of rows) {
            const inputs = row.querySelectorAll('select, input');
            const field = inputs[0].value;
            const op = inputs[1].value;
            const valueRaw = inputs[2].value.trim();
            if (!valueRaw) {
                continue;
            }
            const isNumber = /^-?\d+(\.\d+)?$/.test(valueRaw);
            conditions.push({
                field: field,
                op: op,
                value: isNumber ? Number(valueRaw) : valueRaw,
            });
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

        const rule = { value: value };
        const key = els.inpKey.value.trim();
        if (key) rule.key = key;

        const month = els.inpMonth.value.trim();
        if (month) rule.month = month;

        const hour = els.inpHour.value.trim();
        if (hour) rule.hour = hour;

        const minTime = els.inpMinTime.value.trim();
        if (minTime) rule.min_time = minTime;

        const maxTime = els.inpMaxTime.value.trim();
        if (maxTime) rule.max_time = maxTime;

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

    async function loadRules() {
        setStatus('Loading rules...', '');
        try {
            const rules = await apiGet();
            state.rules = rules.map(normalizeRule);
            renderTable();
            clearEditor();
            setStatus('Loaded ' + state.rules.length + ' rules.', 'ok');
        } catch (e) {
            setStatus(e.message, 'error');
        }
    }

    function attachEvents() {
        els.btnReload.addEventListener('click', loadRules);

        els.btnSaveFile.addEventListener('click', async function () {
            setStatus('Saving rules...', '');
            try {
                const result = await apiSave();
                setStatus(result.message + ' (' + result.count + ' rules)', 'ok');
            } catch (e) {
                setStatus(e.message, 'error');
            }
        });

        els.btnNew.addEventListener('click', function () {
            clearEditor();
            els.editorTitle.textContent = 'New Rule';
        });

        els.inpValueMode.addEventListener('change', function () {
            const isFixed = els.inpValueMode.value === 'fixed';
            els.inpFixedValue.disabled = !isFixed;
            if (!isFixed) {
                els.inpFixedValue.value = '';
            }
        });

        els.btnAddCondition.addEventListener('click', function () {
            els.conditionsList.appendChild(createConditionRow());
        });

        els.btnCancel.addEventListener('click', function () {
            clearEditor();
        });

        els.rulesTbody.addEventListener('click', function (e) {
            const btn = e.target.closest('button[data-action]');
            if (!btn) return;
            const action = btn.getAttribute('data-action');
            const idx = Number(btn.getAttribute('data-idx'));
            if (!Number.isInteger(idx)) return;

            if (action === 'edit') {
                fillEditor(state.rules[idx], idx);
                return;
            }
            if (action === 'dup') {
                state.rules.splice(idx + 1, 0, cloneDeep(state.rules[idx]));
                renderTable();
                setStatus('Rule duplicated.', 'ok');
                return;
            }
            if (action === 'del') {
                if (!window.confirm('Delete rule #' + (idx + 1) + '?')) return;
                state.rules.splice(idx, 1);
                renderTable();
                clearEditor();
                setStatus('Rule deleted.', 'ok');
                return;
            }
            if (action === 'up') {
                moveRule(idx, -1);
                return;
            }
            if (action === 'down') {
                moveRule(idx, 1);
            }
        });

        els.form.addEventListener('submit', function (e) {
            e.preventDefault();
            try {
                const rule = readRuleFromForm();
                if (state.editIndex === null) {
                    state.rules.push(rule);
                    setStatus('Rule added.', 'ok');
                } else {
                    state.rules[state.editIndex] = rule;
                    setStatus('Rule updated.', 'ok');
                }
                renderTable();
                clearEditor();
            } catch (err) {
                setStatus(err.message || 'Invalid rule.', 'error');
            }
        });
    }

    attachEvents();
    loadRules();
})();
