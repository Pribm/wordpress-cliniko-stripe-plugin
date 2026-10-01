(function () {
    'use strict';

    function isMaskToken(character) {
        return character === '#' || character === 'A' || character === '*';
    }

    function matchesMaskToken(character, token) {
        if (token === '#') return /^[0-9]$/.test(character);
        if (token === 'A') return /^[A-Za-z]$/.test(character);
        return token === '*' && /^[A-Za-z0-9]$/.test(character);
    }

    function stripAffixes(value, prefix, suffix) {
        value = String(value || '');
        if (prefix && value.indexOf(prefix) === 0) value = value.slice(prefix.length);
        if (suffix && value.slice(-suffix.length) === suffix) value = value.slice(0, -suffix.length);
        return value;
    }

    function maskState(value, pattern, prefix, suffix) {
        var body = stripAffixes(value, prefix, suffix);
        var candidates = Array.from(body);
        var entered = [];
        var candidateIndex = 0;
        Array.from(pattern).forEach(function (patternCharacter) {
            if (!isMaskToken(patternCharacter)) {
                if (candidates[candidateIndex] === patternCharacter) candidateIndex += 1;
                return;
            }
            while (candidateIndex < candidates.length) {
                var candidate = candidates[candidateIndex++];
                if (matchesMaskToken(candidate, patternCharacter)) {
                    entered.push(candidate);
                    break;
                }
            }
        });
        return { entered: entered, started: body.length > 0 };
    }

    function formatEnteredMask(entered, pattern, prefix, suffix, started) {
        if (!entered.length && !started) return '';
        var output = '';
        var enteredIndex = 0;
        var patternCharacters = Array.from(pattern);
        for (var index = 0; index < patternCharacters.length; index += 1) {
            var character = patternCharacters[index];
            if (isMaskToken(character)) {
                if (enteredIndex >= entered.length) break;
                output += entered[enteredIndex++];
            } else {
                output += character;
            }
        }
        var tokenCount = patternCharacters.filter(isMaskToken).length;
        return prefix + output + (entered.length === tokenCount ? suffix : '');
    }

    function maskCharacters(value, pattern, prefix, suffix) {
        return maskState(value, pattern, prefix, suffix).entered;
    }

    function initialiseRule(control) {
        if (control.dataset.clinikoRuleReady === '1') return;
        control.dataset.clinikoRuleReady = '1';
        var rule = control.getAttribute('data-cliniko-input-rule') || 'none';
        var maxLength = parseInt(control.getAttribute('data-cliniko-max-length') || '0', 10) || 0;
        var pattern = control.getAttribute('data-cliniko-mask') || '';
        var prefix = control.getAttribute('data-cliniko-mask-prefix') || '';
        var suffix = control.getAttribute('data-cliniko-mask-suffix') || '';
        var tokenCount = Array.from(pattern).filter(isMaskToken).length;

        function apply() {
            var value = control.value;
            if (rule === 'numbers') value = value.replace(/[^0-9]/g, '');
            if (rule === 'pattern' && tokenCount > 0) {
                var state = maskState(value, pattern, prefix, suffix);
                value = formatEnteredMask(state.entered, pattern, prefix, suffix, state.started);
            }
            if (maxLength > 0) value = Array.from(value).slice(0, maxLength).join('');
            if (control.value !== value) control.value = value;
            if (rule === 'pattern' && value !== '' && maskCharacters(value, pattern, prefix, suffix).length !== tokenCount) {
                control.setCustomValidity('Please use the requested format: ' + (control.getAttribute('placeholder') || pattern));
            } else {
                control.setCustomValidity('');
            }
        }

        control.addEventListener('input', apply);
        control.addEventListener('blur', apply);
        if (rule === 'pattern') {
            control.addEventListener('keydown', function (event) {
                if (event.key !== 'Backspace' || control.selectionStart !== control.selectionEnd || control.selectionEnd !== control.value.length) return;
                var entered = maskCharacters(control.value, pattern, prefix, suffix);
                if (!entered.length) return;
                var hasSuffix = suffix !== '' && control.value.slice(-suffix.length) === suffix;
                var nativeResult = maskCharacters(control.value.slice(0, -1), pattern, prefix, suffix);
                if (!hasSuffix && nativeResult.length < entered.length) return;
                entered.pop();
                event.preventDefault();
                control.value = formatEnteredMask(entered, pattern, prefix, suffix, true);
                apply();
            });
        }
        apply();
    }

    function dateDefinition(format) {
        var definitions = {
            dmy_slash: { groups: [2, 2, 4], separator: '/', order: ['day', 'month', 'year'], placeholder: 'DD/MM/YYYY' },
            mdy_slash: { groups: [2, 2, 4], separator: '/', order: ['month', 'day', 'year'], placeholder: 'MM/DD/YYYY' },
            ymd_dash: { groups: [4, 2, 2], separator: '-', order: ['year', 'month', 'day'], placeholder: 'YYYY-MM-DD' },
            dmy_dash: { groups: [2, 2, 4], separator: '-', order: ['day', 'month', 'year'], placeholder: 'DD-MM-YYYY' }
        };
        return definitions[format] || definitions.dmy_slash;
    }

    function formatDate(value, format) {
        var definition = dateDefinition(format);
        var digits = String(value || '').replace(/[^0-9]/g, '').slice(0, 8);
        var parts = [];
        var offset = 0;
        definition.groups.forEach(function (length) {
            if (offset >= digits.length) return;
            parts.push(digits.slice(offset, offset + length));
            offset += length;
        });
        var output = parts.join(definition.separator);
        var completed = 0;
        for (var index = 0; index < definition.groups.length - 1; index += 1) {
            completed += definition.groups[index];
            if (digits.length === completed) output += definition.separator;
        }
        return output;
    }

    function dateParts(value, format) {
        var definition = dateDefinition(format);
        var digits = String(value || '').replace(/[^0-9]/g, '');
        if (digits.length !== 8) return null;
        var values = {};
        var offset = 0;
        definition.groups.forEach(function (length, index) {
            values[definition.order[index]] = parseInt(digits.slice(offset, offset + length), 10);
            offset += length;
        });
        if (values.year < 1000 || values.year > 9999) return null;
        var date = new Date(Date.UTC(values.year, values.month - 1, values.day));
        return date.getUTCFullYear() === values.year && date.getUTCMonth() + 1 === values.month && date.getUTCDate() === values.day ? values : null;
    }

    function toIso(value, format) {
        var parts = dateParts(value, format);
        if (!parts) return '';
        return String(parts.year).padStart(4, '0') + '-' + String(parts.month).padStart(2, '0') + '-' + String(parts.day).padStart(2, '0');
    }

    function fromIso(value, format) {
        var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
        if (!match) return '';
        var definition = dateDefinition(format);
        var values = { year: match[1], month: match[2], day: match[3] };
        return formatDate(definition.order.map(function (key) { return values[key]; }).join(''), format);
    }

    function initialiseDate(control) {
        if (control.dataset.clinikoDateReady === '1') return;
        control.dataset.clinikoDateReady = '1';
        var format = control.getAttribute('data-cliniko-date-format') || 'dmy_slash';
        var wrapper = control.closest('[data-cliniko-date-control]');
        var nativePicker = wrapper ? wrapper.querySelector('[data-cliniko-native-date-picker]') : null;
        var trigger = wrapper ? wrapper.querySelector('[data-cliniko-date-picker-trigger]') : null;

        function apply() {
            var value = formatDate(control.value, format);
            if (control.value !== value) control.value = value;
            var iso = toIso(value, format);
            control.setCustomValidity(value !== '' && iso === '' ? 'Enter a valid date in the format ' + dateDefinition(format).placeholder + '.' : '');
            if (nativePicker && nativePicker.value !== iso) nativePicker.value = iso;
        }
        control.addEventListener('input', apply);
        control.addEventListener('blur', apply);
        control.addEventListener('keydown', function (event) {
            if (event.key !== 'Backspace' || control.selectionStart !== control.selectionEnd || control.selectionEnd !== control.value.length) return;
            var digits = control.value.replace(/[^0-9]/g, '');
            if (!digits.length) return;
            event.preventDefault();
            control.value = formatDate(digits.slice(0, -1), format);
            apply();
        });
        if (nativePicker) {
            nativePicker.addEventListener('change', function () {
                control.value = nativePicker.value ? fromIso(nativePicker.value, format) : '';
                apply();
                control.focus({ preventScroll: true });
            });
        }
        if (trigger && nativePicker) {
            trigger.addEventListener('click', function () {
                if (typeof nativePicker.showPicker === 'function') {
                    try { nativePicker.showPicker(); return; } catch (error) {}
                }
                nativePicker.focus({ preventScroll: true });
                nativePicker.click();
            });
        }
        apply();
    }

    function initialiseRequiredChoiceGroup(group) {
        if (group.dataset.clinikoChoiceGroupReady === '1') return;
        group.dataset.clinikoChoiceGroupReady = '1';
        var controls = Array.from(group.querySelectorAll('input[type="checkbox"], input[type="radio"]'));
        if (!controls.length) return;
        function apply() {
            var hasSelection = controls.some(function (control) { return control.checked; });
            controls[0].setCustomValidity(hasSelection ? '' : 'Select at least one option.');
        }
        controls.forEach(function (control) { control.addEventListener('change', apply); });
        apply();
    }

    function initialiseOtherChoice(toggle) {
        if (toggle.dataset.clinikoOtherReady === '1') return;
        toggle.dataset.clinikoOtherReady = '1';
        var fieldset = toggle.closest('fieldset, [data-question-type]');
        var control = fieldset ? fieldset.querySelector('[data-cliniko-other-control]') : null;
        var input = control ? control.querySelector('[data-cliniko-other-input]') : null;
        if (!fieldset || !control || !input) return;
        function apply() {
            control.hidden = !toggle.checked;
            input.required = toggle.checked;
            if (!toggle.checked) input.setCustomValidity('');
        }
        fieldset.querySelectorAll('input[type="checkbox"], input[type="radio"]').forEach(function (choice) {
            if (choice.name === toggle.name) choice.addEventListener('change', apply);
        });
        apply();
    }

    function init(root) {
        (root || document).querySelectorAll('[data-cliniko-input-rule], [data-cliniko-max-length]').forEach(initialiseRule);
        (root || document).querySelectorAll('[data-cliniko-date-input]').forEach(initialiseDate);
        (root || document).querySelectorAll('[data-cliniko-required-choice-group]').forEach(initialiseRequiredChoiceGroup);
        (root || document).querySelectorAll('[data-cliniko-other-toggle]').forEach(initialiseOtherChoice);
    }

    function valueForSubmission(control) {
        if (!control) return '';
        if (control.hasAttribute('data-cliniko-date-input')) {
            return toIso(control.value, control.getAttribute('data-cliniko-date-format') || 'dmy_slash');
        }
        return control.value;
    }

    window.ClinikoShortcodeInputRules = { init: init, valueForSubmission: valueForSubmission };
    init(document);
}());
