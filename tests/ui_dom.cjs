// Execute the real console HTML/JS with jsdom and a synthetic admin API.
// No CSS layout assertions; dialog native methods are minimally polyfilled.
const assert = require('node:assert/strict'),
  fs = require('node:fs'),
  path = require('node:path');
const { JSDOM } = require(process.env.BRIDGE_JSDOM_MODULE || 'jsdom');
const assets = path.join(__dirname, '../ExternalNodeBridge/resources/assets');
const dom = new JSDOM(fs.readFileSync(assets + '/console.html', 'utf8'), {
  url: 'https://panel.test/plugins/external_node_bridge/console.html',
  runScripts: 'outside-only',
});
const w = dom.window,
  d = w.document,
  $ = (id) => d.getElementById(id);
const targets = [
  'mihomo',
  'shadowrocket',
  'singbox',
  'stash',
  'surge',
  'surfboard',
  'loon',
  'quanx',
  'mixed',
  'sssub',
];
let access = { open: true, expires_at: Math.floor(Date.now() / 1000) + 3600 },
  saved = 0,
  downloaded = false;
let config = {
  converter_url: 'http://subconverter:25500',
  upstream_user_agent: 'clash.meta',
  timeout: 15,
  max_stale: 86400,
  mihomo_groups: ['🚀 节点选择'],
  singbox_groups: [],
  ini_groups: [],
  remove_provider_keys: [],
  debug: false,
  debug_until: 0,
  sources: [
    {
      id: '1',
      name: '现有来源',
      prefix: '[1]',
      url: 'https://source.test/sub?token=synthetic',
      enabled: true,
      interval: 3600,
      group_ids: ['1'],
      targets: ['mihomo'],
    },
  ],
};
const clone = (x) => JSON.parse(JSON.stringify(x));
w.localStorage.setItem('XBOARD_ACCESS_TOKEN', JSON.stringify({ value: 'synthetic-admin-token' }));
w.HTMLDialogElement.prototype.showModal = function () {
  this.setAttribute('open', '');
};
w.HTMLDialogElement.prototype.close = function () {
  this.removeAttribute('open');
};
w.confirm = () => true;
w.URL.createObjectURL = () => {
  downloaded = true;
  return 'blob:test';
};
w.URL.revokeObjectURL = () => {};
w.HTMLAnchorElement.prototype.click = function () {};
w.fetch = async (url, options) => {
  assert.equal(options.headers.Authorization, 'synthetic-admin-token');
  const action = url.split('/').pop(),
    body = options.body ? JSON.parse(options.body) : null;
  if (!access.open && !['session', 'close'].includes(action))
    return { ok: false, status: 403, json: async () => ({ error: 'CONSOLE_CLOSED' }) };
  let data = {};
  if (action === 'session')
    data = {
      access,
      version: require('../ExternalNodeBridge/config.json').version,
      summary: {
        sources: config.sources.length,
        enabled_sources: config.sources.length,
        converter_url: config.converter_url,
      },
    };
  else if (action === 'settings' && body) {
    config = body.config;
    config.sources.forEach((s, i) => {
      s.id ||= 'generated' + i;
      s.prefix ??= '[' + s.name + ']';
    });
    saved++;
    data = { ok: true };
  } else if (action === 'settings')
    data = {
      config,
      access,
      targets,
      groups: [
        { id: 1, name: '管理员' },
        { id: 2, name: '朋友' },
        { id: 3, name: '家人' },
      ],
      debug_active: config.debug,
    };
  else if (action === 'status' || action === 'export')
    data = {
      sources: [],
      events: [{ event: 'TEST' }],
      debug_active: config.debug,
      converter_version: 'v1.9.6',
    };
  else if (action === 'health')
    data = { ok: true, version: 'v1.9.6', checked_at: Math.floor(Date.now() / 1000) };
  else if (action === 'debug') {
    config.debug = body.enabled;
    data = { ok: true };
  } else if (action === 'renew') data = { access };
  else if (action === 'close') {
    access = { open: false, expires_at: 0 };
    data = { ok: true };
  } else if (action === 'refresh') data = { ok: true };
  return { ok: true, status: 200, json: async () => clone(data) };
};
const pause = () => new Promise((r) => setTimeout(r, 5));
async function wait(fn) {
  for (let i = 0; i < 100; i++) {
    if (fn()) return;
    await pause();
  }
  throw Error('UI condition did not become true: ' + fn);
}
function field(root, label) {
  const el = [...root.querySelectorAll('label')].find((l) => l.textContent === label);
  assert.ok(el, label);
  return el.querySelector('input,textarea');
}
function fill(el, value) {
  el.value = value;
  el.dispatchEvent(new w.Event('input', { bubbles: true }));
}
async function click(id) {
  $(id).click();
  await pause();
}
async function reopen() {
  access = { open: true, expires_at: Math.floor(Date.now() / 1000) + 3600 };
  await click('retry');
  await click('enter');
  await wait(() => !$('workspace').hidden);
}
(async () => {
  try {
    w.eval(fs.readFileSync(assets + '/console.js', 'utf8'));
    await wait(() => !$('workspace').hidden);
    await pause();
    assert.equal(
      $('plugin-version').textContent,
      'XBOARD PLUGIN · v' + require('../ExternalNodeBridge/config.json').version,
    );
    assert.equal(field(d.querySelector('.source'), '订阅地址').value, config.sources[0].url);
    assert.equal(d.querySelector('select[multiple]'), null);
    assert.equal($('cache_revision'), null);
    await click('add');
    let source = d.querySelectorAll('.source')[1];
    fill(field(source, '来源名称'), '新来源');
    fill(field(source, '订阅地址'), 'https://new.test/sub?token=synthetic-new');
    field(source, '朋友').click();
    field(source, '家人').click();
    await click('save');
    await wait(() => saved === 1 && !$('dirty').hidden === false);
    assert.deepEqual(config.sources[1].group_ids, ['2', '3']);
    assert.equal(config.sources[1].targets.length, 10);
    assert.equal(config.sources[0].id, '1');
    await click('health');
    assert.match($('version-state').textContent, /连接成功.*v1.9.6/);
    await click('debug');
    assert.equal(config.debug, true);
    await click('export');
    assert.equal(downloaded, true);
    fill(field(d.querySelector('.source'), '来源名称'), '未保存');
    await click('close');
    assert.equal($('close-dialog').open, true);
    await click('cancel-close');
    assert.equal($('workspace').hidden, false);
    await click('close');
    await click('discard-close');
    await wait(() => $('workspace').hidden);
    assert.equal(config.sources[0].name, '现有来源');
    assert.equal(d.querySelectorAll('.source').length, 0);
    await reopen();
    fill(field(d.querySelector('.source'), '来源名称'), '保存后关闭');
    await click('close');
    await click('save-close');
    await wait(() => $('workspace').hidden);
    assert.equal(config.sources[0].name, '保存后关闭');
    assert.equal(saved, 2);
    await reopen();
    w.dispatchEvent(
      new w.StorageEvent('storage', { key: 'EXTERNAL_BRIDGE_CLOSED', newValue: 'test' }),
    );
    assert.equal($('workspace').hidden, true);
    assert.equal(d.querySelectorAll('.source').length, 0);
    await reopen();
    access.open = false;
    await click('reload-status');
    assert.equal($('workspace').hidden, true);
    assert.match($('notice').textContent, /已关闭/);
    await reopen();
    access.expires_at = Math.floor(Date.now() / 1000) - 1;
    await click('retry');
    await new Promise((r) => setTimeout(r, 1100));
    assert.equal($('workspace').hidden, true);
    console.log(
      'PASS: real console DOM/JS — plaintext URL, checkbox sets, auto IDs, 10 formats, save, version, Debug/export, cancel/discard/save-close, cross-tab revoke, backend 403, expiry.',
    );
  } finally {
    w.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
