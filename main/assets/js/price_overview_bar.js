/**
 * Price Overview Bar Graph
 * Renders the bar graph visualization for today and tomorrow's electricity prices
 */

/** 24 cent – used for bar height when no price data; bars stay grey and tooltip says no data */
function getPriceOverviewNumberConfig(key, fallback) {
    const value = window.PRICE_OVERVIEW_CONFIG ? window.PRICE_OVERVIEW_CONFIG[key] : undefined;
    return typeof value === 'number' && Number.isFinite(value) ? value : fallback;
}

const PRICE_PROXY_NO_DATA = getPriceOverviewNumberConfig('priceProxyNoData', 0.24);
const POPUP_POWER_EFFICIENCY = getPriceOverviewNumberConfig('popupPowerEfficiency', 0.9);
const POPUP_NETZERO_REFERENCE_W = getPriceOverviewNumberConfig('popupNetzeroReferenceW', 200);
const POPUP_NETZERO_PLUS_REFERENCE_W = getPriceOverviewNumberConfig('popupNetzeroPlusReferenceW', 300);

/**
 * Interpolates between two RGB colors
 * @param {number} r1 - Red component of first color (0-255)
 * @param {number} g1 - Green component of first color (0-255)
 * @param {number} b1 - Blue component of first color (0-255)
 * @param {number} r2 - Red component of second color (0-255)
 * @param {number} g2 - Green component of second color (0-255)
 * @param {number} b2 - Blue component of second color (0-255)
 * @param {number} factor - Interpolation factor (0.0 to 1.0)
 * @returns {string} RGB color string
 */
function interpolateColor(r1, g1, b1, r2, g2, b2, factor) {
    const r = Math.round(r1 + (r2 - r1) * factor);
    const g = Math.round(g1 + (g2 - g1) * factor);
    const b = Math.round(b1 + (b2 - b1) * factor);
    return `rgb(${r}, ${g}, ${b})`;
}

/**
 * Gets color for a price value based on min/max range
 * Green (low) to Red (high) gradient
 * @param {number|null} price - Price value or null
 * @param {number} minPrice - Minimum price in range
 * @param {number} maxPrice - Maximum price in range
 * @returns {string} RGB color string or gray for null
 */
function getPriceColor(price, minPrice, maxPrice) {
    if (price === null || price === undefined || isNaN(price)) {
        return '#606060'; // Gray for null values
    }
    
    if (minPrice === maxPrice) {
        // All prices are the same, use middle color (yellow)
        return 'rgb(255, 200, 0)';
    }
    
    // Normalize price to 0-1 range
    const normalized = (price - minPrice) / (maxPrice - minPrice);
    
    // Green (low) to Red (high)
    // Green: rgb(76, 175, 80) = #4CAF50
    // Red: rgb(244, 67, 54) = #F44336
    return interpolateColor(76, 175, 80, 244, 67, 54, normalized);

}

/**
 * Formats price for display
 * @param {number|null} price - Price value or null
 * @returns {string} Formatted price string
 */
function formatPrice(price) {
    if (price === null || price === undefined || isNaN(price)) {
        return 'N/A';
    }
    return '€' + price.toFixed(3);
}

/**
 * Formats price as cents (price * 100, rounded to 0 decimals)
 * @param {number|null} price - Price value or null
 * @returns {string} Price in cents as string, or empty string for null
 */
function formatPriceCents(price) {
    if (price === null || price === undefined || isNaN(price)) {
        return '';
    }
    return Math.round(price * 100).toString();
}

/**
 * Spot price (excl. tax) from price incl. 21% VAT: P_excl = (P_incl / 1.21) − 0.10880
 * @param {number|null} pIncl - Price incl. tax (€/kWh) or null
 * @returns {number|null} Spot price or null
 */
function spotPriceFromIncl(pIncl) {
    if (pIncl == null || typeof pIncl !== 'number' || Number.isNaN(pIncl)) return null;
    return (pIncl / 1.21) - 0.09;
}

let priceGraphPopup = null;
let priceGraphPopupActiveBar = null;
let priceGraphPopupActiveContainer = null;
const priceGraphPopupBoundContainers = new WeakSet();
let priceGraphRuleDetailModal = null;
let priceGraphRuleDetailEscapeHandler = null;

function formatHourRange(hourValue) {
    const hour = Number.isFinite(hourValue) ? hourValue : NaN;
    if (Number.isNaN(hour)) return '';
    const startHour = String(hour).padStart(2, '0');
    const endHour = String((hour + 1) % 24).padStart(2, '0');
    return `${startHour}:00 - ${endHour}:00`;
}

function getBaseWhForPopup() {
    const fallback = 5760;
    if (typeof BASE_WH === 'undefined') return fallback;
    const parsed = Number(BASE_WH);
    if (!Number.isFinite(parsed) || parsed <= 0) return fallback;
    return parsed;
}

function powerToCapacityPercent(powerW) {
    const baseWh = getBaseWhForPopup();
    const onePercentUsableWh = (baseWh / 100) * POPUP_POWER_EFFICIENCY;
    if (!Number.isFinite(onePercentUsableWh) || onePercentUsableWh <= 0) return null;
    return Math.abs(powerW) / onePercentUsableWh;
}

function formatPopupPercent(pct, opts) {
    if (pct == null || !Number.isFinite(pct)) return '';
    const decimals = (opts && Number.isInteger(opts.decimals)) ? opts.decimals : 1;
    const prefix = (opts && typeof opts.prefix === 'string') ? opts.prefix : '';
    return ` (${prefix}${pct.toFixed(decimals)}%)`;
}

function getRawScheduleEntry(scheduleEntry) {
    if (!scheduleEntry || typeof scheduleEntry !== 'object') return null;
    const entry = scheduleEntry.entry;
    if (!entry || typeof entry !== 'object' || Array.isArray(entry)) return null;
    return entry;
}

function parseOptionalScheduleBound(value) {
    if (value === undefined || value === null || value === '') return null;
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
}

function formatScheduleLimitsText(scheduleValue, minValue, maxValue, scheduleEntry) {
    if (scheduleValue !== 'netzero' && scheduleValue !== 'netzero+') {
        return '';
    }

    let resolvedMin = parseOptionalScheduleBound(minValue);
    let resolvedMax = parseOptionalScheduleBound(maxValue);

    if (resolvedMin === null || resolvedMax === null) {
        const entry = getRawScheduleEntry(scheduleEntry);
        if (entry && entry.value === scheduleValue) {
            if (resolvedMin === null) {
                resolvedMin = parseOptionalScheduleBound(entry.min_power);
            }
            if (resolvedMax === null) {
                resolvedMax = parseOptionalScheduleBound(entry.max_power);
            }
        }
    }

    const hasMin = resolvedMin !== null;
    const hasMax = resolvedMax !== null;

    if (!hasMin && !hasMax) return '';
    if (hasMin && hasMax) return `Limits: ${resolvedMin} to ${resolvedMax} W`;
    if (hasMin) return `Limits: min ${resolvedMin} W`;
    return `Limits: max ${resolvedMax} W`;
}

function escapePopupHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function formatRuleOutputValue(value) {
    if (value === 'netzero' || value === 'netzero+') {
        return String(value);
    }
    const numeric = Number(value);
    if (Number.isFinite(numeric)) {
        return `${Math.trunc(numeric)} W`;
    }
    return String(value || '—');
}

function formatRuleCondition(condition) {
    if (!condition || typeof condition !== 'object') return '';
    const field = condition.field ? String(condition.field) : '';
    const op = condition.op ? String(condition.op) : '';
    const hasValueRef = condition.value_ref !== undefined && condition.value_ref !== null && String(condition.value_ref).trim() !== '';
    const hasValue = Object.prototype.hasOwnProperty.call(condition, 'value');
    const rightSide = hasValueRef
        ? String(condition.value_ref).trim()
        : (hasValue ? String(condition.value) : '');
    return [field, op, rightSide].filter(Boolean).join(' ');
}

function getRuleLabel(ruleIndex, ruleName) {
    const hasRuleIndex = ruleIndex !== undefined &&
        ruleIndex !== null &&
        String(ruleIndex).trim() !== '';
    const hasRuleName = ruleName !== undefined &&
        ruleName !== null &&
        String(ruleName).trim() !== '';
    if (hasRuleName) {
        return `${hasRuleIndex ? ('#' + String(ruleIndex).trim() + ' ') : ''}${String(ruleName).trim()}`;
    }
    if (hasRuleIndex) {
        return `#${String(ruleIndex).trim()}`;
    }
    return '';
}

