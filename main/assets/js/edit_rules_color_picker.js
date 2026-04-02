(function () {
    'use strict';

    const HEX_COLOR_PATTERN = /^#([0-9a-fA-F]{6})$/;
    const DEFAULT_PICKER_COLOR = '#808080';

    function normalizeHexColor(value) {
        const rawValue = String(value || '').trim();
        if (!rawValue) {
            return '';
        }
        return HEX_COLOR_PATTERN.test(rawValue) ? rawValue.toUpperCase() : '';
    }

    function dispatchFieldEvents(field, eventTypes) {
        eventTypes.forEach(function (eventType) {
            field.dispatchEvent(new Event(eventType, { bubbles: true }));
        });
    }

    function updateSelectedDot(dot, hexValue) {
        if (!dot) {
            return;
        }

        if (hexValue) {
            dot.style.backgroundColor = hexValue;
            dot.classList.add('is-active');
            dot.setAttribute('aria-label', 'Selected rule color ' + hexValue);
            return;
        }

        dot.style.backgroundColor = '';
        dot.classList.remove('is-active');
        dot.setAttribute('aria-label', 'No rule color selected');
    }

    function enhanceColorField(fieldRoot) {
        if (!fieldRoot || fieldRoot.dataset.colorPickerEnhanced === 'true') {
            return;
        }

        const textField = fieldRoot.querySelector('#inp-color');
        if (!textField) {
            return;
        }

        const controls = document.createElement('div');
        controls.className = 'rule-color-picker-controls';

        const colorPicker = document.createElement('input');
        colorPicker.type = 'color';
        colorPicker.className = 'rule-color-picker-input';
        colorPicker.value = DEFAULT_PICKER_COLOR;
        colorPicker.defaultValue = DEFAULT_PICKER_COLOR;
        colorPicker.setAttribute('aria-label', 'Pick rule color');

        const colorDot = document.createElement('span');
        colorDot.className = 'rule-color-picker-dot';
        colorDot.setAttribute('role', 'img');
        colorDot.setAttribute('aria-label', 'No rule color selected');

        controls.appendChild(colorPicker);
        controls.appendChild(colorDot);
        fieldRoot.appendChild(controls);

        const syncFromTextField = function () {
            const normalizedColor = normalizeHexColor(textField.value);
            if (normalizedColor) {
                colorPicker.value = normalizedColor;
            } else {
                colorPicker.value = DEFAULT_PICKER_COLOR;
            }
            updateSelectedDot(colorDot, normalizedColor);
        };

        colorPicker.addEventListener('input', function () {
            const nextColor = normalizeHexColor(colorPicker.value) || DEFAULT_PICKER_COLOR;
            textField.value = nextColor;
            updateSelectedDot(colorDot, nextColor);
            dispatchFieldEvents(textField, ['input', 'change']);
        });

        textField.addEventListener('input', syncFromTextField);
        textField.addEventListener('change', syncFromTextField);
        fieldRoot.form?.addEventListener('reset', function () {
            colorPicker.value = DEFAULT_PICKER_COLOR;
            updateSelectedDot(colorDot, '');
        });

        syncFromTextField();
        fieldRoot.dataset.colorPickerEnhanced = 'true';
    }

    function initRuleColorPicker() {
        const fieldRoot = document.querySelector('[data-rule-color-field]');
        if (!fieldRoot) {
            return;
        }
        enhanceColorField(fieldRoot);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initRuleColorPicker);
    } else {
        initRuleColorPicker();
    }
})();
