(function () {
  const form = document.querySelector('[data-cliniko-form-builder]');
  if (!form) return;

  const sections = form.querySelector('[data-sections]');
  const config = form.querySelector('[data-cliniko-layout-config]');
  const previewModal = form.closest('.wrap').querySelector('[data-preview-modal]');
  const tabsRoot = form.querySelector('[data-account-builder-tabs]');
  const names = {};
  const types = {};
  const capabilities = {};
  const customFields = {};
  const definitions = {};

  function selectEditorTab(name, moveFocus) {
    let selectedButton = null;
    form.querySelectorAll('[data-account-builder-tab]').forEach(function (button) {
      const active = button.dataset.accountBuilderTab === name;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
      button.tabIndex = active ? 0 : -1;
      if (active) selectedButton = button;
    });
    form.querySelectorAll('[data-account-builder-tab-panel]').forEach(function (panel) {
      const active = panel.dataset.accountBuilderTabPanel === name;
      panel.hidden = !active;
      panel.classList.toggle('is-active', active);
    });
    if (moveFocus && selectedButton) selectedButton.focus();
  }

  if (tabsRoot) {
    tabsRoot.addEventListener('click', function (event) {
      const button = event.target.closest('[data-account-builder-tab]');
      if (button) selectEditorTab(button.dataset.accountBuilderTab, false);
    });
    tabsRoot.addEventListener('keydown', function (event) {
      if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
      const buttons = Array.from(tabsRoot.querySelectorAll('[data-account-builder-tab]'));
      const current = buttons.indexOf(document.activeElement);
      if (current === -1) return;
      event.preventDefault();
      let next = event.key === 'Home' ? 0 : (event.key === 'End' ? buttons.length - 1 : current + (event.key === 'ArrowRight' ? 1 : -1));
      if (next < 0) next = buttons.length - 1;
      if (next >= buttons.length) next = 0;
      selectEditorTab(buttons[next].dataset.accountBuilderTab, true);
    });
    selectEditorTab('setup', false);
  }

  form.addEventListener('invalid', function (event) {
    const panel = event.target.closest('[data-account-builder-tab-panel]');
    if (panel) selectEditorTab(panel.dataset.accountBuilderTabPanel, false);
  }, true);

  document.querySelectorAll('[data-cliniko-available-fields] [data-field-key]').forEach(function (button) {
    names[button.dataset.fieldKey] = button.dataset.fieldLabel;
    types[button.dataset.fieldKey] = button.dataset.fieldType || 'text';
    customFields[button.dataset.fieldKey] = button.dataset.fieldCustom === '1';
    definitions[button.dataset.fieldKey] = { type: button.dataset.fieldType || 'text', cliniko_field_type: button.dataset.fieldClinikoType || '' };
    try {
      capabilities[button.dataset.fieldKey] = JSON.parse(button.dataset.fieldCapabilities || '{}');
    } catch (error) {
      capabilities[button.dataset.fieldKey] = {};
    }
  });

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[character];
    });
  }

  function fieldOptions(selected) {
    const standard = Object.keys(names).filter(function (key) { return !customFields[key]; });
    const custom = Object.keys(names).filter(function (key) { return customFields[key]; });
    const options = function (keys) {
      return keys.map(function (key) {
        return '<option value="' + escapeHtml(key) + '"' + (key === selected ? ' selected' : '') + '>' + escapeHtml(names[key]) + '</option>';
      }).join('');
    };
    let markup = '<option value="">Select a patient field</option>' + options(standard);
    if (custom.length) markup += '<optgroup label="Cliniko custom fields">' + options(custom) + '</optgroup>';
    return markup;
  }

  function definitionFor(key) {
    return Object.assign({}, definitions[key] || { type: 'text', cliniko_field_type: '' });
  }

  function normaliseInputFormat(value) {
    return ['none', 'numbers', 'pattern'].includes(value) ? value : 'none';
  }

  function inputTypeCapabilities(definition) {
    const allowed = Object.assign({ text: true, date: true, select: true, limit: true, format: true }, capabilities[definition.key] || {});
    return { type: String(definition.type || '').toLowerCase(), text: allowed.text, date: allowed.date, select: allowed.select, limit: allowed.limit, format: allowed.format };
  }

  function normaliseInputType(value, definition) {
    const allowed = inputTypeCapabilities(definition);
    if (value === 'text' && allowed.text) return 'text';
    if (value === 'date' && allowed.date) return 'date';
    if (value === 'select' && allowed.select) return 'select';
    return 'default';
  }

  function fieldTypeLabel(type) {
    return ({ text: 'Text', tel: 'Telephone', email: 'Email', url: 'URL', search: 'Search', textarea: 'Long text', number: 'Number', date: 'Date', select: 'Dropdown', checkbox: 'Checkbox', checkboxes: 'Checkboxes', multi_checkbox: 'Checkboxes', multi_select: 'Multiple choice', paragraph: 'Paragraph', hidden: 'Hidden' })[type] || 'Original control';
  }

  function maskPreview(pattern, prefix, suffix) {
    let digit = 1; let letter = 0; const letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const body = String(pattern || '').split('').map(function (character) {
      if (character === '#') { const value = String(digit); digit = digit === 9 ? 1 : digit + 1; return value; }
      if (character === 'A') return letters.charAt((letter++) % letters.length);
      if (character === '*') { const value = letter % 2 === 0 ? letters.charAt(letter % letters.length) : String(digit); letter += 1; digit = digit === 9 ? 1 : digit + 1; return value; }
      return character;
    }).join('');
    return body ? String(prefix || '') + body + String(suffix || '') : 'Add a pattern to see an example.';
  }

  function ruleSummary(config) {
    const rules = []; const format = normaliseInputFormat(config.input_format || 'none');
    if (format === 'numbers') rules.push('Numbers only');
    if (format === 'pattern') rules.push('Custom pattern');
    if (format !== 'pattern' && parseInt(config.max_length || '0', 10) > 0) rules.push('Maximum ' + parseInt(config.max_length, 10) + ' characters');
    return rules.length ? rules.join(' · ') : 'No rules';
  }

  function switchUid() {
    return 'switch_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8);
  }

  function logicSwitches() {
    return Array.from(sections.querySelectorAll('[data-field-kind="switch"]')).map(function (field) {
      return {
        key: field.dataset.fieldKey || '',
        label: field.querySelector('[data-field-switch-label]')?.value || 'Untitled conditional choice',
        on_label: field.querySelector('[data-field-switch-on-label]')?.value || 'Yes',
        off_label: field.querySelector('[data-field-switch-off-label]')?.value || 'No'
      };
    }).filter(function (field) { return field.key !== ''; });
  }

  function conditionalMarkup(fieldConfig) {
    const switches = logicSwitches();
    const switchKey = String(fieldConfig.condition_switch || '');
    const expected = fieldConfig.condition_value === 'off' ? 'off' : 'on';
    const selected = switchKey ? switchKey + ':' + expected : '';
    let options = '<option value=""' + (selected === '' ? ' selected' : '') + '>Always show this field</option>';
    switches.forEach(function (choice) {
      options += '<optgroup label="' + escapeHtml(choice.label) + '">' +
        '<option value="' + escapeHtml(choice.key + ':on') + '"' + (selected === choice.key + ':on' ? ' selected' : '') + '>Show when “' + escapeHtml(choice.on_label) + '” is selected</option>' +
        '<option value="' + escapeHtml(choice.key + ':off') + '"' + (selected === choice.key + ':off' ? ' selected' : '') + '>Show when “' + escapeHtml(choice.off_label) + '” is selected</option>' +
      '</optgroup>';
    });
    return '<details class="cliniko-onboarding-condition-settings" data-field-condition-settings' + (switchKey ? ' open' : '') + '><summary><span>Conditional visibility <small>Optional</small></span><em>' + (switchKey ? 'Controlled by a choice' : 'Always visible') + '</em></summary><div><label>Display rule <select data-field-condition>' + options + '</select><small>' + (switches.length ? 'Place the controlling choice before this field. A required field is required only while it is visible.' : 'Add a conditional choice to create display rules.') + '</small></label></div></details>';
  }

  function fieldConditionConfig(field) {
    const condition = field.querySelector('[data-field-condition]')?.value || (field.dataset.conditionSwitch ? field.dataset.conditionSwitch + ':' + (field.dataset.conditionValue || 'on') : '');
    const parts = condition.split(':');
    return { condition_switch: parts[0] || '', condition_value: parts[1] === 'off' ? 'off' : 'on' };
  }

  function refreshConditionalSettings() {
    sections.querySelectorAll('[data-field-kind="patient"]').forEach(function (field) {
      const current = fieldConditionConfig(field);
      field.dataset.conditionSwitch = current.condition_switch;
      field.dataset.conditionValue = current.condition_value;
      const existing = field.querySelector('[data-field-condition-settings]');
      if (existing) existing.outerHTML = conditionalMarkup(current);
    });
  }

  function ruleMarkup(key, config) {
    config = config || {};
    const definition = definitionFor(key); definition.key = key;
    const allowed = inputTypeCapabilities(definition);
    const inputType = normaliseInputType(config.input_type || 'default', definition);
    const format = allowed.format ? normaliseInputFormat(config.input_format || 'none') : 'none';
    const dateFormat = ['dmy_slash', 'mdy_slash', 'ymd_dash', 'dmy_dash'].includes(config.date_format) ? config.date_format : 'dmy_slash';
    const options = Array.isArray(config.select_options) && config.select_options.length ? config.select_options : [''];
    const maxLength = allowed.limit && format !== 'pattern' ? Math.max(0, parseInt(config.max_length || '0', 10) || 0) : 0;
    const typeOptions = '<option value="default"' + (inputType === 'default' ? ' selected' : '') + '>Use field default (' + escapeHtml(fieldTypeLabel(allowed.type)) + ')</option>' +
      (allowed.text ? '<option value="text"' + (inputType === 'text' ? ' selected' : '') + '>Text input</option>' : '') +
      (allowed.date ? '<option value="date"' + (inputType === 'date' ? ' selected' : '') + '>Formatted date</option>' : '') +
      (allowed.select ? '<option value="select"' + (inputType === 'select' ? ' selected' : '') + '>Dropdown select</option>' : '');
    return '<section class="cliniko-onboarding-input-type" data-field-input-type-settings>' +
      '<div class="cliniko-onboarding-input-type__heading"><strong>Input type</strong><span>Choose how this question appears to the patient.</span></div>' +
      '<label>Control <select data-field-input-type>' + typeOptions + '</select><small>Use the default unless this form needs a different control.</small></label>' +
      '<div class="cliniko-onboarding-date-settings" data-field-date-settings' + (inputType === 'date' ? '' : ' hidden') + '><label>Date shown as <select data-field-date-format><option value="dmy_slash"' + (dateFormat === 'dmy_slash' ? ' selected' : '') + '>DD/MM/YYYY - 31/12/2026</option><option value="mdy_slash"' + (dateFormat === 'mdy_slash' ? ' selected' : '') + '>MM/DD/YYYY - 12/31/2026</option><option value="ymd_dash"' + (dateFormat === 'ymd_dash' ? ' selected' : '') + '>YYYY-MM-DD - 2026-12-31</option><option value="dmy_dash"' + (dateFormat === 'dmy_dash' ? ' selected' : '') + '>DD-MM-YYYY - 31-12-2026</option></select><small>This changes only the patient-facing input. Cliniko still receives YYYY-MM-DD.</small></label></div>' +
      '<div class="cliniko-onboarding-select-settings" data-field-select-settings' + (inputType === 'select' ? '' : ' hidden') + '><div><strong>Dropdown options</strong><span>Enter one option per line. The line order is the order patients will see.</span></div><label>Options <textarea rows="7" maxlength="15000" data-field-select-options placeholder="First option&#10;Second option&#10;Third option">' + escapeHtml(options.join('\n')) + '</textarea><small>Empty lines and duplicate options are ignored.</small></label></div></section>' +
      '<details class="cliniko-onboarding-input-rules" data-field-input-rules data-can-limit="' + (allowed.limit ? '1' : '0') + '" data-can-format="' + (allowed.format ? '1' : '0') + '"' + (format !== 'none' || maxLength > 0 ? ' open' : '') + '><summary><span>Input rules <small>Optional</small></span><em data-input-rule-summary>' + escapeHtml((!allowed.limit && !allowed.format) ? 'Not available for this field type' : ruleSummary(config)) + '</em></summary><div class="cliniko-onboarding-input-rules__body"><div class="cliniko-onboarding-input-rules__basic"><label>Character limit <input type="number" min="0" max="5000" step="1" data-field-max-length value="' + escapeHtml(maxLength || '') + '" placeholder="No limit"' + (!allowed.limit || format === 'pattern' ? ' disabled' : '') + '><small data-character-limit-help>' + (format === 'pattern' ? 'The pattern determines the exact length.' : 'Leave empty or use 0 for no limit.') + '</small></label><label>Accepted input <select data-field-input-format' + (!allowed.format ? ' disabled' : '') + '><option value="none"' + (format === 'none' ? ' selected' : '') + '>Any characters</option><option value="numbers"' + (format === 'numbers' ? ' selected' : '') + '>Numbers only (0–9)</option><option value="pattern"' + (format === 'pattern' ? ' selected' : '') + '>Custom formatting pattern</option></select><small>' + (allowed.format ? 'Choose how typing should be restricted and formatted.' : 'Formatting is not available for this field type.') + '</small></label></div><div class="cliniko-onboarding-mask-builder" data-field-mask-controls' + (format === 'pattern' ? '' : ' hidden') + '><div class="cliniko-onboarding-mask-builder__guide"><strong>Build the pattern</strong><p><code>#</code> = number, <code>A</code> = letter, <code>*</code> = letter or number. Spaces, brackets, slashes, and hyphens are added automatically.</p></div><div class="cliniko-onboarding-mask-builder__fields"><label>Fixed prefix <input type="text" maxlength="30" data-field-mask-prefix value="' + escapeHtml(config.mask_prefix || '') + '" placeholder="e.g. +61 "></label><label>Pattern <input type="text" maxlength="80" data-field-mask-pattern value="' + escapeHtml(config.mask_pattern || '') + '" placeholder="e.g. (###) ###-####"></label><label>Fixed suffix <input type="text" maxlength="30" data-field-mask-suffix value="' + escapeHtml(config.mask_suffix || '') + '" placeholder="e.g. kg"></label></div><div class="cliniko-onboarding-mask-builder__preview"><span>Example shown to the patient</span><output data-field-mask-preview>' + escapeHtml(maskPreview(config.mask_pattern || '', config.mask_prefix || '', config.mask_suffix || '')) + '</output></div></div></div></details>' + conditionalMarkup(config);
  }

  function syncFieldControls(field) {
    if (!field) return;
    const key = field.querySelector('[data-field-selector]')?.value || field.dataset.fieldKey;
    const definition = definitionFor(key); definition.key = key;
    const typeSettings = field.querySelector('[data-field-input-type-settings]');
    const inputType = normaliseInputType(typeSettings?.querySelector('[data-field-input-type]')?.value || 'default', definition);
    const dateSettings = field.querySelector('[data-field-date-settings]');
    const selectSettings = field.querySelector('[data-field-select-settings]');
    if (dateSettings) { dateSettings.hidden = inputType !== 'date'; dateSettings.querySelectorAll('select, input').forEach(function (control) { control.disabled = inputType !== 'date'; }); }
    if (selectSettings) { selectSettings.hidden = inputType !== 'select'; const options = selectSettings.querySelector('[data-field-select-options]'); if (options) { options.disabled = inputType !== 'select'; options.setCustomValidity(inputType === 'select' && options.value.split(/\r?\n/).every(function (option) { return option.trim() === ''; }) ? 'Add at least one dropdown option.' : ''); } }
    const rules = field.querySelector('[data-field-input-rules]');
    if (!rules) return;
    const canLimit = rules.dataset.canLimit === '1'; const canFormat = rules.dataset.canFormat === '1';
    const formatControl = rules.querySelector('[data-field-input-format]'); const format = canFormat && formatControl ? normaliseInputFormat(formatControl.value) : 'none';
    const mask = rules.querySelector('[data-field-mask-controls]'); const maxLength = rules.querySelector('[data-field-max-length]');
    if (mask) mask.hidden = format !== 'pattern';
    if (maxLength) maxLength.disabled = !canLimit || format === 'pattern';
    const pattern = rules.querySelector('[data-field-mask-pattern]'); const prefix = rules.querySelector('[data-field-mask-prefix]'); const suffix = rules.querySelector('[data-field-mask-suffix]'); const preview = rules.querySelector('[data-field-mask-preview]');
    if (pattern) pattern.setCustomValidity(format === 'pattern' && !/[#A*]/.test(pattern.value) ? 'Add at least one #, A, or * placeholder.' : '');
    if (preview) preview.textContent = maskPreview(pattern?.value || '', prefix?.value || '', suffix?.value || '');
    const summary = rules.querySelector('[data-input-rule-summary]');
    if (summary && (canLimit || canFormat)) summary.textContent = ruleSummary({ input_format: format, max_length: maxLength && !maxLength.disabled ? maxLength.value : 0 });
  }

  function uniqueSectionId() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return 'cliniko-section-' + window.crypto.randomUUID();
    }
    return 'cliniko-section-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
  }

  function sync() {
    const result = [];
    sections.querySelectorAll('[data-section]').forEach(function (section, sectionIndex) {
      const columns = [];
      const columnElements = section.querySelectorAll('[data-column]');
      columnElements.forEach(function (column) {
        const fields = [];
        column.querySelectorAll('.cliniko-patient-form-builder-field').forEach(function (field) {
          if (field.dataset.fieldKind === 'switch') {
            fields.push({ kind: 'switch', key: field.dataset.fieldKey || switchUid(), label: field.querySelector('[data-field-switch-label]')?.value || 'Do you have additional details to provide?', on_label: field.querySelector('[data-field-switch-on-label]')?.value || 'Yes', off_label: field.querySelector('[data-field-switch-off-label]')?.value || 'No', default_value: field.querySelector('[data-field-switch-default]')?.value === 'on' ? 'on' : 'off', required: field.querySelector('[data-field-required]')?.checked !== false, width: parseInt(field.querySelector('[data-field-width]')?.value || '100', 10) || 100 });
            return;
          }
          const selector = field.querySelector('[data-field-selector]');
          const key = selector ? selector.value : field.dataset.fieldKey;
          field.dataset.fieldKey = key;
          if (!key) return;
          const inputType = field.querySelector('[data-field-input-type]')?.value || 'default';
          const format = field.querySelector('[data-field-input-format]')?.value || 'none';
          const selectOptions = (field.querySelector('[data-field-select-options]')?.value || '').split(/\r?\n/).map(function (option) {
            return option.trim();
          }).filter(function (option, index, options) {
            return option !== '' && options.indexOf(option) === index;
          });
          fields.push({
            key: key,
            label: field.querySelector('[data-field-label]').value,
            required: field.querySelector('[data-field-required]').checked,
            width: parseInt(field.querySelector('[data-field-width]')?.value || '100', 10) || 100,
            input_type: inputType,
            date_format: field.querySelector('[data-field-date-format]')?.value || 'dmy_slash',
            select_options: selectOptions,
            max_length: format === 'pattern' ? 0 : (parseInt(field.querySelector('[data-field-max-length]')?.value || '0', 10) || 0),
            input_format: format,
            mask_pattern: format === 'pattern' ? (field.querySelector('[data-field-mask-pattern]')?.value || '') : '',
            mask_prefix: format === 'pattern' ? (field.querySelector('[data-field-mask-prefix]')?.value || '') : '',
            mask_suffix: format === 'pattern' ? (field.querySelector('[data-field-mask-suffix]')?.value || '') : '',
            condition_switch: fieldConditionConfig(field).condition_switch,
            condition_value: fieldConditionConfig(field).condition_value
          });
        });
        columns.push({ width: Math.round(12 / columnElements.length), fields: fields });
      });
      result.push({
        id: section.dataset.sectionId || uniqueSectionId(),
        name: section.querySelector('[data-section-name]').value || 'Section ' + (sectionIndex + 1),
        columns: columns
      });
    });
    config.value = JSON.stringify(result);
  }

  function makeField(key) {
    key = key || Object.keys(names)[0] || '';
    const field = document.createElement('article');
    field.className = 'cliniko-form-builder__field cliniko-patient-form-builder-field';
    field.draggable = false;
    field.dataset.fieldKey = key;
    field.dataset.fieldKind = 'patient';
    field.innerHTML = '<span class="dashicons dashicons-menu cliniko-patient-form-builder-field__drag" data-field-drag-handle aria-label="Drag to reorder" title="Drag to reorder"></span>' +
      '<div class="cliniko-patient-form-builder-field__main"><label>Patient field <select data-field-selector required>' + fieldOptions(key) + '</select></label><label>Visible label <input type="text" value="' + escapeHtml(names[key] || key) + '" data-field-label /></label><label>Width <select data-field-width><option value="25">25%</option><option value="50">50%</option><option value="75">75%</option><option value="100" selected>100%</option></select></label><label class="cliniko-patient-form-builder-field__required"><input type="checkbox" data-field-required /> Required when the patient submits this form</label>' + ruleMarkup(key, {}) + '</div>' +
      '<div class="cliniko-patient-form-builder-field__actions"><button type="button" class="button-link-delete" data-remove-field>Remove</button></div>';
    return field;
  }

  function makeSwitchField() {
    const field = document.createElement('article');
    field.className = 'cliniko-form-builder__field cliniko-patient-form-builder-field cliniko-patient-form-builder-field--switch';
    field.draggable = false;
    field.dataset.fieldKey = switchUid();
    field.dataset.fieldKind = 'switch';
    field.innerHTML = '<span class="dashicons dashicons-randomize cliniko-patient-form-builder-field__drag" data-field-drag-handle aria-label="Drag to reorder" title="Drag to reorder"></span><div class="cliniko-patient-form-builder-field__main cliniko-patient-form-builder-switch__main"><div class="cliniko-patient-form-builder-switch__heading"><strong>Conditional choice</strong><span>A two-option answer that can show, hide, and conditionally require other fields.</span></div><label>Question shown to patient <input type="text" data-field-switch-label value="Do you have additional details to provide?" required></label><div class="cliniko-patient-form-builder-switch__options"><label>First option label <input type="text" data-field-switch-on-label value="Yes" required></label><label>Second option label <input type="text" data-field-switch-off-label value="No" required></label><label>Selected by default <select data-field-switch-default><option value="on">Yes</option><option value="off" selected>No</option></select></label></div><label>Width <select data-field-width><option value="50">50%</option><option value="100" selected>100%</option></select></label><label class="cliniko-patient-form-builder-field__required"><input type="checkbox" data-field-required checked> Require an answer before the patient submits this form</label></div><div class="cliniko-patient-form-builder-field__actions"><button type="button" class="button-link-delete" data-remove-field>Remove</button></div>';
    return field;
  }

  function makeSection() {
    const section = document.createElement('section');
    const sectionId = uniqueSectionId();
    section.className = 'cliniko-form-builder__section';
    section.dataset.section = '1';
    section.dataset.sectionId = sectionId;
    section.innerHTML = '<div class="cliniko-form-builder__section-header"><label>Section name <input type="text" value="New section" data-section-name required></label><span class="cliniko-form-builder__section-css-id">CSS ID: <code>#' + sectionId + '</code></span><label>Columns <select data-column-count><option value="1">1</option><option value="2">2</option><option value="3">3</option></select></label><button type="button" class="button-link-delete" data-remove-section>Remove section</button></div><div class="cliniko-form-builder__columns" data-columns><div class="cliniko-form-builder__column" data-column><div class="cliniko-form-builder__column-fields" data-column-fields></div><div class="cliniko-form-builder__add-actions"><button type="button" class="cliniko-form-builder__add-field" data-add-field>＋ Add patient field</button><button type="button" class="button" data-add-switch>Add conditional choice</button></div><div class="cliniko-form-builder__field-palette" data-field-palette hidden></div></div></div>';
    return section;
  }

  function addField(column) {
    const field = makeField(Object.keys(names)[0] || '');
    column.querySelector('[data-column-fields]').appendChild(field);
    field.querySelector('[data-field-selector]').focus();
    syncFieldControls(field);
  }

  function legacyRuleConfig(field) {
    const legacy = field.querySelector('[data-cliniko-rule-builder]');
    const condition = {
      condition_switch: fieldConditionConfig(field).condition_switch,
      condition_value: fieldConditionConfig(field).condition_value
    };
    if (!legacy) return condition;
    return Object.assign(condition, {
      input_type: legacy.querySelector('[data-rule-input-type]')?.value || 'default',
      date_format: legacy.querySelector('[data-rule-date-format]')?.value || 'dmy_slash',
      select_options: (legacy.querySelector('[data-rule-select-options]')?.value || '').split(/\r?\n/).filter(Boolean),
      max_length: parseInt(legacy.querySelector('[data-rule-max-length]')?.value || '0', 10) || 0,
      input_format: legacy.querySelector('[data-rule-format]')?.value || 'none',
      mask_pattern: legacy.querySelector('[data-rule-mask-pattern]')?.value || '',
      mask_prefix: legacy.querySelector('[data-rule-mask-prefix]')?.value || '',
      mask_suffix: legacy.querySelector('[data-rule-mask-suffix]')?.value || ''
    });
  }

  function resetFieldRules(field, key, fieldConfig) {
    field.querySelectorAll('[data-cliniko-rule-builder], [data-field-input-type-settings], [data-field-input-rules]').forEach(function (control) { control.remove(); });
    field.querySelector('.cliniko-patient-form-builder-field__main').insertAdjacentHTML('beforeend', ruleMarkup(key, fieldConfig || {}));
    syncFieldControls(field);
  }

  sections.querySelectorAll('.cliniko-patient-form-builder-field[data-field-key]').forEach(function (field) {
    resetFieldRules(field, field.dataset.fieldKey, legacyRuleConfig(field));
  });

  form.addEventListener('click', function (event) {
    if (event.target.matches('[data-add-section]')) {
      const section = makeSection();
      sections.appendChild(section);
      section.querySelector('[data-section-name]').focus();
    }
    if (event.target.matches('[data-add-field]')) addField(event.target.closest('[data-column]'));
    if (event.target.matches('[data-add-switch]')) { event.target.closest('[data-column]').querySelector('[data-column-fields]').appendChild(makeSwitchField()); refreshConditionalSettings(); }
    if (event.target.matches('[data-remove-field]')) { event.target.closest('.cliniko-patient-form-builder-field').remove(); refreshConditionalSettings(); }
    if (event.target.matches('[data-remove-section]')) { event.target.closest('[data-section]').remove(); refreshConditionalSettings(); }
    if (event.target.matches('[data-toggle-builder]')) {
      const panel = form.querySelector('[data-builder-panel]');
      panel.hidden = !panel.hidden;
      event.target.setAttribute('aria-expanded', String(!panel.hidden));
    }
    if (event.target.matches('[data-preview]')) openPreview(false);
    if (event.target.matches('[data-mobile-preview]')) openPreview(true);
    if (event.target.matches('[data-close-preview]')) previewModal.hidden = true;
    if (event.target.matches('[data-edit-section]')) {
      const firstName = sections.querySelector('[data-section-name]');
      if (firstName) {
        firstName.focus();
        firstName.select();
      }
    }
    if (event.target.matches('[data-reorder-section]')) {
      sections.querySelectorAll('[data-section]').forEach(function (section) {
        section.draggable = true;
        section.classList.toggle('is-reorderable');
      });
    }
    sync();
  });

  form.addEventListener('change', function (event) {
    if (event.target.matches('[data-field-condition]')) {
      const field = event.target.closest('.cliniko-patient-form-builder-field');
      if (field) {
        const condition = fieldConditionConfig(field);
        field.dataset.conditionSwitch = condition.condition_switch;
        field.dataset.conditionValue = condition.condition_value;
      }
    }
    if (event.target.matches('[data-field-selector]')) {
      const field = event.target.closest('[data-field-key]');
      const key = event.target.value;
      field.dataset.fieldKey = key;
      field.querySelector('[data-field-label]').value = names[key] || '';
      resetFieldRules(field, key, legacyRuleConfig(field));
    }
    if (event.target.matches('[data-column-count]')) {
      const section = event.target.closest('[data-section]');
      const columns = section.querySelector('[data-columns]');
      const count = parseInt(event.target.value, 10);
      while (columns.children.length < count) {
        const column = document.createElement('div');
        column.className = 'cliniko-form-builder__column';
        column.dataset.column = '1';
        column.innerHTML = '<div class="cliniko-form-builder__column-fields" data-column-fields></div><div class="cliniko-form-builder__add-actions"><button type="button" class="cliniko-form-builder__add-field" data-add-field>＋ Add patient field</button><button type="button" class="button" data-add-switch>Add conditional choice</button></div><div class="cliniko-form-builder__field-palette" data-field-palette hidden></div>';
        columns.appendChild(column);
      }
      while (columns.children.length > count) {
        const last = columns.lastElementChild;
        const target = columns.firstElementChild.querySelector('[data-column-fields]');
        last.querySelectorAll('[data-field-key]').forEach(function (field) {
          target.appendChild(field);
        });
        last.remove();
      }
      refreshConditionalSettings();
    }
    sync();
  });

  form.addEventListener('input', function (event) {
    const field = event.target.closest('.cliniko-patient-form-builder-field');
    if (field && field.dataset.fieldKind === 'patient') syncFieldControls(field);
    if (field && field.dataset.fieldKind === 'switch') refreshConditionalSettings();
    sync();
  });
  form.addEventListener('change', function (event) {
    const field = event.target.closest('.cliniko-patient-form-builder-field');
    if (field && field.dataset.fieldKind === 'patient') syncFieldControls(field);
    if (field && field.dataset.fieldKind === 'switch') refreshConditionalSettings();
  });
  form.addEventListener('submit', sync);
  sync();

  let draggedSection = null;
  let draggedField = null;

  function clearFieldDragState() {
    sections.querySelectorAll('.is-dragging, .is-drop-target').forEach(function (element) {
      element.classList.remove('is-dragging', 'is-drop-target');
    });
  }

  let pointerFieldDrag = null;

  function beginPointerFieldDrag(drag, event) {
    const rect = drag.field.getBoundingClientRect();
    const placeholder = document.createElement('div');
    placeholder.className = 'cliniko-patient-form-builder-field__placeholder';
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
    const element = document.elementFromPoint(clientX, clientY);
    if (element === drag.placeholder) return;
    const fieldsRoot = element && element.closest('[data-column-fields]');
    if (!fieldsRoot) return;
    const target = element.closest('[data-field-key]');
    clearFieldDragState();
    drag.field.classList.add('is-dragging');
    if (target && target !== drag.field && target.parentElement === fieldsRoot) {
      target.classList.add('is-drop-target');
      const rect = target.getBoundingClientRect();
      fieldsRoot.insertBefore(drag.placeholder, clientY > rect.top + (rect.height / 2) ? target.nextElementSibling : target);
      return;
    }
    if (!target) fieldsRoot.appendChild(drag.placeholder);
  }

  sections.addEventListener('pointerdown', function (event) {
    const handle = event.target.closest('[data-field-drag-handle]');
    if (!handle || event.button !== 0) return;
    const field = handle.closest('[data-field-key]');
    if (!field) return;
    event.preventDefault();
    pointerFieldDrag = { field: field, pointerId: event.pointerId, x: event.clientX, y: event.clientY, active: false };
    sections.setPointerCapture(event.pointerId);
  });

  sections.addEventListener('pointermove', function (event) {
    if (!pointerFieldDrag || pointerFieldDrag.pointerId !== event.pointerId) return;
    if (!pointerFieldDrag.active) {
      const distance = Math.hypot(event.clientX - pointerFieldDrag.x, event.clientY - pointerFieldDrag.y);
      if (distance < 4) return;
      pointerFieldDrag.active = true;
      beginPointerFieldDrag(pointerFieldDrag, event);
    }
    event.preventDefault();
    moveFieldPlaceholderAtPointer(pointerFieldDrag, event.clientX, event.clientY);
  });

  function finishPointerFieldDrag(event) {
    if (!pointerFieldDrag || pointerFieldDrag.pointerId !== event.pointerId) return;
    const drag = pointerFieldDrag;
    const active = drag.active;
    pointerFieldDrag = null;
    clearFieldDragState();
    if (sections.hasPointerCapture(event.pointerId)) sections.releasePointerCapture(event.pointerId);
    if (active) {
      drag.placeholder.parentElement.insertBefore(drag.field, drag.placeholder);
      drag.placeholder.remove();
      drag.field.classList.remove('is-dragging', 'is-drop-target');
      drag.field.setAttribute('style', drag.style);
      sync();
    }
  }

  sections.addEventListener('pointerup', finishPointerFieldDrag);
  sections.addEventListener('pointercancel', finishPointerFieldDrag);

  sections.addEventListener('dragstart', function (event) {
    const handle = event.target.closest('[data-field-drag-handle]');
    if (handle) {
      draggedField = handle.closest('[data-field-key]');
      if (!draggedField) return;
      draggedField.classList.add('is-dragging');
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', draggedField.dataset.fieldKey || 'field');
      return;
    }
    draggedSection = event.target.closest('[data-section]');
  });
  sections.addEventListener('dragover', function (event) {
    if (draggedField) {
      const fieldsRoot = event.target.closest('[data-column-fields]');
      if (!fieldsRoot) return;
      event.preventDefault();
      event.dataTransfer.dropEffect = 'move';
      const target = event.target.closest('[data-field-key]');
      clearFieldDragState();
      draggedField.classList.add('is-dragging');
      if (target && target !== draggedField) target.classList.add('is-drop-target');
      return;
    }
    if (draggedSection) event.preventDefault();
  });
  sections.addEventListener('drop', function (event) {
    if (draggedField) {
      const fieldsRoot = event.target.closest('[data-column-fields]');
      if (!fieldsRoot) return;
      event.preventDefault();
      const target = event.target.closest('[data-field-key]');
      if (target && target !== draggedField && target.parentElement === fieldsRoot) {
        const after = event.clientY > target.getBoundingClientRect().top + (target.getBoundingClientRect().height / 2);
        fieldsRoot.insertBefore(draggedField, after ? target.nextElementSibling : target);
      } else {
        fieldsRoot.appendChild(draggedField);
      }
      clearFieldDragState();
      draggedField = null;
      sync();
      return;
    }
    const target = event.target.closest('[data-section]');
    if (draggedSection && target && draggedSection !== target) {
      sections.insertBefore(draggedSection, target);
    }
    sync();
  });
  sections.addEventListener('dragend', function () {
    draggedSection = null;
    draggedField = null;
    clearFieldDragState();
  });

  function openPreview(mobile) {
    sync();
    const modal = form.closest('.wrap').querySelector('[data-preview-modal]');
    const content = modal.querySelector('[data-preview-content]');
    content.innerHTML = '';
    const preview = document.createElement('div');
    preview.className = 'cliniko-form-builder__preview-form' + (mobile ? ' is-mobile' : '');
    const title = document.createElement('h3');
    title.textContent = form.querySelector('[name="name"]').value || 'Profile form';
    preview.appendChild(title);

    sections.querySelectorAll('[data-section]').forEach(function (section) {
      const sectionPreview = document.createElement('section');
      sectionPreview.className = 'cliniko-form-builder__preview-section';
      sectionPreview.id = section.dataset.sectionId;
      const sectionTitle = document.createElement('h4');
      sectionTitle.textContent = section.querySelector('[data-section-name]').value;
      sectionPreview.appendChild(sectionTitle);

      const columnsPreview = document.createElement('div');
      columnsPreview.className = 'cliniko-form-builder__preview-row';
      section.querySelectorAll('[data-column]').forEach(function (column) {
        const columnPreview = document.createElement('div');
        columnPreview.className = 'cliniko-form-builder__preview-column';
        column.querySelectorAll('[data-field-key]').forEach(function (field) {
          const key = field.dataset.fieldKey;
          const label = field.querySelector('[data-field-label]').value;
          const required = field.querySelector('[data-field-required]').checked;
          const inputType = field.querySelector('[data-field-input-type]')?.value || 'default';
          const wrapper = document.createElement('label');
          wrapper.className = 'cliniko-form-builder__preview-field';
          wrapper.textContent = label + (required ? ' *' : '');
          let input;
          if (inputType === 'select') {
            input = document.createElement('select');
            const options = (field.querySelector('[data-field-select-options]')?.value || '').split(/\r?\n/).filter(Boolean);
            input.innerHTML = '<option>Select</option>' + options.map(function (option) { return '<option>' + escapeHtml(option) + '</option>'; }).join('');
          } else if (types[key] === 'textarea' && inputType === 'default') input = document.createElement('textarea');
          else if (types[key] === 'select' && inputType === 'default') {
            input = document.createElement('select');
            input.innerHTML = '<option>Select</option>';
          } else {
            input = document.createElement('input');
            input.type = inputType === 'date' ? 'text' : (inputType === 'text' ? 'text' : (types[key] === 'checkbox' ? 'checkbox' : types[key]));
            if (inputType === 'date') input.placeholder = field.querySelector('[data-field-date-format] option:checked')?.textContent || 'DD/MM/YYYY';
            if ((field.querySelector('[data-field-input-format]')?.value || '') === 'pattern') {
              input.placeholder = field.querySelector('[data-field-mask-preview]')?.textContent || '';
            }
          }
          input.disabled = true;
          wrapper.appendChild(input);
          columnPreview.appendChild(wrapper);
        });
        columnsPreview.appendChild(columnPreview);
      });
      sectionPreview.appendChild(columnsPreview);
      preview.appendChild(sectionPreview);
    });

    const save = document.createElement('button');
    save.type = 'button';
    save.className = 'button button-primary';
    save.textContent = 'Save details';
    save.disabled = true;
    preview.appendChild(save);
    content.appendChild(preview);
    modal.hidden = false;
  }

  previewModal.addEventListener('click', function (event) {
    if (event.target === previewModal) previewModal.hidden = true;
  });
}());