function ensurePriceGraphRuleDetailModal() {
    if (priceGraphRuleDetailModal) return priceGraphRuleDetailModal;

    const modal = document.createElement('div');
    modal.className = 'price-graph-rule-detail-modal';
    modal.setAttribute('hidden', 'hidden');
    modal.innerHTML = `
        <div class="price-graph-dialog-shell price-graph-rule-detail-dialog" role="dialog" aria-modal="true" aria-labelledby="price-graph-rule-detail-title">
            <div class="price-graph-dialog-header price-graph-rule-detail-header">
                <div class="price-graph-rule-detail-title-wrap">
                    <div class="price-graph-rule-detail-eyebrow">Rule details</div>
                    <div class="price-graph-rule-detail-title" id="price-graph-rule-detail-title"></div>
                </div>
                <button type="button" class="modal-close price-graph-rule-detail-close" aria-label="Close">&times;</button>
            </div>
            <div class="price-graph-rule-detail-body"></div>
            <div class="price-graph-dialog-footer price-graph-rule-detail-footer">
                <button type="button" class="btn btn-outline price-graph-rule-detail-dismiss">Close</button>
                <button type="button" class="btn btn-primary price-graph-rule-detail-edit">Edit Rule</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            hidePriceGraphRuleDetailModal();
        }
    });

    const closeButton = modal.querySelector('.price-graph-rule-detail-close');
    const dismissButton = modal.querySelector('.price-graph-rule-detail-dismiss');
    if (closeButton) closeButton.addEventListener('click', hidePriceGraphRuleDetailModal);
    if (dismissButton) dismissButton.addEventListener('click', hidePriceGraphRuleDetailModal);

    priceGraphRuleDetailModal = modal;
    return modal;
}

function hidePriceGraphRuleDetailModal() {
    if (!priceGraphRuleDetailModal) return;
    priceGraphRuleDetailModal.setAttribute('hidden', 'hidden');
    priceGraphRuleDetailModal.classList.remove('active');
    if (priceGraphRuleDetailEscapeHandler) {
        document.removeEventListener('keydown', priceGraphRuleDetailEscapeHandler);
        priceGraphRuleDetailEscapeHandler = null;
    }
}

function renderRuleDetailBody(rule) {
    if (!rule || typeof rule !== 'object') {
        return '<div class="price-graph-rule-detail-empty">Rule details unavailable.</div>';
    }

    const normalizedValue = typeof rule.value === 'string'
        ? rule.value.trim().toLowerCase()
        : '';
    const isDynamicOutput = normalizedValue === 'netzero' || normalizedValue === 'netzero+';
    const fields = [];
    fields.push({
        label: 'Output',
        value: escapePopupHtml(formatRuleOutputValue(rule.value))
    });
    fields.push({
        label: 'Enabled',
        value: escapePopupHtml(rule.enabled === false ? 'No' : 'Yes')
    });

    if (isDynamicOutput) {
        [
            { key: 'min_power', label: 'Min power' },
            { key: 'max_power', label: 'Max power' }
        ].forEach(({ key, label }) => {
            if (!Object.prototype.hasOwnProperty.call(rule, key) || rule[key] === '' || rule[key] === null) return;
            fields.push({
                label,
                value: escapePopupHtml(`${String(rule[key])} W`)
            });
        });
    }

    ['month', 'hour', 'min_time', 'max_time', 'fallback_value'].forEach((fieldName) => {
        if (!Object.prototype.hasOwnProperty.call(rule, fieldName) || rule[fieldName] === '' || rule[fieldName] === null) return;
        const labelMap = {
            month: 'Month',
            hour: 'Hour',
            min_time: 'Min time',
            max_time: 'Max time',
            fallback_value: 'Fallback'
        };
        const rawValue = fieldName === 'fallback_value'
            ? formatRuleOutputValue(rule[fieldName])
            : String(rule[fieldName]);
        fields.push({
            label: labelMap[fieldName],
            value: escapePopupHtml(rawValue)
        });
    });

    const fieldsHtml = fields.map((field) => `
        <div class="price-graph-rule-detail-field">
            <div class="price-graph-rule-detail-field-label">${escapePopupHtml(field.label)}</div>
            <div class="price-graph-rule-detail-field-value">${field.value}</div>
        </div>
    `).join('');

    const conditions = Array.isArray(rule.conditions) ? rule.conditions : [];
    const conditionsHtml = conditions.length > 0
        ? `
            <div class="price-graph-rule-detail-section">
                <div class="price-graph-rule-detail-section-title">Conditions</div>
                <div class="price-graph-rule-detail-condition-list">
                    ${conditions.map((condition, index) => `
                        <div class="price-graph-rule-detail-condition">
                            <span class="price-graph-rule-detail-condition-index">${index + 1}.</span>
                            <code>${escapePopupHtml(formatRuleCondition(condition) || 'Invalid condition')}</code>
                        </div>
                    `).join('')}
                </div>
            </div>
        `
        : `
            <div class="price-graph-rule-detail-section">
                <div class="price-graph-rule-detail-section-title">Conditions</div>
                <div class="price-graph-rule-detail-empty">No conditions configured.</div>
            </div>
        `;

    return `
        <div class="price-graph-rule-detail-grid">${fieldsHtml}</div>
        ${conditionsHtml}
    `;
}

async function getPriceGraphRules() {
    const response = await fetch('edit_rules.php?api=1', {
        method: 'GET',
        cache: 'no-store'
    });
    const data = await response.json();
    if (!response.ok || !data.success || !Array.isArray(data.rules)) {
        throw new Error(data.error || 'Rule details unavailable');
    }
    return data.rules;
}

function normalizeRuleOverrideColor(value) {
    const rawValue = String(value || '').trim();
    if (!rawValue) return '';
    return /^#([0-9a-fA-F]{6})$/.test(rawValue) ? rawValue.toUpperCase() : '';
}

function buildRuleColorMap(rules) {
    const colorMap = {};
    if (!Array.isArray(rules)) {
        return colorMap;
    }

    rules.forEach((rule, index) => {
        const color = normalizeRuleOverrideColor(rule?.color);
        if (!color) return;
        colorMap[String(index + 1)] = color;
    });

    return colorMap;
}

async function showPriceGraphRuleDetail(ruleIndex, ruleName) {
    const modal = ensurePriceGraphRuleDetailModal();
    const titleEl = modal.querySelector('.price-graph-rule-detail-title');
    const bodyEl = modal.querySelector('.price-graph-rule-detail-body');
    const editButton = modal.querySelector('.price-graph-rule-detail-edit');
    const safeRuleIndex = Number.parseInt(ruleIndex, 10);
    const ruleLabel = getRuleLabel(ruleIndex, ruleName) || 'Rule';

    titleEl.textContent = ruleLabel;
    bodyEl.innerHTML = '<div class="price-graph-rule-detail-empty">Loading rule details...</div>';
    modal.classList.add('active');
    modal.removeAttribute('hidden');

    if (editButton) {
        editButton.disabled = !Number.isInteger(safeRuleIndex) || safeRuleIndex < 1;
        editButton.onclick = () => {
            if (!Number.isInteger(safeRuleIndex) || safeRuleIndex < 1) return;
            window.location.href = `edit_rules.php?rule=${safeRuleIndex}`;
        };
    }

    if (!priceGraphRuleDetailEscapeHandler) {
        priceGraphRuleDetailEscapeHandler = (event) => {
            if (event.key === 'Escape') hidePriceGraphRuleDetailModal();
        };
        document.addEventListener('keydown', priceGraphRuleDetailEscapeHandler);
    }

    try {
        const rules = await getPriceGraphRules();
        if (!Number.isInteger(safeRuleIndex) || safeRuleIndex < 1 || safeRuleIndex > rules.length) {
            bodyEl.innerHTML = '<div class="price-graph-rule-detail-empty">Rule details unavailable.</div>';
            return;
        }
        bodyEl.innerHTML = renderRuleDetailBody(rules[safeRuleIndex - 1]);
    } catch (error) {
        bodyEl.innerHTML = `<div class="price-graph-rule-detail-empty">${escapePopupHtml(error.message || 'Rule details unavailable.')}</div>`;
    }
}

function renderPopupSource(sourceEl, options) {
    if (!sourceEl) return;

    const {
        scheduleSource,
        hasRuntimeCondition,
        ruleName,
        ruleIndex
    } = options || {};

    const hasSource = scheduleSource !== undefined &&
        scheduleSource !== null &&
        scheduleSource !== '' &&
        scheduleSource !== 'null' &&
        scheduleSource !== 'undefined';
    const normalizedScheduleSource = hasSource ? String(scheduleSource).trim().toLowerCase() : '';
    const ruleLabel = getRuleLabel(ruleIndex, ruleName);
    const plainSourceLabel = (hasSource && normalizedScheduleSource !== 'condition') ? String(scheduleSource).trim() : '';
    const mainSourceLabel = ruleLabel || plainSourceLabel;

    if (!mainSourceLabel && !hasRuntimeCondition) {
        sourceEl.textContent = '';
        return;
    }

    const helperHtml = hasRuntimeCondition
        ? '<div class="price-graph-popup-source-helper">Dynamic rule</div>'
        : '';
    const safeRuleIndex = Number.parseInt(ruleIndex, 10);
    const isRuleClickable = Number.isInteger(safeRuleIndex) && safeRuleIndex > 0;
    const sourceMainHtml = isRuleClickable
        ? `<button type="button" class="price-graph-popup-source-button" data-rule-index="${safeRuleIndex}" data-rule-name="${escapePopupHtml(ruleName || '')}">Source: ${escapePopupHtml(mainSourceLabel)}</button>`
        : `<div class="price-graph-popup-source-main">Source: ${escapePopupHtml(mainSourceLabel)}</div>`;

    sourceEl.innerHTML = mainSourceLabel
        ? `${sourceMainHtml}${helperHtml}`
        : helperHtml;

    if (isRuleClickable) {
        const button = sourceEl.querySelector('.price-graph-popup-source-button');
        if (button) {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                showPriceGraphRuleDetail(String(safeRuleIndex), ruleName || '');
            });
        }
    }
}

function formatScheduleDisplayWithPercent(scheduleValue) {
    if (scheduleValue === undefined || scheduleValue === null || scheduleValue === '') {
        return '—';
    }

    const raw = String(scheduleValue).trim();
    if (raw === '') return '—';

    const normalized = raw.toLowerCase();
    if (normalized === 'netzero' || normalized === 'net zero') {
        const pct = powerToCapacityPercent(POPUP_NETZERO_REFERENCE_W);
        return `netzero${formatPopupPercent(pct, { prefix: '\u00b1' })}`;
    }
    if (normalized === 'netzero+' || normalized === 'net zero+') {
        const pct = powerToCapacityPercent(POPUP_NETZERO_PLUS_REFERENCE_W);
        return `netzero+${formatPopupPercent(pct, { prefix: '+' })}`;
    }

    const num = Number(raw);
    if (Number.isFinite(num)) {
        const pct = powerToCapacityPercent(num);
        return `${raw}${formatPopupPercent(pct)}`;
    }
    return raw;
}

function getPopupForecastBatteryState() {
    const state = window.currentBatteryForecastState;
    if (!state) return null;

    const electricLevel = Number(state.electricLevel);
    if (!Number.isFinite(electricLevel)) return null;

    const minChargeLevelRaw = (typeof CHARGE_STATUS_MIN_CHARGE_LEVEL !== 'undefined')
        ? Number(CHARGE_STATUS_MIN_CHARGE_LEVEL)
        : 20;
    const maxChargeLevelRaw = (typeof CHARGE_STATUS_MAX_CHARGE_LEVEL !== 'undefined')
        ? Number(CHARGE_STATUS_MAX_CHARGE_LEVEL)
        : 90;
    const minChargeLevel = Math.max(0, Math.min(100, minChargeLevelRaw));
    const maxChargeLevel = Math.max(minChargeLevel, Math.min(100, maxChargeLevelRaw));

    return {
        electricLevel: Math.max(0, Math.min(100, electricLevel)),
        minChargeLevel,
        maxChargeLevel
    };
}

function estimateSchedulePowerForPopup(scheduleValue) {
    if (scheduleValue === undefined || scheduleValue === null || scheduleValue === '') {
        return 0;
    }

    if (typeof scheduleValue === 'string') {
        const normalized = scheduleValue.trim().toLowerCase();
        if (normalized === 'netzero' || normalized === 'net zero') {
            return -POPUP_NETZERO_REFERENCE_W;
        }
        if (normalized === 'netzero+' || normalized === 'net zero+') {
            return POPUP_NETZERO_PLUS_REFERENCE_W;
        }
    }

    const numericValue = Number(scheduleValue);
    return Number.isFinite(numericValue) ? numericValue : 0;
}

function clampSchedulePowerForPopup(scheduleValue, minValue, maxValue) {
    let effectivePowerW = estimateSchedulePowerForPopup(scheduleValue);

    const parsedMin = Number(minValue);
    if (Number.isFinite(parsedMin)) {
        effectivePowerW = Math.max(effectivePowerW, parsedMin);
    }

    const parsedMax = Number(maxValue);
    if (Number.isFinite(parsedMax)) {
        effectivePowerW = Math.min(effectivePowerW, parsedMax);
    }

    return effectivePowerW;
}

const POPUP_RUNTIME_BATTERY_FIELDS = new Set(['electricity_level', 'electric_level', 'electricLevel']);

/**
 * From rule runtime_conditions, minimum SoC implied by electricity_level > / >= (tightest lower bound).
 * @param {unknown} conditions
 * @returns {number|null}
 */
function getDischargeSocFloorFromRuntimeConditions(conditions) {
    if (!Array.isArray(conditions) || conditions.length === 0) {
        return null;
    }
    let maxV = null;
    for (let i = 0; i < conditions.length; i++) {
        const c = conditions[i];
        if (!c || typeof c !== 'object') continue;
        const field = String(c.field || '');
        if (!POPUP_RUNTIME_BATTERY_FIELDS.has(field)) continue;
        const op = String(c.op || '==');
        if (op !== '>' && op !== '>=') continue;
        const v = Number(c.value);
        if (!Number.isFinite(v)) continue;
        maxV = maxV === null ? v : Math.max(maxV, v);
    }
    return maxV;
}

function parseBarDatasetRuntimeConditions(bar) {
    if (!bar || !bar.dataset || bar.dataset.runtimeConditions === undefined || bar.dataset.runtimeConditions === '') {
        return null;
    }
    try {
        const parsed = JSON.parse(bar.dataset.runtimeConditions);
        return Array.isArray(parsed) ? parsed : null;
    } catch (e) {
        return null;
    }
}

function getPopupForecastUniqueBars() {
    const uniqueBars = new Map();
    const bars = document.querySelectorAll('.price-graph-bar[data-key][data-date][data-hour]');

    bars.forEach((bar) => {
        const key = bar.dataset.key;
        if (!key) return;
        if (!uniqueBars.has(key)) {
            uniqueBars.set(key, bar);
        }
    });

    return Array.from(uniqueBars.values()).sort((a, b) => {
        const keyA = a.dataset.key || '';
        const keyB = b.dataset.key || '';
        return keyA.localeCompare(keyB);
    });
}

function getPopupForecastForBar(targetBar) {
    if (!targetBar) return null;

    const batteryState = getPopupForecastBatteryState();
    if (!batteryState) return null;

    const allBars = getPopupForecastUniqueBars();
    if (allBars.length === 0) return null;

    const now = new Date();
    let runningPercent = batteryState.electricLevel;
    let targetForecast = null;

    for (const bar of allBars) {
        const dateStr = bar.dataset.date;
        const hour = parseInt(bar.dataset.hour, 10);
        if (!dateStr || Number.isNaN(hour)) continue;

        const slotStart = new Date(
            Number(dateStr.slice(0, 4)),
            Number(dateStr.slice(4, 6)) - 1,
            Number(dateStr.slice(6, 8)),
            hour,
            0,
            0,
            0
        );
        const slotEnd = new Date(slotStart.getTime() + (60 * 60 * 1000));

        if (slotEnd <= now) {
            continue;
        }

        const durationHours = slotStart <= now
            ? Math.max(0, (slotEnd.getTime() - now.getTime()) / (60 * 60 * 1000))
            : 1;

        if (durationHours <= 0) {
            continue;
        }

        const scheduledPowerW = clampSchedulePowerForPopup(
            bar.dataset.scheduleValue,
            bar.dataset.minValue,
            bar.dataset.maxValue
        );
        const percentPerHour = powerToCapacityPercent(scheduledPowerW);
        const rawDeltaPercent = percentPerHour == null ? 0 : percentPerHour * durationHours;
        const signedDeltaPercent = scheduledPowerW < 0 ? -rawDeltaPercent : rawDeltaPercent;
        const startPercent = runningPercent;
        let endPercent = Math.max(
            batteryState.minChargeLevel,
            Math.min(batteryState.maxChargeLevel, startPercent + signedDeltaPercent)
        );
        let estimatedPowerW = scheduledPowerW;

        const ruleFloor = getDischargeSocFloorFromRuntimeConditions(parseBarDatasetRuntimeConditions(bar));
        if (ruleFloor != null && scheduledPowerW < 0) {
            if (startPercent <= ruleFloor) {
                endPercent = startPercent;
                estimatedPowerW = 0;
            } else {
                endPercent = Math.max(ruleFloor, endPercent);
                endPercent = Math.max(
                    batteryState.minChargeLevel,
                    Math.min(batteryState.maxChargeLevel, endPercent)
                );
                if (Math.abs(endPercent - startPercent) < 1e-6) {
                    estimatedPowerW = 0;
                }
            }
        }

        const appliedDeltaPercent = endPercent - startPercent;

        if (bar === targetBar) {
            targetForecast = {
                startPercent,
                endPercent,
                deltaPercent: appliedDeltaPercent,
                estimatedPowerW,
                durationHours,
                minChargeLevel: batteryState.minChargeLevel,
                maxChargeLevel: batteryState.maxChargeLevel,
                isCurrentHour: slotStart <= now && slotEnd > now
            };
            break;
        }

        runningPercent = endPercent;
    }

    return targetForecast;
}

function formatPopupForecastHtml(targetBar) {
    const forecast = getPopupForecastForBar(targetBar);
    if (!forecast) return '';

    const startLabel = forecast.isCurrentHour ? 'Now' : 'Start';
    const endLabel = 'End';
    const deltaPrefix = forecast.deltaPercent > 0 ? '+' : '';
    const deltaClass = forecast.deltaPercent > 0
        ? 'charging'
        : (forecast.deltaPercent < 0 ? 'discharging' : 'neutral');
    const powerPrefix = forecast.estimatedPowerW > 0 ? '+' : '';
    const durationMinutes = Math.round(forecast.durationHours * 60);
    const durationLabel = forecast.isCurrentHour
        ? `Current hour, ${durationMinutes} min remaining`
        : 'Full hour estimate';

    return `
        <div class="price-graph-popup-estimate">
            <div class="price-graph-popup-estimate-title">Estimated battery level</div>
            <div class="price-graph-popup-estimate-values">
                <span>${startLabel}: ${forecast.startPercent.toFixed(1)}%</span>
                <span>${endLabel}: ${forecast.endPercent.toFixed(1)}%</span>
            </div>
            <div class="price-graph-popup-estimate-meta">
                <span class="price-graph-popup-estimate-delta ${deltaClass}">Δ ${deltaPrefix}${forecast.deltaPercent.toFixed(1)}%</span>
                <span>@ ${powerPrefix}${Math.round(forecast.estimatedPowerW)} W</span>
            </div>
            <div class="price-graph-popup-estimate-note">${durationLabel}</div>
        </div>
    `;
}

function ensurePriceGraphPopup() {
    if (priceGraphPopup) return priceGraphPopup;

    const popup = document.createElement('div');
    popup.className = 'price-graph-popup';
    popup.innerHTML = `
        <div class="price-graph-popup-time"></div>
        <div class="price-graph-popup-price"></div>
        <div class="price-graph-popup-spot-price"></div>
        <div class="price-graph-popup-schedule"></div>
        <div class="price-graph-popup-limits"></div>
        <div class="price-graph-popup-source"></div>
        <div class="price-graph-popup-estimate-wrap"></div>
    `;
    document.body.appendChild(popup);

    window.addEventListener('scroll', hidePriceGraphPopup, true);
    window.addEventListener('resize', hidePriceGraphPopup);

    priceGraphPopup = popup;
    return popup;
}

function hidePriceGraphPopup() {
    if (!priceGraphPopup) return;
    priceGraphPopup.style.display = 'none';
    priceGraphPopup.style.visibility = 'hidden';
    priceGraphPopupActiveBar = null;
    priceGraphPopupActiveContainer = null;
}

function bindPopupContainer(container) {
    if (!container || priceGraphPopupBoundContainers.has(container)) return;
    container.addEventListener('scroll', hidePriceGraphPopup, { passive: true });
    priceGraphPopupBoundContainers.add(container);
}

/**
 * Returns true when the price graph is shown in mobile context (mobile page or narrow viewport).
 */
function isPriceGraphMobile() {
    return document.body.classList.contains('mobile-dark') || window.innerWidth <= 768;
}

const PRICE_GRAPH_MOBILE_ZOOMED_CLASS = 'price-graph-zoomed';
let priceGraphMobileZoomEnabled = false;

function getPriceGraphMobileWrapper() {
    return document.querySelector('.price-graph-wrapper-mobile');
}

function getPriceGraphMobileRows() {
    return Array.from(document.querySelectorAll('.price-graph-row-mobile'));
}

function getPriceGraphZoomButtons() {
    return Array.from(document.querySelectorAll('[data-price-graph-zoom-toggle]'));
}

function getPriceGraphMobileCenterSnapshot(container) {
    if (!container) return null;

    const bars = Array.from(container.querySelectorAll('.price-graph-bar'));
    if (bars.length === 0) {
        return {
            ratio: container.scrollWidth > container.clientWidth
                ? (container.scrollLeft / Math.max(1, container.scrollWidth - container.clientWidth))
                : 0
        };
    }

    const visibleCenter = container.scrollLeft + (container.clientWidth / 2);
    let closestBar = bars[0];
    let closestDistance = Number.POSITIVE_INFINITY;

    bars.forEach((bar) => {
        const barLeft = bar.offsetLeft;
        const barWidth = bar.offsetWidth || 1;
        const barCenter = barLeft + (barWidth / 2);
        const distance = Math.abs(barCenter - visibleCenter);
        if (distance < closestDistance) {
            closestBar = bar;
            closestDistance = distance;
        }
    });

    if (!closestBar) return null;

    const barLeft = closestBar.offsetLeft;
    const barWidth = closestBar.offsetWidth || 1;
    const relativeOffset = (visibleCenter - barLeft) / barWidth;

    return {
        key: closestBar.dataset.key || '',
        ratio: container.scrollWidth > container.clientWidth
            ? (container.scrollLeft / Math.max(1, container.scrollWidth - container.clientWidth))
            : 0,
        offsetRatio: Math.max(0, Math.min(1, relativeOffset))
    };
}

function restorePriceGraphMobileCenter(container, snapshot) {
    if (!container || !snapshot) return;

    const maxScrollLeft = Math.max(0, container.scrollWidth - container.clientWidth);
    if (maxScrollLeft === 0) {
        container.scrollLeft = 0;
        return;
    }

    if (snapshot.key) {
        const targetBar = container.querySelector(`.price-graph-bar[data-key="${snapshot.key}"]`);
        if (targetBar) {
            const barLeft = targetBar.offsetLeft;
            const barWidth = targetBar.offsetWidth || 1;
            const targetCenter = barLeft + (barWidth * (snapshot.offsetRatio ?? 0.5));
            container.scrollLeft = Math.max(0, Math.min(maxScrollLeft, targetCenter - (container.clientWidth / 2)));
            return;
        }
    }

    const targetRatio = typeof snapshot.ratio === 'number' ? snapshot.ratio : 0;
    container.scrollLeft = Math.max(0, Math.min(maxScrollLeft, maxScrollLeft * targetRatio));
}

function scrollPriceGraphMobileRowToCurrentHour(container) {
    if (!container) return;

    const currentBar = container.querySelector('.price-graph-bar.price-current');
    if (!currentBar) {
        if (typeof window.scrollPriceGraphToCurrent === 'function') {
            window.scrollPriceGraphToCurrent();
        }
        return;
    }

    const maxScrollLeft = Math.max(0, container.scrollWidth - container.clientWidth);
    const barLeft = currentBar.offsetLeft;
    const barWidth = currentBar.offsetWidth || currentBar.clientWidth || 1;
    const targetLeft = barLeft - (container.clientWidth / 2) + (barWidth / 2);

    const nextLeft = Math.max(0, Math.min(maxScrollLeft, targetLeft));

    if (typeof container.scrollTo === 'function') {
        container.scrollTo({
            left: nextLeft,
            behavior: 'smooth'
        });
    } else {
        container.scrollLeft = nextLeft;
    }
}

function syncPriceGraphMobileZoomUi() {
    const wrapper = getPriceGraphMobileWrapper();
    if (!wrapper) return;

    wrapper.classList.toggle(PRICE_GRAPH_MOBILE_ZOOMED_CLASS, priceGraphMobileZoomEnabled);

    getPriceGraphZoomButtons().forEach((button) => {
        button.setAttribute('aria-pressed', priceGraphMobileZoomEnabled ? 'true' : 'false');
        button.textContent = priceGraphMobileZoomEnabled ? 'Normal' : 'Zoom';
        button.setAttribute(
            'aria-label',
            priceGraphMobileZoomEnabled ? 'Return price bars to normal size' : 'Zoom in price bars'
        );
    });
}

function togglePriceGraphMobileZoom(preferredContainer) {
    if (!isPriceGraphMobile()) return;

    const rows = getPriceGraphMobileRows();
    const snapshots = rows.map((container) => ({
        container,
        snapshot: getPriceGraphMobileCenterSnapshot(container)
    }));

    priceGraphMobileZoomEnabled = !priceGraphMobileZoomEnabled;
    syncPriceGraphMobileZoomUi();

    const anchorContainer = preferredContainer || rows[0] || null;
    if (anchorContainer) {
        hidePriceGraphPopup();
        hidePriceGraphMobilePopup();
    }

    window.requestAnimationFrame(() => {
        window.requestAnimationFrame(() => {
            if (priceGraphMobileZoomEnabled) {
                const todayContainer = document.getElementById('price-graph-today');
                scrollPriceGraphMobileRowToCurrentHour(todayContainer);
                window.setTimeout(() => {
                    scrollPriceGraphMobileRowToCurrentHour(todayContainer);
                }, 180);
            } else {
                snapshots.forEach(({ container, snapshot }) => {
                    restorePriceGraphMobileCenter(container, snapshot);
                });
            }
        });
    });
}

function initPriceGraphMobileZoomControls() {
    const wrapper = getPriceGraphMobileWrapper();
    if (!wrapper) return;

    getPriceGraphZoomButtons().forEach((button) => {
        if (button.dataset.zoomBound === 'true') return;
        button.dataset.zoomBound = 'true';
        button.addEventListener('click', () => {
            const containerIds = (button.getAttribute('aria-controls') || '')
                .split(/\s+/)
                .filter(Boolean);
            const targetContainer = containerIds.length > 0
                ? document.getElementById(containerIds[0])
                : null;
            togglePriceGraphMobileZoom(targetContainer);
        });
    });

    syncPriceGraphMobileZoomUi();
}

let priceGraphMobilePopup = null;
let priceGraphMobilePopupEscapeHandler = null;
let priceGraphMobilePopupResizeHandler = null;
let priceGraphMobilePopupState = null;
const PRICE_GRAPH_MOBILE_POPUP_NAV_OUT_MS = 90;
const PRICE_GRAPH_MOBILE_POPUP_NAV_IN_MS = 140;

function ensurePriceGraphMobilePopup() {
    if (priceGraphMobilePopup) return priceGraphMobilePopup;

    const backdrop = document.createElement('div');
    backdrop.className = 'price-graph-mobile-popup';
    backdrop.setAttribute('id', 'price-graph-mobile-popup');
    backdrop.innerHTML = `
        <div class="price-graph-dialog-shell price-graph-mobile-popup-dialog">
            <div class="price-graph-dialog-header price-graph-mobile-popup-header">
                <button type="button" class="price-graph-mobile-popup-nav price-graph-mobile-popup-nav-prev" aria-label="Previous time slot"></button>
                <span class="price-graph-mobile-popup-title-wrap">
                    <span class="price-graph-mobile-popup-title"></span>
                </span>
                <button type="button" class="price-graph-mobile-popup-nav price-graph-mobile-popup-nav-next" aria-label="Next time slot"></button>
                <button type="button" class="modal-close price-graph-mobile-popup-close" aria-label="Close">&times;</button>
            </div>
            <div class="price-graph-mobile-popup-body">
                <div class="price-graph-mobile-popup-content">
                    <div class="price-graph-popup-price"></div>
                    <div class="price-graph-popup-spot-price"></div>
                    <div class="price-graph-popup-schedule"></div>
                    <div class="price-graph-popup-limits"></div>
                    <div class="price-graph-popup-source"></div>
                    <div class="price-graph-popup-estimate-wrap"></div>
                </div>
            </div>
            <div class="price-graph-dialog-footer price-graph-mobile-popup-footer">
                <div style="display:flex; gap:8px; align-items:center;">
                    <button type="button"
                            class="btn btn-outline price-graph-mobile-popup-netzero">
                        🔌&nbsp;netZero
                    </button>
                    <button type="button"
                            class="btn btn-outline price-graph-mobile-popup-netzero-plus">
                        ☀️&nbsp;netZero+
                    </button>
                </div>
                <div class="price-graph-mobile-popup-actions">
                    <button type="button" class="btn btn-outline price-graph-mobile-popup-auto">↩&nbsp;Auto</button>
                    <button type="button" class="btn btn-primary price-graph-mobile-popup-edit">Edit Schedule</button>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(backdrop);

    const prevNav = backdrop.querySelector('.price-graph-mobile-popup-nav-prev');
    const nextNav = backdrop.querySelector('.price-graph-mobile-popup-nav-next');
    if (prevNav) {
        prevNav.onclick = (e) => {
            e.stopPropagation();
            navigatePriceGraphMobilePopup(-1);
        };
    }

    if (nextNav) {
        nextNav.onclick = (e) => {
            e.stopPropagation();
            navigatePriceGraphMobilePopup(1);
        };
    }

    priceGraphMobilePopup = backdrop;
    return backdrop;
}

