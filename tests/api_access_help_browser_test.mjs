// Dependency-free real-browser checks against read-only PHP-rendered admin fixtures.
// Requires PHP, Node 22+ and Chrome/Chromium (or set BROWSER_PATH).
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const browserPath = [process.env.BROWSER_PATH,
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome',
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
].find((path) => path && existsSync(path));
assert.ok(browserPath, 'Install Chrome/Chromium or set BROWSER_PATH');
const fixtures = {};
for (const tab of ['getting-started', 'settings', 'patient-login', 'login-ready', 'wordpress', 'social', 'registration', 'sessions', 'clients', 'mappings', 'flows', 'booking-flows', 'booking-edit', 'tutorials', 'documentation']) {
  const result = spawnSync('php', ['tests/managed_api_test.php', `--render-admin=${tab}`], { cwd: root, encoding: 'utf8', windowsHide: true });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  fixtures[tab] = result.stdout;
}
const assets = join(root, 'src/Admin/Modules/ApiAccess/assets');
// Include local WordPress core styles when available, without booting WordPress.
const coreCss = ['wp-admin/css/common.css', 'wp-admin/css/forms.css', 'wp-includes/css/buttons.css', 'wp-includes/css/dashicons.css']
  .map((path) => resolve(root, '../../..', path)).filter(existsSync).map((path) => readFileSync(path, 'utf8')).join('\n');
