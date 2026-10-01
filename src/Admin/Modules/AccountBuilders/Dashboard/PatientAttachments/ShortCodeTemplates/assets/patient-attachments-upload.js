(function () {
    'use strict';

    document.querySelectorAll('[data-cliniko-attachment-upload-module]').forEach(function (root) {
        var form = root.querySelector('[data-cliniko-attachment-form]');
        var message = root.querySelector('[data-cliniko-attachment-message]');
        var headers = { 'X-WP-Nonce': root.dataset.nonce };

        function show(text, error) {
            message.textContent = text;
            message.classList.toggle('is-error', !!error);
        }

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            var file = root.querySelector('[data-cliniko-attachment-file]').files[0];
            var descriptionControl = root.querySelector('[data-cliniko-attachment-description]');
            var button = form.querySelector('button');
            if (!file) return;
            button.disabled = true;
            show('Uploading…');
            try {
                var data = new FormData();
                data.append('file', file);
                data.append('description', window.ClinikoShortcodeInputRules?.valueForSubmission(descriptionControl) || descriptionControl.value);
                var response = await fetch(root.dataset.endpoint + '/upload', { method: 'POST', headers: headers, body: data });
                var json = await response.json();
                window.ClinikoConnectionNotice?.inspectResponse(response, json);
                if (!response.ok || !json.ok) throw new Error(json.message || 'Upload failed');
                form.reset();
                show('Document uploaded.');
            } catch (error) {
                if (!navigator.onLine || error instanceof TypeError) window.ClinikoConnectionNotice?.reportOffline?.();
                show(error.message, true);
            } finally {
                button.disabled = false;
            }
        });
    });
}());