function hidePriceGraphMobilePopup() {
    if (!priceGraphMobilePopup) return;
    priceGraphMobilePopup.classList.remove('active');
    if (priceGraphMobilePopupEscapeHandler) {
        document.removeEventListener('keydown', priceGraphMobilePopupEscapeHandler);
        priceGraphMobilePopupEscapeHandler = null;
    }
    if (priceGraphMobilePopupResizeHandler) {
        window.removeEventListener('resize', priceGraphMobilePopupResizeHandler);
        priceGraphMobilePopupResizeHandler = null;
    }
    priceGraphMobilePopupState = null;
}

function getPriceGraphMobilePopupBars(bar) {
    const container = bar && bar.parentElement;
    if (!container) return [];
    return Array.from(container.querySelectorAll('.price-graph-bar'));
}

function getAdjacentPriceGraphMobilePopupBar(direction) {
    if (!priceGraphMobilePopupState || !priceGraphMobilePopupState.bar) return null;
    const bars = getPriceGraphMobilePopupBars(priceGraphMobilePopupState.bar);
    const currentIndex = bars.indexOf(priceGraphMobilePopupState.bar);
    if (currentIndex === -1) return null;
    const nextIndex = currentIndex + direction;
    if (nextIndex < 0 || nextIndex >= bars.length) return null;
    return bars[nextIndex];
}

