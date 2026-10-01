(function () {
    'use strict';

    var form = document.querySelector('[data-cliniko-onboarding-builder]');
    if (!form) return;

    var stepsRoot = form.querySelector('[data-onboarding-steps]');
    var configInput = form.querySelector('[data-onboarding-steps-config]');
    var availableScript = form.querySelector('[data-onboarding-available-fields]');
    var conditionsRoot = form.querySelector('[data-onboarding-conditions]');
    var conditionsInput = form.querySelector('[data-onboarding-conditions-config]');
    var pagesScript = form.querySelector('[data-onboarding-pages]');
    var tabsRoot = form.querySelector('[data-onboarding-builder-tabs]');
    var placementControl = form.querySelector('[data-onboarding-placement-control]');
    var overlaySizeField = form.querySelector('[data-onboarding-overlay-size-field]');
    var customColorsControl = form.querySelector('[data-onboarding-custom-colors]');
    var colorControls = form.querySelector('[data-onboarding-color-controls]');
    var welcomeEnabledControl = form.querySelector('[data-onboarding-welcome-enabled]');
    var welcomeControls = form.querySelector('[data-onboarding-welcome-controls]');
    var welcomeSourceControl = form.querySelector('[data-onboarding-welcome-source]');
    var welcomeShortcodeField = form.querySelector('[data-onboarding-welcome-shortcode]');
    var welcomeHtmlFields = form.querySelectorAll('[data-onboarding-welcome-html]');
    if (!stepsRoot || !configInput || !availableScript) return;

    var available = [];
    var byKey = {};
    try { available = JSON.parse(availableScript.textContent || '[]'); } catch (error) { available = []; }
    available.forEach(function (field) { byKey[field.key] = field; });

    var initial = [];
    try { initial = JSON.parse(configInput.value || '[]'); } catch (error) { initial = []; }
    var state = Array.isArray(initial) ? initial : [];
    var pages = [];
    var conditionsInitial = [];
    try { pages = JSON.parse(pagesScript ? pagesScript.textContent : '[]'); } catch (error) { pages = []; }
    try { conditionsInitial = JSON.parse(conditionsInput ? conditionsInput.value : '[]'); } catch (error) { conditionsInitial = []; }
    var conditionsState = Array.isArray(conditionsInitial) ? conditionsInitial : [];

    function selectTab(name) {
        form.querySelectorAll('[data-onboarding-tab]').forEach(function (button) {
            var active = button.dataset.onboardingTab === name;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        form.querySelectorAll('[data-onboarding-tab-panel]').forEach(function (panel) {
            var active = panel.dataset.onboardingTabPanel === name;
            panel.hidden = !active;
            panel.classList.toggle('is-active', active);
        });
    }

    function syncPlacementControls() {
        if (!placementControl || !overlaySizeField) return;
        var isOverlay = placementControl.value === 'modal' || placementControl.value === 'blocking_overlay';
        overlaySizeField.hidden = !isOverlay;
        var select = overlaySizeField.querySelector('select');
        if (select) select.disabled = !isOverlay;
    }

    function syncColorControls() {
        if (!customColorsControl || !colorControls) return;
        var enabled = customColorsControl.checked;
        colorControls.classList.toggle('is-disabled', !enabled);
        colorControls.querySelectorAll('input').forEach(function (input) {
            input.disabled = !enabled;
        });
    }

    function syncWelcomeControls() {
        if (!welcomeEnabledControl || !welcomeControls) return;
        welcomeControls.hidden = !welcomeEnabledControl.checked;
        var source = welcomeSourceControl ? welcomeSourceControl.value : 'shortcode';
        if (welcomeShortcodeField) welcomeShortcodeField.hidden = source !== 'shortcode';
        welcomeHtmlFields.forEach(function (field) { field.hidden = source !== 'html'; });
    }

    if (tabsRoot) {
        tabsRoot.addEventListener('click', function (event) {
            var button = event.target.closest('[data-onboarding-tab]');
            if (!button) return;
            selectTab(button.dataset.onboardingTab);
        });
    }
    form.addEventListener('invalid', function (event) {
        var panel = event.target.closest('[data-onboarding-tab-panel]');
        if (panel) selectTab(panel.dataset.onboardingTabPanel);
    }, true);
    if (placementControl) placementControl.addEventListener('change', syncPlacementControls);
    if (customColorsControl) customColorsControl.addEventListener('change', syncColorControls);
    if (welcomeEnabledControl) welcomeEnabledControl.addEventListener('change', syncWelcomeControls);
    if (welcomeSourceControl) welcomeSourceControl.addEventListener('change', syncWelcomeControls);

    function uid() {
        return 'onboarding-step-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);
    }

    function switchUid() {
        return 'switch_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8);
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[character];
        });
    }

    function fieldOptions(selected) {
        var standard = available.filter(function (field) { return !field.custom; });
        var custom = available.filter(function (field) { return field.custom; });
        function options(fields) {
            return fields.map(function (field) {
                return '<option value="' + escapeHtml(field.key) + '"' + (field.key === selected ? ' selected' : '') + '>' + escapeHtml(field.label) + '</option>';
            }).join('');
        }
        var html = '<option value="">Select a patient field</option>' + options(standard);
        if (custom.length) html += '<optgroup label="Cliniko custom fields">' + options(custom) + '</optgroup>';
        return html;
    }

    function logicSwitches() {
        var switches = [];
        state.forEach(function (step) {
            (Array.isArray(step.fields) ? step.fields : []).forEach(function (field) {
                if (field.kind === 'switch' && field.key) switches.push(field);
            });
        });
        return switches;
    }

    function renderConditionalSettings(field) {
        var switches = logicSwitches();
        var selectedSwitch = String(field.condition_switch || '');
        var selectedValue = field.condition_value === 'off' ? 'off' : 'on';
        var selected = selectedSwitch ? selectedSwitch + ':' + selectedValue : '';
        var options = '<option value=""' + (selected === '' ? ' selected' : '') + '>Always show this field</option>';
        switches.forEach(function (logicSwitch) {
            var label = logicSwitch.label || 'Untitled switch';
            var onLabel = logicSwitch.on_label || 'Yes';
            var offLabel = logicSwitch.off_label || 'No';
            options += '<optgroup label="' + escapeHtml(label) + '">' +
                '<option value="' + escapeHtml(logicSwitch.key + ':on') + '"' + (selected === logicSwitch.key + ':on' ? ' selected' : '') + '>Show when “' + escapeHtml(onLabel) + '” is selected</option>' +
                '<option value="' + escapeHtml(logicSwitch.key + ':off') + '"' + (selected === logicSwitch.key + ':off' ? ' selected' : '') + '>Show when “' + escapeHtml(offLabel) + '” is selected</option>' +
            '</optgroup>';
        });
        return '<details class="cliniko-onboarding-condition-settings" data-field-condition-settings' + (selectedSwitch ? ' open' : '') + '>' +
            '<summary><span>Conditional visibility <small>Optional</small></span><em>' + (selectedSwitch ? 'Controlled by a choice' : 'Always visible') + '</em></summary>' +
            '<div><label>Display rule <select data-field-condition>' + options + '</select><small>' +
                (switches.length ? 'Place the controlling choice before this field. If this field is required, it is required only while visible.' : 'Add a conditional choice to create display rules.') +
            '</small></label></div>' +
        '</details>';
    }

    function normaliseInputFormat(value) {
        return ['none', 'numbers', 'pattern'].indexOf(value) !== -1 ? value : 'none';
    }

    function inputTypeCapabilities(definition) {
        var type = String(definition.type || '').toLowerCase();
        var clinikoType = String(definition.cliniko_field_type || '').toLowerCase();
        var scalar = ['checkbox', 'checkboxes', 'multi_checkbox', 'multi_select', 'paragraph', 'hidden'].indexOf(type) === -1
            && ['checkbox', 'checkboxes', 'multi_checkbox', 'radiobuttons', 'radio'].indexOf(clinikoType) === -1;
        return {
            type: type,
            text: scalar,
            date: scalar && ['text', 'tel', 'date'].indexOf(type) !== -1,
            select: scalar && ['text', 'tel', 'select', 'textarea'].indexOf(type) !== -1
        };
    }

    function normaliseInputType(value, definition) {
        var capabilities = inputTypeCapabilities(definition);
        if (value === 'text' && capabilities.text) return 'text';
        if (value === 'date' && capabilities.date) return 'date';
        if (value === 'select' && capabilities.select) return 'select';
        return 'default';
    }

    function effectiveFieldType(field, definition) {
        var inputType = normaliseInputType(field.input_type || 'default', definition);
        return inputType === 'default' ? String(definition.type || '').toLowerCase() : inputType;
    }

    function fieldTypeLabel(type) {
        var labels = {
            text: 'Text', tel: 'Telephone', email: 'Email', url: 'URL', search: 'Search', textarea: 'Long text',
            number: 'Number', date: 'Date', select: 'Dropdown', checkbox: 'Checkbox', checkboxes: 'Checkboxes',
            multi_checkbox: 'Checkboxes', multi_select: 'Multiple choice', paragraph: 'Paragraph', hidden: 'Hidden'
        };
        return labels[type] || 'Original control';
    }

    function renderInputTypeSettings(field, definition) {
        var capabilities = inputTypeCapabilities(definition);
        var inputType = normaliseInputType(field.input_type || 'default', definition);
        var dateFormat = ['dmy_slash', 'mdy_slash', 'ymd_dash', 'dmy_dash'].indexOf(field.date_format) !== -1 ? field.date_format : 'dmy_slash';
        var selectOptions = Array.isArray(field.select_options) && field.select_options.length ? field.select_options : [''];
        var typeOptions = '<option value="default"' + (inputType === 'default' ? ' selected' : '') + '>Use field default (' + escapeHtml(fieldTypeLabel(capabilities.type)) + ')</option>';
        if (capabilities.text) typeOptions += '<option value="text"' + (inputType === 'text' ? ' selected' : '') + '>Text input</option>';
        if (capabilities.date) typeOptions += '<option value="date"' + (inputType === 'date' ? ' selected' : '') + '>Formatted date</option>';
        if (capabilities.select) typeOptions += '<option value="select"' + (inputType === 'select' ? ' selected' : '') + '>Dropdown select</option>';

        return '<section class="cliniko-onboarding-input-type" data-field-input-type-settings>' +
            '<div class="cliniko-onboarding-input-type__heading"><strong>Input type</strong><span>Choose how this question appears to the patient.</span></div>' +
            '<label>Control <select data-field-input-type>' + typeOptions + '</select><small>Use the default unless this onboarding needs a different control.</small></label>' +
            '<div class="cliniko-onboarding-date-settings" data-field-date-settings' + (inputType === 'date' ? '' : ' hidden') + '>' +
                '<label>Date shown as <select data-field-date-format>' +
                    '<option value="dmy_slash"' + (dateFormat === 'dmy_slash' ? ' selected' : '') + '>DD/MM/YYYY - 31/12/2026</option>' +
                    '<option value="mdy_slash"' + (dateFormat === 'mdy_slash' ? ' selected' : '') + '>MM/DD/YYYY - 12/31/2026</option>' +
                    '<option value="ymd_dash"' + (dateFormat === 'ymd_dash' ? ' selected' : '') + '>YYYY-MM-DD - 2026-12-31</option>' +
                    '<option value="dmy_dash"' + (dateFormat === 'dmy_dash' ? ' selected' : '') + '>DD-MM-YYYY - 31-12-2026</option>' +
                '</select><small>This changes only the patient-facing input. Cliniko still receives YYYY-MM-DD.</small></label>' +
            '</div>' +
            '<div class="cliniko-onboarding-select-settings" data-field-select-settings' + (inputType === 'select' ? '' : ' hidden') + '>' +
                '<div><strong>Dropdown options</strong><span>Enter one option per line. The line order is the order patients will see.</span></div>' +
                '<label>Options <textarea rows="7" maxlength="15000" data-field-select-options placeholder="First option&#10;Second option&#10;Third option">' + escapeHtml(selectOptions.join('\n')) + '</textarea><small>Empty lines and duplicate options are ignored.</small></label>' +
            '</div>' +
        '</section>';
    }

    function syncInputTypeControls(article) {
        if (!article) return;
        var settings = article.querySelector('[data-field-input-type-settings]');
        if (!settings) return;
        var definition = byKey[article.querySelector('[data-field-key]').value] || {};
        var inputTypeControl = settings.querySelector('[data-field-input-type]');
        var inputType = normaliseInputType(inputTypeControl ? inputTypeControl.value : 'default', definition);
        var dateSettings = settings.querySelector('[data-field-date-settings]');
        var selectSettings = settings.querySelector('[data-field-select-settings]');
        if (dateSettings) {
            dateSettings.hidden = inputType !== 'date';
            dateSettings.querySelectorAll('select, input').forEach(function (control) { control.disabled = inputType !== 'date'; });
        }
        if (selectSettings) {
            selectSettings.hidden = inputType !== 'select';
            var optionsControl = selectSettings.querySelector('[data-field-select-options]');
            if (optionsControl) {
                optionsControl.disabled = inputType !== 'select';
                optionsControl.setCustomValidity(inputType === 'select' && optionsControl.value.split(/\r?\n/).every(function (option) { return option.trim() === ''; })
                    ? 'Add at least one dropdown option.'
                    : '');
            }
        }
    }

    function maskPreview(pattern, prefix, suffix) {
        var sampleDigit = 1;
        var sampleLetter = 0;
        var letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        var body = String(pattern || '').split('').map(function (character) {
            if (character === '#') {
                var digit = String(sampleDigit);
                sampleDigit = sampleDigit === 9 ? 1 : sampleDigit + 1;
                return digit;
            }
            if (character === 'A') {
                var letter = letters.charAt(sampleLetter % letters.length);
                sampleLetter += 1;
                return letter;
            }
            if (character === '*') {
                var mixed = sampleLetter % 2 === 0 ? letters.charAt(sampleLetter % letters.length) : String(sampleDigit);
                sampleLetter += 1;
                sampleDigit = sampleDigit === 9 ? 1 : sampleDigit + 1;
                return mixed;
            }
            return character;
        }).join('');

        return body ? String(prefix || '') + body + String(suffix || '') : 'Add a pattern to see an example.';
    }

    function inputRuleSummary(field) {
        var format = normaliseInputFormat(field.input_format || 'none');
        var rules = [];
        if (format === 'numbers') rules.push('Numbers only');
        if (format === 'pattern') rules.push('Custom pattern');
        if (format !== 'pattern' && parseInt(field.max_length || '0', 10) > 0) {
            rules.push('Maximum ' + parseInt(field.max_length, 10) + ' characters');
        }
        return rules.length ? rules.join(' · ') : 'No rules';
    }

    function renderInputRules(field, definition) {
        var type = effectiveFieldType(field, definition);
        var canLimit = ['text', 'tel', 'email', 'url', 'search', 'textarea', 'number'].indexOf(type) !== -1;
        var canFormat = ['text', 'tel', 'number'].indexOf(type) !== -1;
        var format = canFormat ? normaliseInputFormat(field.input_format || 'none') : 'none';
        var maxLength = canLimit && format !== 'pattern' ? Math.max(0, parseInt(field.max_length || '0', 10) || 0) : 0;
        var pattern = String(field.mask_pattern || '');
        var prefix = String(field.mask_prefix || '');
        var suffix = String(field.mask_suffix || '');
        var hasRules = format !== 'none' || maxLength > 0;
        var unavailable = !canLimit && !canFormat;

        return '<details class="cliniko-onboarding-input-rules" data-field-input-rules data-can-limit="' + (canLimit ? '1' : '0') + '" data-can-format="' + (canFormat ? '1' : '0') + '"' + (hasRules ? ' open' : '') + '>' +
            '<summary><span>Input rules <small>Optional</small></span><em data-input-rule-summary>' + escapeHtml(unavailable ? 'Not available for this field type' : inputRuleSummary(field)) + '</em></summary>' +
            '<div class="cliniko-onboarding-input-rules__body">' +
                (unavailable ? '<p class="description cliniko-onboarding-input-rules__unavailable">Character rules apply to text inputs. This field uses a choice, date, or checkbox control.</p>' : '') +
                '<div class="cliniko-onboarding-input-rules__basic">' +
                    '<label>Character limit <input type="number" min="0" max="5000" step="1" data-field-max-length value="' + escapeHtml(maxLength || '') + '" placeholder="No limit"' + (!canLimit || format === 'pattern' ? ' disabled' : '') + '><small data-character-limit-help>' + (format === 'pattern' ? 'The pattern determines the exact length.' : 'Leave empty or use 0 for no limit.') + '</small></label>' +
                    '<label>Accepted input <select data-field-input-format' + (!canFormat ? ' disabled' : '') + '><option value="none"' + (format === 'none' ? ' selected' : '') + '>Any characters</option><option value="numbers"' + (format === 'numbers' ? ' selected' : '') + '>Numbers only (0–9)</option><option value="pattern"' + (format === 'pattern' ? ' selected' : '') + '>Custom formatting pattern</option></select><small>' + (canFormat ? 'Choose how typing should be restricted and formatted.' : 'Formatting is not available for this field type.') + '</small></label>' +
                '</div>' +
                '<div class="cliniko-onboarding-mask-builder" data-field-mask-controls' + (format === 'pattern' ? '' : ' hidden') + '>' +
                    '<div class="cliniko-onboarding-mask-builder__guide"><strong>Build the pattern</strong><p><code>#</code> = number, <code>A</code> = letter, <code>*</code> = letter or number. Spaces, brackets, slashes, and hyphens are added automatically.</p><p>Example: prefix <code>+61 </code> with pattern <code>### ### ###</code>, or pattern <code>(###) ###-####</code>.</p></div>' +
                    '<div class="cliniko-onboarding-mask-builder__fields">' +
                        '<label>Fixed prefix <input type="text" maxlength="30" data-field-mask-prefix value="' + escapeHtml(prefix) + '" placeholder="e.g. +61 "><small>Optional text placed before the answer.</small></label>' +
                        '<label>Pattern <input type="text" maxlength="80" data-field-mask-pattern value="' + escapeHtml(pattern) + '" placeholder="e.g. (###) ###-####"><small>Include at least one #, A, or * placeholder.</small></label>' +
                        '<label>Fixed suffix <input type="text" maxlength="30" data-field-mask-suffix value="' + escapeHtml(suffix) + '" placeholder="e.g. kg"><small>Optional text placed after a complete answer.</small></label>' +
                    '</div>' +
                    '<div class="cliniko-onboarding-mask-builder__preview"><span>Example shown to the patient</span><output data-field-mask-preview>' + escapeHtml(maskPreview(pattern, prefix, suffix)) + '</output></div>' +
                '</div>' +
            '</div>' +
        '</details>';
    }

    function syncFieldRuleControls(article) {
        if (!article) return;
        var rules = article.querySelector('[data-field-input-rules]');
        if (!rules) return;
        var canLimit = rules.dataset.canLimit === '1';
        var canFormat = rules.dataset.canFormat === '1';
        var formatControl = rules.querySelector('[data-field-input-format]');
        var format = canFormat && formatControl ? normaliseInputFormat(formatControl.value) : 'none';
        var maskControls = rules.querySelector('[data-field-mask-controls]');
        var maxLength = rules.querySelector('[data-field-max-length]');
        var maxHelp = rules.querySelector('[data-character-limit-help]');
        if (maskControls) maskControls.hidden = format !== 'pattern';
        if (maxLength) maxLength.disabled = !canLimit || format === 'pattern';
        if (maxHelp) maxHelp.textContent = format === 'pattern' ? 'The pattern determines the exact length.' : 'Leave empty or use 0 for no limit.';

        var pattern = rules.querySelector('[data-field-mask-pattern]');
        var prefix = rules.querySelector('[data-field-mask-prefix]');
        var suffix = rules.querySelector('[data-field-mask-suffix]');
        var preview = rules.querySelector('[data-field-mask-preview]');
        if (pattern) {
            pattern.setCustomValidity(format === 'pattern' && !/[#A*]/.test(pattern.value) ? 'Add at least one #, A, or * placeholder.' : '');
        }
        if (preview) preview.textContent = maskPreview(pattern ? pattern.value : '', prefix ? prefix.value : '', suffix ? suffix.value : '');

        var summary = rules.querySelector('[data-input-rule-summary]');
        if (summary && (canLimit || canFormat)) {
            summary.textContent = inputRuleSummary({
                input_format: format,
                max_length: maxLength && !maxLength.disabled ? maxLength.value : 0
            });
        }
    }

    function conditionTypeOptions(selected) {
        return [
            ['page_is', 'Show on exact page'],
            ['page_child_of', 'Show on children of parent page']
        ].map(function (type) {
            return '<option value="' + type[0] + '"' + (type[0] === selected ? ' selected' : '') + '>' + type[1] + '</option>';
        }).join('');
    }

    function pageOptions(selected) {
        selected = Array.isArray(selected) ? selected.map(String) : [];
        return pages.map(function (page) {
            var id = String(page.id);
            return '<option value="' + escapeHtml(id) + '"' + (selected.indexOf(id) !== -1 ? ' selected' : '') + '>' + escapeHtml(page.label) + '</option>';
        }).join('');
    }

    function renderConditions() {
        if (!conditionsRoot) return;
        conditionsRoot.innerHTML = '';
        if (!conditionsState.length) {
            conditionsRoot.innerHTML = '<p class="description">No conditions: the shortcode may render on any page where it is placed.</p>';
            return;
        }
        conditionsState.forEach(function (condition, index) {
            var row = document.createElement('article');
            row.className = 'cliniko-onboarding-builder-condition';
            row.dataset.conditionIndex = String(index);
            row.innerHTML = '<div class="cliniko-onboarding-builder-condition__main"><label>Page rule <select data-condition-type>' + conditionTypeOptions(condition.type || 'page_is') + '</select></label><label>Choose pages <select data-condition-pages multiple size="5">' + pageOptions(condition.page_ids || []) + '</select><small>For child rules, select the parent page. Use Ctrl/Cmd to select multiple pages.</small></label></div><button type="button" class="button-link-delete" data-condition-remove>Remove rule</button>';
            conditionsRoot.appendChild(row);
        });
    }

    function readConditions() {
        if (!conditionsRoot || !conditionsInput) return;
        var result = [];
        conditionsRoot.querySelectorAll('[data-condition-index]').forEach(function (row) {
            var selected = Array.prototype.map.call(row.querySelector('[data-condition-pages]').selectedOptions, function (option) {
                return option.value;
            });
            result.push({
                type: row.querySelector('[data-condition-type]').value,
                page_ids: selected
            });
        });
        conditionsState = result;
        conditionsInput.value = JSON.stringify(result);
    }

    function render() {
        stepsRoot.innerHTML = '';
        state.forEach(function (step, stepIndex) {
            var section = document.createElement('section');
            section.className = 'cliniko-onboarding-builder-step';
            section.dataset.stepIndex = String(stepIndex);
            var fields = Array.isArray(step.fields) ? step.fields : [];
            section.innerHTML = '<div class="cliniko-onboarding-builder-step__head"><div><strong>Step ' + (stepIndex + 1) + '</strong><span class="description">Organize the questions shown together.</span></div><div class="cliniko-onboarding-builder-step__actions"><button type="button" class="button" data-step-up' + (stepIndex === 0 ? ' disabled' : '') + '>Move up</button><button type="button" class="button" data-step-down' + (stepIndex === state.length - 1 ? ' disabled' : '') + '>Move down</button><button type="button" class="button-link-delete" data-step-remove>Remove step</button></div></div>' +
                '<div class="cliniko-onboarding-builder-step__settings"><label>Step title <input type="text" data-step-title value="' + escapeHtml(step.title || ('Step ' + (stepIndex + 1))) + '" required></label><label>Step subtitle <textarea rows="2" data-step-subtitle>' + escapeHtml(step.subtitle || '') + '</textarea></label></div>' +
                '<div class="cliniko-onboarding-builder-fields" data-step-fields></div><div class="cliniko-onboarding-builder-step__add-actions"><button type="button" class="button" data-field-add>Add patient field</button><button type="button" class="button" data-switch-add>Add conditional choice</button></div>';
            var fieldsRoot = section.querySelector('[data-step-fields]');
            fields.forEach(function (field, fieldIndex) { fieldsRoot.appendChild(renderField(field, fieldIndex, fields.length, stepIndex)); });
            stepsRoot.appendChild(section);
        });
    }

    function renderField(field, fieldIndex, count, stepIndex) {
        var article = document.createElement('article');
        article.className = 'cliniko-onboarding-builder-field';
        article.draggable = false;
        article.dataset.fieldIndex = String(fieldIndex);
        article.dataset.fieldKind = field.kind === 'switch' ? 'switch' : 'patient';
        article.dataset.fieldKey = field.key || '';
        var actions = '<div class="cliniko-onboarding-builder-field__actions"><button type="button" class="button-link-delete" data-field-remove>Remove</button></div>';
        if (field.kind === 'switch') {
            var onLabel = field.on_label || 'Yes';
            var offLabel = field.off_label || 'No';
            var defaultValue = field.default_value === 'on' ? 'on' : 'off';
            article.classList.add('cliniko-onboarding-builder-field--switch');
            article.innerHTML = '<span class="dashicons dashicons-randomize cliniko-onboarding-builder-field__drag" data-onboarding-field-drag-handle aria-label="Drag to reorder" title="Drag to reorder"></span>' +
                '<div class="cliniko-onboarding-builder-field__main cliniko-onboarding-builder-switch__main">' +
                    '<div class="cliniko-onboarding-builder-switch__heading"><strong>Conditional choice</strong><span>A clear two-option answer that can show, hide, and require other fields.</span></div>' +
                    '<label class="cliniko-onboarding-builder-switch__question">Question shown to patient <input type="text" data-field-switch-label value="' + escapeHtml(field.label || 'Do you have additional details to provide?') + '" required></label>' +
                    '<div class="cliniko-onboarding-builder-switch__options">' +
                        '<label>First option label <input type="text" data-field-switch-on-label value="' + escapeHtml(onLabel) + '" required></label>' +
                        '<label>Second option label <input type="text" data-field-switch-off-label value="' + escapeHtml(offLabel) + '" required></label>' +
                        '<label>Selected by default <select data-field-switch-default>' +
                            '<option value="on"' + (defaultValue === 'on' ? ' selected' : '') + '>' + escapeHtml(onLabel) + '</option>' +
                            '<option value="off"' + (defaultValue === 'off' ? ' selected' : '') + '>' + escapeHtml(offLabel) + '</option>' +
                        '</select><small>The patient can change this selection.</small></label>' +
                    '</div>' +
                    '<label>Width <select data-field-width><option value="50"' + ((field.width || 100) === 50 ? ' selected' : '') + '>50%</option><option value="100"' + ((field.width || 100) === 100 ? ' selected' : '') + '>100%</option></select></label>' +
                    '<label class="cliniko-onboarding-builder-field__required"><input type="checkbox" data-field-required' + (field.required !== false ? ' checked' : '') + '> Require the patient to submit this choice before onboarding is complete</label>' +
                '</div>' + actions;
            return article;
        }
        var definition = byKey[field.key] || {};
        article.innerHTML = '<span class="dashicons dashicons-menu cliniko-onboarding-builder-field__drag" data-onboarding-field-drag-handle aria-label="Drag to reorder" title="Drag to reorder"></span>' +
            '<div class="cliniko-onboarding-builder-field__main">' +
                '<label>Patient field <select data-field-key>' + fieldOptions(field.key) + '</select></label>' +
                '<label>Visible label <input type="text" data-field-label value="' + escapeHtml(field.label || definition.label || field.key || '') + '"></label>' +
                '<label>Width <select data-field-width><option value="25"' + ((field.width || 100) === 25 ? ' selected' : '') + '>25%</option><option value="50"' + ((field.width || 100) === 50 ? ' selected' : '') + '>50%</option><option value="75"' + ((field.width || 100) === 75 ? ' selected' : '') + '>75%</option><option value="100"' + ((field.width || 100) === 100 ? ' selected' : '') + '>100%</option></select></label>' +
                '<label class="cliniko-onboarding-builder-field__required"><input type="checkbox" data-field-required' + (field.required ? ' checked' : '') + '> Required for onboarding completion</label>' +
                renderInputTypeSettings(field, definition) +
                renderInputRules(field, definition) +
                renderConditionalSettings(field) +
            '</div>' +
            actions;
        syncInputTypeControls(article);
        syncFieldRuleControls(article);
        return article;
    }

    function read() {
        var result = [];
        stepsRoot.querySelectorAll('[data-step-index]').forEach(function (section, stepIndex) {
            var fields = [];
            section.querySelectorAll('[data-field-index]').forEach(function (field) {
                if (field.dataset.fieldKind === 'switch') {
                    fields.push({
                        kind: 'switch',
                        key: field.dataset.fieldKey || (state[stepIndex] && state[stepIndex].fields[field.dataset.fieldIndex] && state[stepIndex].fields[field.dataset.fieldIndex].key) || switchUid(),
                        label: field.querySelector('[data-field-switch-label]').value || 'Yes or no?',
                        on_label: field.querySelector('[data-field-switch-on-label]').value || 'Yes',
                        off_label: field.querySelector('[data-field-switch-off-label]').value || 'No',
                        default_value: field.querySelector('[data-field-switch-default]').value === 'on' ? 'on' : 'off',
                        required: field.querySelector('[data-field-required]').checked,
                        width: parseInt(field.querySelector('[data-field-width]').value, 10) || 100
                    });
                    return;
                }
                var key = field.querySelector('[data-field-key]').value;
                var definition = byKey[key] || {};
                var inputTypeControl = field.querySelector('[data-field-input-type]');
                var inputType = normaliseInputType(inputTypeControl ? inputTypeControl.value : 'default', definition);
                var dateFormatControl = field.querySelector('[data-field-date-format]');
                var selectOptionsControl = field.querySelector('[data-field-select-options]');
                var selectOptions = (selectOptionsControl ? selectOptionsControl.value.split(/\r?\n/) : []).map(function (option) {
                    return option.trim();
                }).filter(function (option, index, options) {
                    return option !== '' && options.indexOf(option) === index;
                });
                var formatControl = field.querySelector('[data-field-input-format]');
                var inputFormat = normaliseInputFormat(formatControl && !formatControl.disabled ? formatControl.value : 'none');
                var maxLengthControl = field.querySelector('[data-field-max-length]');
                var maskPattern = field.querySelector('[data-field-mask-pattern]');
                var maskPrefix = field.querySelector('[data-field-mask-prefix]');
                var maskSuffix = field.querySelector('[data-field-mask-suffix]');
                var conditionControl = field.querySelector('[data-field-condition]');
                var conditionParts = conditionControl && conditionControl.value ? conditionControl.value.split(':') : [];
                fields.push({
                    key: key,
                    label: field.querySelector('[data-field-label]').value || definition.label || key,
                    required: field.querySelector('[data-field-required]').checked,
                    width: parseInt(field.querySelector('[data-field-width]').value, 10) || 100,
                    input_type: inputType,
                    date_format: dateFormatControl ? dateFormatControl.value : 'dmy_slash',
                    select_options: selectOptions,
                    max_length: inputFormat === 'pattern' || !maxLengthControl || maxLengthControl.disabled ? 0 : (parseInt(maxLengthControl.value, 10) || 0),
                    input_format: inputFormat,
                    mask_pattern: inputFormat === 'pattern' && maskPattern ? maskPattern.value : '',
                    mask_prefix: inputFormat === 'pattern' && maskPrefix ? maskPrefix.value : '',
                    mask_suffix: inputFormat === 'pattern' && maskSuffix ? maskSuffix.value : '',
                    condition_switch: conditionParts[0] || '',
                    condition_value: conditionParts[1] === 'off' ? 'off' : 'on'
                });
            });
            result.push({
                id: section.dataset.stepId || (state[stepIndex] && state[stepIndex].id) || uid(),
                title: section.querySelector('[data-step-title]').value || ('Step ' + (stepIndex + 1)),
                subtitle: section.querySelector('[data-step-subtitle]').value || '',
                fields: fields
            });
        });
        state = result;
        configInput.value = JSON.stringify(result);
    }

    function swap(parent, first, second) {
        if (!first || !second) return;
        if (first.compareDocumentPosition(second) & Node.DOCUMENT_POSITION_FOLLOWING) parent.insertBefore(second, first);
        else parent.insertBefore(first, second);
    }

    var draggedField = null;

    function clearFieldDragState() {
        stepsRoot.querySelectorAll('.is-dragging, .is-drop-target').forEach(function (element) {
            element.classList.remove('is-dragging', 'is-drop-target');
        });
    }

    var pointerFieldDrag = null;

    function beginPointerFieldDrag(drag, event) {
        var rect = drag.field.getBoundingClientRect();
        var placeholder = document.createElement('div');
        placeholder.className = 'cliniko-onboarding-builder-field__placeholder';
        placeholder.style.height = rect.height + 'px';
        drag.field.parentElement.insertBefore(placeholder, drag.field);
        drag.style = drag.field.getAttribute('style') || '';
        drag.offsetX = event.clientX - rect.left;
        drag.offsetY = event.clientY - rect.top;
        drag.placeholder = placeholder;
        drag.field.classList.add('is-dragging');
        drag.field.style.cssText += ';position:fixed !important;left:' + (event.clientX - drag.offsetX) + 'px;top:' + (event.clientY - drag.offsetY) + 'px;width:' + rect.width + 'px;height:' + rect.height + 'px;margin:0 !important;z-index:100000;pointer-events:none;';
        document.body.appendChild(drag.field);
    }

    function moveFieldPlaceholderAtPointer(drag, clientX, clientY) {
        drag.field.style.left = (clientX - drag.offsetX) + 'px';
        drag.field.style.top = (clientY - drag.offsetY) + 'px';
        var element = document.elementFromPoint(clientX, clientY);
        if (element === drag.placeholder) return;
        var fieldsRoot = element && element.closest('[data-step-fields]');
        if (!fieldsRoot) return;
        var target = element.closest('[data-field-index]');
        clearFieldDragState();
        drag.field.classList.add('is-dragging');
        if (target && target !== drag.field && target.closest('[data-step-fields]') === fieldsRoot) {
            target.classList.add('is-drop-target');
            var rect = target.getBoundingClientRect();
            fieldsRoot.insertBefore(drag.placeholder, clientY > rect.top + (rect.height / 2) ? target.nextElementSibling : target);
            return;
        }
        if (!target) fieldsRoot.appendChild(drag.placeholder);
    }

    stepsRoot.addEventListener('pointerdown', function (event) {
        var handle = event.target.closest('[data-onboarding-field-drag-handle]');
        if (!handle || event.button !== 0) return;
        var field = handle.closest('[data-field-index]');
        if (!field) return;
        event.preventDefault();
        pointerFieldDrag = { field: field, pointerId: event.pointerId, x: event.clientX, y: event.clientY, active: false };
        stepsRoot.setPointerCapture(event.pointerId);
    });

    stepsRoot.addEventListener('pointermove', function (event) {
        if (!pointerFieldDrag || pointerFieldDrag.pointerId !== event.pointerId) return;
        if (!pointerFieldDrag.active) {
            var distance = Math.hypot(event.clientX - pointerFieldDrag.x, event.clientY - pointerFieldDrag.y);
            if (distance < 4) return;
            pointerFieldDrag.active = true;
            beginPointerFieldDrag(pointerFieldDrag, event);
        }
        event.preventDefault();
        moveFieldPlaceholderAtPointer(pointerFieldDrag, event.clientX, event.clientY);
    });

    function finishPointerFieldDrag(event) {
        if (!pointerFieldDrag || pointerFieldDrag.pointerId !== event.pointerId) return;
        var drag = pointerFieldDrag;
        var active = drag.active;
        pointerFieldDrag = null;
        clearFieldDragState();
        if (stepsRoot.hasPointerCapture(event.pointerId)) stepsRoot.releasePointerCapture(event.pointerId);
        if (active) {
            drag.placeholder.parentElement.insertBefore(drag.field, drag.placeholder);
            drag.placeholder.remove();
            drag.field.classList.remove('is-dragging', 'is-drop-target');
            drag.field.setAttribute('style', drag.style);
            read();
            render();
            read();
        }
    }

    stepsRoot.addEventListener('pointerup', finishPointerFieldDrag);
    stepsRoot.addEventListener('pointercancel', finishPointerFieldDrag);

    stepsRoot.addEventListener('dragstart', function (event) {
        var handle = event.target.closest('[data-onboarding-field-drag-handle]');
        if (!handle) return;
        var field = handle.closest('[data-field-index]');
        var step = handle.closest('[data-step-index]');
        if (!field || !step) return;
        read();
        draggedField = {
            element: field,
            stepIndex: parseInt(step.dataset.stepIndex, 10),
            fieldIndex: parseInt(field.dataset.fieldIndex, 10)
        };
        field.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', field.dataset.fieldKey || 'field');
    });

    stepsRoot.addEventListener('dragover', function (event) {
        if (!draggedField) return;
        var fieldsRoot = event.target.closest('[data-step-fields]');
        if (!fieldsRoot) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        var target = event.target.closest('[data-field-index]');
        clearFieldDragState();
        draggedField.element.classList.add('is-dragging');
        if (target && target !== draggedField.element) target.classList.add('is-drop-target');
    });

    stepsRoot.addEventListener('drop', function (event) {
        if (!draggedField) return;
        var fieldsRoot = event.target.closest('[data-step-fields]');
        var destinationStep = fieldsRoot && fieldsRoot.closest('[data-step-index]');
        if (!fieldsRoot || !destinationStep) return;
        event.preventDefault();
        var target = event.target.closest('[data-field-index]');
        var destinationStepIndex = parseInt(destinationStep.dataset.stepIndex, 10);
        var destinationIndex = state[destinationStepIndex].fields.length;
        if (target && target !== draggedField.element && target.closest('[data-step-fields]') === fieldsRoot) {
            destinationIndex = parseInt(target.dataset.fieldIndex, 10);
            if (event.clientY > target.getBoundingClientRect().top + (target.getBoundingClientRect().height / 2)) {
                destinationIndex += 1;
            }
        }
        var moved = state[draggedField.stepIndex].fields.splice(draggedField.fieldIndex, 1)[0];
        if (draggedField.stepIndex === destinationStepIndex && draggedField.fieldIndex < destinationIndex) {
            destinationIndex -= 1;
        }
        state[destinationStepIndex].fields.splice(destinationIndex, 0, moved);
        draggedField = null;
        clearFieldDragState();
        render();
        read();
    });

    stepsRoot.addEventListener('dragend', function () {
        draggedField = null;
        clearFieldDragState();
    });

    form.addEventListener('click', function (event) {
        var target = event.target;
        if (target.matches('[data-onboarding-add-condition]')) {
            read();
            readConditions();
            conditionsState.push({ type: 'page_is', page_ids: [] });
            renderConditions();
            return;
        }
        var condition = target.closest('[data-condition-index]');
        if (target.matches('[data-condition-remove]') && condition) {
            readConditions();
            conditionsState.splice(parseInt(condition.dataset.conditionIndex, 10), 1);
            renderConditions();
            readConditions();
            return;
        }
        if (target.matches('[data-onboarding-add-step]')) {
            read();
            state.push({ id: uid(), title: 'New step', subtitle: '', fields: [] });
            render();
            return;
        }
        var step = target.closest('[data-step-index]');
        if (target.matches('[data-field-add]') && step) {
            read();
            var stepIndex = parseInt(step.dataset.stepIndex, 10);
            state[stepIndex].fields.push({ key: available[0] ? available[0].key : '', label: available[0] ? available[0].label : '', required: false, width: 100, input_type: 'default', date_format: 'dmy_slash', select_options: [], max_length: 0, input_format: 'none', mask_pattern: '', mask_prefix: '', mask_suffix: '' });
            render();
            return;
        }
        if (target.matches('[data-switch-add]') && step) {
            read();
            var switchStepIndex = parseInt(step.dataset.stepIndex, 10);
            state[switchStepIndex].fields.push({ kind: 'switch', key: switchUid(), label: 'Do you have additional details to provide?', on_label: 'Yes', off_label: 'No', default_value: 'off', required: true, width: 100 });
            render();
            return;
        }
        if (target.matches('[data-step-remove]') && step) {
            read();
            state.splice(parseInt(step.dataset.stepIndex, 10), 1);
            render();
            return;
        }
        if (target.matches('[data-step-up], [data-step-down]') && step) {
            read();
            var index = parseInt(step.dataset.stepIndex, 10);
            var direction = target.matches('[data-step-up]') ? -1 : 1;
            if (state[index + direction]) {
                var moved = state.splice(index, 1)[0];
                state.splice(index + direction, 0, moved);
                render();
            }
            return;
        }
        var field = target.closest('[data-field-index]');
        if (target.matches('[data-field-remove]') && field && step) {
            read();
            state[parseInt(step.dataset.stepIndex, 10)].fields.splice(parseInt(field.dataset.fieldIndex, 10), 1);
            render();
            return;
        }
    });

    form.addEventListener('input', function (event) {
        var field = event.target.closest('[data-field-index]');
        syncInputTypeControls(field);
        syncFieldRuleControls(field);
        read();
        readConditions();
    });
    form.addEventListener('change', function (event) {
        var field = event.target.closest('[data-field-index]');
        syncInputTypeControls(field);
        syncFieldRuleControls(field);
        read();
        readConditions();
        if (event.target.matches('[data-field-key], [data-field-input-type], [data-field-condition], [data-field-switch-label], [data-field-switch-on-label], [data-field-switch-off-label]')) render();
    });
    form.addEventListener('submit', function () { read(); readConditions(); });
    renderConditions();
    render();
    read();
    readConditions();
    syncPlacementControls();
    syncColorControls();
    syncWelcomeControls();
}());
