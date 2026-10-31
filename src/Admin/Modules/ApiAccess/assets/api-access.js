document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-booking-flow-toggle]').forEach((button) => {
    const panel = document.getElementById(button.getAttribute('aria-controls'));
    if (!panel) return;
    // Without JavaScript the details remain readable and action forms still work.
    button.disabled = false;
    button.setAttribute('aria-expanded', 'false');
    panel.hidden = true;
    button.addEventListener('click', () => {
      const expanded = button.getAttribute('aria-expanded') !== 'true';
      button.setAttribute('aria-expanded', String(expanded));
      panel.hidden = !expanded;
    });
  });
  document.querySelectorAll('[data-api-confirm]').forEach((button) => button.addEventListener('click', (event) => {
    if (!window.confirm(button.dataset.apiConfirm)) event.preventDefault();
  }));
  document.querySelectorAll('[data-copy-value], [data-copy-target]').forEach((button) => button.addEventListener('click', async () => {
    const target = button.dataset.copyTarget ? document.getElementById(button.dataset.copyTarget) : null;
    const value = button.dataset.copyValue || (target instanceof HTMLTextAreaElement || target instanceof HTMLInputElement ? target.value : target?.textContent) || '';
    const status = document.getElementById(button.dataset.copyStatusTarget || 'cliniko-api-copy-status');
    if (button.dataset.copyTarget && !target) {
      if (status) status.textContent = 'The requested content is unavailable. Reload the page and try again.';
      return;
    }
    try {
      if (!navigator.clipboard) throw new Error('Clipboard unavailable');
      await navigator.clipboard.writeText(value);
      if (status) status.textContent = button.dataset.copySuccess || 'Copied. Examples contain placeholders, not real credentials.';
    } catch {
      if (target) {
        for (let parent = target.parentElement; parent; parent = parent.parentElement) {
          if (parent instanceof HTMLDetailsElement) parent.open = true;
        }
        if (target instanceof HTMLTextAreaElement || target instanceof HTMLInputElement) {
          target.focus();
          target.select();
        } else {
          const range = document.createRange(); range.selectNodeContents(target);
          const selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range);
        }
      }
      if (status) status.textContent = target ? 'Example selected. Use Ctrl+C / Command+C to copy.' : 'Copy unavailable in this browser. Select the displayed value and copy it manually.';
    }
  }));
  document.querySelectorAll('[data-download-json-target]').forEach((button) => button.addEventListener('click', () => {
    const target = document.getElementById(button.dataset.downloadJsonTarget);
    const status = document.getElementById(button.dataset.copyStatusTarget || 'cliniko-api-copy-status');
    try {
      const json = target instanceof HTMLTextAreaElement ? target.value : target?.textContent;
      if (!json) throw new Error('Missing JSON');
      JSON.parse(json);
      const url = URL.createObjectURL(new Blob([json], {type: 'application/json;charset=utf-8'}));
      const link = document.createElement('a');
      link.href = url;
      link.download = 'booking-flows-developer.json';
      link.hidden = true;
      document.body.append(link);
      link.click();
      link.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
      if (status) status.textContent = 'JSON download started. Share the file with your developer; this is a development snapshot, not a live API response.';
    } catch {
      if (status) status.textContent = 'JSON could not be downloaded. Use View developer JSON to copy it manually, or reload the page.';
    }
  }));
  const flowButtons = [...document.querySelectorAll('[data-flow-button]')];
  const flowPanels = [...document.querySelectorAll('[data-flow-panel]')];
  const chooseFlow = (key, focus = false) => {
    flowButtons.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.flowButton === key)));
    flowPanels.forEach((panel) => {
      panel.hidden = panel.dataset.flowPanel !== key;
      if (!panel.hidden && focus) panel.querySelector('h3').focus();
    });
  };
  if (flowButtons.length) {
    chooseFlow('wordpress');
    flowButtons.forEach((button) => button.addEventListener('click', () => chooseFlow(button.dataset.flowButton, true)));
  }
  const clientForm = document.querySelector('[data-api-client-form]');
  if (clientForm) {
    const type = clientForm.querySelector('[name="type"]');
    const updateType = () => {
      const descriptions = {patient: 'Uses this website’s WordPress, Google or Apple login and patient JWT sessions. Copy the generated public App ID after saving.', oauth: 'Uses a separate OAuth 2.0 service. Enter its public app/client ID. Patient identities require explicit links.', server: 'Receives a private key for trusted backend use only. Cannot access patient profiles, booking creation, payment or booking progress.'};
      clientForm.querySelector('[data-client-type-description]').textContent = descriptions[type.value];
      ['external_id', 'google_client_id', 'google_presenter_id', 'apple_client_id'].forEach((name) => {
        const input = clientForm.querySelector(`[name="${name}"]`);
        const visible = name === 'external_id' ? type.value === 'oauth' : type.value === 'patient';
        input.closest('tr').hidden = !visible;
        input.disabled = !visible;
      });
      clientForm.querySelectorAll('[name="scopes[]"]').forEach((input) => {
          const forbidden = type.value === 'server' && ['patient:read', 'patient:write', 'patient:onboarding', 'appointments:read', 'communications:read', 'communications:write', 'attachments:read', 'attachments:write', 'booking:create', 'booking:read', 'account:register'].includes(input.value);
        input.disabled = forbidden;
        if (forbidden) input.checked = false;
        input.closest('label').hidden = forbidden;
      });
    };
    type.addEventListener('change', updateType);
    updateType();
  }
  const slug = document.getElementById('api-slug');
  const preview = document.getElementById('cliniko-api-url-preview');
  if (slug && preview) slug.addEventListener('input', () => {
    const value = slug.value.trim().toLowerCase().replace(/^\/+|\/+$/g, '');
    preview.textContent = `${preview.dataset.restRoot}${value}/v1`;
  });
  document.querySelectorAll('[data-cliniko-picker]').forEach((picker) => {
    const search = picker.querySelector('[data-cliniko-picker-search]');
    if (!search) return;
    search.addEventListener('input', () => {
      const query = search.value.trim().toLowerCase();
      let matches = 0;
      picker.querySelectorAll('[data-picker-search]').forEach((choice) => {
        choice.hidden = !choice.dataset.pickerSearch.includes(query);
        if (!choice.hidden) matches++;
      });
      picker.querySelector('[data-cliniko-picker-no-matches]').hidden = matches > 0;
    });
  });
  const login = document.querySelector('[data-login-setup]');
  if (login) {
    const panels = Array.from(login.querySelectorAll('[data-login-panel]'));
    const buttons = Array.from(login.querySelectorAll('[data-login-go]'));
    const required = Array.from(login.querySelectorAll('[data-login-required]'));
    const inputs = required.map((row) => document.getElementById(`api-${row.dataset.loginRequired}`));
    const enabled = document.getElementById('api-oauth-enabled');
    const provider = document.getElementById('api-oauth-provider');
    const providerConfig = () => JSON.parse(document.getElementById(`cliniko-api-provider-${provider.value}`).dataset.providerConfig);
    const initialProvider = provider.value;
    const secret = document.getElementById('api-introspection_secret');
    const check = login.querySelector('[data-login-check]');
    const form = enabled.form;
    const initial = [...inputs.map((input) => input.value), enabled.checked];
    const error = login.querySelector('[data-login-error]');
    const fieldStep = (name) => ['issuer', 'audience'].includes(name) ? 1 : 2;
    let step = 0;
    const go = (index, focus = true) => {
      step = Math.max(0, Math.min(panels.length - 1, index));
      panels.forEach((panel, i) => { panel.hidden = i !== step; });
      buttons.forEach((button, i) => {
        if (i === step) button.setAttribute('aria-current', 'step');
        else button.removeAttribute('aria-current');
      });
      login.querySelector('[data-login-position]').textContent = `Step ${step + 1} of ${panels.length}`;
      login.querySelector('[data-login-previous]').disabled = step === 0;
      login.querySelector('[data-login-next]').disabled = step === panels.length - 1;
      if (focus) panels[step].querySelector('h3').focus();
    };
    const present = (row, input) => input.value.trim() !== '' ||
      (row.dataset.loginRequired === 'introspection_secret' && login.dataset.savedSecret === '1');
    const changed = () => inputs.some((input, i) => input.value !== initial[i]) || enabled.checked !== initial[inputs.length] || provider.value !== initialProvider;
    const refresh = () => {
      required.forEach((row, i) => {
        const ready = present(row, inputs[i]);
        const indicator = row.querySelector('.cliniko-api-login-indicator');
        indicator.textContent = ready ? 'Present' : 'Needed';
        indicator.classList.toggle('is-ready', ready);
        indicator.classList.toggle('is-pending', !ready);
      });
      const dirty = changed();
      login.querySelector('[data-login-unsaved]').hidden = !dirty;
      // This action checks saved values, never a password that is still being edited.
      check.disabled = dirty || !check.dataset.savedReady || !providerConfig().can_enable;
      error.hidden = true;
    };
    check.dataset.savedReady = check.disabled ? '' : '1';
    login.querySelectorAll('[data-login-navigation]').forEach((element) => { element.hidden = false; });
    login.querySelectorAll('[data-login-edit], [data-login-show-secret]').forEach((element) => { element.hidden = false; });
    buttons.forEach((button) => button.addEventListener('click', () => go(Number(button.dataset.loginGo))));
    login.querySelector('[data-login-previous]').addEventListener('click', () => go(step - 1));
    login.querySelector('[data-login-next]').addEventListener('click', () => go(step + 1));
    login.querySelectorAll('[data-login-edit]').forEach((button) => button.addEventListener('click', () => {
      go(fieldStep(button.dataset.loginEdit), false);
      document.getElementById(`api-${button.dataset.loginEdit}`).focus();
    }));
    inputs.forEach((input) => input.addEventListener('input', refresh));
    enabled.addEventListener('change', refresh);
    const applyGuide = () => {
      const template = document.getElementById(`cliniko-api-provider-${provider.value}`);
      const config = providerConfig();
      login.querySelector('[data-login-provider-guide]').replaceChildren(template.content.cloneNode(true));
      login.querySelectorAll('[data-login-provider-hint]').forEach((hint) => {
        hint.textContent = config.locations[hint.dataset.loginProviderHint] || '';
      });
      login.querySelectorAll('[data-login-direct-warning]').forEach((warning) => {
        warning.hidden = config.can_enable;
        warning.textContent = `${config.name} direct validation is not implemented here. These five fields are for compatible token-checking connections, not a way to configure this direct service. Use the service guide to collect information for your developer; keep existing connection values unchanged while planning that work.`;
      });
      document.getElementById('cliniko-api-login-request').value = config.request;
      login.querySelector('[data-login-copy-status]').textContent = '';
      login.querySelector('[data-login-build-endpoint]').hidden = !config.endpoint_hint;
      login.querySelector('[data-login-endpoint-status]').textContent = '';
      refresh();
    };
    provider.addEventListener('change', applyGuide);
    login.querySelector('[data-login-build-endpoint]').addEventListener('click', () => {
      const hint = providerConfig().endpoint_hint;
      const status = login.querySelector('[data-login-endpoint-status]');
      const issuer = document.getElementById('api-issuer').value.trim();
      const endpoint = document.getElementById('api-introspection_url');
      try {
        const url = new URL(issuer);
        if (!hint || !/^https:\/\//i.test(issuer) || url.protocol !== 'https:' || url.username || url.password || url.search || url.hash || !new RegExp(hint.pattern).test(url.pathname)) throw new Error('Not a provider issuer');
        const suggestion = issuer.replace(/\/+$/, '') + hint.suffix;
        if (endpoint.value.trim() && endpoint.value.trim() !== suggestion) {
          status.textContent = 'An address is already entered. It has been kept. Clear it yourself if you want to use the service-address suggestion instead.';
          return;
        }
        endpoint.value = suggestion;
        endpoint.dispatchEvent(new Event('input', { bubbles: true }));
        status.textContent = 'Suggested address filled in. Confirm it against your service’s OpenID configuration before saving. This has not contacted or tested the service.';
      } catch {
        status.textContent = 'Enter the full HTTPS issuer for the selected Keycloak realm or Okta custom authorization server first. Do not use a login-page address, root Okta org address, or a URL with query parameters.';
      }
    });
    let handlingInvalid = false;
    form.addEventListener('invalid', (event) => {
      if (!inputs.includes(event.target) || handlingInvalid) return;
      handlingInvalid = true;
      go(fieldStep(event.target.name), false);
      event.target.focus();
      // Keep the first invalid field visible when the browser validates several panels at once.
      setTimeout(() => { handlingInvalid = false; }, 0);
    }, true);
    form.addEventListener('submit', (event) => {
      if (!enabled.checked) return; // Saving an unfinished setup while sign-in is off is allowed.
      if (!providerConfig().can_enable) {
        event.preventDefault();
        error.textContent = `${providerConfig().name} needs a validation method this plugin does not implement. Leave patient sign-in off, or use a separately implemented and verified compatible adapter under Other compatible service / custom adapter.`;
        error.hidden = false;
        go(3);
        return;
      }
      const missing = required.filter((row, i) => !present(row, inputs[i]));
      if (missing.length === 0) return;
      event.preventDefault();
      error.textContent = `Before enabling patient sign-in, enter: ${missing.map((row) => row.dataset.loginLabel).join(', ')}. Or leave patient sign-in off to save your progress.`;
      error.hidden = false;
      go(3);
    });
    const reveal = login.querySelector('[data-login-show-secret]');
    reveal.addEventListener('click', () => {
      const showing = secret.type === 'password';
      secret.type = showing ? 'text' : 'password';
      reveal.textContent = showing ? 'Hide entered password' : 'Show entered password';
      reveal.setAttribute('aria-pressed', String(showing));
    });
    login.querySelector('[data-login-copy]').addEventListener('click', async () => {
      const request = document.getElementById('cliniko-api-login-request');
      const status = login.querySelector('[data-login-copy-status]');
      try {
        if (!navigator.clipboard) throw new Error('Clipboard unavailable');
        await navigator.clipboard.writeText(request.value);
        status.textContent = 'Checklist copied. Send it to your developer; it contains no saved passwords.';
      } catch {
        request.focus();
        request.select();
        status.textContent = 'Checklist selected. Use your device’s Copy command, or Ctrl+C / Command+C.';
      }
    });
    go(Number(login.dataset.loginStart) || 0, false);
    applyGuide();
  }
  const dialog = document.getElementById('cliniko-api-help-dialog');
  if (dialog) {
    const title = document.getElementById('cliniko-api-help-title');
    const body = document.getElementById('cliniko-api-help-body');
    const tutorial = document.getElementById('cliniko-api-help-tutorial');
    let opener = null;
    document.addEventListener('click', (event) => {
      const trigger = event.target.closest('[data-api-help]');
      if (!trigger) return;
      const template = document.getElementById(`cliniko-api-help-${trigger.dataset.apiHelp}`);
      if (!template) return;
      if (typeof dialog.showModal !== 'function') {
        window.location.assign(template.dataset.tutorialUrl);
        return;
      }
      opener = trigger;
      title.textContent = template.dataset.title;
      body.replaceChildren(template.content.cloneNode(true));
      body.scrollTop = 0;
      tutorial.href = template.dataset.tutorialUrl;
      dialog.showModal();
      document.body.classList.add('cliniko-api-modal-open');
      title.focus();
    });
    dialog.querySelectorAll('[data-api-help-close]').forEach((button) => {
      button.addEventListener('click', () => dialog.close());
    });
    // Native dialog supplies focus containment, background inertness and Escape dismissal.
    dialog.addEventListener('close', () => {
      document.body.classList.remove('cliniko-api-modal-open');
      if (opener && opener.isConnected) opener.focus();
      opener = null;
    });
    dialog.addEventListener('click', (event) => {
      if (event.target !== dialog) return;
      const bounds = dialog.getBoundingClientRect();
      if (event.clientX < bounds.left || event.clientX > bounds.right ||
          event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
    });
  }
  const search = document.getElementById('cliniko-api-search');
  if (!search) return;
  const accessFilter = document.getElementById('cliniko-api-access-filter');
  const updateDocumentation = () => {
    const query = search.value.trim().toLowerCase();
    const access = accessFilter ? accessFilter.value : 'all';
    let visible = 0;
    document.querySelectorAll('.cliniko-api-endpoint').forEach((endpoint) => {
      endpoint.hidden = !endpoint.dataset.search.includes(query) || (access !== 'all' && endpoint.dataset.access !== access);
      if (!endpoint.hidden) visible += 1;
    });
    document.querySelectorAll('[data-doc-category]').forEach((category) => {
      const count = category.querySelectorAll('.cliniko-api-endpoint:not([hidden])').length;
      category.hidden = count === 0;
      category.querySelector('.cliniko-api-category-count').textContent = `${count} ${count === 1 ? 'endpoint' : 'endpoints'}`;
      const jump = document.querySelector(`[data-doc-jump="${category.dataset.docCategory}"]`);
      if (jump) {
        jump.hidden = category.hidden;
        jump.querySelector('span').textContent = `(${count})`;
      }
    });
    const count = document.getElementById('cliniko-api-doc-count');
    if (count) count.textContent = `Showing ${visible} ${visible === 1 ? 'endpoint' : 'endpoints'}.`;
    const empty = document.getElementById('cliniko-api-doc-empty');
    if (empty) empty.hidden = visible !== 0;
  };
  search.addEventListener('input', updateDocumentation);
  if (accessFilter) accessFilter.addEventListener('change', updateDocumentation);
  updateDocumentation();
});
