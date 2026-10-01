(function () {
    'use strict';

    var table = document.querySelector('.cliniko-booking-builder__table');
    if (!table) return;

    function rowForControl(id) {
        var control = document.getElementById(id);
        return control ? control.closest('tr') : null;
    }

    function sectionBefore(row, number, title, description) {
        if (!row || row.previousElementSibling?.classList.contains('cliniko-booking-builder__section')) return;
        var section = document.createElement('tr');
        section.className = 'cliniko-booking-builder__section';
        section.innerHTML = '<th colspan="2"><span>' + number + '</span><div><strong>' + title + '</strong><small>' + description + '</small></div></th>';
        row.before(section);
    }

    sectionBefore(rowForControl('booking-form-scheduling'), '2', 'Patient journey', 'Control appointment selection, page flow, and payment.');
    sectionBefore(rowForControl('booking-form-success-action'), '3', 'Success and failure', 'Choose what patients see after the booking attempt finishes.');
    sectionBefore(rowForControl('booking-form-custom-css'), '5', 'Advanced code', 'Optional CSS and JavaScript for this specific guest form.');

    var behaviourRow = Array.from(table.querySelectorAll('tr')).find(function (row) {
        return row.querySelector('th')?.textContent.trim() === 'Input behaviour';
    });
    sectionBefore(behaviourRow, '4', 'Fields and input behaviour', 'Configure dates, dropdowns, character limits, number-only fields, and guided masks.');
    if (behaviourRow) behaviourRow.classList.add('cliniko-booking-builder__wide-row');

    function syncAction(selectId, messageId, redirectId) {
        var select = document.getElementById(selectId);
        var message = document.getElementById(messageId)?.closest('tr');
        var redirect = document.getElementById(redirectId)?.closest('tr');
        if (!select || !message || !redirect) return;
        function apply() {
            var redirects = select.value === 'redirect';
            message.hidden = redirects;
            redirect.hidden = !redirects;
        }
        select.addEventListener('change', apply);
        apply();
    }

    syncAction('booking-form-success-action', 'booking-form-success', 'booking-form-success-redirect');
    syncAction('booking-form-failure-action', 'booking-form-failure-message', 'booking-form-failure-redirect');

    var mode = document.getElementById('booking-form-mode');
    if (mode) {
        var originalMode = mode.value;
        var note = document.createElement('p');
        note.className = 'cliniko-booking-builder__reload-note';
        note.hidden = true;
        note.textContent = 'Save and reopen this form to refresh the fields available for the selected booking mode.';
        mode.closest('td')?.appendChild(note);
        mode.addEventListener('change', function () { note.hidden = mode.value === originalMode; });
    }
}());
