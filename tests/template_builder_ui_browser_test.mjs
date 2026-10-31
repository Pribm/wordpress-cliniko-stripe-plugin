// Real WordPress rendering; isolated browser fixtures never submit forms or contact live services.
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
const names = ['forms', 'profile-editor', 'onboarding', 'onboarding-editor', 'bookings', 'booking-editor', 'dynamic-booking', 'aliases', 'dashboard', 'dashboard-editor', 'attachments', 'communications', 'components', 'shortcodes', 'redirects', 'emails', 'code-editor', 'migration'];
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
  fixtures[name] = { html: data.html, styles, scripts, query: data.query };
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
  response.end('<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>body {font:13px/1.5 system-ui,sans-serif;background:#f0f0f1;margin:24px}' + coreCss + '</style>' + data.styles + '</head><body class="wp-core-ui cliniko-template-builder-screen"><main id="wpbody-content">' + data.html + '</main>' + data.scripts + '<script>document.addEventListener("submit", event => event.preventDefault(), true);</script></body></html>');
});
await new Promise(done => server.listen(0, '127.0.0.1', done));
const origin = 'http://127.0.0.1:' + server.address().port;
const profile = mkdtempSync(join(tmpdir(), 'cliniko-template-ui-'));
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
  for (const name of names) {
    await navigate(name);
    assert.equal(await evaluate("document.querySelectorAll('.cliniko-admin-shell').length"), 1, name + ': one workspace');
    assert.equal(await evaluate("document.querySelectorAll('.cliniko-admin-content .wrap').length > 0"), true, name + ': content stays inside the workspace');
    assert.equal(await evaluate("document.querySelectorAll('.cliniko-admin-nav [aria-current=page]').length"), 1, name + ': one accessible active navigation item');
    assert.equal(await evaluate("getComputedStyle(document.querySelector('.cliniko-admin-shell')).display"), 'grid', name + ': desktop sidebar layout');
    assert.equal(await evaluate("getComputedStyle(document.querySelector('.cliniko-admin-ui')).getPropertyValue('--cliniko-admin-accent').trim()"), '#007fa3', name + ': shared API accent');
    assert.equal(await evaluate("getComputedStyle(document.querySelector('.cliniko-admin-nav .is-active')).backgroundColor"), 'rgb(240, 248, 251)', name + ': consistent active navigation');
    assert.equal(await evaluate("document.querySelector('.cliniko-admin-nav [aria-current=page]').href.includes('builder_tab=')"), true, name + ': original routing preserved');
    assert.equal(await evaluate("document.documentElement.scrollWidth <= innerWidth"), true, name + ': no desktop page overflow');
    if (['profile-editor', 'booking-editor', 'onboarding-editor', 'forms'].includes(name)) writeFileSync(join(profile, name + '-desktop.png'), Buffer.from((await page('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
  }
  await navigate('profile-editor');
  assert.equal(await evaluate("document.querySelector('[name=action]').value"), 'wp_cliniko_patient_account_form_save', 'Original save action retained');
  assert.equal(await evaluate("document.querySelector('[name=_wpnonce]') !== null"), true, 'Nonce retained');
  await evaluate("document.querySelector('[data-account-builder-tab=fields]').click()");
  assert.equal(await evaluate("document.querySelector('[data-account-builder-tab-panel=fields]').hidden"), false, 'Field editor tab works');
  await evaluate("document.querySelector('[data-preview]').click()");
  assert.equal(await evaluate("document.querySelector('[data-preview-modal]').hidden"), false, 'Existing desktop preview opens');
  assert.equal(await evaluate("document.querySelector('[data-preview-content] .cliniko-form-builder__preview-form') !== null"), true, 'Preview still renders the patient form');
  await evaluate("document.querySelector('[data-close-preview]').click()");
  assert.equal(await evaluate("document.querySelector('[data-preview-modal]').hidden"), true, 'Preview closes');
  await evaluate("document.querySelector('[data-mobile-preview]').click()");
  assert.equal(await evaluate("document.querySelector('[data-preview-content] .is-mobile') !== null"), true, 'Existing mobile preview still works');
  await evaluate("document.querySelector('[data-close-preview]').click()");
  await evaluate("document.querySelector('[data-account-builder-tab=setup]').click()");
  assert.equal(await evaluate("document.querySelector('[data-account-builder-tab-panel=setup]').hidden"), false, 'Setup tab works');
  await evaluate("document.querySelector('[data-account-builder-tab=submission]').click()");
  assert.equal(await evaluate("document.querySelector('[data-account-builder-tab-panel=submission]').hidden"), false, 'Submission tab works');
  assert.equal(await evaluate("getComputedStyle(document.querySelector('.cliniko-admin-content .button-primary')).backgroundColor"), 'rgb(0, 127, 163)', 'Publish button matches API Access');
  await navigate('onboarding-editor');
  assert.equal(await evaluate("document.querySelector('[name=action]').value"), 'wp_cliniko_patient_onboarding_save', 'Onboarding save unchanged');
  await navigate('booking-editor');
  assert.equal(await evaluate("document.querySelector('[name=action]').value"), 'wp_cliniko_patient_booking_form_save', 'Booking save unchanged');
  await navigate('forms');
  await evaluate("const notice = document.createElement('div'); notice.className = 'um-admin-notice'; document.querySelector('.cliniko-template-builder-page').append(notice); document.dispatchEvent(new Event('DOMContentLoaded'));");
  assert.equal(await evaluate("document.querySelector('.cliniko-template-builder-notice').nextElementSibling.classList.contains('cliniko-template-builder')"), true, 'Nested notices relocate outside the workspace without errors');
  await page('Emulation.setDeviceMetricsOverride', { width: 390, height: 800, deviceScaleFactor: 1, mobile: true });
  for (const name of names) {
    await navigate(name);
    assert.equal(await evaluate("getComputedStyle(document.querySelector('.cliniko-admin-shell')).display"), 'block', name + ': mobile navigation layout');
    const overflow = await evaluate("({width: innerWidth, scroll: document.documentElement.scrollWidth, offenders: Array.from(document.querySelectorAll('body *')).filter(el => el.getBoundingClientRect().right > innerWidth + 1 && getComputedStyle(el).position !== 'fixed').slice(0, 6).map(el => el.className || el.tagName)})");
    assert.ok(overflow.scroll <= overflow.width, name + ': mobile overflow ' + JSON.stringify(overflow));
  }
  await navigate('profile-editor');
  assert.equal(await evaluate("document.querySelector('.cliniko-account-form-editor__hero p').getBoundingClientRect().right <= innerWidth"), true, 'Mobile heading copy must not be clipped');
  writeFileSync(join(profile, 'profile-editor-mobile.png'), Buffer.from((await page('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
  assert.deepEqual(errors, [], 'No runtime JavaScript errors across builder screens');
  console.log('[PASS] 18 real WordPress builder screens, shared API theme, sidebar navigation, editor tabs, original forms and responsive layout');
  console.log('Screenshots: ' + profile);
} finally {
  if (send && socket?.readyState === WebSocket.OPEN) {
    await send('Browser.close').catch(() => {});
    socket.close();
  }
  if (browser.exitCode === null) browser.kill();
  await new Promise((done) => server.close(done));
}