function updatePriceGraphMobilePopupNavState(popup) {
    if (!popup) return;
    const prevNav = popup.querySelector('.price-graph-mobile-popup-nav-prev');
    const nextNav = popup.querySelector('.price-graph-mobile-popup-nav-next');
    const hasPrev = !!getAdjacentPriceGraphMobilePopupBar(-1);
    const hasNext = !!getAdjacentPriceGraphMobilePopupBar(1);

    if (prevNav) {
        prevNav.disabled = !hasPrev;
        prevNav.setAttribute('aria-hidden', hasPrev ? 'false' : 'true');
    }
    if (nextNav) {
        nextNav.disabled = !hasNext;
        nextNav.setAttribute('aria-hidden', hasNext ? 'false' : 'true');
    }
}

function setPriceGraphMobilePopupNavigationClass(popup, className) {
    if (!popup) return;
    const animatedEls = popup.querySelectorAll(
        '.price-graph-mobile-popup-title-wrap, .price-graph-mobile-popup-content'
    );
    animatedEls.forEach((el) => el.classList.remove(
        'price-graph-mobile-popup-dialog-nav-prev-out',
        'price-graph-mobile-popup-dialog-nav-prev-in',
        'price-graph-mobile-popup-dialog-nav-next-out',
        'price-graph-mobile-popup-dialog-nav-next-in'
    ));
    if (className) {
        animatedEls.forEach((el) => el.classList.add(className));
    }
}

