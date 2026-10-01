(function () {
  'use strict';
  function escapeHtml(value) { return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[c]; }); }
  function formatDate(value) { var date = new Date(value || ''); return isNaN(date.getTime()) ? '' : date.toLocaleString(); }
  function init(root) {
    var status = root.querySelector('[data-cliniko-communication-status]');
    var form = root.querySelector('[data-cliniko-communication-form]');
    var emailModal = root.querySelector('[data-cliniko-email-modal]');
    var emailModalTitle = root.querySelector('[data-cliniko-email-modal-title]');
    var emailModalContent = root.querySelector('[data-cliniko-email-modal-content]');
    var emailModalDialog = emailModal && emailModal.querySelector('[role="dialog"]');
    var emailContents = {};
    var lastEmailOpener = null;
    var activeView = 'general';
    var unreadCounts = { general: 0, email: 0, sms: 0, total: 0 };
    var headers = { Accept: 'application/json', 'X-WP-Nonce': root.dataset.nonce || '' };
    function show(message, error) { if (!status) return; status.textContent = message; status.classList.toggle('is-error', !!error); }
    async function request(url, options) { try { var response = await fetch(url, Object.assign({ headers: headers }, options || {})); var json = await response.json(); window.ClinikoConnectionNotice?.inspectResponse(response, json); if (!response.ok || !json.ok) throw new Error(json.message || 'Request failed'); return json.data; } catch (error) { if (!navigator.onLine || error instanceof TypeError) window.ClinikoConnectionNotice?.reportOffline?.(); throw error; } }
    function updateUnreadBadges() {
      root.querySelectorAll('[data-cliniko-unread-badge]').forEach(function (badge) {
        var view = badge.dataset.clinikoUnreadBadge;
        var count = Math.max(0, Number(unreadCounts[view]) || 0);
        badge.hidden = count === 0;
        badge.textContent = count > 99 ? '99+' : String(count);
        var tab = badge.closest('[data-cliniko-communication-tab]');
        if (tab) tab.setAttribute('aria-label', count ? tab.dataset.clinikoCommunicationTab + ', ' + count + ' unread' : tab.dataset.clinikoCommunicationTab);
      });
      document.dispatchEvent(new CustomEvent('cliniko:communications-unread-change', { detail: { unread: unreadCounts.total } }));
    }
    async function loadUnread() {
      var data = await request(root.dataset.endpoint + '/unread');
      unreadCounts = Object.assign(unreadCounts, data.unread || {});
      updateUnreadBadges();
    }
    async function markItemsRead(view, items) {
      var ids = items.filter(function (item) { return Number(item.direction_code) === 1 && item.id; }).map(function (item) { return String(item.id); });
      if (!ids.length) return [];
      try {
        var data = await request(root.dataset.endpoint + '/read', { method: 'POST', headers: Object.assign({}, headers, { 'Content-Type': 'application/json' }), body: JSON.stringify({ ids: ids }) });
        var readIds = Array.isArray(data.read_ids) ? data.read_ids.map(String) : [];
        var readCount = readIds.length;
        if (readCount) {
          unreadCounts[view] = Math.max(0, (Number(unreadCounts[view]) || 0) - readCount);
          unreadCounts.total = Math.max(0, (Number(unreadCounts.total) || 0) - readCount);
          updateUnreadBadges();
        }
        return readIds;
      } catch (error) { return []; /* The messages remain unread and will be retried next time. */ }
    }
    function itemMarkup(item) {
      var sentByClinic = Number(item.direction_code) === 1;
      var author = sentByClinic ? (item.from || root.dataset.clinicLabel || 'Clinic') : 'You';
      var recipient = sentByClinic ? 'You' : (item.to || root.dataset.clinicLabel || 'Clinic');
      if (item.content_html) {
        var id = String(item.id || 'email-' + Math.random().toString(36).slice(2));
        var unread = !!item.is_unread;
        var text = document.createElement('div'); text.innerHTML = String(item.content || '');
        var preview = (text.textContent || text.innerText || '').trim().replace(/\s+/g, ' ');
        emailContents[id] = { content: String(item.content || ''), title: author + ' → ' + recipient, item: item };
        return '<article class="cliniko-patient-communications__item ' + (sentByClinic ? 'is-clinic' : 'is-patient') + (unread ? ' is-unread' : '') + '"><header><strong>' + escapeHtml(author) + ' <span>→</span> ' + escapeHtml(recipient) + (unread ? ' <span class="cliniko-patient-communications__email-unread">Unread</span>' : '') + '</strong><time>' + escapeHtml(formatDate(item.created_at)) + '</time></header><p class="cliniko-patient-communications__email-preview">' + escapeHtml(preview.slice(0, 220) || 'Open email to view its contents.') + '</p><div class="cliniko-patient-communications__email-actions"><small>' + escapeHtml(item.type || 'Email') + '</small><button class="cliniko-patient-communications__open-email" type="button" data-cliniko-open-email="' + escapeHtml(id) + '">Open email</button></div></article>';
      }
      return '<article class="cliniko-patient-communications__item ' + (sentByClinic ? 'is-clinic' : 'is-patient') + '"><header><strong>' + escapeHtml(author) + ' <span>→</span> ' + escapeHtml(recipient) + '</strong><time>' + escapeHtml(formatDate(item.created_at)) + '</time></header><div class="cliniko-patient-communications__item-content">' + escapeHtml(item.content || '').replace(/\n/g, '<br>') + '</div><small>' + escapeHtml(item.type || 'Memo') + '</small></article>';
    }
    function closeEmail() { if (!emailModal || emailModal.hidden) return; emailModal.hidden = true; if (lastEmailOpener) lastEmailOpener.focus(); }
    function openEmail(id, opener) { var email = emailContents[id]; if (!email || !emailModal || !emailModalContent) return; lastEmailOpener = opener; emailModalTitle.textContent = email.title || 'Email'; emailModalContent.innerHTML = email.content; emailModal.hidden = false; if (email.item.is_unread) markItemsRead('email', [email.item]).then(function (readIds) { if (readIds.indexOf(String(email.item.id)) === -1) return; email.item.is_unread = false; var article = opener.closest('.cliniko-patient-communications__item'); if (article) { article.classList.remove('is-unread'); var indicator = article.querySelector('.cliniko-patient-communications__email-unread'); if (indicator) indicator.remove(); } }); if (emailModalDialog) emailModalDialog.focus(); }
    async function load(view) {
      var list = root.querySelector('[data-cliniko-communication-list="' + view + '"]');
      if (!list) return;
      try {
        var data = await request(root.dataset.endpoint + '?page=1&per_page=' + encodeURIComponent(root.dataset.perPage || '20') + '&view=' + encodeURIComponent(view));
        var items = data.communications || [];
        list.innerHTML = items.length ? items.map(itemMarkup).join('') : '<p class="cliniko-patient-communications__empty">No ' + (view === 'general' ? 'messages' : view.toUpperCase() + ' communications') + ' are available yet.</p>';
        if (view !== 'email') markItemsRead(view, items);
      } catch (error) { list.innerHTML = ''; show(error.message, true); }
    }
    function selectView(view, focus) {
      activeView = view;
      root.querySelectorAll('[data-cliniko-communication-tab]').forEach(function (tab) { var active = tab.dataset.clinikoCommunicationTab === view; tab.setAttribute('aria-selected', active ? 'true' : 'false'); tab.tabIndex = active ? 0 : -1; if (active && focus) tab.focus(); });
      root.querySelectorAll('[data-cliniko-communication-panel]').forEach(function (panel) { panel.hidden = panel.dataset.clinikoCommunicationPanel !== view; });
      load(view);
    }
    root.querySelectorAll('[data-cliniko-communication-tab]').forEach(function (tab) { tab.addEventListener('click', function () { selectView(tab.dataset.clinikoCommunicationTab, false); }); });
    root.addEventListener('click', function (event) { var opener = event.target.closest('[data-cliniko-open-email]'); if (opener) openEmail(opener.dataset.clinikoOpenEmail, opener); if (event.target.closest('[data-cliniko-email-modal-close]')) closeEmail(); });
    root.addEventListener('keydown', function (event) { if (event.key === 'Escape') closeEmail(); });
    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      var button = form.querySelector('button[type="submit"]'); var content = form.querySelector('[data-cliniko-communication-content]');
      if (!content || !content.value.trim()) return;
      button.disabled = true; show('Sending your message…');
      try { await request(root.dataset.endpoint, { method: 'POST', headers: Object.assign({}, headers, { 'Content-Type': 'application/json' }), body: JSON.stringify({ content: content.value.trim(), clinic_label: root.dataset.clinicLabel || 'Clinic' }) }); form.reset(); show('Your message was recorded for the clinic.'); selectView('general', false); }
      catch (error) { show(error.message, true); } finally { button.disabled = false; }
    });
    loadUnread().catch(function () { /* History stays usable if unread totals are unavailable. */ }).finally(function () { selectView(activeView, false); });
  }
  document.querySelectorAll('[data-cliniko-patient-communications]').forEach(init);
}());
