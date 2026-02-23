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
        rawJsonCard: document.getElementById('raw-json-card'),
        rawJsonTextarea: document.getElementById('raw-json-textarea'),
        btnRawJson: document.getElementById('btn-raw-json'),
        btnCopyRawJson: document.getElementById('btn-copy-raw-json'),
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
        conditionsList: document.getElementById('conditions-list'),
    };

    const conditionFields = ['price', 'ranking', 'min_price', 'max_price', 'min_price_hour', 'max_price_hour', 'spread_price', 'month', 'hour', 'min_time', 'max_time'];
    const conditionOps = ['>', '>=', '<', '<=', '==', '!=', 'in'];
    const valueRefs = ['min_price', 'max_price', 'min_price_hour', 'max_price_hour', 'spread_price'];

    function cloneDeep(v) {
        return JSON.parse(JSON.stringify(v));
    }

    function setStatus(text, type) {
        els.status.className = 'status ' + (type || '');
        els.status.textContent = text || '';
    }

    function renderRawJson() {
        if (!els.rawJsonTextarea) return;
        els.rawJsonTextarea.value = JSON.stringify(state.rules, null, 2);
    }

    function normalizeRule(rule) {
        const out = {};
        out.name = String(rule.name || '').trim();
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
                '<td><code>' + escapeHtml(rule.name || '(unnamed)') + '</code></td>',
                '<td class="table-actions">',
                '<div class="rule-actions-menu">',
                '<button type="button" class="rule-actions-toggle" data-menu-toggle aria-haspopup="true" aria-expanded="false">Actions</button>',
                '<div class="rule-actions-popover" role="menu">',
                '<button type="button" data-action="edit" data-idx="' + idx + '" role="menuitem">Edit</button>',
                '<button type="button" data-action="dup" data-idx="' + idx + '" role="menuitem">Duplicate</button>',
                '<button type="button" data-action="up" data-idx="' + idx + '" role="menuitem">Move Up</button>',
                '<button type="button" data-action="down" data-idx="' + idx + '" role="menuitem">Move Down</button>',
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
        valueInp.placeholder = 'value (optional)';
        valueInp.value = condition?.value !== undefined ? String(condition.value) : '';

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
        els.inpValueMode.value = 'fixed';
        els.inpFixedValue.disabled = false;
        els.conditionsList.innerHTML = '';
    }

    function fillEditor(rule, idx) {
        state.editIndex = idx;
        els.editorTitle.textContent = 'Editing Rule #' + (idx + 1);
        els.inpName.value = rule.name || '';

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
            renderTable();
            clearEditor();
            setStatus('Loaded ' + state.rules.length + ' rules.', 'ok');
        } catch (e) {
            setStatus(e.message, 'error');
        }
    }

    function attachEvents() {
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

        els.btnReload.addEventListener('click', loadRules);

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
                if (!window.confirm('Delete rule #' + (idx + 1) + '?')) return;
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
                }
                const ok = await mutateAndPersist(function () {
                    if (isNew) {
                        state.rules.push(rule);
                    } else {
                        state.rules[state.editIndex] = rule;
                    }
                }, isNew ? 'Rule added and saved.' : 'Rule updated and saved.');
                if (ok) {
                    clearEditor();
                }
            } catch (err) {
                setStatus(err.message || 'Invalid rule.', 'error');
            }
        });
    }

    attachEvents();
    loadRules();
})();