function renderPriceGraphMobilePopupContent() {
    const popup = ensurePriceGraphMobilePopup();
    const state = priceGraphMobilePopupState;
    if (!popup || !state || !state.bar) return;

    const { bar, editModal, scheduleMap } = state;
    const key = state.key || bar.dataset.key || '';

    const titleEl = popup.querySelector('.price-graph-mobile-popup-title');
    const priceEl = popup.querySelector('.price-graph-popup-price');
    const spotPriceEl = popup.querySelector('.price-graph-popup-spot-price');
    const scheduleEl = popup.querySelector('.price-graph-popup-schedule');
    const limitsEl = popup.querySelector('.price-graph-popup-limits');
    const sourceEl = popup.querySelector('.price-graph-popup-source');
    const estimateEl = popup.querySelector('.price-graph-popup-estimate-wrap');

    const hourValue = parseInt(bar.dataset.hour, 10);
    const timeRange = formatHourRange(hourValue);

    const rawPrice = bar.dataset.price;
    const isProxy = bar.dataset.proxy === 'true';
    const priceValue = rawPrice === '' || rawPrice === undefined ? null : Number(rawPrice);
    const priceDisplay = isProxy ? 'No price data available' : (priceValue === null || Number.isNaN(priceValue) ? 'N/A' : formatPrice(priceValue));

    const spotPriceValue = spotPriceFromIncl(priceValue);
    const spotPriceDisplay = spotPriceValue != null ? `Spot price: ${formatPrice(spotPriceValue)}` : '';

    const scheduleValue = bar.dataset.scheduleValue;
    const scheduleDisplay = formatScheduleDisplayWithPercent(scheduleValue);
    const scheduleEntry = scheduleMap && Object.prototype.hasOwnProperty.call(scheduleMap, key) ? scheduleMap[key] : null;
    const minValue = bar.dataset.minValue;
    const maxValue = bar.dataset.maxValue;
    const limitsDisplay = formatScheduleLimitsText(scheduleValue, minValue, maxValue, scheduleEntry);

    const scheduleSource = bar.dataset.scheduleSource;
    const hasRuntimeCondition = bar.dataset.runtimeCondition === 'true';
    const ruleNameRaw = bar.dataset.ruleName;
    const ruleIndexRaw = bar.dataset.ruleIndex;

    titleEl.textContent = timeRange || '—';
    priceEl.textContent = priceDisplay;
    spotPriceEl.textContent = spotPriceDisplay;
    scheduleEl.textContent = `Schedule: ${scheduleDisplay}`;
    if (limitsEl) {
        limitsEl.textContent = limitsDisplay;
        limitsEl.style.display = limitsDisplay ? 'block' : 'none';
    }
    renderPopupSource(sourceEl, {
        scheduleSource,
        hasRuntimeCondition,
        ruleName: ruleNameRaw,
        ruleIndex: ruleIndexRaw
    });
    if (estimateEl) {
        estimateEl.innerHTML = formatPopupForecastHtml(bar);
    }

    const close = () => {
        hidePriceGraphMobilePopup();
    };

    const netZeroBtn = popup.querySelector('.price-graph-mobile-popup-netzero');
    const netZeroPlusBtn = popup.querySelector('.price-graph-mobile-popup-netzero-plus');
    const autoBtn = popup.querySelector('.price-graph-mobile-popup-auto');
    const editBtn = popup.querySelector('.price-graph-mobile-popup-edit');
    const closeBtn = popup.querySelector('.price-graph-mobile-popup-close');

    const hasExistingEntry = scheduleMap && Object.prototype.hasOwnProperty.call(scheduleMap, key);
    const existingEntry = hasExistingEntry ? scheduleMap[key] : undefined;

    const applyQuickMode = async (mode) => {
        close();
        if (editModal && typeof editModal.applyQuickMode === 'function') {
            await editModal.applyQuickMode(key, mode, {
                originalKey: hasExistingEntry ? key : null
            });
        } else if (editModal) {
            if (existingEntry !== undefined) {
                editModal.open(key, existingEntry);
            } else {
                editModal.open(null, null, key);
            }
        }
    };

    netZeroBtn.onclick = () => applyQuickMode('netzero');
    netZeroPlusBtn.onclick = () => applyQuickMode('netzero+');
    if (autoBtn) {
        autoBtn.onclick = () => applyQuickMode('auto');
    }

    editBtn.onclick = () => {
        close();
        if (editModal) {
            if (existingEntry !== undefined) {
                editModal.open(key, existingEntry);
            } else {
                editModal.open(null, null, key);
            }
        }
    };

    closeBtn.onclick = close;

    popup.onclick = (e) => {
        if (e.target === popup) close();
    };
    const dialog = popup.querySelector('.price-graph-mobile-popup-dialog');
    if (dialog) {
        dialog.onclick = (e) => e.stopPropagation();
    }

    if (priceGraphMobilePopupEscapeHandler) {
        document.removeEventListener('keydown', priceGraphMobilePopupEscapeHandler);
    }
    priceGraphMobilePopupEscapeHandler = (e) => {
        if (e.key === 'Escape') close();
    };
    document.addEventListener('keydown', priceGraphMobilePopupEscapeHandler);

    if (priceGraphMobilePopupResizeHandler) {
        window.removeEventListener('resize', priceGraphMobilePopupResizeHandler);
    }
    priceGraphMobilePopupResizeHandler = close;
    window.addEventListener('resize', priceGraphMobilePopupResizeHandler);

    updatePriceGraphMobilePopupNavState(popup);
    popup.classList.add('active');
}

function navigatePriceGraphMobilePopup(direction) {
    const nextBar = getAdjacentPriceGraphMobilePopupBar(direction);
    if (!nextBar || !priceGraphMobilePopupState || priceGraphMobilePopupState.isNavigating) return;

    const popup = ensurePriceGraphMobilePopup();
    const outClass = direction < 0
        ? 'price-graph-mobile-popup-dialog-nav-prev-out'
        : 'price-graph-mobile-popup-dialog-nav-next-out';
    const inClass = direction < 0
        ? 'price-graph-mobile-popup-dialog-nav-prev-in'
        : 'price-graph-mobile-popup-dialog-nav-next-in';

    priceGraphMobilePopupState = {
        ...priceGraphMobilePopupState,
        isNavigating: true
    };

    setPriceGraphMobilePopupNavigationClass(popup, outClass);

    window.setTimeout(() => {
        priceGraphMobilePopupState = {
            ...priceGraphMobilePopupState,
            bar: nextBar,
            key: nextBar.dataset.key || ''
        };
        renderPriceGraphMobilePopupContent();
        setPriceGraphMobilePopupNavigationClass(popup, inClass);

        window.setTimeout(() => {
            setPriceGraphMobilePopupNavigationClass(popup, '');
            priceGraphMobilePopupState = {
                ...priceGraphMobilePopupState,
                isNavigating: false
            };
        }, PRICE_GRAPH_MOBILE_POPUP_NAV_IN_MS);
    }, PRICE_GRAPH_MOBILE_POPUP_NAV_OUT_MS);
}

function showPriceGraphMobilePopup(bar, editModal, scheduleMap, key) {
    const popup = ensurePriceGraphMobilePopup();
    if (!bar || !popup) return;

    priceGraphMobilePopupState = {
        bar,
        editModal,
        scheduleMap,
        key: key || bar.dataset.key || '',
        isNavigating: false
    };
    renderPriceGraphMobilePopupContent();
}

function showPriceGraphPopup(bar, container) {
    const popup = ensurePriceGraphPopup();
    if (!bar || !container || !popup) return;

    const timeEl = popup.querySelector('.price-graph-popup-time');
    const priceEl = popup.querySelector('.price-graph-popup-price');
    const spotPriceEl = popup.querySelector('.price-graph-popup-spot-price');
    const scheduleEl = popup.querySelector('.price-graph-popup-schedule');
    const limitsEl = popup.querySelector('.price-graph-popup-limits');
    const sourceEl = popup.querySelector('.price-graph-popup-source');
    const estimateEl = popup.querySelector('.price-graph-popup-estimate-wrap');

    const hourValue = parseInt(bar.dataset.hour, 10);
    const timeRange = formatHourRange(hourValue);

    const rawPrice = bar.dataset.price;
    const isProxy = bar.dataset.proxy === 'true';
    const priceValue = rawPrice === '' || rawPrice === undefined ? null : Number(rawPrice);
    const priceDisplay = isProxy ? 'No price data available' : (priceValue === null || Number.isNaN(priceValue) ? 'N/A' : formatPrice(priceValue));

    const spotPriceValue = spotPriceFromIncl(priceValue);
    const spotPriceDisplay = spotPriceValue != null ? `Spot price: ${formatPrice(spotPriceValue)}` : '';

    const scheduleValue = bar.dataset.scheduleValue;
    const scheduleDisplay = formatScheduleDisplayWithPercent(scheduleValue);
    let scheduleEntry = null;
    if (bar.dataset.scheduleEntry) {
        try {
            scheduleEntry = JSON.parse(bar.dataset.scheduleEntry);
        } catch (error) {
            console.warn('Failed to parse popup schedule entry dataset:', error);
        }
    }
    const minValue = bar.dataset.minValue;
    const maxValue = bar.dataset.maxValue;
    const limitsDisplay = formatScheduleLimitsText(scheduleValue, minValue, maxValue, scheduleEntry);

    const scheduleSource = bar.dataset.scheduleSource;
    const hasRuntimeCondition = bar.dataset.runtimeCondition === 'true';
    const ruleNameRaw = bar.dataset.ruleName;
    const ruleIndexRaw = bar.dataset.ruleIndex;

    timeEl.textContent = timeRange || '—';
    priceEl.textContent = priceDisplay;
    spotPriceEl.textContent = spotPriceDisplay;
    scheduleEl.textContent = `Schedule: ${scheduleDisplay}`;
    if (limitsEl) {
        limitsEl.textContent = limitsDisplay;
        limitsEl.style.display = limitsDisplay ? 'block' : 'none';
    }
    renderPopupSource(sourceEl, {
        scheduleSource,
        hasRuntimeCondition,
        ruleName: ruleNameRaw,
        ruleIndex: ruleIndexRaw
    });
    if (estimateEl) {
        estimateEl.innerHTML = formatPopupForecastHtml(bar);
    }

    popup.style.display = 'block';
    popup.style.visibility = 'hidden';

    const barRect = bar.getBoundingClientRect();
    const containerRect = container.getBoundingClientRect();

    requestAnimationFrame(() => {
        const popupRect = popup.getBoundingClientRect();
        let left = barRect.left + barRect.width / 2 - popupRect.width / 2;
        const minLeft = containerRect.left + 4;
        const maxLeft = containerRect.right - popupRect.width - 4;
        left = Math.max(minLeft, Math.min(left, maxLeft));

        let top = barRect.top - popupRect.height - 8;
        if (top < containerRect.top + 4) {
            top = barRect.bottom + 8;
        }

        popup.style.left = `${Math.round(left)}px`;
        popup.style.top = `${Math.round(top)}px`;
        popup.style.visibility = 'visible';
    });

    priceGraphPopupActiveBar = bar;
    priceGraphPopupActiveContainer = container;
}

