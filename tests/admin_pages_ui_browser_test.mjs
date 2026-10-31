// Real WordPress settings/operations UI; forms and external services remain read-only.
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const wpRoot = resolve(process.argv[2] || join(root, '../../..'));
const browserPath = [process.env.BROWSER_PATH, 'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', '/usr/bin/chromium', '/usr/bin/google-chrome'].find(path => path && existsSync(path));
assert.ok(browserPath, 'Chrome/Chromium is required');
const names = ['settings', 'patient-settings', 'tools', 'debug', 'guide'];
const fixtures = {}, assets = new Map();
const addAsset = path => {
  const id = '/assets/' + assets.size;
  assets.set(id, path);
  return id;
};
const escapeScript = text => text.replace(/<\/script/gi, '<\\/script');
for (const name of names) {
  const result = spawnSync('php', ['tests/template_builder_ui_fixture.php', wpRoot, name], { cwd: root, encoding: 'utf8', windowsHide: true, maxBuffer: 5 * 1024 * 1024 });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  const data = JSON.parse(result.stdout);
  const styles = data.styles.map(item => (item.path ? '<link rel="stylesheet" href="' + addAsset(item.path) + '">' : '') + item.after.map(css => '<style>' + css + '</style>').join('')).join('');
  const scripts = data.scripts.map(item => (item.data ? '<script>' + escapeScript(item.data) + '</script>' : '') + item.before.map(js => '<script>' + escapeScript(js) + '</script>').join('') + (item.path ? '<script src="' + addAsset(item.path) + '"></script>' : '') + item.after.map(js => '<script>' + escapeScript(js) + '</script>').join('')).join('');
  // The fixture has an isolated origin: retain settings query strings locally
  // so the real history.replaceState handler is exercised without live navigation.
  const localTabLinks = '<script>document.querySelectorAll("[data-es-settings-tab]").forEach(link => { const url = new URL(link.href); link.href = location.pathname + url.search; });</script>';
  fixtures[name] = { html: data.html + localTabLinks, styles, scripts, query: data.query };
}
const coreCss = ['wp-admin/css/common.css', 'wp-admin/css/forms.css', 'wp-includes/css/buttons.css', 'wp-includes/css/dashicons.css'].map(path => join(wpRoot, path)).filter(existsSync).map(path => readFileSync(path, 'utf8')).join('\n');
const server = createServer((request, response) => {
  const path = new URL(request.url, 'http://localhost').pathname;
  if (assets.has(path)) {
    const file = assets.get(path);
    response.setHeader('Content-Type', file.endsWith('.js') ? 'text/javascript' : 'text/css');
    response.end(readFileSync(file));
    return;
  }
  const data = fixtures[path.slice(1)];
  if (!data) { response.statusCode = 404; response.end('No live service in UI fixtures'); return; }
  response.setHeader('Content-Type', 'text/html; charset=utf-8');
  response.end('<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>body {font:13px/1.5 system-ui,sans-serif;background:#f0f0f1;margin:24px}' + coreCss + '#wpbody-content {margin-left:160px;width:calc(100% - 160px)} @media(max-width:782px){#wpbody-content {margin-left:0;width:100%}}</style>' + data.styles + '</head><body class="wp-core-ui"><main id="wpbody-content">' + data.html + '</main>' + data.scripts + '<script>document.addEventListener("submit", event => event.preventDefault(), true);</script></body></html>');
});
await new Promise(done => server.listen(0, '127.0.0.1', done));
const origin = 'http://127.0.0.1:' + server.address().port;
const profile = mkdtempSync(join(tmpdir(), 'cliniko-admin-ui-'));
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

  await page('Network.enable');
  await page('Network.setBlockedURLs', { urls: ['http://localhost/easyscripts/*', 'https://*'] });
  await page('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
  for (const width of [1440, 1200, 980, 390]) {
    await page('Emulation.setDeviceMetricsOverride', { width, height: width < 500 ? 800 : 1000, deviceScaleFactor: 1, mobile: width < 500 });
    for (const name of names) {
      await navigate(name);
      assert.equal(await evaluate("document.querySelectorAll('.cliniko-admin-shell').length"), 1, name + ': one workspace');
      assert.equal(await evaluate("document.querySelectorAll('.cliniko-admin-content .cliniko-admin-page').length"), 1, name + ': content stays inside the workspace');
      assert.equal(await evaluate("document.querySelectorAll('.cliniko-admin-nav [aria-current=page]').length"), 1, name + ': one active navigation item');
      assert.equal(await evaluate("document.querySelectorAll('h1').length"), 1, name + ': one page heading');
      assert.equal(await evaluate("getComputedStyle(document.querySelector('.cliniko-admin-shell')).display"), width <= 782 ? 'block' : 'grid', name + ': responsive workspace');
      assert.equal(await evaluate("getComputedStyle(document.querySelector('.cliniko-admin-nav .is-active')).backgroundColor"), 'rgb(240, 248, 251)', name + ': consistent navigation state');
      assert.equal(await evaluate("document.querySelectorAll('.cliniko-admin-nav a').length"), 6, name + ': all plugin workspaces available');
      if (width <= 782) assert.equal(await evaluate("document.querySelector('.cliniko-admin-nav [aria-current=page]').getBoundingClientRect().right <= innerWidth"), true, name + ': selected mobile tab visible');
      assert.equal(await evaluate("getComputedStyle(document.querySelector('.cliniko-admin-ui')).color"), 'rgb(75, 56, 76)', name + ': dark plum text');
      assert.equal(await evaluate("getComputedStyle(document.querySelector('.cliniko-admin-nav .is-active')).color"), 'rgb(204, 48, 87)', name + ': pink selected navigation');
      const overflow = await evaluate("({width: innerWidth, scroll: document.documentElement.scrollWidth, offenders: Array.from(document.querySelectorAll('body *')).filter(el => el.getBoundingClientRect().right > innerWidth + 1 && getComputedStyle(el).position !== 'fixed').slice(0, 8).map(el => el.className || el.tagName)})");
      assert.ok(overflow.scroll <= overflow.width, name + ' at ' + width + ': overflow ' + JSON.stringify(overflow));
      assert.equal(await evaluate("Array.from(document.querySelectorAll('form[method=post]')).every(form => !!form.querySelector('[name=_wpnonce]'))"), true, name + ': original nonces retained');

      if (name === 'settings' || name === 'patient-settings') {
        assert.equal(await evaluate("document.querySelector('.cliniko-settings-form').getAttribute('action')"), 'options.php');
        assert.equal(await evaluate("document.querySelector('[name=option_page]').value"), 'wp_cliniko_stripe_group');
        const initial = name === 'settings' ? 'credentials' : 'patient-accounts';
        assert.equal(await evaluate("document.querySelector('.es-settings-tabs [aria-current=page]').dataset.esSettingsTab"), initial, 'Initial settings tab retained');
        assert.equal(await evaluate("Array.from(document.querySelectorAll('input[type=password]')).every(input => input.value === '')"), true, 'Secrets masked in fixtures');
        assert.equal(await evaluate("!!document.querySelector('#verify_cliniko_key') && !!document.querySelector('#verify_tyrohealth')"), true, 'Original connection controls preserved');
        await evaluate("document.querySelector('[name=wp_cliniko_app_name]').value = 'unsaved-fixture-clinic'");
        await evaluate("document.querySelector('[data-es-settings-tab=patient-accounts]').click()");
        assert.equal(await evaluate("new URL(location.href).searchParams.get('tab')"), 'patient-accounts', 'Tab URL updates');
        assert.equal(await evaluate("document.querySelector('.es-settings-tabs [aria-current=page]').dataset.esSettingsTab"), 'patient-accounts');
        assert.equal(await evaluate("Array.from(document.querySelectorAll('.form-table')).filter(table => table.querySelector('[name^=wp_cliniko_patient_]')).every(table => !table.classList.contains('es-settings-tab-hidden'))"), true, 'Patient settings visible');
        assert.equal(await evaluate("Array.from(document.querySelectorAll('.form-table')).filter(table => table.querySelector('[name=wp_cliniko_api_key]')).every(table => table.classList.contains('es-settings-tab-hidden'))"), true, 'Credentials hidden on patient tab');
        await evaluate("document.querySelector('[data-es-settings-tab=credentials]').click()");
        assert.equal(await evaluate("document.querySelector('[name=wp_cliniko_app_name]').value"), 'unsaved-fixture-clinic', 'Unsaved values retained when switching tabs');
        assert.equal(await evaluate("getComputedStyle(document.querySelector('.cliniko-settings-form .button-primary')).backgroundColor"), 'rgb(0, 127, 163)');
        if (initial === 'patient-accounts') await evaluate("document.querySelector('[data-es-settings-tab=patient-accounts]').click()");
      }
      if (name === 'tools') {
        assert.deepEqual(await evaluate("Array.from(document.querySelectorAll('form [name=action]')).map(input => input.value)"), ['wp_cliniko_clear_cache', 'wp_cliniko_test_connectivity', 'wp_cliniko_trigger_sync']);
        assert.equal(await evaluate("document.querySelectorAll('.cliniko-system-info tbody tr').length"), 11, 'System information retained');
      }
      if (name === 'debug') {
        assert.equal(await evaluate("document.querySelectorAll('.cliniko-debug-metric').length"), 4);
        assert.equal(await evaluate("!!document.querySelector('[name=action][value=wp_cliniko_debug_export_logs]') && !!document.querySelector('[name=action][value=wp_cliniko_debug_clear_logs]')"), true, 'Original export and clear actions retained');
        assert.equal(await evaluate("document.querySelector('.cliniko-debug-filters [name=page]').value"), 'wp-cliniko-debug');
        assert.equal(await evaluate("document.querySelector('.cliniko-debug-table').textContent.includes('Synthetic test data only')"), true, 'Real log renderer with synthetic data');
        await evaluate("document.querySelector('.cliniko-debug-table details').open = true");
        assert.equal(await evaluate("document.querySelector('.cliniko-debug-table pre').textContent.includes('fixture')"), true);
        if (width < 500) assert.equal(await evaluate("document.querySelector('.cliniko-admin-table-scroll').scrollWidth > document.querySelector('.cliniko-admin-table-scroll').clientWidth"), true, 'Wide log table scrolls within card');
      }
      if (name === 'guide') {
        assert.equal(await evaluate("document.querySelectorAll('[data-cliniko-docs-section]').length >= 15"), true, 'All guide sections retained');
        assert.equal(await evaluate("getComputedStyle(document.querySelector('.cliniko-docs-hero')).backgroundImage"), 'none', 'Guide uses neutral shared cards');
        await evaluate("document.querySelector('[data-cliniko-docs-search]').value = 'no_such_topic_ui_fixture'; document.querySelector('[data-cliniko-docs-search]').dispatchEvent(new Event('input'))");
        assert.equal(await evaluate("document.querySelector('[data-cliniko-docs-empty]').hidden"), false, 'Search empty state works');
        await evaluate("document.querySelector('[data-cliniko-docs-search]').value = 'social'; document.querySelector('[data-cliniko-docs-search]').dispatchEvent(new Event('input'))");
        assert.equal(await evaluate("document.querySelector('[data-cliniko-docs-empty]').hidden"), true, 'Matching search restores guide');
        await evaluate("document.querySelector('[data-cliniko-docs-search]').value = ''; document.querySelector('[data-cliniko-docs-search]').dispatchEvent(new Event('input'))");
        await evaluate("document.querySelector('.cliniko-docs-nav a[href=\"#requirements\"]').click()");
        assert.equal(await evaluate("document.querySelector('.cliniko-docs-nav a[href=\"#requirements\"]').getAttribute('aria-current')"), 'location', 'Guide anchor navigation works');
        await evaluate("window.scrollTo({top: 0, left: 0, behavior: 'instant'})");
      }
      await settled();
      if (width === 1440 || width === 390) writeFileSync(join(profile, name + '-' + width + '.png'), Buffer.from((await page('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
    }
  }
  assert.deepEqual(errors, [], 'No runtime JavaScript errors across plugin screens');
  console.log('[PASS] Settings, Patient Accounts, Tools, Debug and Getting Started at 1440/1200/980/390px; forms, nonces, navigation, guide search and log layout retained');
  console.log('Screenshots: ' + profile);
} finally {
  if (send && socket?.readyState === WebSocket.OPEN) {
    await send('Browser.close').catch(() => {});
    socket.close();
  }
  if (browser.exitCode === null) browser.kill();
  await new Promise((done) => server.close(done));
}
