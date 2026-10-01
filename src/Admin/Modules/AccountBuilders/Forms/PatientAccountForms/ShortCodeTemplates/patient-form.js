(function () {
  function initialiseConditionalFields(form) {
    var switches = Array.prototype.slice.call(form.querySelectorAll('[data-cliniko-switch]'));
    var conditionalFields = Array.prototype.slice.call(form.querySelectorAll('[data-cliniko-conditional-field]'));

    function controls(field) {
      return Array.prototype.slice.call(field.querySelectorAll('input, select, textarea'));
    }

    function switchIsOn(key) {
      var selected = switches.find(function (control) {
        return control.getAttribute('data-cliniko-switch') === key && control.checked;
      });
      return !!(selected && ['1', 'yes', 'true', 'on'].indexOf(String(selected.value).toLowerCase()) !== -1);
    }

    function sync() {
      conditionalFields.forEach(function (field) {
        var expectedOn = field.getAttribute('data-cliniko-condition-value') !== 'off';
        var visible = switchIsOn(field.getAttribute('data-cliniko-condition-switch') || '') === expectedOn;
        var fieldControls = controls(field);

        field.hidden = !visible;
        field.setAttribute('aria-hidden', visible ? 'false' : 'true');
        fieldControls.forEach(function (control) {
          control.disabled = !visible;
          control.required = false;
        });

        if (visible && field.getAttribute('data-cliniko-condition-required') === '1') {
          var requiredControl = fieldControls.find(function (control) {
            return control.type !== 'hidden' && !control.hasAttribute('data-cliniko-native-date-picker');
          });
          if (requiredControl) requiredControl.required = true;
        }
      });
    }

    switches.forEach(function (control) { control.addEventListener('change', sync); });
    sync();
  }

  document.querySelectorAll('[data-cliniko-patient-form]').forEach(initialiseConditionalFields);
}());
