(function($) {
  const syncCustomActions = () => {
    const selectControl = $('[data-setting="selected_appointment_types"]');
    const jsonInput = $('[data-setting="custom_actions_data"]');
    
    const uiContainer = $('#custom-actions-panel');

    if (!selectControl.length || !jsonInput.length || !uiContainer.length) return;

    const currentValue = jsonInput.val();
    let data = {};
    try {
      data = JSON.parse(currentValue || '{}');
    } catch (e) {
      data = {};
    }

    const selectedIds = selectControl.val() || [];

    // Remove unselected
    Object.keys(data).forEach(id => {
      if (!selectedIds.includes(id)) {
        delete data[id];
      }
    });

    // Add new empty ones
    selectedIds.forEach(id => {
      if (!data[id]) {
        data[id] = { text: 'Book Now', url: '' };
      }
    });

    // Save updated structure
    jsonInput.val(JSON.stringify(data));

    // Render UI
    uiContainer.html('');
    selectedIds.forEach(id => {
      const item = data[id];

      const group = $(`
        <div style="margin-bottom: 20px;">
          <label><strong>ID ${id}</strong></label>
          <div style="margin-top: 8px;">
            <label>Button Text</label>
            <input type="text" class="button-text" data-id="${id}" value="${item.text}" style="width: 100%; margin-bottom: 8px;">
            <label>Button URL</label>
            <input type="text" class="button-url" data-id="${id}" value="${item.url}" style="width: 100%;">
          </div>
        </div>
      `);

      uiContainer.append(group);
    });

    // Sync on change
    uiContainer.find('input').on('input', function() {
      const id = $(this).data('id');
      const type = $(this).hasClass('button-text') ? 'text' : 'url';
      const value = $(this).val();

      if (data[id]) {
        data[id][type] = value;
        jsonInput.val(JSON.stringify(data));
      }
    });
  };

  $(window).on('elementor:init', function() {
    elementor.hooks.addAction('panel/open_editor/widget', function(panel, model) {
      // Wait a moment for controls to render
      setTimeout(syncCustomActions, 500);

      // Also re-bind on select change
      $(document).on('change', '[data-setting="selected_appointment_types"]', syncCustomActions);
    });
  });
})(jQuery);