/**
 * Renders the price graph for today and tomorrow
 * @param {Object} priceData - Price data from API
 * @param {number} currentHour - Current hour (0-23)
 * @param {Array|Object} scheduleEntries - Array of schedule entries or schedule context
 * @param {Object} editModal - Edit modal instance for click handlers
 */
function renderPriceGraph(priceData, currentHour, scheduleEntries, editModal, ruleColorMap = {}) {
    const todayContainer = document.getElementById('price-graph-today');
    const tomorrowContainer = document.getElementById('price-graph-tomorrow');
    const tomorrowContainerMobile = document.getElementById('price-graph-tomorrow-mobile');
    
    if (!todayContainer) return;
    
    const scheduleContext = Array.isArray(scheduleEntries) ? null : scheduleEntries;
    const scheduleEntryList = Array.isArray(scheduleEntries) ? scheduleEntries : (scheduleContext?.entries || []);
    const resolvedToday = scheduleContext?.resolvedToday || [];
    const resolvedTomorrow = scheduleContext?.resolvedTomorrow || [];

    const buildExpandedHourMap = (resolved) => {
        if (!Array.isArray(resolved) || resolved.length === 0) return null;
        const baseMap = typeof buildHourMap === 'function' ? buildHourMap(resolved) : null;
        if (!baseMap) return null;
        const expanded = {};
        let lastValue;
        for (let h = 0; h < 24; h++) {
            if (baseMap[h] !== undefined) {
                lastValue = baseMap[h];
            }
            if (lastValue !== undefined) {
                expanded[h] = lastValue;
            }
        }
        return expanded;
    };

    /**
     * Build an expanded hour -> source map from resolved schedule slots.
     * Propagates the last non-null source forward across the day.
     */
    const buildExpandedSourceMap = (resolved) => {
        if (!Array.isArray(resolved) || resolved.length === 0) return null;

        const baseSourceMap = {};
        let lastSource = undefined;

        resolved.forEach((slot) => {
            if (!slot || slot.time === undefined) return;
            const hour = parseInt(String(slot.time).substring(0, 2), 10);
            if (Number.isNaN(hour)) return;

            if (Object.prototype.hasOwnProperty.call(slot, 'source')) {
                if (slot.source !== undefined && slot.source !== null && String(slot.source).trim() !== '') {
                    lastSource = slot.source;
                } else {
                    lastSource = undefined;
                }
            }
            baseSourceMap[hour] = lastSource;
        });

        if (Object.keys(baseSourceMap).length === 0) {
            return null;
        }

        const expanded = {};
        let currentSource = undefined;
        for (let h = 0; h < 24; h++) {
            if (Object.prototype.hasOwnProperty.call(baseSourceMap, h)) {
                currentSource = baseSourceMap[h];
            }
            expanded[h] = currentSource;
        }

        return expanded;
    };

    /**
     * Build an expanded hour -> hasRuntimeCondition map from resolved schedule slots.
     * Propagates the last known runtime-condition state forward across the day.
     */
    const buildExpandedRuntimeConditionMap = (resolved) => {
        if (!Array.isArray(resolved) || resolved.length === 0) return null;

        const baseRuntimeMap = {};
        let lastHasRuntimeCondition = undefined;

        resolved.forEach((slot) => {
            if (!slot || slot.time === undefined) return;
            const hour = parseInt(String(slot.time).substring(0, 2), 10);
            if (Number.isNaN(hour)) return;

            if (Object.prototype.hasOwnProperty.call(slot, 'runtime_conditions')) {
                const runtimeConditions = slot.runtime_conditions;
                lastHasRuntimeCondition = Array.isArray(runtimeConditions) && runtimeConditions.length > 0;
            } else {
                lastHasRuntimeCondition = false;
            }
            baseRuntimeMap[hour] = lastHasRuntimeCondition;
        });

        if (Object.keys(baseRuntimeMap).length === 0) {
            return null;
        }

        const expanded = {};
        let currentState = undefined;
        for (let h = 0; h < 24; h++) {
            if (Object.prototype.hasOwnProperty.call(baseRuntimeMap, h)) {
                currentState = baseRuntimeMap[h];
            }
            if (currentState !== undefined) {
                expanded[h] = currentState;
            }
        }
        return expanded;
    };

    /**
     * Build an expanded hour -> runtime_conditions[] map from resolved schedule slots.
     * Mirrors buildExpandedRuntimeConditionMap: clearing when a slot omits runtime_conditions
     * or supplies an empty array; forward-fills the last non-empty array across later hours.
     */
    const buildExpandedRuntimeConditionsArrayMap = (resolved) => {
        if (!Array.isArray(resolved) || resolved.length === 0) return null;

        const baseMap = {};
        let lastRuntimeConditions = undefined;

        resolved.forEach((slot) => {
            if (!slot || slot.time === undefined) return;
            const hour = parseInt(String(slot.time).substring(0, 2), 10);
            if (Number.isNaN(hour)) return;

            if (Object.prototype.hasOwnProperty.call(slot, 'runtime_conditions')) {
                const runtimeConditions = slot.runtime_conditions;
                if (Array.isArray(runtimeConditions) && runtimeConditions.length > 0) {
                    lastRuntimeConditions = runtimeConditions.slice();
                } else {
                    lastRuntimeConditions = [];
                }
            } else {
                lastRuntimeConditions = [];
            }
            baseMap[hour] = lastRuntimeConditions;
        });

        if (Object.keys(baseMap).length === 0) {
            return null;
        }

        const expanded = {};
        let current = undefined;
        for (let h = 0; h < 24; h++) {
            if (Object.prototype.hasOwnProperty.call(baseMap, h)) {
                current = baseMap[h];
            }
            expanded[h] = current;
        }
        return expanded;
    };

    /**
     * Build an expanded hour -> rule metadata map from resolved schedule slots.
     * Propagates last known rule_name/rule_index/min_power/max_power forward across the day.
     */
    const buildExpandedRuleMetaMap = (resolved) => {
        if (!Array.isArray(resolved) || resolved.length === 0) return null;

        const baseRuleMap = {};
        let lastRuleMeta = undefined;

        resolved.forEach((slot) => {
            if (!slot || slot.time === undefined) return;
            const hour = parseInt(String(slot.time).substring(0, 2), 10);
            if (Number.isNaN(hour)) return;

            const hasRuleName = Object.prototype.hasOwnProperty.call(slot, 'rule_name') &&
                slot.rule_name !== undefined &&
                slot.rule_name !== null &&
                String(slot.rule_name).trim() !== '';
            const hasRuleIndex = Object.prototype.hasOwnProperty.call(slot, 'rule_index') &&
                slot.rule_index !== undefined &&
                slot.rule_index !== null &&
                !Number.isNaN(Number(slot.rule_index));
            const hasMinValue = Object.prototype.hasOwnProperty.call(slot, 'min_power') &&
                slot.min_power !== undefined &&
                slot.min_power !== null &&
                slot.min_power !== '' &&
                Number.isFinite(Number(slot.min_power));
            const hasMaxValue = Object.prototype.hasOwnProperty.call(slot, 'max_power') &&
                slot.max_power !== undefined &&
                slot.max_power !== null &&
                slot.max_power !== '' &&
                Number.isFinite(Number(slot.max_power));

            if (hasRuleName || hasRuleIndex || hasMinValue || hasMaxValue) {
                lastRuleMeta = {
                    ruleName: hasRuleName ? String(slot.rule_name).trim() : undefined,
                    ruleIndex: hasRuleIndex ? String(parseInt(slot.rule_index, 10)) : undefined,
                    minValue: hasMinValue ? Number(slot.min_power) : undefined,
                    maxValue: hasMaxValue ? Number(slot.max_power) : undefined
                };
            } else {
                lastRuleMeta = undefined;
            }
            baseRuleMap[hour] = lastRuleMeta;
        });

        if (Object.keys(baseRuleMap).length === 0) {
            return null;
        }

        const expanded = {};
        let currentMeta = undefined;
        for (let h = 0; h < 24; h++) {
            if (Object.prototype.hasOwnProperty.call(baseRuleMap, h)) {
                currentMeta = baseRuleMap[h];
            }
            expanded[h] = currentMeta;
        }
        return expanded;
    };

    const scheduleHourMapByDate = {};
    const scheduleSourceMapByDate = {};
    const scheduleRuntimeConditionMapByDate = {};
    const scheduleRuntimeConditionsArrayMapByDate = {};
    const scheduleRuleMetaMapByDate = {};

    // Build a map of schedule entries for quick lookup
    const scheduleMap = {};
    if (scheduleEntryList) {
        scheduleEntryList.forEach(entry => {
            scheduleMap[entry.key] = entry;
        });
    }
    
    const getScheduleType = (value) => {
        if (value === 'netzero') {
            return 'netzero';
        }
        if (value === 'netzero+') {
            return 'netzero-plus';
        }
        if (value === null || value === undefined || value === '') {
            return '';
        }
        if (typeof value === 'string') {
            const trimmed = value.trim();
            if (trimmed === '') return '';
            const normalized = trimmed
                .toLowerCase()
                .replace(/[^a-z0-9+.\- ]/g, '')
                .replace(/\s+/g, ' ')
                .trim();
            if (normalized === 'net zero' || normalized === 'netzero') {
                return 'netzero';
            }
            if (normalized === 'netzero+' || normalized === 'solar charge' || normalized.includes('only')) {
                return 'netzero-plus';
            }
            const match = trimmed.match(/[-+]?\d+(?:\.\d+)?/);
            if (match) {
                const parsed = Number(match[0]);
                if (!Number.isNaN(parsed)) {
                    if (parsed > 0) return 'charge';
                    if (parsed < 0) return 'discharge';
                    return '';
                }
            }
        }
        const numericValue = typeof value === 'number' ? value : Number(value);
        if (!Number.isNaN(numericValue)) {
            if (numericValue > 0) return 'charge';
            if (numericValue < 0) return 'discharge';
            return '';
        }
        return '';
    };

    const scheduleByDate = {};
    if (scheduleEntryList) {
        scheduleEntryList.forEach((entry) => {
            if (!entry || !entry.key) return;
            const dateKey = entry.key.slice(0, 8);
            if (!scheduleByDate[dateKey]) {
                scheduleByDate[dateKey] = [];
            }
            scheduleByDate[dateKey].push({
                key: entry.key,
                value: getRawScheduleEntryValue(entry)
            });
        });
        Object.values(scheduleByDate).forEach((entries) => {
            entries.sort((a, b) => a.key.localeCompare(b.key));
        });
    }

    const getActiveScheduleInfo = (dateStr, hourKey) => {
        const hourMap = scheduleHourMapByDate[dateStr];
        const sourceMap = scheduleSourceMapByDate[dateStr];
        const runtimeConditionMap = scheduleRuntimeConditionMapByDate[dateStr];
        const ruleMetaMap = scheduleRuleMetaMapByDate[dateStr];
        const hourIndex = parseInt(hourKey, 10);

        let value;
        let source;
        let hasRuntimeCondition;
        let runtimeConditions;
        let ruleName;
        let ruleIndex;
        let minValue;
        let maxValue;

        const runtimeConditionsArrayMap = scheduleRuntimeConditionsArrayMapByDate[dateStr];

        if (hourMap) {
            value = hourMap[hourIndex];
        }
        if (sourceMap) {
            source = sourceMap[hourIndex];
        }
        if (runtimeConditionMap) {
            hasRuntimeCondition = runtimeConditionMap[hourIndex];
        }
        if (runtimeConditionsArrayMap && Object.prototype.hasOwnProperty.call(runtimeConditionsArrayMap, hourIndex)) {
            runtimeConditions = runtimeConditionsArrayMap[hourIndex];
        }
        if (ruleMetaMap && Object.prototype.hasOwnProperty.call(ruleMetaMap, hourIndex) && ruleMetaMap[hourIndex]) {
            ruleName = ruleMetaMap[hourIndex].ruleName;
            ruleIndex = ruleMetaMap[hourIndex].ruleIndex;
            minValue = ruleMetaMap[hourIndex].minValue;
            maxValue = ruleMetaMap[hourIndex].maxValue;
        }

        if (
            value !== undefined ||
            source !== undefined ||
            hasRuntimeCondition !== undefined ||
            ruleName !== undefined ||
            ruleIndex !== undefined ||
            minValue !== undefined ||
            maxValue !== undefined
        ) {
            return { value, source, hasRuntimeCondition, runtimeConditions, ruleName, ruleIndex, minValue, maxValue };
        }

        const entries = scheduleByDate[dateStr];
        if (!entries || entries.length === 0) {
            return {
                value: undefined,
                source: undefined,
                hasRuntimeCondition: undefined,
                runtimeConditions: undefined,
                ruleName: undefined,
                ruleIndex: undefined,
                minValue: undefined,
                maxValue: undefined
            };
        }

        let activeValue = undefined;
        for (const entry of entries) {
            const entryHour = entry.key.slice(8, 10);
            if (entryHour <= hourKey) {
                activeValue = getRawScheduleEntryValue(entry);
            } else {
                break;
            }
        }
        return {
            value: activeValue,
            source: undefined,
            hasRuntimeCondition: undefined,
            runtimeConditions: undefined,
            ruleName: undefined,
            ruleIndex: undefined,
            minValue: undefined,
            maxValue: undefined
        };
    };

    const getRuleGroupIdentity = (dateStr, hourKey) => {
        const hourIndex = parseInt(hourKey, 10);
        if (Number.isNaN(hourIndex)) {
            return 'no-rule';
        }

        const ruleMetaMap = scheduleRuleMetaMapByDate[dateStr];
        if (!ruleMetaMap || !Object.prototype.hasOwnProperty.call(ruleMetaMap, hourIndex) || !ruleMetaMap[hourIndex]) {
            return 'no-rule';
        }

        const ruleMeta = ruleMetaMap[hourIndex];
        const normalizedRuleIndex = ruleMeta.ruleIndex !== undefined && ruleMeta.ruleIndex !== null
            ? String(ruleMeta.ruleIndex).trim()
            : '';
        if (normalizedRuleIndex !== '') {
            return `rule-index:${normalizedRuleIndex}`;
        }

        const normalizedRuleName = ruleMeta.ruleName !== undefined && ruleMeta.ruleName !== null
            ? String(ruleMeta.ruleName).trim()
            : '';
        if (normalizedRuleName !== '') {
            return `rule-name:${normalizedRuleName}`;
        }

        return 'no-rule';
    };

    // Extract price data
    const todayPrices = priceData?.today || {};
    // Always treat tomorrow prices as an object; when empty we will render proxy (grey) bars
    const tomorrowPrices = priceData?.tomorrow || {};
    
    // Always show the tomorrow cards; when there is no real data the graph will use proxy prices
    if (tomorrowContainer) {
        const tomorrowCard = tomorrowContainer.closest('.card');
        if (tomorrowCard) {
            tomorrowCard.style.display = '';
        }
    }
    
    if (tomorrowContainerMobile) {
        const tomorrowCardMobile = document.getElementById('tomorrow-price-card-mobile');
        if (tomorrowCardMobile) {
            tomorrowCardMobile.style.display = '';
        }
    }
    
    // Collect all prices to calculate min/max (use proxy when missing so scale is sensible)
    const allPrices = [];
    for (let h = 0; h < 24; h++) {
        const hourKey = String(h).padStart(2, '0');
        const todayVal = todayPrices[hourKey];
        const tomorrowVal = tomorrowPrices !== null && tomorrowPrices !== undefined ? tomorrowPrices[hourKey] : undefined;
        if (todayVal !== null && todayVal !== undefined && !isNaN(todayVal)) {
            allPrices.push(todayVal);
        } else {
            allPrices.push(PRICE_PROXY_NO_DATA);
        }
        if (tomorrowPrices !== null && tomorrowPrices !== undefined) {
            if (tomorrowVal !== null && tomorrowVal !== undefined && !isNaN(tomorrowVal)) {
                allPrices.push(tomorrowVal);
            } else {
                allPrices.push(PRICE_PROXY_NO_DATA);
            }
        }
    }
    
    // Calculate min and max prices
    let minPrice = 0;
    let maxPrice = 0.5; // Default max if no prices
    if (allPrices.length > 0) {
        minPrice = Math.min(...allPrices);
        maxPrice = Math.max(...allPrices);
        // Add some padding to the range for better visualization
        const range = maxPrice - minPrice;
        if (range > 0) {
            minPrice -= range * 0.1;
            maxPrice += range * 0.1;
        } else {
            // All prices are the same, add small padding
            minPrice -= 0.01;
            maxPrice += 0.01;
        }
    }
    
    // Get current date
    const now = new Date();
    const currentDate = (scheduleContext?.todayDate || (now.getFullYear().toString() +
        String(now.getMonth() + 1).padStart(2, '0') +
        String(now.getDate()).padStart(2, '0')));
    
    // Helper function to render a row of price bars
    const renderPriceRow = (prices, dateStr, container, isToday) => {
        container.innerHTML = '';
        bindPopupContainer(container);

        for (let h = 0; h < 24; h++) {
            const hourKey = String(h).padStart(2, '0');
            const price = prices[hourKey] !== undefined ? prices[hourKey] : null;
            const hasRealPrice = price !== null && price !== undefined && !isNaN(price);
            const priceForHeight = hasRealPrice ? price : PRICE_PROXY_NO_DATA;
            
            // Determine if this is the current hour
            const isCurrentHour = isToday && (h === now.getHours()) && (dateStr === currentDate);
            
            // Calculate bar height (based on price relative to min/max; use proxy for missing data)
            let barHeight = '4px'; // Minimum height
            const priceRange = maxPrice - minPrice;
            if (priceRange > 0) {
                const normalized = (priceForHeight - minPrice) / priceRange;
                barHeight = Math.max(4, normalized * 100) + '%';
            } else {
                barHeight = '50%'; // Middle height if all prices are same
            }
            
            // Get color for price (grey when no real data)
            const defaultBarColor = getPriceColor(price, minPrice, maxPrice);
            
            // Format display text
            const priceDisplay = formatPrice(price);
            
            // Create key for schedule lookup (YYYYMMDDHHmm format)
            const hourTime = hourKey + '00';
            const key = dateStr + hourTime;
            
            const {
                value: scheduledValue,
                source: scheduledSource,
                hasRuntimeCondition,
                runtimeConditions,
                ruleName,
                ruleIndex,
                minValue,
                maxValue
            } = getActiveScheduleInfo(dateStr, hourKey);
            const rawScheduleEntry = scheduleMap[key];

            // Create bar element
            const barDiv = document.createElement('div');
            barDiv.className = `price-graph-bar ${isCurrentHour ? 'price-current' : ''}`;
            barDiv.dataset.date = dateStr;
            barDiv.dataset.hour = h;
            barDiv.dataset.time = hourTime;
            barDiv.dataset.key = key;
            barDiv.dataset.price = hasRealPrice ? price : '';
            barDiv.dataset.proxy = hasRealPrice ? '' : 'true';
            const scheduleType = scheduledValue !== undefined ? getScheduleType(scheduledValue) : '';
            if (scheduleType) {
                barDiv.classList.add('has-schedule');
                barDiv.dataset.scheduleType = scheduleType;
                barDiv.dataset.scheduleValue = scheduledValue;
                if (hasRuntimeCondition === true) {
                    barDiv.classList.add('has-runtime-condition');
                    barDiv.dataset.runtimeCondition = 'true';
                }
            }
            if (Array.isArray(runtimeConditions) && runtimeConditions.length > 0) {
                barDiv.dataset.runtimeConditions = JSON.stringify(runtimeConditions);
            }
            if (scheduledSource !== undefined && scheduledSource !== null && scheduledSource !== '') {
                barDiv.dataset.scheduleSource = String(scheduledSource);
            }
            if (rawScheduleEntry) {
                barDiv.dataset.scheduleEntry = JSON.stringify(rawScheduleEntry);
            }
            if (ruleName !== undefined && ruleName !== null && String(ruleName).trim() !== '') {
                barDiv.dataset.ruleName = String(ruleName);
            }
            if (ruleIndex !== undefined && ruleIndex !== null && String(ruleIndex).trim() !== '') {
                barDiv.dataset.ruleIndex = String(ruleIndex);
            }
            if (minValue !== undefined && minValue !== null && Number.isFinite(Number(minValue))) {
                barDiv.dataset.minValue = String(minValue);
            }
            if (maxValue !== undefined && maxValue !== null && Number.isFinite(Number(maxValue))) {
                barDiv.dataset.maxValue = String(maxValue);
            }
            barDiv.setAttribute('aria-label', `${hourKey}:00 - ${hasRealPrice ? priceDisplay : 'No price data available'}`);
            
            const overrideRuleColor = ruleIndex !== undefined && ruleIndex !== null
                ? normalizeRuleOverrideColor(ruleColorMap[String(ruleIndex)])
                : '';

            const barInner = document.createElement('div');
            barInner.className = `price-graph-bar-inner ${!hasRealPrice ? 'price-null' : ''}`;
            barInner.style.height = barHeight;
            barInner.style.backgroundColor = defaultBarColor;
            if (overrideRuleColor) {
                barDiv.dataset.ruleColor = overrideRuleColor;
                barDiv.style.setProperty('--rule-dot-color', overrideRuleColor);
            }
            
            const barLabel = document.createElement('div');
            barLabel.className = 'price-graph-bar-label';
            const ruleGroupIdentity = getRuleGroupIdentity(dateStr, hourKey);
            const previousRuleGroupIdentity = h > 0
                ? getRuleGroupIdentity(dateStr, String(h - 1).padStart(2, '0'))
                : null;
            if (h === 0 || ruleGroupIdentity !== previousRuleGroupIdentity) {
                barLabel.dataset.blockStart = 'true';
            }
            barLabel.textContent = hourKey;
            
            // Create price label element
            const priceLabel = document.createElement('div');
            priceLabel.className = 'price-graph-bar-price';
            priceLabel.textContent = formatPriceCents(price);
            
            barDiv.appendChild(barInner);
            barDiv.appendChild(barLabel);
            barDiv.appendChild(priceLabel);
            
            // On mobile: no hover/focus tooltip (only click opens the mobile popup)
            if (!isPriceGraphMobile()) {
                const showPopup = () => showPriceGraphPopup(barDiv, container);
                const hidePopup = () => hidePriceGraphPopup();
                barDiv.addEventListener('mouseenter', showPopup);
                barDiv.addEventListener('mouseleave', hidePopup);
                barDiv.addEventListener('focus', showPopup);
                barDiv.addEventListener('blur', hidePopup);
            }
            // Add click handler: on mobile show info popup first; on desktop open edit modal directly
            barDiv.addEventListener('click', () => {
                if (isPriceGraphMobile()) {
                    showPriceGraphMobilePopup(barDiv, editModal, scheduleMap, key);
                } else if (editModal) {
                    const existingEntry = scheduleMap[key];
                    if (existingEntry !== undefined) {
                        editModal.open(key, existingEntry);
                    } else {
                        editModal.open(null, null, key);
                    }
                }
            });
            
            container.appendChild(barDiv);
        }
    };
    
    // Calculate tomorrow's date string
    const tomorrowDateStr = scheduleContext?.tomorrowDate || (() => {
        const tomorrowDate = new Date(now);
        tomorrowDate.setDate(tomorrowDate.getDate() + 1);
        return tomorrowDate.getFullYear().toString() +
            String(tomorrowDate.getMonth() + 1).padStart(2, '0') +
            String(tomorrowDate.getDate()).padStart(2, '0');
    })();

    const todayHourMap = buildExpandedHourMap(resolvedToday);
    const tomorrowHourMap = buildExpandedHourMap(resolvedTomorrow);
    if (todayHourMap) {
        scheduleHourMapByDate[currentDate] = todayHourMap;
    }
    if (tomorrowHourMap) {
        scheduleHourMapByDate[tomorrowDateStr] = tomorrowHourMap;
    }
    const todaySourceMap = buildExpandedSourceMap(resolvedToday);
    const tomorrowSourceMap = buildExpandedSourceMap(resolvedTomorrow);
    const todayRuntimeConditionMap = buildExpandedRuntimeConditionMap(resolvedToday);
    const tomorrowRuntimeConditionMap = buildExpandedRuntimeConditionMap(resolvedTomorrow);
    const todayRuntimeConditionsArrayMap = buildExpandedRuntimeConditionsArrayMap(resolvedToday);
    const tomorrowRuntimeConditionsArrayMap = buildExpandedRuntimeConditionsArrayMap(resolvedTomorrow);
    const todayRuleMetaMap = buildExpandedRuleMetaMap(resolvedToday);
    const tomorrowRuleMetaMap = buildExpandedRuleMetaMap(resolvedTomorrow);
    if (todaySourceMap) {
        scheduleSourceMapByDate[currentDate] = todaySourceMap;
    }
    if (tomorrowSourceMap) {
        scheduleSourceMapByDate[tomorrowDateStr] = tomorrowSourceMap;
    }
    if (todayRuntimeConditionMap) {
        scheduleRuntimeConditionMapByDate[currentDate] = todayRuntimeConditionMap;
    }
    if (tomorrowRuntimeConditionMap) {
        scheduleRuntimeConditionMapByDate[tomorrowDateStr] = tomorrowRuntimeConditionMap;
    }
    if (todayRuntimeConditionsArrayMap) {
        scheduleRuntimeConditionsArrayMapByDate[currentDate] = todayRuntimeConditionsArrayMap;
    }
    if (tomorrowRuntimeConditionsArrayMap) {
        scheduleRuntimeConditionsArrayMapByDate[tomorrowDateStr] = tomorrowRuntimeConditionsArrayMap;
    }
    if (todayRuleMetaMap) {
        scheduleRuleMetaMapByDate[currentDate] = todayRuleMetaMap;
    }
    if (tomorrowRuleMetaMap) {
        scheduleRuleMetaMapByDate[tomorrowDateStr] = tomorrowRuleMetaMap;
    }
    
    // Render today
    renderPriceRow(todayPrices, currentDate, todayContainer, true);
    if (priceData && priceData.error) {
        const errEl = document.createElement('div');
        errEl.className = 'price-graph-api-error';
        errEl.textContent = priceData.error;
        todayContainer.prepend(errEl);
    }
    
    // Render tomorrow in desktop container (will use proxy prices when no real data)
    if (tomorrowContainer) {
        renderPriceRow(tomorrowPrices, tomorrowDateStr, tomorrowContainer, false);
    }
    
    // Render tomorrow in mobile container (will use proxy prices when no real data)
    if (tomorrowContainerMobile) {
        const pricesToRender = tomorrowPrices || {};
        renderPriceRow(pricesToRender, tomorrowDateStr, tomorrowContainerMobile, false);
    }

    initPriceGraphMobileZoomControls();
    
    // Auto-scroll to current time (center it) for desktop view.
    // Use the today container and its nearest price-graph-container instead of the first match on the page.
    setTimeout(() => {
        if (!todayContainer) {
            return;
        }

        const currentBar = todayContainer.querySelector('.price-graph-bar.price-current');
        const container =
            todayContainer.closest('.price-graph-container') || todayContainer;

        if (currentBar && container && typeof container.scrollTo === 'function') {
            const containerWidth = container.clientWidth;
            const barLeft = currentBar.offsetLeft;
            const barWidth = currentBar.clientWidth;

            // Calculate scroll position to center the bar
            const scrollPos = barLeft - (containerWidth / 2) + (barWidth / 2);
            container.scrollTo({
                left: scrollPos,
                behavior: 'smooth'
            });
        }
    }, 100);
}

