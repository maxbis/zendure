(function () {
    'use strict';

    const state = {
        rules: [],
        ruleProfiles: {
            active_profile_id: 'show_all',
            profiles: [],
        },
        editIndex: null,
        selectedProfileId: 'show_all',
        editingProfileId: null,
        initialRuleIndex: Number.isInteger(window.EDIT_RULES_INITIAL_RULE) ? window.EDIT_RULES_INITIAL_RULE - 1 : null,
        hasPendingImportedRules: false,
        pageWasHidden: false,
        isRefreshingForFreshData: false,
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
        inpColor: document.getElementById('inp-color'),
        inpMonth: document.getElementById('inp-month'),
        inpHour: document.getElementById('inp-hour'),
        inpMinTime: document.getElementById('inp-min-time'),
        inpMaxTime: document.getElementById('inp-max-time'),
        limitsRow: document.getElementById('limits-row'),
        limitsOff: document.getElementById('limits-off'),
        limitsOn: document.getElementById('limits-on'),
        limitsSliderPanel: document.getElementById('limits-slider-panel'),
        limitsSlider: document.getElementById('limits-slider'),
        limitsSelectedRange: document.getElementById('limits-selected-range'),
        limitsMinRange: document.getElementById('limits-min-range'),
        limitsMaxRange: document.getElementById('limits-max-range'),
        limitsMinDisplay: document.getElementById('limits-min-display'),
        limitsMaxDisplay: document.getElementById('limits-max-display'),
        inpMinValue: document.getElementById('inp-min-value'),
        inpMaxValue: document.getElementById('inp-max-value'),
        fallbackRow: document.getElementById('fallback-row'),
        inpFallbackValue: document.getElementById('inp-fallback-value'),
        powerRangeIndicator: document.getElementById('power-range-indicator'),
        conditionsList: document.getElementById('conditions-list'),
        profileButtonBar: document.getElementById('profile-button-bar'),
        profileEditor: document.getElementById('profile-editor'),
        inpProfileShortName: document.getElementById('inp-profile-short-name'),
        inpProfileDescription: document.getElementById('inp-profile-description'),
        profileRuleMembership: document.getElementById('profile-rule-membership'),
        profileSelectionStatus: document.getElementById('profile-selection-status'),
        btnActivateProfile: document.getElementById('btn-activate-profile'),
        btnSaveProfile: document.getElementById('btn-save-profile'),
    };

    const conditionFields = [
        'price', 'ranking', 'min_price', 'max_price', 'min_price_hour', 'max_price_hour', 'spread_price',
        'sunrise_hour', 'sunset_hour', 'sunrise_offset_hour', 'sunset_offset_hour',
        'month', 'hour', 'min_time', 'max_time', 'electricity_level'
    ];
    const conditionOps = ['>', '>=', '<', '<=', '==', '!=', 'in'];
    const valueRefs = [
        'min_price', 'max_price', 'min_price_hour', 'max_price_hour',
        'max_price_hour_am', 'max_price_hour_pm', 'spread_price',
        'sunrise_hour', 'sunset_hour'
    ];
    const editorHelpTexts = {
        'inp-name': 'Rule name shown in the rules list and source labels.',
        'inp-value-mode': 'Select output mode: fixed watts, netzero, netzero-, or netzero+. Netzero+ limits are charge-only, netzero- limits are discharge-only.',
        'inp-fixed-value': 'Used only when Value Mode is Fixed. Positive = charge, negative = discharge.',
        'inp-color': 'Optional hex color override for graph bars when this rule is active, for example #FF7043.',
        'inp-month': 'Optional month filter. Comma-separated values 1-12 (e.g. 10,11,12,1,2,3).',
        'inp-hour': 'Optional hour filter. Comma-separated values 0-23 (e.g. 1,2,17,18).',
        'inp-min-time': 'Optional lower time bound in hour format (0-23).',
        'inp-max-time': 'Optional upper time bound in hour format (0-23).',
        'inp-min-value': 'Optional minimum power for dynamic rules only. Netzero allows the full signed range, netzero+ only allows 0 W and above, and netzero- only allows 0 W and below. Slider uses 100 W steps.',
        'inp-max-value': 'Optional maximum power for dynamic rules only. Netzero allows the full signed range, netzero+ only allows 0 W and above, and netzero- only allows 0 W and below. Slider uses 100 W steps.',
        'inp-fallback-value': 'Optional value when runtime conditions fail.',
    };
    const trackedFieldIds = [
        'inp-name',
        'inp-value-mode',
        'inp-fixed-value',
        'inp-color',
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
        'inp-color',
        'inp-hour',
        'inp-min-time',
        'inp-max-time',
        'inp-min-value',
        'inp-max-value',
        'inp-fallback-value',
    ];
    const runtimeOnlyConditionFields = new Set(['electricity_level', 'electric_level', 'electricLevel']);
    const EDIT_RULES_CONFIG = window.EDIT_RULES_CONFIG || {};
    const LIMIT_MIN = Number.isFinite(Number(EDIT_RULES_CONFIG.limitMin)) ? Number(EDIT_RULES_CONFIG.limitMin) : -1200;
    const LIMIT_MAX = Number.isFinite(Number(EDIT_RULES_CONFIG.limitMax)) ? Number(EDIT_RULES_CONFIG.limitMax) : 1200;
    const LIMIT_STEP = 100;
    const SHOW_ALL_PROFILE_ID = 'show_all';

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
        els.rawJsonTextarea.value = JSON.stringify({
            rules: state.rules,
            rule_profiles: state.ruleProfiles,
        }, null, 2);
    }

    function generateRuleId() {
        return 'rule_' + Math.random().toString(16).slice(2) + Date.now().toString(16);
    }

    function normalizeRuleProfiles(config, rules) {
        const validRuleIds = new Set((rules || []).map(function (rule) {
            return rule && rule.rule_id ? String(rule.rule_id) : '';
        }).filter(Boolean));
        const defaultProfiles = [
            { id: 'profile_a', short_name: 'A', description: '', rule_ids: [] },
            { id: 'profile_b', short_name: 'B', description: '', rule_ids: [] },
            { id: 'profile_c', short_name: 'C', description: '', rule_ids: [] },
            { id: 'profile_d', short_name: 'D', description: '', rule_ids: [] },
            { id: 'profile_e', short_name: 'E', description: '', rule_ids: [] },
        ];
        const incoming = config && Array.isArray(config.profiles) ? config.profiles : [];
        const profilesById = {};

        incoming.forEach(function (profile) {
            if (!profile || typeof profile !== 'object') return;
            const id = String(profile.id || '').trim();
            if (!id || id === SHOW_ALL_PROFILE_ID) return;
            const seen = new Set();
            const ruleIds = Array.isArray(profile.rule_ids) ? profile.rule_ids.filter(function (ruleId) {
                const normalizedId = String(ruleId || '').trim();
                if (!normalizedId || !validRuleIds.has(normalizedId) || seen.has(normalizedId)) {
                    return false;
                }
                seen.add(normalizedId);
                return true;
            }).map(function (ruleId) {
                return String(ruleId).trim();
            }) : [];
            profilesById[id] = {
                id: id,
                short_name: String(profile.short_name || '').trim() || id,
                description: String(profile.description || '').trim(),
                rule_ids: ruleIds,
            };
        });

        defaultProfiles.forEach(function (profile) {
            if (!profilesById[profile.id]) {
                profilesById[profile.id] = cloneDeep(profile);
            } else if (!profilesById[profile.id].short_name) {
                profilesById[profile.id].short_name = profile.short_name;
            }
        });

        const orderedProfiles = defaultProfiles.map(function (profile) {
            return profilesById[profile.id];
        });

        Object.keys(profilesById).forEach(function (id) {
            if (!orderedProfiles.some(function (profile) { return profile.id === id; })) {
                orderedProfiles.push(profilesById[id]);
            }
        });

        const activeProfileId = config && typeof config.active_profile_id === 'string'
            ? String(config.active_profile_id).trim()
            : SHOW_ALL_PROFILE_ID;
        const normalizedActiveId = activeProfileId === SHOW_ALL_PROFILE_ID ||
            orderedProfiles.some(function (profile) { return profile.id === activeProfileId; })
            ? activeProfileId
            : SHOW_ALL_PROFILE_ID;

        return {
            active_profile_id: normalizedActiveId,
            profiles: orderedProfiles,
        };
    }

    function getActiveProfileId() {
        return state.selectedProfileId || state.ruleProfiles.active_profile_id || SHOW_ALL_PROFILE_ID;
    }

    function getRuntimeActiveProfileId() {
        return state.ruleProfiles.active_profile_id || SHOW_ALL_PROFILE_ID;
    }

    function getProfileLabel(profileId) {
        if (profileId === SHOW_ALL_PROFILE_ID) {
            return 'Show All';
        }
        const profile = getProfileById(profileId);
        if (!profile) {
            return profileId || 'Unknown';
        }
        return profile.short_name || profile.id;
    }

    function ensureSelectedProfileId() {
        const selectedProfileId = state.selectedProfileId || '';
        if (
            selectedProfileId === SHOW_ALL_PROFILE_ID ||
            state.ruleProfiles.profiles.some(function (profile) { return profile.id === selectedProfileId; })
        ) {
            return;
        }
        state.selectedProfileId = getRuntimeActiveProfileId();
    }

    function getProfileById(profileId) {
        return state.ruleProfiles.profiles.find(function (profile) {
            return profile.id === profileId;
        }) || null;
    }

    function ruleMatchesSelectedProfile(rule) {
        const activeProfileId = getActiveProfileId();
        if (activeProfileId === SHOW_ALL_PROFILE_ID) {
            return true;
        }
        const profile = getProfileById(activeProfileId);
        return !!(profile && Array.isArray(profile.rule_ids) && profile.rule_ids.includes(rule.rule_id));
    }

    function getVisibleRules() {
        return state.rules
            .map(function (rule, idx) {
                return { rule: rule, idx: idx };
            })
            .filter(function (entry) {
                return ruleMatchesSelectedProfile(entry.rule);
            });
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
        out.rule_id = String(rule.rule_id || '').trim() || generateRuleId();
        out.name = String(rule.name || '').trim();
        out.value = rule.value;
        out.enabled = rule.enabled !== false;
        const normalizedColor = normalizeRuleColor(rule.color);
        if (normalizedColor) out.color = normalizedColor;
        if (rule.key) out.key = String(rule.key);
        if (rule.month) out.month = String(rule.month);
        if (rule.hour) out.hour = String(rule.hour);
        if (rule.min_time !== undefined && rule.min_time !== null && rule.min_time !== '') {
            out.min_time = String(rule.min_time);
        }
        if (rule.max_time !== undefined && rule.max_time !== null && rule.max_time !== '') {
            out.max_time = String(rule.max_time);
        }
        if (isDynamicLimitMode(rule.value)) {
            const normalizedLimits = normalizeLimitPair(
                rule.min_power !== undefined && rule.min_power !== null && rule.min_power !== '' ? Number(rule.min_power) : null,
                rule.max_power !== undefined && rule.max_power !== null && rule.max_power !== '' ? Number(rule.max_power) : null,
                rule.value
            );
            out.min_power = normalizedLimits.minValue;
            out.max_power = normalizedLimits.maxValue;
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
        updatePowerInputState(els.inpFixedValue);
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

    function isDynamicLimitMode(mode) {
        return mode === 'netzero' || mode === 'netzero-' || mode === 'netzero+';
    }

    function getLimitBoundsForMode(mode) {
        if (mode === 'netzero+') {
            return { min: 0, max: LIMIT_MAX };
        }
        if (mode === 'netzero-') {
            return { min: LIMIT_MIN, max: 0 };
        }
        return { min: LIMIT_MIN, max: LIMIT_MAX };
    }

    function snapLimitValue(rawValue, mode = (els.inpValueMode ? els.inpValueMode.value : 'netzero'), fallbackValue = 0) {
        const numericValue = Number(rawValue);
        if (!Number.isFinite(numericValue)) return fallbackValue;
        const snapped = Math.round(numericValue / LIMIT_STEP) * LIMIT_STEP;
        const bounds = getLimitBoundsForMode(mode);
        return Math.min(bounds.max, Math.max(bounds.min, snapped));
    }

    function normalizeLimitPair(minValue, maxValue, mode = (els.inpValueMode ? els.inpValueMode.value : 'netzero'), options = {}) {
        const bounds = getLimitBoundsForMode(mode);
        let normalizedMin = minValue === null ? null : snapLimitValue(minValue, mode, bounds.min);
        let normalizedMax = maxValue === null ? null : snapLimitValue(maxValue, mode, bounds.max);

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

    function parseStoredLimitValue(input, fallbackValue, mode = (els.inpValueMode ? els.inpValueMode.value : 'netzero')) {
        if (!input) return fallbackValue;
        const rawValue = String(input.value || '').trim();
        if (rawValue === '') return fallbackValue;
        return snapLimitValue(rawValue, mode, fallbackValue);
    }

    function formatLimitValue(value, mode = (els.inpValueMode ? els.inpValueMode.value : 'netzero')) {
        const numericValue = snapLimitValue(value, mode);
        return numericValue > 0 ? ('+' + numericValue + ' W') : (numericValue + ' W');
    }

    function setLimitHiddenInput(input, value, mode = (els.inpValueMode ? els.inpValueMode.value : 'netzero')) {
        if (!input) return;
        if (value === null || value === undefined || value === '') {
            input.value = '';
        } else {
            const bounds = getLimitBoundsForMode(mode);
            const fallbackValue = input.id === 'inp-min-value' ? bounds.min : bounds.max;
            input.value = String(snapLimitValue(value, mode, fallbackValue));
        }
        updateFieldState(input.id);
        updatePowerInputState(input);
    }

    function updateLimitsVisuals(mode = (els.inpValueMode ? els.inpValueMode.value : 'netzero')) {
        if (!els.limitsSlider || !els.limitsSelectedRange || !els.limitsMinRange || !els.limitsMaxRange) return;
        const bounds = getLimitBoundsForMode(mode);
        els.limitsMinRange.min = String(bounds.min);
        els.limitsMinRange.max = String(bounds.max);
        els.limitsMaxRange.min = String(bounds.min);
        els.limitsMaxRange.max = String(bounds.max);
        const minValue = snapLimitValue(els.limitsMinRange.value, mode, bounds.min);
        const maxValue = snapLimitValue(els.limitsMaxRange.value, mode, bounds.max);
        const totalRange = bounds.max - bounds.min;
        const startPercent = totalRange === 0 ? 0 : ((minValue - bounds.min) / totalRange) * 100;
        const endPercent = totalRange === 0 ? 100 : ((maxValue - bounds.min) / totalRange) * 100;
        els.limitsSelectedRange.style.left = startPercent + '%';
        els.limitsSelectedRange.style.width = Math.max(0, endPercent - startPercent) + '%';
        els.limitsSlider.style.setProperty('--limits-start', startPercent + '%');
        els.limitsSlider.style.setProperty('--limits-end', endPercent + '%');
        if (els.limitsMinDisplay) {
            els.limitsMinDisplay.textContent = formatLimitValue(minValue, mode);
        }
        if (els.limitsMaxDisplay) {
            els.limitsMaxDisplay.textContent = formatLimitValue(maxValue, mode);
        }
        updateLimitValueTone(els.limitsMinDisplay, minValue);
        updateLimitValueTone(els.limitsMaxDisplay, maxValue);
        updateLimitThumbTone(els.limitsMinRange, minValue);
        updateLimitThumbTone(els.limitsMaxRange, maxValue);
    }

    function updateLimitValueTone(element, value) {
        if (!element) return;
        element.classList.remove('is-negative', 'is-positive', 'is-neutral');
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

    function updateLimitThumbTone(input, value) {
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

    function syncLimitsState(options) {
        const opts = options || {};
        const mode = els.inpValueMode.value;
        const modeAllowsLimits = isDynamicLimitMode(mode);
        const limitsEnabled = modeAllowsLimits && !!els.limitsOn?.checked;
        const bounds = getLimitBoundsForMode(mode);

        if (els.limitsRow) {
            els.limitsRow.hidden = !modeAllowsLimits;
        }
        if (els.limitsSliderPanel) {
            els.limitsSliderPanel.hidden = !limitsEnabled;
        }
        if (els.limitsOff) {
            els.limitsOff.disabled = !modeAllowsLimits;
        }
        if (els.limitsOn) {
            els.limitsOn.disabled = !modeAllowsLimits;
        }
        if (els.limitsMinRange) {
            els.limitsMinRange.disabled = !limitsEnabled;
        }
        if (els.limitsMaxRange) {
            els.limitsMaxRange.disabled = !limitsEnabled;
        }

        let minValue = opts.activeThumb === 'min'
            ? snapLimitValue(els.limitsMinRange ? els.limitsMinRange.value : bounds.min, mode, bounds.min)
            : parseStoredLimitValue(els.inpMinValue, bounds.min, mode);
        let maxValue = opts.activeThumb === 'max'
            ? snapLimitValue(els.limitsMaxRange ? els.limitsMaxRange.value : bounds.max, mode, bounds.max)
            : parseStoredLimitValue(els.inpMaxValue, bounds.max, mode);

        if (opts.resetToDefaults) {
            minValue = bounds.min;
            maxValue = bounds.max;
        }

        if (limitsEnabled) {
            const normalized = normalizeLimitPair(minValue, maxValue, mode, opts);
            minValue = normalized.minValue ?? bounds.min;
            maxValue = normalized.maxValue ?? bounds.max;
            if (els.limitsMinRange) {
                els.limitsMinRange.min = String(bounds.min);
                els.limitsMinRange.max = String(bounds.max);
                els.limitsMinRange.value = String(minValue);
            }
            if (els.limitsMaxRange) {
                els.limitsMaxRange.min = String(bounds.min);
                els.limitsMaxRange.max = String(bounds.max);
                els.limitsMaxRange.value = String(maxValue);
            }
            setLimitHiddenInput(els.inpMinValue, minValue, mode);
            setLimitHiddenInput(els.inpMaxValue, maxValue, mode);
        } else {
            if (els.limitsMinRange) {
                els.limitsMinRange.min = String(bounds.min);
                els.limitsMinRange.max = String(bounds.max);
                els.limitsMinRange.value = String(bounds.min);
            }
            if (els.limitsMaxRange) {
                els.limitsMaxRange.min = String(bounds.min);
                els.limitsMaxRange.max = String(bounds.max);
                els.limitsMaxRange.value = String(bounds.max);
            }
            setLimitHiddenInput(els.inpMinValue, null, mode);
            setLimitHiddenInput(els.inpMaxValue, null, mode);
        }

        if (els.inpMinValue) {
            els.inpMinValue.disabled = !limitsEnabled;
        }
        if (els.inpMaxValue) {
            els.inpMaxValue.disabled = !limitsEnabled;
        }

        updateLimitsVisuals(mode);
        updatePowerRangeIndicator();
    }

    function updatePowerRangeIndicator() {
        if (!els.powerRangeIndicator) return;

        const indicator = els.powerRangeIndicator;
        const minRaw = String(els.inpMinValue?.value || '').trim();
        const maxRaw = String(els.inpMaxValue?.value || '').trim();
        const enabled = !els.inpMinValue.disabled && !els.inpMaxValue.disabled && !!els.limitsOn?.checked;
        const mode = els.inpValueMode.value;
        const bounds = getLimitBoundsForMode(mode);

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

    function hasRuntimeConditionRows() {
        const rows = Array.from(els.conditionsList.querySelectorAll('.condition-row'));
        return rows.some(function (row) {
            const fieldSel = row.querySelector('select');
            return fieldSel && runtimeOnlyConditionFields.has(fieldSel.value);
        });
    }

    function updateFallbackVisibility() {
        if (!els.fallbackRow || !els.inpFallbackValue) return;
        const shouldShow = hasRuntimeConditionRows();
        els.fallbackRow.hidden = !shouldShow;
        els.inpFallbackValue.disabled = !shouldShow;
        if (!shouldShow) {
            els.inpFallbackValue.value = '';
        }
        updateFallbackTone();
        updateFieldState('inp-fallback-value');
    }

    function updateFallbackTone() {
        if (!els.inpFallbackValue) return;
        const value = String(els.inpFallbackValue.value || '');
        els.inpFallbackValue.classList.remove(
            'fallback-neutral',
            'fallback-netzero',
            'fallback-netzero-minus',
            'fallback-netzero-plus',
            'fallback-negative',
            'fallback-positive'
        );

        if (value === '') {
            els.inpFallbackValue.classList.add('fallback-neutral');
            return;
        }
        if (value === 'netzero') {
            els.inpFallbackValue.classList.add('fallback-netzero');
            return;
        }
        if (value === 'netzero-') {
            els.inpFallbackValue.classList.add('fallback-netzero-minus');
            return;
        }
        if (value === 'netzero+') {
            els.inpFallbackValue.classList.add('fallback-netzero-plus');
            return;
        }

        const numericValue = Number(value);
        if (!Number.isFinite(numericValue) || numericValue === 0) {
            els.inpFallbackValue.classList.add('fallback-neutral');
            return;
        }

        els.inpFallbackValue.classList.add(numericValue < 0 ? 'fallback-negative' : 'fallback-positive');
    }

    function updateValueModeTone() {
        if (!els.inpValueMode) return;
        const value = String(els.inpValueMode.value || '');
        els.inpValueMode.classList.remove('value-mode-fixed', 'value-mode-netzero', 'value-mode-netzero-minus', 'value-mode-netzero-plus');

        if (value === 'netzero') {
            els.inpValueMode.classList.add('value-mode-netzero');
            return;
        }
        if (value === 'netzero-') {
            els.inpValueMode.classList.add('value-mode-netzero-minus');
            return;
        }
        if (value === 'netzero+') {
            els.inpValueMode.classList.add('value-mode-netzero-plus');
            return;
        }
        els.inpValueMode.classList.add('value-mode-fixed');
    }

    function renderTable() {
        els.rulesTbody.innerHTML = '';
        const visibleRules = getVisibleRules();
        if (state.rules.length === 0) {
            const tr = document.createElement('tr');
            tr.innerHTML = '<td colspan="4" class="muted">No rules yet.</td>';
            els.rulesTbody.appendChild(tr);
            return;
        }
        if (visibleRules.length === 0) {
            const tr = document.createElement('tr');
            tr.innerHTML = '<td colspan="4" class="muted">No rules in the selected profile.</td>';
            els.rulesTbody.appendChild(tr);
            renderRawJson();
            return;
        }

        visibleRules.forEach(function (entry) {
            const rule = entry.rule;
            const idx = entry.idx;
            const hasMinLimit = rule.min_power !== undefined && rule.min_power !== null && rule.min_power !== '';
            const hasMaxLimit = rule.max_power !== undefined && rule.max_power !== null && rule.max_power !== '';
            const hasLimits = hasMinLimit || hasMaxLimit;
            const limitLabel = hasMinLimit && hasMaxLimit
                ? ('Limits: ' + rule.min_power + ' to ' + rule.max_power + ' W')
                : (hasMinLimit ? ('Limit: min ' + rule.min_power + ' W') : (hasMaxLimit ? ('Limit: max ' + rule.max_power + ' W') : ''));
            const limitIndicatorHtml = hasLimits
                ? '<span class="rule-limit-indicator" aria-hidden="true"></span>'
                : '<span class="rule-limit-indicator rule-limit-indicator-placeholder" aria-hidden="true"></span>';
            const colorSwatchHtml = rule.color
                ? '<span class="rule-color-indicator" style="background:' + escapeHtml(rule.color) + ';" aria-hidden="true"></span>'
                : '';
            const nameTitle = hasLimits
                ? ' title="' + escapeHtml(limitLabel + (rule.color ? (' | Color: ' + rule.color) : '')) + '"'
                : (rule.color ? ' title="Color: ' + escapeHtml(rule.color) + '"' : '');
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
                '<td><button type="button" class="rule-name-button" data-action="edit" data-idx="' + idx + '"' + nameTitle + '>' + colorSwatchHtml + limitIndicatorHtml + '<code>' + escapeHtml(rule.name || '(unnamed)') + '</code></button></td>',
                '<td class="table-actions">',
                '<div class="rule-actions-menu">',
                '<button type="button" class="rule-actions-toggle" data-menu-toggle aria-haspopup="true" aria-expanded="false" aria-label="Open actions for rule #' + (idx + 1) + '" title="More actions">⋯</button>',
                '<div class="rule-actions-popover" role="menu">',
                '<button type="button" data-action="up" data-idx="' + idx + '" role="menuitem"' + upDisabled + '>Move Up</button>',
                '<button type="button" data-action="down" data-idx="' + idx + '" role="menuitem"' + downDisabled + '>Move Down</button>',
                '<button type="button" data-action="edit" data-idx="' + idx + '" role="menuitem">Edit</button>',
                '<button type="button" data-action="dup" data-idx="' + idx + '" role="menuitem">Duplicate</button>',
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

    function renderProfileButtons() {
        if (!els.profileButtonBar) return;
        els.profileButtonBar.innerHTML = '';

        const profiles = [{
            id: SHOW_ALL_PROFILE_ID,
            short_name: 'Show All',
            description: 'Show all rules. Individually disabled rules remain off.',
        }].concat(state.ruleProfiles.profiles || []);

        profiles.forEach(function (profile) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'profile-filter-button';
            if (getActiveProfileId() === profile.id) {
                button.classList.add('is-active');
            }
            if (getRuntimeActiveProfileId() === profile.id) {
                button.classList.add('is-live');
            }
            button.setAttribute('data-profile-id', profile.id);
            button.title = profile.description || profile.short_name || profile.id;
            button.textContent = profile.short_name || profile.id;
            els.profileButtonBar.appendChild(button);
        });
    }

    function renderProfileSelectionStatus() {
        if (!els.profileSelectionStatus) return;
        const selectedProfileId = getActiveProfileId();
        const runtimeActiveProfileId = getRuntimeActiveProfileId();
        const selectedLabel = getProfileLabel(selectedProfileId);
        const runtimeLabel = getProfileLabel(runtimeActiveProfileId);
        if (selectedProfileId === runtimeActiveProfileId) {
            els.profileSelectionStatus.textContent = 'Editing live profile: ' + selectedLabel + '.';
            return;
        }
        els.profileSelectionStatus.textContent = 'Editing profile: ' + selectedLabel + '. Live system still uses: ' + runtimeLabel + '.';
    }

    function renderProfileActivationControl() {
        if (!els.btnActivateProfile) return;
        const selectedProfileId = getActiveProfileId();
        const runtimeActiveProfileId = getRuntimeActiveProfileId();
        const isLiveSelection = selectedProfileId === runtimeActiveProfileId;
        els.btnActivateProfile.hidden = isLiveSelection;
        els.btnActivateProfile.disabled = isLiveSelection;
        els.btnActivateProfile.textContent = selectedProfileId === SHOW_ALL_PROFILE_ID
            ? 'Activate Show All'
            : 'Activate Profile';
        els.btnActivateProfile.title = isLiveSelection
            ? 'Selected profile is already live.'
            : ('Make ' + getProfileLabel(selectedProfileId) + ' the live profile.');
    }

    function renderProfileEditor() {
        if (!els.profileEditor || !els.profileRuleMembership || !els.inpProfileShortName || !els.inpProfileDescription) {
            return;
        }
        const profileId = getActiveProfileId();
        state.editingProfileId = profileId;

        if (profileId === SHOW_ALL_PROFILE_ID) {
            els.profileEditor.hidden = true;
            els.inpProfileShortName.value = '';
            els.inpProfileDescription.value = '';
            els.profileRuleMembership.innerHTML = '';
            return;
        }

        const profile = getProfileById(profileId);
        if (!profile) {
            els.profileEditor.hidden = true;
            return;
        }

        els.profileEditor.hidden = false;
        els.inpProfileShortName.value = profile.short_name || '';
        els.inpProfileDescription.value = profile.description || '';
        els.profileRuleMembership.innerHTML = '';

        state.rules.forEach(function (rule, idx) {
            const label = document.createElement('label');
            label.className = 'profile-rule-chip';

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = rule.rule_id;
            checkbox.checked = Array.isArray(profile.rule_ids) && profile.rule_ids.includes(rule.rule_id);
            checkbox.setAttribute('data-profile-rule-id', rule.rule_id);

            const text = document.createElement('span');
            text.textContent = (rule.name || ('Rule #' + (idx + 1))) + (rule.enabled === false ? ' (disabled)' : '');
            text.title = rule.rule_id;

            label.appendChild(checkbox);
            label.appendChild(text);
            els.profileRuleMembership.appendChild(label);
        });
    }

    function renderProfiles() {
        ensureSelectedProfileId();
        renderProfileButtons();
        renderProfileSelectionStatus();
        renderProfileActivationControl();
        renderProfileEditor();
    }

    function persistProfileEditorChanges() {
        const profileId = state.editingProfileId;
        if (!profileId || profileId === SHOW_ALL_PROFILE_ID) return;
        const profile = getProfileById(profileId);
        if (!profile) return;

        profile.short_name = String(els.inpProfileShortName?.value || '').trim() || profile.short_name || profile.id;
        profile.description = String(els.inpProfileDescription?.value || '').trim();
        profile.rule_ids = Array.from(els.profileRuleMembership?.querySelectorAll('input[data-profile-rule-id]:checked') || [])
            .map(function (input) { return String(input.value || '').trim(); })
            .filter(Boolean);
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
            updateFallbackVisibility();
        });

        fieldSel.addEventListener('change', function () {
            updateFallbackVisibility();
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
        els.inpColor.value = '';
        els.inpMonth.value = '';
        els.inpHour.value = '';
        els.inpMinTime.value = '';
        els.inpMaxTime.value = '';
        els.inpMinValue.value = '';
        els.inpMaxValue.value = '';
        if (els.limitsOff) els.limitsOff.checked = true;
        if (els.limitsOn) els.limitsOn.checked = false;
        if (els.limitsMinRange) els.limitsMinRange.value = String(getLimitBoundsForMode('netzero').min);
        if (els.limitsMaxRange) els.limitsMaxRange.value = String(getLimitBoundsForMode('netzero').max);
        els.inpFallbackValue.value = '';
        els.inpValueMode.value = 'fixed';
        els.inpFixedValue.disabled = false;
        els.conditionsList.innerHTML = '';
        updateFallbackVisibility();
        syncLimitsState({ resetToDefaults: true });
        els.inpColor.dispatchEvent(new Event('input', { bubbles: true }));
        els.inpColor.dispatchEvent(new Event('change', { bubbles: true }));
        updateAllFieldStates();
        renderTable();
        renderProfiles();
    }

    function resetImportedFileInput() {
        if (els.importJsonInput) {
            els.importJsonInput.value = '';
        }
    }

    function updateValueModeFields() {
        const isFixed = els.inpValueMode.value === 'fixed';
        els.inpFixedValue.disabled = !isFixed;
        if (!isFixed) {
            els.inpFixedValue.value = '';
        }
        updateValueModeTone();
        syncLimitsState();
        updateAllFieldStates();
    }

    function fillEditor(rule, idx) {
        state.editIndex = idx;
        const safeName = String(rule?.name || '').trim();
        els.editorTitle.textContent = safeName ? ('Editing Rule #' + (idx + 1) + ' · ' + safeName) : ('Editing Rule #' + (idx + 1));
        els.inpName.value = rule.name || '';

        if (isDynamicLimitMode(rule.value)) {
            els.inpValueMode.value = rule.value;
            els.inpFixedValue.value = '';
            els.inpFixedValue.disabled = true;
        } else {
            els.inpValueMode.value = 'fixed';
            els.inpFixedValue.value = String(rule.value);
        }
        els.inpColor.value = rule.color || '';
        els.inpMonth.value = rule.month || '';
        els.inpHour.value = rule.hour || '';
        els.inpMinTime.value = rule.min_time || '';
        els.inpMaxTime.value = rule.max_time || '';
        const hasLimits = (rule.min_power !== undefined && rule.min_power !== null) || (rule.max_power !== undefined && rule.max_power !== null);
        const normalizedLimits = normalizeLimitPair(
            rule.min_power !== undefined && rule.min_power !== null ? rule.min_power : null,
            rule.max_power !== undefined && rule.max_power !== null ? rule.max_power : null,
            els.inpValueMode.value
        );
        els.inpMinValue.value = normalizedLimits.minValue !== null ? String(normalizedLimits.minValue) : '';
        els.inpMaxValue.value = normalizedLimits.maxValue !== null ? String(normalizedLimits.maxValue) : '';
        if (els.limitsOff) els.limitsOff.checked = !hasLimits;
        if (els.limitsOn) els.limitsOn.checked = hasLimits;
        if (els.limitsMinRange) {
            els.limitsMinRange.value = String(normalizedLimits.minValue !== null ? normalizedLimits.minValue : normalizedLimits.bounds.min);
        }
        if (els.limitsMaxRange) {
            els.limitsMaxRange.value = String(normalizedLimits.maxValue !== null ? normalizedLimits.maxValue : normalizedLimits.bounds.max);
        }
        els.inpFallbackValue.value = rule.fallback_value !== undefined ? String(rule.fallback_value) : '';
        updateValueModeFields();
        els.conditionsList.innerHTML = '';
        (rule.conditions || []).forEach((condition) => {
            els.conditionsList.appendChild(createConditionRow(condition));
        });
        updateFallbackVisibility();
        els.inpColor.dispatchEvent(new Event('input', { bubbles: true }));
        els.inpColor.dispatchEvent(new Event('change', { bubbles: true }));
        updateAllFieldStates();
        renderTable();
        renderProfiles();
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
        if (isDynamicLimitMode(mode)) {
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
        const color = normalizeRuleColor(els.inpColor.value);
        if (String(els.inpColor.value || '').trim() !== '' && !color) {
            throw new Error('Rule color must be a hex color like #FF7043.');
        }
        if (color) {
            rule.color = color;
        }

        const month = els.inpMonth.value.trim();
        if (month) rule.month = month;

        const hour = els.inpHour.value.trim();
        if (hour) rule.hour = hour;

        const minTime = els.inpMinTime.value.trim();
        if (minTime) rule.min_time = minTime;

        const maxTime = els.inpMaxTime.value.trim();
        if (maxTime) rule.max_time = maxTime;

        if (isDynamicLimitMode(mode)) {
            rule.min_power = null;
            rule.max_power = null;
            if (els.limitsOn && els.limitsOn.checked) {
                const minValue = els.inpMinValue.value.trim();
                const maxValue = els.inpMaxValue.value.trim();
                if (!/^-?\d+$/.test(minValue) || !/^-?\d+$/.test(maxValue)) {
                    throw new Error('Power limits must be valid integers.');
                }
                rule.min_power = parseInt(minValue, 10);
                rule.max_power = parseInt(maxValue, 10);
                const normalizedLimits = normalizeLimitPair(rule.min_power, rule.max_power, mode);
                rule.min_power = normalizedLimits.minValue;
                rule.max_power = normalizedLimits.maxValue;
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
            if (fallbackRaw === 'netzero' || fallbackRaw === 'netzero-' || fallbackRaw === 'netzero+') {
                rule.fallback_value = fallbackRaw;
            } else {
                const fallbackNumber = Number(fallbackRaw);
                if (!Number.isFinite(fallbackNumber)) {
                    throw new Error('Fallback value must be one of the allowed dropdown values.');
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

    function normalizeRuleColor(value) {
        const rawValue = String(value || '').trim();
        if (!rawValue) return '';
        return /^#([0-9a-fA-F]{6})$/.test(rawValue) ? rawValue.toUpperCase() : '';
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
        return data;
    }

    async function apiSave() {
        const res = await fetch(window.EDIT_RULES_API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                rules: state.rules,
                rule_profiles: state.ruleProfiles,
            }),
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            throw new Error(data.error || 'Failed to save rules.');
        }
        return data;
    }

    async function saveRulesToFile(successMessage) {
        try {
            const selectedProfileIdBeforeSave = state.selectedProfileId || getRuntimeActiveProfileId();
            const result = await apiSave();
            if (Array.isArray(result.rules)) {
                state.rules = result.rules.map(normalizeRule);
            }
            state.ruleProfiles = normalizeRuleProfiles(result.rule_profiles || state.ruleProfiles, state.rules);
            state.selectedProfileId = selectedProfileIdBeforeSave;
            ensureSelectedProfileId();
            state.hasPendingImportedRules = false;
            updatePendingImportState();
            renderTable();
            renderProfiles();
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
            const data = await apiGet();
            const rules = Array.isArray(data.rules) ? data.rules : [];
            state.rules = rules.map(normalizeRule);
            state.ruleProfiles = normalizeRuleProfiles(data.rule_profiles || {}, state.rules);
            state.selectedProfileId = state.ruleProfiles.active_profile_id || SHOW_ALL_PROFILE_ID;
            state.hasPendingImportedRules = false;
            updatePendingImportState();
            resetImportedFileInput();
            renderTable();
            renderProfiles();
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
                if (rule.value === 'netzero' || rule.value === 'netzero-' || rule.value === 'netzero+') return true;
                return Number.isFinite(Number(rule.value));
            });
        return normalized;
    }

    function applyImportedRules(normalizedRules, originalCount) {
        state.rules = normalizedRules;
        state.ruleProfiles = normalizeRuleProfiles(state.ruleProfiles, state.rules);
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

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                state.pageWasHidden = true;
                return;
            }

            if (state.pageWasHidden && !state.isRefreshingForFreshData) {
                state.isRefreshingForFreshData = true;
                window.location.reload();
            }
        });

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

        if (els.profileButtonBar) {
            els.profileButtonBar.addEventListener('click', function (e) {
                const button = e.target.closest('button[data-profile-id]');
                if (!button) return;
                persistProfileEditorChanges();
                const nextProfileId = String(button.getAttribute('data-profile-id') || '').trim() || SHOW_ALL_PROFILE_ID;
                state.selectedProfileId = nextProfileId;
                renderTable();
                renderProfiles();
            });
        }

        if (els.btnActivateProfile) {
            els.btnActivateProfile.addEventListener('click', async function () {
                persistProfileEditorChanges();
                const selectedProfileId = getActiveProfileId();
                if (selectedProfileId === getRuntimeActiveProfileId()) {
                    renderProfiles();
                    return;
                }
                const previousActiveProfileId = getRuntimeActiveProfileId();
                state.ruleProfiles.active_profile_id = selectedProfileId;
                renderProfiles();
                const ok = await saveRulesToFile(selectedProfileId === SHOW_ALL_PROFILE_ID
                    ? 'Show All activated.'
                    : ('Rule profile activated: ' + getProfileLabel(selectedProfileId) + '.'));
                if (!ok) {
                    state.ruleProfiles.active_profile_id = previousActiveProfileId;
                    renderProfiles();
                }
            });
        }

        if (els.btnSaveProfile) {
            els.btnSaveProfile.addEventListener('click', async function () {
                persistProfileEditorChanges();
                renderProfiles();
                await saveRulesToFile('Rule profile saved.');
            });
        }

        if (els.profileRuleMembership) {
            els.profileRuleMembership.addEventListener('change', function () {
                persistProfileEditorChanges();
                renderTable();
            });
        }

        if (els.inpProfileShortName) {
            els.inpProfileShortName.addEventListener('input', function () {
                persistProfileEditorChanges();
                renderProfileButtons();
                renderProfileSelectionStatus();
                renderProfileActivationControl();
            });
        }

        if (els.inpProfileDescription) {
            els.inpProfileDescription.addEventListener('input', function () {
                persistProfileEditorChanges();
                renderProfileButtons();
                renderProfileSelectionStatus();
                renderProfileActivationControl();
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
            updateValueModeTone();
        });

        if (els.limitsOff) {
            els.limitsOff.addEventListener('change', function () {
                syncLimitsState({ resetToDefaults: false });
            });
        }

        if (els.limitsOn) {
            els.limitsOn.addEventListener('change', function () {
                syncLimitsState({ resetToDefaults: !!els.limitsOn.checked });
            });
        }

        if (els.limitsMinRange) {
            const syncMinRange = function () {
                syncLimitsState({ activeThumb: 'min' });
            };
            els.limitsMinRange.addEventListener('input', syncMinRange);
            els.limitsMinRange.addEventListener('change', syncMinRange);
        }

        if (els.limitsMaxRange) {
            const syncMaxRange = function () {
                syncLimitsState({ activeThumb: 'max' });
            };
            els.limitsMaxRange.addEventListener('input', syncMaxRange);
            els.limitsMaxRange.addEventListener('change', syncMaxRange);
        }

        els.inpFallbackValue.addEventListener('change', function () {
            updateFallbackTone();
        });

        trackedFieldIds.forEach(function (inputId) {
            const input = document.getElementById(inputId);
            if (!input) return;
            input.addEventListener('input', function () {
                updateFieldState(inputId);
                if (
                    inputId === 'inp-fixed-value' ||
                    inputId === 'inp-min-value' ||
                    inputId === 'inp-max-value' ||
                    inputId === 'inp-value-mode'
                ) {
                    updatePowerInputState(input);
                    updatePowerRangeIndicator();
                }
            });
            input.addEventListener('change', function () {
                updateFieldState(inputId);
                if (
                    inputId === 'inp-fixed-value' ||
                    inputId === 'inp-min-value' ||
                    inputId === 'inp-max-value' ||
                    inputId === 'inp-value-mode'
                ) {
                    updatePowerInputState(input);
                    updatePowerRangeIndicator();
                }
            });
        });

        els.btnAddCondition.addEventListener('click', function () {
            els.conditionsList.appendChild(createConditionRow());
            updateFallbackVisibility();
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
                    const duplicate = cloneDeep(state.rules[idx]);
                    duplicate.rule_id = generateRuleId();
                    state.rules.splice(idx + 1, 0, duplicate);
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
                    if (existing && existing.rule_id) {
                        rule.rule_id = existing.rule_id;
                    }
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
    updateValueModeTone();
    updateFallbackTone();
    syncLimitsState({ resetToDefaults: true });
    updateAllFieldStates();
    loadRules();
})();
