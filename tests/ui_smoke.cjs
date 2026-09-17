// Real browser, synthetic authenticated API: no production credentials.
const assert = require('node:assert/strict'),
  fs = require('node:fs'),
  path = require('node:path');
const { chromium } = require('playwright');
(async () => {
  const portable = process.env.BRIDGE_CHROMIUM_MODULE
    ? require(process.env.BRIDGE_CHROMIUM_MODULE)
    : null;
  const browser = await chromium.launch({
    headless: true,
    ...(portable
      ? {
          executablePath:
            process.env.BRIDGE_CHROMIUM_EXECUTABLE || (await portable.executablePath()),
          args: portable.args,
        }
      : { args: ['--no-sandbox'] }),
  });
  try {
    const context = await browser.newContext({ viewport: { width: 1280, height: 960 } }),
      page = await context.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
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
    let access = { open: true, expires_at: Math.floor(Date.now() / 1000) + 3600 };
    let config = {
      converter_url: 'http://subconverter:25500',
      upstream_user_agent: 'clash.meta',
      timeout: 15,
      max_stale: 86400,
      mihomo_groups: ['🚀 节点选择', '♻️ 自动选择'],
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
          url: 'https://upstream.example.test/subscribe?token=synthetic',
          enabled: true,
          interval: 3600,
          group_ids: ['1'],
          targets: ['mihomo', 'shadowrocket', 'singbox'],
        },
      ],
    };
    let saved = 0;
    await context.addInitScript(() =>
      localStorage.setItem(
        'XBOARD_ACCESS_TOKEN',
        JSON.stringify({ value: 'synthetic-admin-token' }),
      ),
    );
    await context.route('https://panel.test/**', async (route) => {
      const req = route.request(),
        url = new URL(req.url());
      if (url.pathname.startsWith('/api/')) {
        assert.equal(req.headers().authorization, 'synthetic-admin-token');
        let data = {};
        const action = url.pathname.split('/').pop();
        if (!access.open && !['session', 'close'].includes(action))
          return route.fulfill({ status: 403, json: { error: 'CONSOLE_CLOSED' } });
        if (action === 'session')
          data = {
            access,
            summary: {
              sources: config.sources.length,
              enabled_sources: config.sources.length,
              converter_url: config.converter_url,
            },
            version: require('../ExternalNodeBridge/config.json').version,
          };
        else if (action === 'settings' && req.method() === 'POST') {
          config = req.postDataJSON().config;
          config.sources.forEach((s, i) => {
            s.id ||= 'generated' + i;
            s.prefix ??= '[' + s.name + ']';
          });
          saved++;
          data = { ok: true };
        } else if (action === 'settings')
          data = {
            config,
            targets,
            groups: [
              { id: 1, name: 'Administrator' },
              { id: 2, name: 'Best Friends' },
              { id: 3, name: 'Friends' },
            ],
            debug_active: config.debug,
            access,
          };
        else if (action === 'debug') {
          config.debug = req.postDataJSON().enabled;
          data = { ok: true };
        } else if (action === 'status' || action === 'export')
          data = {
            plugin_version: require('../ExternalNodeBridge/config.json').version,
            converter_version: 'v1.9.6',
            debug_active: config.debug,
            debug_until: config.debug_until,
            sources: [],
            events: [{ event: 'UI_TEST', count: 3 }],
          };
        else if (action === 'health')
          data = { ok: true, version: 'v1.9.6', checked_at: Math.floor(Date.now() / 1000) };
        else if (action === 'renew') data = { access };
        else if (action === 'close') {
          access = { open: false, expires_at: 0 };
          data = { ok: true };
        } else if (action === 'refresh') data = { ok: true, state: { count: 3 } };
        return route.fulfill({ json: data, headers: { 'cache-control': 'no-store' } });
      }
      const name = path.basename(url.pathname),
        file = path.join(__dirname, '../ExternalNodeBridge/resources/assets', name);
      return route.fulfill({
        body: fs.readFileSync(file),
        contentType: name.endsWith('.html')
          ? 'text/html'
          : name.endsWith('.css')
            ? 'text/css'
            : 'application/javascript',
      });
    });
    await page.goto('https://panel.test/plugins/external_node_bridge/console.html');
    await page.waitForSelector('#workspace:not([hidden])');
    assert.equal(
      await page.getByLabel('订阅地址', { exact: true }).inputValue(),
      config.sources[0].url,
    );
    await page.getByRole('button', { name: '＋ 添加来源' }).click();
    const source = page.locator('.source').nth(1);
    await source.getByLabel('来源名称', { exact: true }).fill('新来源');
    await source
      .getByLabel('订阅地址', { exact: true })
      .fill('https://new.example.test/sub?token=synthetic-new');
    await source.getByLabel('Best Friends', { exact: true }).check();
    await source.getByLabel('Friends', { exact: true }).check();
    await page.locator('#save').click();
    await page.waitForFunction(() =>
      document.querySelector('#notice').textContent.includes('配置已保存'),
    );
    assert.equal(saved, 1);
    assert.deepEqual(config.sources[1].group_ids, ['2', '3']);
    assert.equal(config.sources[0].id, '1');
    assert.equal(config.sources[1].targets.length, 10);
    fs.mkdirSync(path.join(__dirname, 'runtime'), { recursive: true });
    await page.screenshot({
      path: path.join(__dirname, 'runtime/console-desktop.png'),
      fullPage: true,
    });
    await page.getByRole('button', { name: '转换服务', exact: true }).click();
    await page.locator('#health').click();
    await page.waitForFunction(() =>
      document.querySelector('#version-state').textContent.includes('连接成功'),
    );
    await page.getByRole('button', { name: '运行与调试', exact: true }).click();
    await page.locator('#debug').click();
    await page.waitForFunction(() => document.querySelector('#debug').textContent === '关闭 Debug');
    assert.equal(config.debug, true);
    const download = page.waitForEvent('download');
    await page.locator('#export').click();
    assert.equal((await download).suggestedFilename(), 'external-node-bridge-diagnostics.json');
    await page.getByRole('button', { name: '订阅来源', exact: true }).click();
    await page.getByLabel('来源名称', { exact: true }).first().fill('未保存修改');
    await page.locator('#close').click();
    await page.waitForSelector('#close-dialog[open]');
    await page.locator('#cancel-close').click();
    assert.equal(await page.locator('#workspace').isVisible(), true);
    await page.locator('#close').click();
    await page.locator('#discard-close').click();
    await page.waitForSelector('#landing:not([hidden])');
    assert.equal(config.sources[0].name, '现有来源');
    assert.equal(await page.locator('.source').count(), 0);
    access = { open: true, expires_at: Math.floor(Date.now() / 1000) + 3600 };
    await page.locator('#retry').click();
    await page.locator('#enter').click();
    await page.waitForSelector('#workspace:not([hidden])');
    await page.getByLabel('来源名称', { exact: true }).first().fill('保存后关闭');
    await page.locator('#close').click();
    await page.locator('#save-close').click();
    await page.waitForSelector('#landing:not([hidden])');
    assert.equal(config.sources[0].name, '保存后关闭');
    assert.equal(saved, 2);
    access = { open: true, expires_at: Math.floor(Date.now() / 1000) + 3600 };
    await page.locator('#retry').click();
    await page.locator('#enter').click();
    await page.waitForSelector('#workspace:not([hidden])');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({
      path: path.join(__dirname, 'runtime/console-mobile.png'),
      fullPage: true,
    });
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await page.evaluate(() =>
      window.dispatchEvent(
        new StorageEvent('storage', { key: 'EXTERNAL_BRIDGE_CLOSED', newValue: 'test' }),
      ),
    );
    assert.equal(await page.locator('#workspace').isVisible(), false);
    assert.deepEqual(errors, []);
    console.log(
      'PASS: plaintext URL, checkbox groups, stable IDs, save, version, Debug/export, three-way close, cross-tab revoke, desktop/mobile layout.',
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