/**
 * Fetches price data from API and renders the graph
 * @param {string|string[]} priceApiUrl - URL or list of URLs to price API endpoint(s)
 * @param {Array} scheduleEntries - Array of schedule entries for lookup
 * @param {Object} editModal - Edit modal instance for click handlers
 */
async function fetchAndRenderPrices(priceApiUrl, scheduleEntries, editModal) {
    const normalizeUrls = (input) => {
        if (Array.isArray(input)) {
            return input
                .filter((u) => typeof u === 'string')
                .map((u) => u.trim())
                .filter((u) => u.length > 0);
        }
        if (typeof input === 'string' && input.trim().length > 0) {
            return [input.trim()];
        }
        return [];
    };

    const shuffle = (arr) => {
        const copy = arr.slice();
        for (let i = copy.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            const tmp = copy[i];
            copy[i] = copy[j];
            copy[j] = tmp;
        }
        return copy;
    };

    const candidateUrls = shuffle(normalizeUrls(priceApiUrl));

    try {
        if (candidateUrls.length === 0) {
            throw new Error('No price API URL configured');
        }

        let lastError = null;
        let priceData = null;

        for (const url of candidateUrls) {
            try {
                const response = await fetch(url);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status} from ${url}`);
                }

                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    console.error('Non-JSON response from price API:', url, text.substring(0, 200));
                    throw new Error(`Non-JSON response from ${url}`);
                }

                const parsed = await response.json();
                if (!parsed || typeof parsed !== 'object' || !('today' in parsed) || !('tomorrow' in parsed)) {
                    throw new Error(`Invalid price payload from ${url}`);
                }

                priceData = parsed;
                break;
            } catch (err) {
                lastError = err;
                console.warn('Price API candidate failed:', url, err);
            }
        }

        if (!priceData) {
            throw (lastError || new Error('All price API URLs failed'));
        }
        
        let ruleColorMap = {};
        try {
            const rules = await getPriceGraphRules();
            ruleColorMap = buildRuleColorMap(rules);
        } catch (ruleError) {
            console.warn('Failed to load rule colors for price graph:', ruleError);
        }

        // Get current hour
        const now = new Date();
        const currentHour = now.getHours();
        
        // Render the price graph
        renderPriceGraph(priceData, currentHour, scheduleEntries, editModal, ruleColorMap);
    } catch (e) {
        console.error('Failed to fetch prices:', e);
        const errorMessage = e && (e.message || String(e));
        const now = new Date();
        const currentHour = now.getHours();
        renderPriceGraph({ today: {}, tomorrow: {}, error: errorMessage }, currentHour, scheduleEntries, editModal, {});
    }
}

document.addEventListener('DOMContentLoaded', () => {
    initPriceGraphMobileZoomControls();
});
