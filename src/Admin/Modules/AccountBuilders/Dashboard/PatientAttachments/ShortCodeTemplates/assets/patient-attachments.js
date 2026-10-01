(function () {
    'use strict';

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (character) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[character];
        });
    }

    function init(root) {
        var query = function (selector) { return root.querySelector(selector); };
        var message = query('[data-cliniko-attachment-message]');
        var list = query('[data-cliniko-attachment-list]');
        var descriptionTemplate = query('[data-cliniko-attachment-description-template]');
        var headers = { 'X-WP-Nonce': root.dataset.nonce };
        function show(text, error) { message.textContent = text; message.classList.toggle('is-error', !!error); }
        async function request(url, options) {
            try {
                var response = await fetch(url, Object.assign({ headers: Object.assign({ Accept: 'application/json' }, headers) }, options || {}));
                var json = await response.json();
                window.ClinikoConnectionNotice?.inspectResponse(response, json);
                if (!response.ok || !json.ok) throw new Error(json.message || 'Request failed');
                return json.data;
            } catch (error) {
                if (!navigator.onLine || error instanceof TypeError) window.ClinikoConnectionNotice?.reportOffline?.();
                throw error;
            }
        }
        async function load() {
            try {
                var data = await request(root.dataset.endpoint), attachments = data.attachments || [];
                list.innerHTML = attachments.length ? attachments.map(function (item) {
                var id = item.id || '';
                    return '<article class="cliniko-dashboard-attachment" data-attachment-id="' + escapeHtml(id) + '">' +
                        '<strong>' + escapeHtml(item.filename || item.description || 'Document') + '</strong>' +
                        '<form data-cliniko-attachment-edit data-description="' + escapeHtml(item.description || '') + '"><span data-cliniko-attachment-edit-control></span><button type="submit">Save</button></form>' +
                    '<button type="button" data-cliniko-attachment-delete>Delete</button></article>';
                }).join('') : '<p>No documents uploaded yet.</p>';
                list.querySelectorAll('[data-cliniko-attachment-edit]').forEach(function (form) {
                    var slot = form.querySelector('[data-cliniko-attachment-edit-control]');
                    if (slot && descriptionTemplate) slot.replaceWith(descriptionTemplate.content.cloneNode(true));
                    var control = form.querySelector('input,select');
                    if (control) control.value = form.dataset.description || '';
                });
                window.ClinikoShortcodeInputRules?.init(list);
            } catch (error) { list.innerHTML = ''; show(error.message, true); }
        }
        query('[data-cliniko-attachment-form]').addEventListener('submit', async function (event) {
            event.preventDefault();
            var file = query('[data-cliniko-attachment-file]').files[0], button = event.target.querySelector('button');
            if (!file) return;
            button.disabled = true; show('Uploading…');
            try {
                var form = new FormData();
                form.append('file', file);
                var descriptionControl = query('[data-cliniko-attachment-description]');
                form.append('description', window.ClinikoShortcodeInputRules?.valueForSubmission(descriptionControl) || descriptionControl.value);
                await request(root.dataset.endpoint + '/upload', { method: 'POST', body: form });
                event.target.reset(); show('Document uploaded.'); await load();
            } catch (error) { show(error.message, true); } finally { button.disabled = false; }
        });
        list.addEventListener('submit', async function (event) {
            var form = event.target.closest('[data-cliniko-attachment-edit]');
            if (!form) return;
            event.preventDefault();
            var item = form.closest('[data-attachment-id]'), button = form.querySelector('button');
            button.disabled = true;
            try {
                await request(root.dataset.endpoint + '/' + encodeURIComponent(item.dataset.attachmentId), {
                    method: 'PATCH',
                    headers: Object.assign({ 'Content-Type': 'application/json' }, headers),
                    body: JSON.stringify({ description: window.ClinikoShortcodeInputRules?.valueForSubmission(form.querySelector('input,select')) || form.querySelector('input,select')?.value || '' })
                });
                show('Description updated.'); await load();
            } catch (error) { show(error.message, true); } finally { button.disabled = false; }
        });
        list.addEventListener('click', async function (event) {
            var button = event.target.closest('[data-cliniko-attachment-delete]');
            if (!button) return;
            var item = button.closest('[data-attachment-id]');
            if (!window.confirm('Delete this document?')) return;
            button.disabled = true;
            try {
                await request(root.dataset.endpoint + '/' + encodeURIComponent(item.dataset.attachmentId), { method: 'DELETE' });
                show('Document deleted.'); await load();
            } catch (error) { show(error.message, true); button.disabled = false; }
        });
        load();
    }

    document.querySelectorAll('[data-cliniko-attachments]').forEach(init);
}());
