(function () {
  const root = document.querySelector('[data-cliniko-patient-details-builder]');
  if (!root) return;

  const config = root.querySelector('[data-patient-details-fields-config]');
  const list = root.querySelector('[data-patient-details-fields]');
  const picker = root.querySelector('[data-patient-details-field-picker]');
  const modal = document.querySelector('[data-patient-details-preview-modal]');
  const preview = document.querySelector('[data-patient-details-preview-content]');
  let dragged = null;

  function selected() {
    return Array.from(list.querySelectorAll('[data-patient-details-field]')).map(function (item) {
      return { key: item.dataset.fieldKey, alias: item.querySelector('[data-patient-details-field-alias]').value.trim() };
    });
  }

  function sync() {
    config.value = JSON.stringify(selected());
  }

  function fieldElement(key, sourceLabel, alias) {
    const item = document.createElement('article');
    item.className = 'cliniko-dashboard-column';
    item.draggable = true;
    item.dataset.patientDetailsField = '';
    item.dataset.fieldKey = key;
    item.innerHTML = '<span class="dashicons dashicons-menu"></span><strong></strong><label>Alias <input type="text" data-patient-details-field-alias></label><button type="button" class="button-link-delete" data-remove-patient-details-field>Remove</button>';
    item.querySelector('strong').textContent = sourceLabel;
    item.querySelector('input').value = alias || sourceLabel;
    return item;
  }

  root.querySelector('[data-add-patient-details-field]').addEventListener('click', function () {
    const key = picker.value;
    if (!key || list.querySelector('[data-field-key="' + CSS.escape(key) + '"]')) return;
    const option = picker.options[picker.selectedIndex];
    list.appendChild(fieldElement(key, option.dataset.fieldLabel || option.textContent, option.dataset.fieldLabel || option.textContent));
    sync();
  });

  list.addEventListener('click', function (event) {
    const remove = event.target.closest('[data-remove-patient-details-field]');
    if (!remove) return;
    remove.closest('[data-patient-details-field]').remove();
    sync();
  });
  list.addEventListener('input', sync);
  list.addEventListener('dragstart', function (event) {
    dragged = event.target.closest('[data-patient-details-field]');
  });
  list.addEventListener('dragover', function (event) {
    event.preventDefault();
    const target = event.target.closest('[data-patient-details-field]');
    if (!dragged || !target || dragged === target) return;
    const box = target.getBoundingClientRect();
    list.insertBefore(dragged, event.clientY < box.top + box.height / 2 ? target : target.nextSibling);
  });
  list.addEventListener('drop', sync);
  list.addEventListener('dragend', function () { dragged = null; sync(); });

  root.querySelector('[data-toggle-patient-details-builder]').addEventListener('click', function (event) {
    const panel = root.querySelector('[data-patient-details-builder-panel]');
    panel.hidden = !panel.hidden;
    event.currentTarget.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
  });

  function showPreview(mobile) {
    sync();
    const columns = Math.max(1, Math.min(3, Number(root.elements.columns.value || 2)));
    const title = root.elements.title.value.trim();
    const emptyValue = root.elements.empty_value.value || '—';
    const showLabels = root.elements.show_labels.checked;
    const sampleValues = {
      full_name: 'Alex Patient',
      first_name: 'Alex', last_name: 'Patient', email: 'alex@example.com', phone: '0400 000 000',
      date_of_birth: '1 January 1990', address_1: '10 Example Street', city: 'Sydney',
      state: 'NSW', post_code: '2000', country: 'Australia'
    };
    const wrapper = document.createElement('div');
    wrapper.className = 'cliniko-form-builder__preview-form cliniko-patient-details-preview' + (mobile ? ' is-mobile' : '');
    if (title) {
      const heading = document.createElement('h2');
      heading.textContent = title;
      wrapper.appendChild(heading);
    }
    const grid = document.createElement('div');
    grid.className = 'cliniko-dashboard-details';
    grid.style.setProperty('--cliniko-detail-columns', mobile ? '1' : String(columns));
    selected().forEach(function (field) {
      const row = document.createElement('div');
      row.className = 'cliniko-dashboard-detail';
      if (showLabels) {
        const label = document.createElement('strong');
        label.textContent = field.alias;
        row.appendChild(label);
      }
      const value = document.createElement('span');
      value.textContent = sampleValues[field.key] || ('Example ' + field.alias.toLowerCase()) || emptyValue;
      row.appendChild(value);
      grid.appendChild(row);
    });
    wrapper.appendChild(grid);
    preview.replaceChildren(wrapper);
    modal.hidden = false;
  }

  root.querySelector('[data-patient-details-preview]').addEventListener('click', function () { showPreview(false); });
  root.querySelector('[data-patient-details-mobile-preview]').addEventListener('click', function () { showPreview(true); });
  document.querySelector('[data-close-patient-details-preview]').addEventListener('click', function () { modal.hidden = true; });
  modal.addEventListener('click', function (event) { if (event.target === modal) modal.hidden = true; });
  root.addEventListener('submit', sync);
  sync();
}());
