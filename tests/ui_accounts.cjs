// Actual account UI with synthetic credentials and independent save failures.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { JSDOM } = require('jsdom');
const dom = new JSDOM(
  '<div id="accounts"></div><p id="accounts-empty"></p><div id="notification-settings"></div><button id="reload-accounts"></button><button id="test-notification"></button>',
  { runScripts: 'outside-only' },
);
const w = dom.window;
const calls = [];
let fail = true;
w.eval(fs.readFileSync('ExternalNodeBridge/resources/assets/accounts.js', 'utf8'));
const ui = w.BridgeAccounts.create({
  api: async (path, body) => {
    if (path === 'accounts') return { accounts: [], notifications: {} };
    calls.push(body.source_id || 'notification');
    if (body.source_id === 'b' && fail) throw new Error('UPSTREAM_CREDENTIAL_CONTROL_CHARACTERS');
    if (path === 'account-save')
      return {
        account: {
          source_id: body.source_id,
          configured: true,
          revision: 'saved',
          config: { has_password: true },
        },
      };
    return { notifications: { enabled: false } };
  },
  run: (fn) => fn(),
  notice: () => {},
  mark: () => {},
  stamp: () => '',
  canAct: () => {},
  reload: async () => {},
});
(async () => {
  try {
    const sources = ['a', 'b', 'c'].map((id) => ({ id, name: 'Source ' + id }));
    await ui.load(sources);
    for (const card of w.document.querySelectorAll('.account')) {
      card.open = true;
      const input = card.querySelector('input[type=password]');
      input.value = '  synthetic password  ';
      input.dispatchEvent(new w.Event('input'));
      assert.equal(card.querySelector('button').disabled, true);
    }
    await assert.rejects(ui.save(sources), /Source b.*换行/);
    assert.deepEqual(calls, ['a', 'b', 'c']);
    const cardA = w.document.querySelector('[data-source-id=a]');
    const cardB = w.document.querySelector('[data-source-id=b]');
    assert.equal(cardA.querySelector('button').disabled, false);
    assert.equal(cardA.querySelector('input[type=password]').value, '');
    assert.equal(cardB.querySelector('input[type=password]').value, '  synthetic password  ');
    assert.equal(cardB.open, true);
    fail = false;
    await ui.save(sources);
    assert.deepEqual(calls, ['a', 'b', 'c', 'b']);
    assert.equal(w.document.querySelector('[data-source-id=b] button').disabled, false);
    console.log(
      'PASS: partial saves retain failed drafts, clear saved secrets, continue other sources, retry only failures and retain expanded cards.',
    );
  } finally {
    w.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