const server = createServer((request, response) => {
  const path = new URL(request.url, 'http://localhost').pathname;
  if (path === '/api-access.js' || path === '/api-access.css' || path === '/admin-workspace.css') {
    response.setHeader('Content-Type', path.endsWith('.js') ? 'text/javascript' : 'text/css');
    response.end(readFileSync(path === '/admin-workspace.css' ? join(root, 'src/Admin/assets/admin-workspace.css') : join(assets, path.slice(1))));
    return;
  }
  response.setHeader('Content-Type', 'text/html; charset=utf-8');
  response.end(`<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>:root {--wp-admin-theme-color:#2271b1} body {font:13px/1.5 system-ui,sans-serif;background:#f0f0f1;margin:24px} ${coreCss}</style><link rel="stylesheet" href="/admin-workspace.css"><link rel="stylesheet" href="/api-access.css"><script src="/api-access.js" defer></script></head><body class="wp-core-ui"><main id="wpbody-content">${fixtures[path.slice(1)] || fixtures.settings}</main></body></html>`);
});
await new Promise((done) => server.listen(0, '127.0.0.1', done));
const origin = `http://127.0.0.1:${server.address().port}`;
const profile = mkdtempSync(join(tmpdir(), 'cliniko-api-help-browser-'));
const browser = spawn(browserPath, ['--headless', '--remote-debugging-port=0', `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', '--disable-background-networking'], { windowsHide: true });
let socket;
let send;
try {
  const address = await new Promise((done, fail) => {
    let output = '';
    const timer = setTimeout(() => fail(new Error('Browser did not expose its debugging endpoint')), 20000);
    browser.on('error', (error) => { clearTimeout(timer); fail(error); });
    browser.on('exit', (code) => { clearTimeout(timer); fail(new Error(`Browser exited early: ${code}`)); });
    browser.stderr.on('data', (chunk) => {
      output += chunk;
      const match = output.match(/DevTools listening on (ws:\/\/[^\s]+)/);
      if (match) { clearTimeout(timer); done(match[1]); }
    });
  });
  socket = new WebSocket(address);
  await new Promise((done, fail) => { socket.addEventListener('open', done, { once: true }); socket.addEventListener('error', fail, { once: true }); });
  let nextId = 0;
  const pending = new Map();
  const errors = [];
  socket.addEventListener('message', (event) => {
    const message = JSON.parse(event.data);
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text);
    const callback = pending.get(message.id);
    if (callback) { pending.delete(message.id); callback(message); }
  });
  send = (method, params = {}, sessionId) => new Promise((done, fail) => {
    const id = ++nextId;
    const timer = setTimeout(() => { pending.delete(id); fail(new Error(`Timed out: ${method}`)); }, 15000);
    pending.set(id, (message) => { clearTimeout(timer); message.error ? fail(new Error(JSON.stringify(message.error))) : done(message.result); });
    socket.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
  });
  const target = await send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await send('Target.attachToTarget', { targetId: target.targetId, flatten: true });
  const page = (method, params) => send(method, params, sessionId);
  await page('Runtime.enable');
  await page('Page.enable');
  const evaluate = async (expression) => {
    const result = await page('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    assert.ok(!result.exceptionDetails, JSON.stringify(result.exceptionDetails));
    return result.result.value;
  };
  const settled = () => evaluate('new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(() => resolve(true))))');
  const navigate = async (tab) => {
    await page('Page.navigate', { url: `${origin}/${tab}` });
    for (let i = 0; i < 100; i++) {
      if (await evaluate(`location.pathname === '/${tab}' && document.readyState === 'complete'`)) return;
      await new Promise((done) => setTimeout(done, 50));
    }
    throw new Error(`Failed to load ${tab}`);
  };
  await page('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
  await navigate('settings');
  assert.equal(await evaluate("getComputedStyle(document.querySelector('.cliniko-api-admin')).color"), 'rgb(75, 56, 76)', 'API text uses dark plum');
  assert.equal(await evaluate("getComputedStyle(document.querySelector('.cliniko-api-workspace')).backgroundColor"), 'rgb(240, 248, 251)', 'API workspace uses pale blue');
  assert.equal(await evaluate("getComputedStyle(document.querySelector('.cliniko-api-admin .nav-tab-active')).color"), 'rgb(204, 48, 87)', 'API selection uses pink');
  assert.equal(await evaluate("getComputedStyle(document.querySelector('.cliniko-api-admin .button-primary')).backgroundColor"), 'rgb(0, 127, 163)', 'API primary actions use teal');
  assert.equal(await evaluate("document.querySelector('[data-login-setup]') === null"), true, 'General settings must not show the patient-login wizard');
  assert.equal(await evaluate("new FormData(document.querySelector('[name=\"appointment_types[]\"]').form).has('oauth_enabled')"), false, 'General save must not submit login fields');
  assert.equal(await evaluate("Array.from(document.querySelectorAll('[name=\"appointment_types[]\"]:checked')).map(input => input.value).join(',')"), '123,999', 'Saved service selections must be checked, including missing services');
  assert.equal(await evaluate("document.querySelector('[name=\"appointment_types[]\"][value=\"124\"]').checked"), false, 'New service must remain unpublished');
  assert.equal(await evaluate("getComputedStyle(document.querySelector('.cliniko-api-service-choice')).display"), 'flex', 'WordPress styles must not turn service rows into inline labels');
  await evaluate("{ const search = document.querySelector('#cliniko-api-service-search'); search.value = 'follow'; search.dispatchEvent(new Event('input')); }");
  assert.equal(await evaluate("document.querySelector('#cliniko-api-service-search').closest('fieldset').querySelectorAll('[data-picker-search]:not([hidden])').length"), 1, 'Search must filter service names');
  assert.deepEqual(await evaluate("new FormData(document.querySelector('[name=\"appointment_types[]\"]').form).getAll('appointment_types[]')"), ['123', '999'], 'Filtering must not drop hidden checked services from submission');
  await evaluate("document.querySelector('[name=\"appointment_types[]\"][value=\"124\"]').click()");
  assert.deepEqual(await evaluate("new FormData(document.querySelector('[name=\"appointment_types[]\"]').form).getAll('appointment_types[]')"), ['123', '124', '999'], 'Checkbox choice must submit the Cliniko ID');
  await evaluate("{ const search = document.querySelector('#cliniko-api-service-search'); search.value = 'does-not-exist'; search.dispatchEvent(new Event('input')); }");
  assert.equal(await evaluate("document.querySelector('#cliniko-api-service-no-matches').hidden"), false, 'No search matches should show helpful feedback');
  await evaluate("{ const search = document.querySelector('#cliniko-api-service-search'); search.value = ''; search.dispatchEvent(new Event('input')); }");
  await evaluate("document.querySelector('.cliniko-api-services').scrollIntoView({ block: 'center' })");
  await settled();
  const pickerShot = await page('Page.captureScreenshot', { format: 'png' });
  writeFileSync(join(profile, 'api-service-picker.png'), Buffer.from(pickerShot.data, 'base64'));
  assert.deepEqual(await evaluate("new FormData(document.querySelector('[name=\"form_templates[]\"]').form).getAll('form_templates[]')"), ['456', '9998'], 'Saved and missing templates must stay checked');
  await evaluate("{ const search = document.querySelector('#cliniko-api-form-template-search'); search.value = 'follow'; search.dispatchEvent(new Event('input')); }");
  assert.equal(await evaluate("document.querySelector('#cliniko-api-form-template-search').closest('fieldset').querySelectorAll('[data-picker-search]:not([hidden])').length"), 1, 'Template name search must filter locally');
  assert.equal(await evaluate("document.querySelector('#cliniko-api-service-search').closest('fieldset').querySelectorAll('[data-picker-search]:not([hidden])').length"), 3, 'Template filter must not hide services');
  assert.deepEqual(await evaluate("new FormData(document.querySelector('[name=\"form_templates[]\"]').form).getAll('form_templates[]')"), ['456', '9998'], 'Hidden checked template must still submit');
  await evaluate("document.querySelector('[name=\"form_templates[]\"][value=\"457\"]').click()");
  assert.deepEqual(await evaluate("new FormData(document.querySelector('[name=\"form_templates[]\"]').form).getAll('form_templates[]').sort()"), ['456', '457', '9998'], 'Template checkbox must submit its Cliniko ID');
  await evaluate("{ const search = document.querySelector('#cliniko-api-form-template-search'); search.value = 'does-not-exist'; search.dispatchEvent(new Event('input')); }");
  assert.equal(await evaluate("document.querySelector('#cliniko-api-form-template-no-matches').hidden"), false, 'No matching templates must show feedback');
  await evaluate("{ const search = document.querySelector('#cliniko-api-form-template-search'); search.value = ''; search.dispatchEvent(new Event('input')); } document.querySelector('#cliniko-api-form-template-search').closest('fieldset').scrollIntoView({ block: 'center' });");
  await settled();
  writeFileSync(join(profile, 'api-template-picker.png'), Buffer.from((await page('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
  await navigate('patient-login');
  assert.equal(await evaluate("document.querySelector('.nav-tab-active').textContent"), 'Patient login', 'Wizard must have its own active tab');
  assert.equal(await evaluate("new FormData(document.querySelector('#cliniko-api-patient-login-form')).get('action')"), 'cliniko_api_patient_login');
  assert.equal(await evaluate("new FormData(document.querySelector('#cliniko-api-patient-login-form')).has('slug') || new FormData(document.querySelector('#cliniko-api-patient-login-form')).has('appointment_types[]')"), false, 'Login save must not submit route/publication settings');
  // Guided login setup never sends entered credentials to a check of saved settings.
  assert.equal(await evaluate("document.querySelectorAll('[data-login-panel]:not([hidden])').length"), 1);
  assert.equal(await evaluate("document.querySelector('[data-login-panel]:not([hidden])').id"), 'cliniko-api-login-step-0');
  assert.equal(await evaluate("document.querySelector('#api-oauth-provider').options.length"), 10, 'Service-specific setup choices missing');
  await evaluate("{ const select = document.querySelector('#api-oauth-provider'); select.value = 'keycloak'; select.dispatchEvent(new Event('change')); }");
  assert.equal(await evaluate("document.querySelector('[data-login-provider-guide]').textContent.includes('Client authentication off')"), true, 'Keycloak-specific registration instructions missing');
  assert.equal(await evaluate("document.querySelector('[data-login-provider-hint=\"introspection_secret\"]').textContent.includes('Credentials')"), true, 'Service-specific value location missing');
  assert.equal(await evaluate("document.querySelector('#cliniko-api-login-request').value.includes('Selected sign-in service: Keycloak')"), true);
  await evaluate("document.querySelector('[data-login-setup]').scrollIntoView({block:'start'})");
  await settled();
  writeFileSync(join(profile, 'api-provider-guide.png'), Buffer.from((await page('Page.captureScreenshot', {format:'png'})).data, 'base64'));
  await evaluate("{ document.querySelector('#api-issuer').value = 'https://login.example.test/realms/clinic'; document.querySelector('#api-issuer').dispatchEvent(new Event('input')); document.querySelector('[data-login-go=\"2\"]').click(); document.querySelector('[data-login-build-endpoint]').click(); }");
  assert.equal(await evaluate("document.querySelector('#api-introspection_url').value"), 'https://login.example.test/realms/clinic/protocol/openid-connect/token/introspect', 'Keycloak endpoint suggestion incorrect');
  assert.equal(await evaluate("document.querySelector('#api-introspection_client').value"), '', 'Service choice must not invent a credential ID');
  assert.equal(await evaluate("document.querySelector('#api-oauth-enabled').checked"), false, 'Choosing a provider must not turn login on');
  await evaluate("document.querySelector('#api-introspection_url').value = 'https://keep.example.test/check'; document.querySelector('[data-login-build-endpoint]').click()");
  assert.equal(await evaluate("document.querySelector('#api-introspection_url').value"), 'https://keep.example.test/check', 'Endpoint helper overwrote an existing address');
  await evaluate("{ const select = document.querySelector('#api-oauth-provider'); select.value = 'okta'; select.dispatchEvent(new Event('change')); document.querySelector('#api-issuer').value = 'https://login.example.test/oauth2/default'; document.querySelector('#api-introspection_url').value = ''; document.querySelector('[data-login-build-endpoint]').click(); }");
  assert.equal(await evaluate("document.querySelector('#api-introspection_url').value"), 'https://login.example.test/oauth2/default/v1/introspect', 'Okta custom-server endpoint suggestion incorrect');
  await evaluate("document.querySelector('#api-issuer').value = 'https://login.example.test'; document.querySelector('#api-introspection_url').value = ''; document.querySelector('[data-login-build-endpoint]').click()");
  assert.equal(await evaluate("document.querySelector('#api-introspection_url').value"), '', 'Root Okta org URL must not generate a custom-server endpoint');
  await evaluate("document.querySelector('#api-issuer').value = 'https://login.example.test/oauth2/default?bad=1'; document.querySelector('[data-login-build-endpoint]').click()");
  assert.equal(await evaluate("document.querySelector('#api-introspection_url').value"), '', 'Endpoint helper must reject query strings');
  await evaluate("{ const select = document.querySelector('#api-oauth-provider'); select.value = 'authentik'; select.dispatchEvent(new Event('change')); }");
  assert.equal(await evaluate("document.querySelector('[data-login-provider-guide]').textContent.includes('may lack token_type, client_id and scope')"), true, 'Default authentik response must not be advertised as plug-and-play');
  for (const id of ['google', 'firebase', 'cognito']) {
    await evaluate(`{ const select = document.querySelector('#api-oauth-provider'); select.value = '${id}'; select.dispatchEvent(new Event('change')); }`);
    assert.equal(await evaluate("document.querySelector('[data-login-provider-guide]').textContent.includes('Information to collect for your developer')"), true, 'Managed-service collection guide missing');
    assert.equal(await evaluate("document.querySelector('[data-login-provider-guide]').textContent.includes('not for these five connection fields')"), true, 'Planning-only information must not be presented as current connection values');
    assert.equal(await evaluate("document.querySelector('#cliniko-api-login-request').value.includes('integration-planning request')"), true, 'Direct service handoff must describe the missing integration');
    assert.equal(await evaluate("document.querySelector('[data-login-build-endpoint]').hidden"), true, 'Direct service must not suggest an introspection URL');
    assert.equal(await evaluate("document.querySelector('[data-login-direct-warning]').hidden"), false, 'Existing connection fields need a direct-integration warning');
  }
  await evaluate("{ const select = document.querySelector('#api-oauth-provider'); select.value = 'firebase'; select.dispatchEvent(new Event('change')); document.querySelector('[data-login-go=\"0\"]').click(); document.querySelector('[data-login-provider-guide]').scrollIntoView({block:'start'}); }");
  await settled();
  writeFileSync(join(profile, 'api-firebase-guide.png'), Buffer.from((await page('Page.captureScreenshot', {format:'png'})).data, 'base64'));
  await evaluate("{ const select = document.querySelector('#api-oauth-provider'); select.value = 'google_keycloak'; select.dispatchEvent(new Event('change')); }");
  assert.equal(await evaluate("document.querySelector('[data-login-provider-hint=\"issuer\"]').textContent.includes('not https://accounts.google.com')"), true, 'Google broker must direct staff to the Keycloak issuer');
  assert.equal(await evaluate("document.querySelector('[data-login-build-endpoint]').hidden"), false);
  assert.equal(await evaluate("document.querySelector('[data-login-direct-warning]').hidden"), true);
  await evaluate("{ const select = document.querySelector('#api-oauth-provider'); select.value = 'custom'; select.dispatchEvent(new Event('change')); document.querySelector('#api-issuer').value = ''; document.querySelector('#api-introspection_url').value = ''; document.querySelector('[data-login-go=\"0\"]').click(); }");
  await evaluate("window.loginSubmitBlocked = null; document.addEventListener('submit', event => { window.loginSubmitBlocked = event.defaultPrevented; event.preventDefault(); }); document.querySelector('[data-login-next]').click()");
  assert.equal(await evaluate('document.activeElement.id'), 'cliniko-api-login-heading-1', 'Step navigation must move focus to the new heading');
  await evaluate("{ const values = {issuer:'https://identity.example.test', audience:'patient-api'}; for (const [key, value] of Object.entries(values)) { const input = document.getElementById('api-' + key); input.value = value; input.dispatchEvent(new Event('input')); } document.querySelector('[data-login-next]').click(); }");
  await evaluate("{ const values = {introspection_url:'https://identity.example.test/introspect', introspection_client:'website-id', introspection_secret:'typed-setup-secret'}; for (const [key, value] of Object.entries(values)) { const input = document.getElementById('api-' + key); input.value = value; input.dispatchEvent(new Event('input')); } }");
  await evaluate("document.querySelector('[data-login-show-secret]').click()");
  assert.equal(await evaluate("document.querySelector('#api-introspection_secret').type"), 'text');
  await evaluate("document.querySelector('[data-login-show-secret]').click(); document.querySelector('[data-login-next]').click()");
  assert.equal(await evaluate("document.querySelector('#api-introspection_secret').type"), 'password');
  assert.equal(await evaluate("document.querySelectorAll('[data-login-required] .is-ready').length"), 5, 'Review must recognise all entered values');
  await evaluate("window.connectionValuesBeforeGuideChange = Array.from(document.querySelectorAll('[data-login-required]')).map(row => document.getElementById('api-' + row.dataset.loginRequired).value)");
  assert.equal(await evaluate("document.querySelector('[data-login-check]').disabled"), true, 'Unsaved settings must not enable the saved-connection check');
  await evaluate("{ const select = document.querySelector('#api-oauth-provider'); select.value = 'auth0'; select.dispatchEvent(new Event('change')); const enabled = document.querySelector('#api-oauth-enabled'); enabled.checked = true; enabled.dispatchEvent(new Event('change')); enabled.form.requestSubmit(); }");
  assert.equal(await evaluate('window.loginSubmitBlocked'), true, 'Unsupported provider must not be enabled by the wizard');
  assert.equal(await evaluate("document.querySelector('[data-login-error]').textContent.includes('does not implement')"), true);
  assert.deepEqual(await evaluate("Array.from(document.querySelectorAll('[data-login-required]')).map(row => document.getElementById('api-' + row.dataset.loginRequired).value)"), await evaluate('window.connectionValuesBeforeGuideChange'), 'Switching guides must not overwrite connection values/password');
  for (const id of ['google', 'firebase', 'cognito']) {
    await evaluate(`{ const select = document.querySelector('#api-oauth-provider'); select.value = '${id}'; select.dispatchEvent(new Event('change')); document.querySelector('#api-oauth-enabled').form.requestSubmit(); }`);
    assert.equal(await evaluate('window.loginSubmitBlocked'), true, 'Direct Google/managed services must remain drafts until validation is implemented');
  }
  await evaluate("{ const select = document.querySelector('#api-oauth-provider'); select.value = 'custom'; select.dispatchEvent(new Event('change')); const enabled = document.querySelector('#api-oauth-enabled'); enabled.checked = false; enabled.dispatchEvent(new Event('change')); }");
  assert.deepEqual(await evaluate("Array.from(new FormData(document.querySelector('#cliniko-api-provider-test-form')).keys()).sort()"), ['_wpnonce', 'action'], 'Check request must not submit the edited password');
  await evaluate("{ const input = document.querySelector('#api-issuer'); input.value = ''; input.dispatchEvent(new Event('input')); const enabled = document.querySelector('#api-oauth-enabled'); enabled.checked = true; enabled.dispatchEvent(new Event('change')); enabled.form.requestSubmit(); }");
  assert.equal(await evaluate('window.loginSubmitBlocked'), true, 'Incomplete enabled sign-in must be blocked');
  assert.equal(await evaluate("document.querySelector('[data-login-error]').hidden"), false);
  assert.equal(await evaluate("document.querySelector('[data-login-error]').textContent.includes('Sign-in service address')"), true);
  await evaluate("{ const enabled = document.querySelector('#api-oauth-enabled'); enabled.checked = false; enabled.dispatchEvent(new Event('change')); enabled.form.requestSubmit(); }");
  assert.equal(await evaluate('window.loginSubmitBlocked'), false, 'An unfinished setup may be saved with login off');
  await evaluate("{ for (const key of ['issuer', 'introspection_url']) { const input = document.getElementById('api-' + key); input.value = 'not-a-url'; input.dispatchEvent(new Event('input')); } document.querySelector('#api-oauth-enabled').form.requestSubmit(); }");
  assert.equal(await evaluate('document.activeElement.id'), 'api-issuer', 'First invalid URL must be visible and focused, even when another panel has an invalid URL');
  assert.equal(await evaluate("document.querySelector('[data-login-panel]:not([hidden])').id"), 'cliniko-api-login-step-1');
  await evaluate("{ for (const key of ['issuer', 'introspection_url']) { const input = document.getElementById('api-' + key); input.value = 'https://identity.example.test' + (key === 'introspection_url' ? '/introspect' : ''); input.dispatchEvent(new Event('input')); } }");
  await evaluate("document.querySelector('[data-login-go=\"0\"]').click(); Object.defineProperty(navigator, 'clipboard', {configurable:true, value:{writeText: async text => {window.copiedChecklist = text}}}); document.querySelector('[data-login-copy]').click()");
  await settled();
  assert.equal(await evaluate("window.copiedChecklist.includes('https://example.test/wp-json/cliniko-api/v1') && !window.copiedChecklist.includes('typed-setup-secret') && !window.copiedChecklist.includes('introspection-secret')"), true, 'Developer checklist must be useful and secret-free');
  await evaluate("document.querySelector('[data-login-go=\"3\"]').click(); document.querySelector('[data-login-setup]').scrollIntoView({block:'start'})");
  await settled();
  writeFileSync(join(profile, 'api-patient-login-setup.png'), Buffer.from((await page('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
  await evaluate("document.querySelector('[data-login-go=\"1\"]').click()");
  await evaluate("window.submissions = 0; document.addEventListener('submit', event => { window.submissions++; event.preventDefault(); }); document.querySelector('[data-api-help=audience]').click()");
  assert.equal(await evaluate("document.querySelector('dialog').matches(':modal')"), true, 'Help must open as a native modal');
  assert.equal(await evaluate("document.activeElement.id"), 'cliniko-api-help-title', 'Structured content receives initial focus');
  assert.equal(await evaluate("document.querySelector('#cliniko-api-help-title').textContent"), 'API name at the sign-in service');
  assert.equal(await evaluate("document.querySelectorAll('#cliniko-api-help-body > .cliniko-api-help-section').length"), 4);
  assert.equal(await evaluate("document.querySelector('#cliniko-api-help-body .cliniko-api-help-intro').textContent.includes('login passes meant for a different service')"), true, 'Staff help explains the purpose in plain language');
  assert.equal(await evaluate("document.querySelector('#cliniko-api-help-body .cliniko-api-developer-notes').open"), false, 'Technical reference starts closed');
  assert.equal(await evaluate("document.querySelector('#cliniko-api-help-tutorial').hash"), '#oauth');
  assert.equal(await evaluate('window.submissions'), 0, 'Help must not submit a settings form');
  for (let i = 0; i < 8; i++) {
    await page('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
    await page('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
    assert.equal(await evaluate("document.activeElement === document.body || document.querySelector('dialog').contains(document.activeElement)"), true, 'Tab must not focus background controls');
  }
  await page('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
  await page('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
  await settled();
  assert.equal(await evaluate("document.querySelector('dialog').open"), false, 'Escape must close help');
  assert.equal(await evaluate('document.activeElement.dataset.apiHelp'), 'audience', 'Focus must return to original trigger');
  assert.equal(await evaluate("document.body.classList.contains('cliniko-api-modal-open')"), false, 'Scroll lock must be released');
  await evaluate("document.querySelector('[data-login-go=\"2\"]').click(); document.querySelector('[data-api-help=introspection_client]').click()");
  assert.equal(await evaluate("document.querySelector('#cliniko-api-help-body').textContent.includes('The mobile app has a different ID')"), true, 'Modal must refresh content for each field');
  await evaluate("document.querySelector('[data-api-help-close]').click()");
  await settled();
  assert.equal(await evaluate('document.activeElement.dataset.apiHelp'), 'introspection_client');
  await evaluate("document.querySelector('[data-login-go=\"1\"]').click(); document.querySelector('[data-api-help=issuer]').click()");
  await page('Input.dispatchMouseEvent', { type: 'mousePressed', x: 2, y: 2, button: 'left', clickCount: 1 });
  await page('Input.dispatchMouseEvent', { type: 'mouseReleased', x: 2, y: 2, button: 'left', clickCount: 1 });
  await settled();
  assert.equal(await evaluate("document.querySelector('dialog').open"), false, 'Backdrop must dismiss help');
  await page('Emulation.setDeviceMetricsOverride', { width: 390, height: 680, deviceScaleFactor: 1, mobile: true });
  assert.equal(await evaluate("(() => { const panel = document.querySelector('[data-login-panel]:not([hidden])'); const r = panel.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth && panel.scrollWidth <= panel.clientWidth; })()"), true, 'Wizard fields must fit a narrow viewport');
  await evaluate("document.querySelector('[data-login-go=\"2\"]').click(); document.querySelector('[data-api-help=introspection_url]').click()");
  assert.equal(await evaluate("(() => { const r = document.querySelector('dialog').getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth && r.top >= 0 && r.bottom <= innerHeight; })()"), true, 'Dialog must fit narrow viewport');
  await page('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
  await settled();
  const shot = await page('Page.captureScreenshot', { format: 'png' });
  const screenshot = join(profile, 'api-help-modal.png');
  writeFileSync(screenshot, Buffer.from(shot.data, 'base64'));
  await navigate('login-ready');
  assert.equal(await evaluate("document.querySelector('[data-login-panel]:not([hidden])').id"), 'cliniko-api-login-step-4', 'Existing completed settings open at the saved connection check');
  assert.equal(await evaluate("document.querySelector('[data-login-saved-check]').textContent.includes('Saved connection check passed')"), true);
  assert.equal(await evaluate("document.querySelector('[data-login-check]').disabled"), false, 'An unchanged saved connection can be tested again');
  await evaluate("{ const select = document.querySelector('#api-oauth-provider'); select.value = 'keycloak'; select.dispatchEvent(new Event('change')); }");
  assert.equal(await evaluate("document.querySelector('[data-login-check]').disabled"), true, 'Changing the service guide must mark the saved check as out of date');
  await evaluate("{ const select = document.querySelector('#api-oauth-provider'); select.value = 'custom'; select.dispatchEvent(new Event('change')); }");
  assert.equal(await evaluate("document.querySelector('[data-login-check]').disabled"), false, 'Restoring the saved service restores the check');
  assert.equal(await evaluate("document.querySelector('#api-introspection_secret').value"), '', 'Saved password must never be filled into the form');
  await evaluate("{ const input = document.querySelector('#api-audience'); input.value = 'edited-api'; input.dispatchEvent(new Event('input')); }");
  assert.equal(await evaluate("document.querySelector('[data-login-check]').disabled"), true, 'Draft edit must disable checking the saved connection');
  assert.equal(await evaluate("document.querySelector('[data-login-unsaved]').hidden"), false);
  await evaluate("{ const input = document.querySelector('#api-audience'); input.value = 'cliniko-api'; input.dispatchEvent(new Event('input')); }");
  assert.equal(await evaluate("document.querySelector('[data-login-check]').disabled"), false, 'Restoring saved values restores the check');
  await navigate('clients');
  assert.equal(await evaluate("document.querySelectorAll('[data-api-help]').length > 5"), true);
  assert.equal(await evaluate("document.querySelector('[name=external_id]').disabled"), true, 'Native apps do not submit OAuth provider credentials');
  assert.equal(await evaluate("document.querySelector('[name=google_client_id]').disabled"), false);
  await evaluate("{ const select = document.querySelector('[data-api-client-form] [name=type]'); select.value = 'server'; select.dispatchEvent(new Event('change')); }");
  assert.equal(await evaluate("document.querySelector('[data-api-client-form] [name=\"scopes[]\"][value=\"patient:read\"]').disabled"), true, 'Server apps cannot request patient scope');
  for (const scope of ['booking:create', 'booking:read']) {
    assert.equal(await evaluate(`document.querySelector('[data-api-client-form] [name="scopes[]"][value="${scope}"]').disabled`), true, 'Server keys cannot request booking permissions');
  }
  await navigate('mappings');
  await evaluate("document.querySelector('[data-api-help=subject]').click()");
  assert.equal(await evaluate("document.querySelector('#cliniko-api-help-body').textContent.includes('Do not guess using an email address')"), true);
  await navigate('tutorials');
  assert.equal(await evaluate("document.querySelectorAll('.cliniko-api-tutorial-card').length"), 9);
  assert.equal(await evaluate("document.querySelector('#oauth').textContent.includes('Three identifiers')"), true);
  assert.equal(await evaluate("document.querySelectorAll('.cliniko-api-tutorial .cliniko-api-developer-notes[open]').length"), 0, 'Technical tutorials stay optional');
  assert.equal(await evaluate("Array.from(document.querySelectorAll('.cliniko-api-tutorial pre')).every(block => block.closest('.cliniko-api-developer-notes'))"), true, 'Code examples only appear in developer references');
  await navigate('documentation');
  assert.equal(await evaluate("document.querySelectorAll('[data-doc-category]').length"), 9, 'Documentation needs nine useful categories');
  assert.equal(await evaluate("document.querySelectorAll('.cliniko-api-endpoint summary .cliniko-api-access-badge').length"), 28, 'Access must be explicit before expanding an endpoint');
  assert.equal(await evaluate("Array.from(document.querySelectorAll('.cliniko-api-endpoint summary code')).some(route => route.textContent === '/booking-attempts')"), false, 'Guest creation must not appear in the catalog');
  assert.equal(await evaluate("Array.from(document.querySelectorAll('.cliniko-api-endpoint[data-access=attempt] summary')).every(summary => summary.textContent.includes('patient token + booking secret'))"), true, 'Attempt badges must explain both credentials');
  assert.equal(await evaluate("document.querySelector('#cliniko-api-category-booking h3').textContent.includes('Guest')"), false, 'Payment/progress category must not imply guest access');
  assert.equal(await evaluate("document.querySelector('#cliniko-api-category-booking_flows').textContent.includes('Structure-only booking contract')"), true, 'New structure routes need detailed endpoint documentation');
  assert.equal(await evaluate("Array.from(document.querySelectorAll('[data-doc-jump]')).every(link => document.querySelector(link.getAttribute('href')))"), true, 'Category shortcuts must target real headings');
  await evaluate("const s = document.querySelector('#cliniko-api-search'); s.value = '/me'; s.dispatchEvent(new Event('input')); ");
  assert.equal(await evaluate("document.querySelectorAll('.cliniko-api-endpoint:not([hidden])').length"), 4, 'Endpoint search must still work');
  assert.equal(await evaluate("document.querySelectorAll('[data-doc-category]:not([hidden])').length"), 2, 'Search hides empty categories');
  assert.equal(await evaluate("document.querySelector('#cliniko-api-doc-count').textContent"), 'Showing 4 endpoints.');
  await evaluate("{ const filter = document.querySelector('#cliniko-api-access-filter'); filter.value = 'public'; filter.dispatchEvent(new Event('change')); }");
  assert.equal(await evaluate("document.querySelector('#cliniko-api-doc-empty').hidden"), false, 'Search and access filter must combine');
  assert.equal(await evaluate("document.querySelectorAll('[data-doc-jump]:not([hidden])').length"), 0, 'Empty category shortcuts should disappear');
  await evaluate("s.value = ''; s.dispatchEvent(new Event('input'));");
  assert.equal(await evaluate("document.querySelectorAll('.cliniko-api-endpoint:not([hidden])').length"), 7, 'Only seven read-only endpoints require no credentials');
  assert.equal(await evaluate("document.querySelector('#cliniko-api-doc-empty').hidden"), true);
  const accessCounts = { patient: 7, attempt: 4, credentials: 1, social: 2, oauth: 1, refresh: 2, receipt: 2, registration: 1, app: 1 };
  for (const [kind, count] of Object.entries(accessCounts)) {
    await evaluate(`{ const filter = document.querySelector('#cliniko-api-access-filter'); filter.value = '${kind}'; filter.dispatchEvent(new Event('change')); }`);
    assert.equal(await evaluate("document.querySelectorAll('.cliniko-api-endpoint:not([hidden])').length"), count, `Incorrect ${kind} access filter`);
  }
  await evaluate("{ const filter = document.querySelector('#cliniko-api-access-filter'); filter.value = 'all'; filter.dispatchEvent(new Event('change')); s.value = 'registration & verification'; s.dispatchEvent(new Event('input')); }");
  assert.equal(await evaluate("document.querySelectorAll('.cliniko-api-endpoint:not([hidden])').length"), 5, 'Category names must be searchable');
  await evaluate("s.value = ''; s.dispatchEvent(new Event('input'));");
  assert.equal(await evaluate("document.querySelectorAll('.cliniko-api-endpoint:not([hidden])').length"), 28, 'Clearing filters must restore the complete catalog');
  writeFileSync(join(profile, 'api-endpoint-categories.png'), Buffer.from((await page('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
  await navigate('booking-flows');
  assert.equal(await evaluate("document.querySelector('.cliniko-api-flow-export').open"), false, 'Developer JSON preview stays compact');
  assert.equal(await evaluate("JSON.parse(document.querySelector('#cliniko-api-booking-flow-json').value).flows.length"), 2, 'Developer JSON lists both saved flows');
  await evaluate("Object.defineProperty(navigator, 'clipboard', {configurable:true, value:{writeText: async text => {window.copiedBookingFlows = text}}}); document.querySelector('#api-booking-name').value = 'UNSAVED_EDITOR_NAME'; document.querySelector('[data-copy-target=cliniko-api-booking-flow-json]').click()");
  await settled();
  assert.equal(await evaluate("window.copiedBookingFlows === document.querySelector('#cliniko-api-booking-flow-json').value"), true, 'Clipboard receives complete valid JSON, not escaped HTML');
  assert.equal(await evaluate("JSON.parse(window.copiedBookingFlows).flows[0].name"), 'API consultation', 'Unsaved editor values are not exported');
  assert.equal(await evaluate("JSON.parse(window.copiedBookingFlows).flows[0].form.sections[0].questions[0].name"), 'Symptoms', 'Mock bundle includes the real blank form questions');
  assert.equal(await evaluate("JSON.parse(window.copiedBookingFlows).flows[0].payment.amount === null && JSON.parse(window.copiedBookingFlows).flows[0].payment.required === null"), true, 'Unknown live payment is explicit');
  assert.equal(await evaluate("document.querySelector('#cliniko-api-booking-flow-copy-status').textContent.includes('JSON copied')"), true, 'Copy feedback appears next to the controls');
  await send('Browser.setDownloadBehavior', {behavior: 'allow', downloadPath: profile});
  await evaluate("document.querySelector('[data-download-json-target=cliniko-api-booking-flow-json]').click()");
  const bundleFile = join(profile, 'booking-flows-developer.json');
  for (let i = 0; i < 60 && !existsSync(bundleFile); i++) await new Promise(done => setTimeout(done, 50));
  assert.ok(existsSync(bundleFile), 'Download creates the JSON file');
  assert.equal(readFileSync(bundleFile, 'utf8'), await evaluate('window.copiedBookingFlows'), 'Downloaded file matches the clipboard JSON');
  await evaluate("Object.defineProperty(navigator, 'clipboard', {configurable:true, value:{writeText: async () => {throw new Error('Clipboard denied')}}}); document.querySelector('[data-copy-target=cliniko-api-booking-flow-json]').click()");
  await settled();
  assert.equal(await evaluate("document.querySelector('.cliniko-api-flow-export').open && document.activeElement.id === 'cliniko-api-booking-flow-json'"), true, 'Clipboard fallback opens and focuses the JSON preview');
  assert.equal(await evaluate("document.activeElement.selectionStart === 0 && document.activeElement.selectionEnd === document.activeElement.value.length"), true, 'Fallback selects the entire JSON for manual copy');
  assert.equal(await evaluate("document.querySelector('#cliniko-api-booking-flow-copy-status').textContent.includes('Ctrl+C')"), true, 'Fallback gives manual-copy instructions');
  await evaluate("document.querySelector('.cliniko-api-flow-export').open = false; document.querySelector('#api-booking-name').value = ''");
  assert.equal(await evaluate("document.querySelectorAll('[data-booking-flow-row]').length"), 2, 'API and legacy flows use compact rows');
  assert.equal(await evaluate("Array.from(document.querySelectorAll('[data-booking-flow-toggle]')).every(button => button.getAttribute('aria-expanded') === 'false')"), true, 'Flow accordions start collapsed');
  assert.equal(await evaluate("document.querySelector('.cliniko-api-flow-intro').open"), false, 'Getting started stays compact');
  assert.equal(await evaluate("Array.from(document.querySelectorAll('.cliniko-api-booking-flow-actions')).every(group => group.querySelectorAll('a, button').length === 3)"), true, 'Three actions available for each row');
  assert.equal(await evaluate("Array.from(document.querySelectorAll('.cliniko-api-booking-flow-actions')).every(group => { const items = [...group.querySelectorAll('a, button')].map(item => item.getBoundingClientRect()); return items.every(item => Math.abs(item.top - items[0].top) < 3); })"), true, 'Desktop actions sit side by side');
  await evaluate("document.querySelector('[data-booking-flow-toggle]').click()");
  assert.equal(await evaluate("document.querySelector('[data-booking-flow-toggle]').getAttribute('aria-expanded')"), 'true', 'Flow toggle exposes expanded state');
  assert.equal(await evaluate("document.querySelector('.cliniko-api-booking-flow-body').hidden"), false, 'Dropdown opens flow details');
  await evaluate("document.querySelector('[data-flow-appointment] > summary').click(); document.querySelector('[data-flow-template] > summary').click(); document.querySelector('.cliniko-api-flow-section > summary').click()");
  assert.equal(await evaluate("document.querySelector('[data-flow-appointment]').open && document.querySelector('[data-flow-template]').open && document.querySelector('.cliniko-api-flow-section').open"), true, 'Resource and section sub-accordions open independently');
  assert.equal(await evaluate("document.querySelector('[data-flow-appointment]').textContent.includes('30 minutes') && document.querySelector('[data-flow-template]').textContent.includes('Symptoms')"), true, 'Details include service duration and actual blank form questions');
  await evaluate("document.querySelector('.cliniko-api-booking-flow-body').scrollIntoView({block:'start'})");
  await settled();
  writeFileSync(join(profile, 'api-booking-flow-details.png'), Buffer.from((await page('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
  await evaluate("document.querySelector('[data-booking-flow-toggle]').focus()");
  assert.equal(await evaluate("document.activeElement.matches('[data-booking-flow-toggle]')"), true, 'Flow dropdown is keyboard focusable');
  await page('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', text: '\r', windowsVirtualKeyCode: 13 });
  await page('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
  assert.equal(await evaluate("document.querySelector('[data-booking-flow-toggle]').getAttribute('aria-expanded')"), 'false', 'Keyboard closes flow accordion');
  await evaluate("window.confirm = () => false; document.querySelector('.cliniko-api-flow-remove').click()");
  assert.equal(await evaluate("document.querySelector('[data-booking-flow-toggle]').getAttribute('aria-expanded')"), 'false', 'Action clicks do not open accordion');
  writeFileSync(join(profile, 'api-booking-flow-list.png'), Buffer.from((await page('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
  assert.equal(await evaluate("document.querySelector('[name=configuration_id]') === null && document.querySelector('[data-api-booking-flow] [name=flow_slug]') !== null"), true, 'API creation must not require a web setup');
  assert.equal(await evaluate("document.querySelector('[data-api-booking-flow] [name=flow_enabled]').checked"), false, 'New structures are not automatically published');
  assert.equal(await evaluate("document.querySelector('#api-booking-appointment_type_id').textContent.includes('Consultation') && document.querySelector('#api-booking-patient_form_template_id').textContent.includes('Intake')"), true, 'Staff choose named resources, not arbitrary IDs');
  assert.equal(await evaluate("document.querySelector('[name=operation][value=make-api-only]') !== null"), true, 'Legacy detach action available');
  assert.equal(await evaluate("document.querySelector('[data-api-booking-flow] [name=_wpnonce]') !== null"), true, 'API flow form retains CSRF protection');
  await evaluate("document.querySelector('[data-api-help=api-booking-enable]').click()");
  assert.equal(await evaluate("document.querySelector('#cliniko-api-help-body').textContent.includes('never permits guest bookings')"), true, 'Flow help must explain patient authentication');
  await evaluate("document.querySelector('[data-api-help-close]').click()");
  await navigate('booking-edit');
  assert.equal(await evaluate("document.querySelector('[data-api-booking-flow] [name=flow_slug]').value"), 'api-consultation', 'API-only edit retains the stable identifier');
  assert.equal(await evaluate("document.querySelector('[data-api-booking-flow]').textContent.includes('Symptoms')"), true, 'Actual form questions are editable');
  assert.equal(await evaluate("document.querySelector('[name=rules_template_id]').value"), '456', 'Answer checks are bound to the chosen form');
  assert.equal(await evaluate("document.querySelectorAll('[name=multistep], [name=show_calendar], [name=show_patient_details_review], [name=success_message], [name=failure_message], [name=custom_css], [name=custom_js]').length"), 0, 'API editor has no web-only fields');
  await evaluate("document.querySelector('input[name=\"input_rules[question_0_0][max_length]\"]').value = 12");
  assert.equal(await evaluate("new FormData(document.querySelector('[data-api-booking-flow]')).get('input_rules[question_0_0][max_length]')"), '12', 'Answer rule submits its own value');
  await evaluate("document.querySelector('#api-booking-flow-editor').scrollIntoView({block:'start'}); document.querySelector('[data-api-booking-flow] .cliniko-api-developer-notes').open = true");
  await settled();
  writeFileSync(join(profile, 'api-booking-editor.png'), Buffer.from((await page('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
  await navigate('wordpress');
  assert.equal(await evaluate("document.querySelector('[name=password_login_enabled]').checked"), false, 'Native login remains opt-in');
  assert.equal(await evaluate("new FormData(document.querySelector('.cliniko-api-settings-card')).get('action')"), 'cliniko_api_app_login');
  assert.equal(await evaluate("new FormData(document.querySelector('.cliniko-api-settings-card')).has('oauth_enabled')"), false, 'Native save does not overwrite OAuth');
  writeFileSync(join(profile, 'api-wordpress-login.png'), Buffer.from((await page('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
  await navigate('social');
  assert.equal(await evaluate("document.querySelector('[name=google_login_enabled]').checked || document.querySelector('[name=apple_login_enabled]').checked"), false, 'Social login remains opt-in');
  await navigate('sessions');
  assert.equal(await evaluate("document.querySelector('[name=access_minutes]').value"), '15');
  await navigate('registration');
  assert.equal(await evaluate("document.querySelector('[name=registration_password_enabled]').checked || document.querySelector('[name=registration_social_enabled]').checked || document.querySelector('[name=registration_oauth_enabled]').checked"), false, 'Registration remains opt-in');
  assert.equal(await evaluate("new FormData(document.querySelector('.cliniko-api-settings-card')).get('section')"), 'registration');
  assert.equal(await evaluate("document.querySelector('[name=registration_role] option[value=patient]') !== null"), true, 'Safe configured patient role missing');
  assert.equal(await evaluate("new FormData(document.querySelector('.cliniko-api-settings-card')).has('password_login_enabled')"), false, 'Signup settings overwrite login settings');
  await navigate('flows');
  assert.equal(await evaluate("document.querySelectorAll('[data-flow-panel]:not([hidden])').length"), 1, 'Only selected walkthrough is shown');
  await evaluate("document.querySelector('[data-flow-button=social]').click()");
  assert.equal(await evaluate("document.querySelector('[data-flow-panel]:not([hidden])').dataset.flowPanel"), 'social');
  assert.equal(await evaluate("document.querySelector('[data-flow-panel=social]').textContent.includes('/auth/challenge')"), true);
  await page('Emulation.setDeviceMetricsOverride', { width: 390, height: 800, deviceScaleFactor: 1, mobile: true });
  for (const tab of ['getting-started', 'wordpress', 'social', 'registration', 'sessions', 'clients', 'mappings', 'flows', 'booking-flows', 'booking-edit', 'documentation']) {
    await navigate(tab);
    if (tab === 'booking-flows') {
      await evaluate("document.querySelector('[data-booking-flow-toggle]').click(); document.querySelector('[data-flow-appointment] > summary').click(); document.querySelector('[data-flow-template] > summary').click(); document.querySelector('.cliniko-api-flow-section > summary').click()");
      assert.equal(await evaluate("Array.from(document.querySelectorAll('.cliniko-api-booking-flow-actions')).every(group => { const items = [...group.querySelectorAll('a, button')].map(item => item.getBoundingClientRect()); return items.every(item => Math.abs(item.top - items[0].top) < 3); })"), true, 'Mobile actions remain side by side');
      await evaluate("document.querySelector('.cliniko-api-booking-flow-body').scrollIntoView({block:'start'})");
      await settled();
      writeFileSync(join(profile, 'api-booking-flow-mobile.png'), Buffer.from((await page('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
      await evaluate("document.querySelector('.cliniko-api-flow-export').open = true");
      assert.equal(await evaluate("document.querySelector('#cliniko-api-booking-flow-json').getBoundingClientRect().right <= innerWidth"), true, 'JSON preview fits mobile width');
    }
    if (tab === 'booking-edit') {
      await evaluate("document.querySelector('[data-api-booking-flow] .cliniko-api-developer-notes').open = true");
      assert.equal(await evaluate("Array.from(document.querySelectorAll('[data-api-booking-flow] input.regular-text, [data-api-booking-flow] select, [data-api-booking-flow] textarea')).filter(input => input.getBoundingClientRect().width > 0).every(input => input.getBoundingClientRect().right <= innerWidth)"), true, 'Opened answer-check controls must not be clipped on mobile');
    }
    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, `No horizontal page overflow on ${tab}`);
  }
  await page('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1100, deviceScaleFactor: 1, mobile: false });
  await navigate('getting-started');
  assert.equal(await evaluate("getComputedStyle(document.querySelector('.cliniko-api-workspace')).display"), 'grid');
  assert.equal(await evaluate("document.querySelectorAll('.cliniko-api-onboarding article').length"), 4);
  writeFileSync(join(profile, 'api-workspace-overview.png'), Buffer.from((await page('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
  assert.deepEqual(errors, [], 'No runtime JavaScript errors');
  console.log('[PASS] Guided patient login, validation, secret-free checklist, independent pickers, modals, keyboard, responsive layout and endpoint search');
  console.log(`Visual review screenshot: ${screenshot}`);
} finally {
  if (send && socket?.readyState === WebSocket.OPEN) {
    await send('Browser.close').catch(() => {});
    socket.close();
  }
  if (browser.exitCode === null) browser.kill();
  await new Promise((done) => server.close(done));
}
