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
        var patternCharacters = Array.from(pattern);
        var entered = [];
        var candidateIndex = 0;

        patternCharacters.forEach(function (patternCharacter) {
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

    function maskCharacters(value, pattern, prefix, suffix) {
        return maskState(value, pattern, prefix, suffix).entered;
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

    function formatMask(value, pattern, prefix, suffix) {
        var state = maskState(value, pattern, prefix, suffix);
        return formatEnteredMask(state.entered, pattern, prefix, suffix, state.started);
    }

    function initialiseInputRule(control) {
        var rule = control.getAttribute('data-onboarding-input-rule') || 'none';
        var maxLength = parseInt(control.getAttribute('data-onboarding-max-length') || '0', 10) || 0;
        var pattern = control.getAttribute('data-onboarding-mask') || '';
        var prefix = control.getAttribute('data-onboarding-mask-prefix') || '';
        var suffix = control.getAttribute('data-onboarding-mask-suffix') || '';
        var tokenCount = Array.from(pattern).filter(isMaskToken).length;

        function applyRule() {
            var value = control.value;
            if (rule === 'numbers') value = value.replace(/[^0-9]/g, '');
            if (rule === 'pattern' && tokenCount > 0) value = formatMask(value, pattern, prefix, suffix);
            if (maxLength > 0) value = Array.from(value).slice(0, maxLength).join('');
            if (control.value !== value) control.value = value;

            if (rule === 'pattern' && value !== '' && maskCharacters(value, pattern, prefix, suffix).length !== tokenCount) {
                control.setCustomValidity('Please use the requested format: ' + (control.getAttribute('placeholder') || pattern));
            } else {
                control.setCustomValidity('');
            }
        }

        control.addEventListener('input', applyRule);
        control.addEventListener('blur', applyRule);
        if (rule === 'pattern') {
            control.addEventListener('keydown', function (event) {
                if (event.key !== 'Backspace' || control.selectionStart !== control.selectionEnd || control.selectionEnd !== control.value.length) return;
                var hasSuffix = suffix !== '' && control.value.slice(-suffix.length) === suffix;
                var entered = maskCharacters(control.value, pattern, prefix, suffix);
                if (!entered.length) return;
                var enteredAfterNativeBackspace = maskCharacters(control.value.slice(0, -1), pattern, prefix, suffix);
                if (!hasSuffix && enteredAfterNativeBackspace.length < entered.length) return;
                entered.pop();
                event.preventDefault();
                control.value = formatEnteredMask(entered, pattern, prefix, suffix, true);
                applyRule();
            });
        }
        applyRule();
    }

    function dateFormatDefinition(format) {
        var definitions = {
            dmy_slash: { groups: [2, 2, 4], separator: '/', order: ['day', 'month', 'year'], placeholder: 'DD/MM/YYYY' },
            mdy_slash: { groups: [2, 2, 4], separator: '/', order: ['month', 'day', 'year'], placeholder: 'MM/DD/YYYY' },
            ymd_dash: { groups: [4, 2, 2], separator: '-', order: ['year', 'month', 'day'], placeholder: 'YYYY-MM-DD' },
            dmy_dash: { groups: [2, 2, 4], separator: '-', order: ['day', 'month', 'year'], placeholder: 'DD-MM-YYYY' }
        };
        return definitions[format] || definitions.dmy_slash;
    }

    function formatDateInput(value, format) {
        var definition = dateFormatDefinition(format);
        var digits = String(value || '').replace(/[^0-9]/g, '').slice(0, 8);
        var parts = [];
        var offset = 0;
        definition.groups.forEach(function (length) {
            if (offset >= digits.length) return;
            parts.push(digits.slice(offset, offset + length));
            offset += length;
        });
        var output = parts.join(definition.separator);
        var completedLength = 0;
        for (var index = 0; index < definition.groups.length - 1; index += 1) {
            completedLength += definition.groups[index];
            if (digits.length === completedLength) output += definition.separator;
        }
        return output;
    }

    function dateInputParts(value, format) {
        var definition = dateFormatDefinition(format);
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
        return date.getUTCFullYear() === values.year
            && date.getUTCMonth() + 1 === values.month
            && date.getUTCDate() === values.day
            ? values
            : null;
    }

    function dateInputIsValid(value, format) {
        return dateInputParts(value, format) !== null;
    }

    function padDatePart(value, length) {
        return String(value).padStart(length, '0');
    }

    function dateInputToIso(value, format) {
        var parts = dateInputParts(value, format);
        return parts ? padDatePart(parts.year, 4) + '-' + padDatePart(parts.month, 2) + '-' + padDatePart(parts.day, 2) : '';
    }

    function isoDateToInput(value, format) {
        var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
        if (!match) return '';
        var parts = { year: parseInt(match[1], 10), month: parseInt(match[2], 10), day: parseInt(match[3], 10) };
        var date = new Date(Date.UTC(parts.year, parts.month - 1, parts.day));
        if (date.getUTCFullYear() !== parts.year || date.getUTCMonth() + 1 !== parts.month || date.getUTCDate() !== parts.day) return '';
        var definition = dateFormatDefinition(format);
        var digits = definition.order.map(function (part) {
            return padDatePart(parts[part], part === 'year' ? 4 : 2);
        }).join('');
        return formatDateInput(digits, format);
    }

    function initialiseDateInput(control) {
        var format = control.getAttribute('data-onboarding-date-format') || 'dmy_slash';
        var definition = dateFormatDefinition(format);
        var dateControl = control.closest('[data-onboarding-date-control]');
        var nativePicker = dateControl ? dateControl.querySelector('[data-onboarding-native-date-picker]') : null;
        var pickerTrigger = dateControl ? dateControl.querySelector('[data-onboarding-date-picker-trigger]') : null;

        function applyDateFormat() {
            var value = formatDateInput(control.value, format);
            if (control.value !== value) control.value = value;
            var isoValue = dateInputToIso(value, format);
            if (value !== '' && isoValue === '') {
                control.setCustomValidity('Enter a valid date in the format ' + definition.placeholder + '.');
            } else {
                control.setCustomValidity('');
            }
            if (nativePicker && nativePicker.value !== isoValue) nativePicker.value = isoValue;
        }

        control.addEventListener('input', applyDateFormat);
        control.addEventListener('blur', applyDateFormat);
        control.addEventListener('keydown', function (event) {
            if (event.key !== 'Backspace' || control.selectionStart !== control.selectionEnd || control.selectionEnd !== control.value.length) return;
            var digits = control.value.replace(/[^0-9]/g, '');
            if (!digits.length) return;
            event.preventDefault();
            control.value = formatDateInput(digits.slice(0, -1), format);
            applyDateFormat();
        });
        if (nativePicker) {
            nativePicker.addEventListener('change', function () {
                control.value = nativePicker.value ? isoDateToInput(nativePicker.value, format) : '';
                applyDateFormat();
                control.focus({ preventScroll: true });
            });
        }
        if (pickerTrigger && nativePicker) {
            pickerTrigger.addEventListener('click', function () {
                if (nativePicker.disabled) return;
                if (typeof nativePicker.showPicker === 'function') {
                    try {
                        nativePicker.showPicker();
                        return;
                    } catch (error) {
                        // Fall through for browsers that expose showPicker but block it.
                    }
                }
                nativePicker.focus({ preventScroll: true });
                nativePicker.click();
            });
        }
        applyDateFormat();
    }

    function initialiseConditionalFields(root) {
        var switches = Array.prototype.slice.call(root.querySelectorAll('[data-onboarding-switch]'));
        var conditionalFields = Array.prototype.slice.call(root.querySelectorAll('[data-onboarding-conditional-field]'));

        function fieldControls(field) {
            if (field.matches && field.matches('input, select, textarea')) return [field];
            return Array.prototype.slice.call(field.querySelectorAll('input, select, textarea'));
        }

        function switchIsOn(key) {
            var selected = switches.find(function (candidate) {
                return candidate.getAttribute('data-onboarding-switch') === key && candidate.checked;
            });
            return !!(selected && ['1', 'yes', 'true', 'on'].indexOf(String(selected.value).toLowerCase()) !== -1);
        }

        function sync() {
            switches.forEach(function (controller) {
                controller.setAttribute('aria-checked', controller.checked ? 'true' : 'false');
            });

            conditionalFields.forEach(function (field) {
                var switchKey = field.getAttribute('data-onboarding-condition-switch') || '';
                var expected = field.getAttribute('data-onboarding-condition-value') !== 'off';
                var visible = switchIsOn(switchKey) === expected;
                var step = field.closest('[data-onboarding-step]');
                var stepIsActive = !step || step.getAttribute('aria-hidden') !== 'true';
                var controls = fieldControls(field);

                field.hidden = !visible;
                field.setAttribute('aria-hidden', visible ? 'false' : 'true');
                controls.forEach(function (control) {
                    control.disabled = !visible || !stepIsActive;
                    control.required = false;
                });

                if (visible && field.getAttribute('data-onboarding-condition-required') === '1') {
                    var requiredControl = controls.find(function (control) {
                        return control.type !== 'hidden' && !control.hasAttribute('data-onboarding-native-date-picker');
                    });
                    if (requiredControl) requiredControl.required = true;
                }
            });
        }

        switches.forEach(function (controller) {
            controller.addEventListener('change', sync);
        });
        sync();
        return sync;
    }

    document.querySelectorAll('[data-cliniko-patient-onboarding]').forEach(function (root) {
        var placement = root.getAttribute('data-onboarding-placement') || 'inline';
        var isOverlay = placement === 'modal' || placement === 'blocking_overlay';
        var isComplete = parseInt(root.getAttribute('data-missing-count') || '0', 10) === 0;

        // The completion query parameter is a one-time flash state. Remove it
        // from the address so refreshing or revisiting the page cannot reopen
        // a completed onboarding flow.
        if (isComplete && window.history && typeof window.history.replaceState === 'function') {
            var currentUrl = new URL(window.location.href);
            if (currentUrl.searchParams.has('cliniko_onboarding_saved')) {
                currentUrl.searchParams.delete('cliniko_onboarding_saved');
                window.history.replaceState({}, document.title, currentUrl.toString());
            }
        }

        // Elementor containers can apply opacity, filters, transforms, or
        // stacking contexts to their children. Portal overlays to the body so
        // the modal is composited independently of the shortcode container.
        if (isOverlay && root.parentNode !== document.body) {
            document.body.appendChild(root);
        }

        function lockBody() {
            if (document.body.classList.contains('cliniko-patient-onboarding-modal-open')) return;
            var scrollY = window.scrollY || window.pageYOffset || 0;
            document.body.dataset.clinikoOnboardingScrollY = String(scrollY);
            document.body.style.top = '-' + scrollY + 'px';
            document.body.classList.add('cliniko-patient-onboarding-modal-open');
        }

        function unlockBody() {
            var scrollY = parseInt(document.body.dataset.clinikoOnboardingScrollY || '0', 10);
            document.body.classList.remove('cliniko-patient-onboarding-modal-open');
            document.body.style.top = '';
            delete document.body.dataset.clinikoOnboardingScrollY;
            window.scrollTo(0, scrollY);
        }

        function unlockBodyIfUnused() {
            var openOverlay = Array.prototype.some.call(
                document.querySelectorAll('[data-cliniko-patient-onboarding]'),
                function (candidate) {
                    return !candidate.hidden && ['modal', 'blocking_overlay'].indexOf(candidate.getAttribute('data-onboarding-placement')) !== -1;
                }
            );
            if (!openOverlay) unlockBody();
        }

        function closeOverlay() {
            if (!isOverlay || placement === 'blocking_overlay') return;
            root.hidden = true;
            root.setAttribute('aria-hidden', 'true');
            unlockBodyIfUnused();
        }

        if (isOverlay) {
            lockBody();
            var close = root.querySelector('[data-onboarding-close]');
            var backdrop = root.querySelector('[data-onboarding-backdrop]');
            if (close) close.addEventListener('click', closeOverlay);
            if (backdrop) backdrop.addEventListener('click', closeOverlay);
            if (placement === 'modal') {
                root.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape') closeOverlay();
                });
            }
        }

        var welcome = root.querySelector('[data-onboarding-welcome]');
        var flow = root.querySelector('[data-onboarding-flow]');
        var start = root.querySelector('[data-onboarding-start]');
        if (welcome && flow && start) {
            start.addEventListener('click', function () {
                welcome.hidden = true;
                flow.hidden = false;
                var panel = root.querySelector('.cliniko-patient-onboarding__panel');
                if (panel) panel.scrollTo({ top: 0, behavior: 'smooth' });
                var firstControl = flow.querySelector('input:not([type="hidden"]), select, textarea, button');
                if (firstControl) firstControl.focus({ preventScroll: true });
            });
        }

        var form = root.querySelector('[data-onboarding-form]');
        var syncConditionalFields = initialiseConditionalFields(root);
        root.querySelectorAll('[data-onboarding-input-rule], [data-onboarding-max-length]').forEach(initialiseInputRule);
        root.querySelectorAll('[data-onboarding-date-input]').forEach(initialiseDateInput);
        var steps = Array.prototype.slice.call(root.querySelectorAll('[data-onboarding-step]'));
        if (!form || steps.length < 2) return;

        var current = 0;
        var label = root.querySelector('[data-onboarding-progress-label]');
        var fill = root.querySelector('[data-onboarding-progress-fill]');

        function controls(step) {
            return Array.prototype.slice.call(step.querySelectorAll('input, select, textarea'));
        }

        function validateStep(step) {
            var invalid = controls(step).find(function (control) {
                return typeof control.checkValidity === 'function' && !control.checkValidity();
            });
            if (!invalid) return true;
            if (typeof invalid.reportValidity === 'function') invalid.reportValidity();
            return false;
        }

        function setStep(index) {
            current = Math.max(0, Math.min(index, steps.length - 1));
            steps.forEach(function (step, stepIndex) {
                var active = stepIndex === current;
                step.hidden = !active;
                step.setAttribute('aria-hidden', active ? 'false' : 'true');
                controls(step).forEach(function (control) {
                    var conditionalField = control.closest('[data-onboarding-conditional-field]');
                    control.disabled = !active || !!(conditionalField && conditionalField.hidden);
                });
                var back = step.querySelector('[data-onboarding-back]');
                if (back) back.hidden = current === 0;
            });
            syncConditionalFields();
            if (label) label.textContent = 'Step ' + (current + 1) + ' of ' + steps.length;
            if (fill) fill.style.width = (((current + 1) / steps.length) * 100) + '%';
            if (window.ClinikoComponents && window.ClinikoComponents.steps) {
                window.ClinikoComponents.steps.afterChange(root);
            }
        }

        root.addEventListener('click', function (event) {
            var next = event.target.closest('[data-onboarding-next]');
            var back = event.target.closest('[data-onboarding-back]');
            if (next) {
                var active = steps[current];
                if (!validateStep(active)) return;
                setStep(current + 1);
            }
            if (back) setStep(current - 1);
        });

        form.addEventListener('submit', function (event) {
            var firstInvalid = null;
            steps.forEach(function (step, stepIndex) {
                controls(step).forEach(function (control) {
                    var conditionalField = control.closest('[data-onboarding-conditional-field]');
                    var conditionIsHidden = !!(conditionalField && conditionalField.hidden);
                    control.disabled = conditionIsHidden;
                    if (conditionIsHidden) return;
                    if (!firstInvalid && typeof control.checkValidity === 'function' && !control.checkValidity()) {
                        firstInvalid = { control: control, step: stepIndex };
                    }
                });
            });
            if (firstInvalid) {
                event.preventDefault();
                setStep(firstInvalid.step);
                firstInvalid.control.focus();
            }
        });

        setStep(0);
    });
}());
