(function () {
  const form = document.querySelector('[data-cliniko-dashboard-module-builder]');
  if (!form) return;
  const modal = document.querySelector('[data-dashboard-preview-modal]');
  const content = modal.querySelector('[data-dashboard-preview-content]');
  const columns = form.querySelector('[data-dashboard-columns]');
  const config = form.querySelector('[data-dashboard-columns-config]');
  const picker = form.querySelector('[data-dashboard-column-picker]');
  const templateSelect = form.querySelector('[name="patient_form_template_id"]');
  let dragged = null;

  function syncColumns() {
    config.value = JSON.stringify(Array.from(columns.querySelectorAll('[data-dashboard-column]')).map(function (column) {
      return {key: column.dataset.columnKey, field: column.dataset.columnField || '', alias: column.querySelector('[data-column-alias]').value};
    }));
  }

  function refreshFormFields() {
    if (!templateSelect) return;
    picker.querySelectorAll('[data-form-field]').forEach(function (option) { option.remove(); });
    const selected = templateSelect.options[templateSelect.selectedIndex];
    let fields = [];
    try { fields = JSON.parse(selected?.dataset.formFields || '[]'); } catch (error) { fields = []; }
    fields.forEach(function (field) {
      const option = document.createElement('option');
      option.value = 'patient_form_field';
      option.dataset.formField = field.field;
      option.dataset.columnLabel = field.label;
      option.textContent = 'Form: ' + field.label;
      picker.appendChild(option);
    });
  }

  function addColumn() {
    const option = picker.options[picker.selectedIndex];
    const key = option.value;
    const field = option.dataset.formField || '';
    if (!key || columns.querySelector('[data-column-key="' + key + '"][data-column-field="' + field + '"]')) return;
    const column = document.createElement('article');
    column.className = 'cliniko-dashboard-column';
    column.draggable = true;
    column.dataset.dashboardColumn = '1';
    column.dataset.columnKey = key;
    if (field) column.dataset.columnField = field;
    column.innerHTML = '<span class="dashicons dashicons-menu"></span><strong>' + escape(option.textContent) + '</strong><label>Alias <input type="text" value="' + escape(option.dataset.columnLabel || option.textContent) + '" data-column-alias></label><button type="button" class="button-link-delete" data-remove-dashboard-column>Remove</button>';
    columns.appendChild(column);
    syncColumns();
  }

  form.addEventListener('click', function (event) {
    if (event.target.matches('[data-toggle-module-builder]')) {
      const panel = form.querySelector('[data-module-builder-panel]');
      panel.hidden = !panel.hidden;
      event.target.setAttribute('aria-expanded', String(!panel.hidden));
    }
    if (event.target.matches('[data-add-dashboard-column]')) addColumn();
    if (event.target.matches('[data-remove-dashboard-column]')) {
      event.target.closest('[data-dashboard-column]').remove();
      syncColumns();
    }
    if (event.target.matches('[data-dashboard-preview]')) openPreview(false);
    if (event.target.matches('[data-dashboard-mobile-preview]')) openPreview(true);
  });

  form.addEventListener('input', syncColumns);
  if (templateSelect) templateSelect.addEventListener('change', function () {
    columns.querySelectorAll('[data-column-key="patient_form_field"]').forEach(function (column) { column.remove(); });
    refreshFormFields();
    syncColumns();
  });
  form.addEventListener('submit', syncColumns);
  columns.addEventListener('dragstart', function (event) { dragged = event.target.closest('[data-dashboard-column]'); });
  columns.addEventListener('dragover', function (event) { if (dragged) event.preventDefault(); });
  columns.addEventListener('drop', function (event) {
    const target = event.target.closest('[data-dashboard-column]');
    if (dragged && target && dragged !== target) columns.insertBefore(dragged, target);
    syncColumns();
  });

  modal.addEventListener('click', function (event) {
    if (event.target === modal || event.target.matches('[data-close-dashboard-preview]')) modal.hidden = true;
  });

  function openPreview(mobile) {
    syncColumns();
    const title = form.querySelector('[name="title"]').value || 'Appointments';
    const typeSelect = form.querySelector('[name="appointment_type_id"]');
    const typeName = typeSelect.options[typeSelect.selectedIndex] ? typeSelect.options[typeSelect.selectedIndex].textContent : 'Appointment';
    const configured = JSON.parse(config.value || '[]');
    const sample = {
      starts_at: '18/08/2026, 10:00 am', ends_at: '18/08/2026, 10:45 am',
      service: typeName, appointment_type: typeName, status: 'Upcoming',
      doctor: 'Dr Example', duration: '45 minutes', category: 'Consultation',
      description: 'Appointment description', price: '$120.00', notes: 'Appointment notes',
      telehealth_url: 'Join telehealth', patient_form: 'Completed 18/08/2026'
    };
    const preview = document.createElement('div');
    preview.className = 'cliniko-form-builder__preview-form' + (mobile ? ' is-mobile' : '');
    const headers = configured.map(function (column) { return '<th>' + escape(column.alias) + '</th>'; }).join('');
    const values = configured.map(function (column) { return '<td>' + escape(sample[column.key] || '') + '</td>'; }).join('');
    const details = form.querySelector('[name="show_details_button"]').checked
      ? '<td><button type="button" class="button">' + escape(form.querySelector('[name="details_button_label"]').value || 'View details') + '</button></td>'
      : '';
    preview.innerHTML = '<h3>' + escape(title) + '</h3><div class="cliniko-dashboard-table-scroll"><table class="widefat striped"><thead><tr>' + headers + (details ? '<th>Details</th>' : '') + '</tr></thead><tbody><tr>' + values + details + '</tr></tbody></table></div>';
    content.innerHTML = '';
    content.appendChild(preview);
    modal.hidden = false;
  }

  function escape(value) {
    const node = document.createElement('span');
    node.textContent = value == null ? '' : String(value);
    return node.innerHTML;
  }

  syncColumns();
  refreshFormFields();
}());
