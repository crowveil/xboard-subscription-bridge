'use strict';
(() => {
  const $ = (id) => document.getElementById(id);
  const apiBase = '/api/v1/external-node-bridge/admin/';
  const labels = {
    mihomo: 'Clash / Clash Meta',
    shadowrocket: 'Shadowrocket',
    singbox: 'Sing-box',
    stash: 'Stash',
    surge: 'Surge 5',
    surfboard: 'Surfboard',
    loon: 'Loon',
    quanx: 'Quantumult X',
    mixed: '通用链接 / v2rayN / v2rayNG',
    sssub: 'Shadowsocks (SIP008)',
  };
  let config,
    groups = [],
    targets = [],
    access = {},
    dirty = false,
    active = false,
    busy = false,
    debugActive = false,
    generation = 0,
    revision;
  const stamp = (n) => (n ? new Date(n * 1000).toLocaleString() : '—');
  const versionText = (report) => {
    const info = report.converter || {};
    const version = info.version || report.converter_version;
    if (!version) return '尚未识别版本；点击检测连接与版本。';
    return `最近识别版本：${version}${info.checked_at ? ' · ' + stamp(info.checked_at) : ''}${info.ok === false ? ' · 最近检测失败：' + info.error : ''}`;
  };
  const notice = (message, error = false) => {
    $('notice').textContent = message;
    $('notice').classList.toggle('error', error);
  };
  const accountsUI = window.BridgeAccounts.create({
    api,
    run,
    notice,
    mark,
    stamp,
    reload: open,
    canAct() {
      if (dirty) throw new Error('请先保存配置，再执行账户操作。');
    },
  });
  function token() {
    try {
      const v = JSON.parse(localStorage.getItem('XBOARD_ACCESS_TOKEN'));
      return typeof v === 'string' ? v : v?.value || '';
    } catch {
      return '';
    }
  }
  async function api(path, body) {
    const epoch = generation;
    const ensureCurrent = () => {
      if (epoch === generation) return;
      const error = new Error('管理访问状态已改变，请重新检查。');
      error.presented = true;
      throw error;
    };
    const auth = token();
    if (!auth) {
      const error = new Error('请先在同一域名登录 XBoard 管理后台，再重新检查。');
      error.state = 'auth';
      presentFailure(error);
      throw error;
    }
    const operation =
      {
        session: '检查管理访问',
        settings: body === undefined ? '读取配置' : '保存配置',
        status: '读取运行状态',
        accounts: '读取上游账户',
      }[path] || '执行操作';
    let response;
    try {
      response = await fetch(apiBase + path, {
        method: body === undefined ? 'GET' : 'POST',
        headers: {
          Authorization: auth,
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        cache: 'no-store',
        body: body === undefined ? undefined : JSON.stringify(body),
      });
    } catch {
      ensureCurrent();
      throw new Error(`${operation}失败：无法连接 XBoard，请检查网络后重试。`);
    }
    const data = await response.json().catch(() => ({}));
    ensureCurrent();
    if (!response.ok) {
      const message =
        data.error === 'CONSOLE_CLOSED'
          ? '管理访问已关闭或到期。请在插件配置中重新开放。'
          : [401, 403].includes(response.status)
            ? '需要在同一域名重新登录 XBoard 管理后台。'
            : (data.error && accountsUI.explain(data.error)) || '服务器未能完成请求。';
      const details = [`${operation}失败（HTTP ${response.status}）：${message}`];
      if (/^[a-f0-9]{16}$/.test(data.trace)) details.push(`诊断编号：${data.trace}`);
      const site = data.diagnostic;
      if (
        site &&
        /^[A-Za-z0-9_.-]{1,64}$/.test(site.error_file) &&
        Number.isInteger(site.error_line)
      )
        details.push(`位置：${site.error_file}:${site.error_line}`);
      const error = new Error(details.join(' · '));
      error.state =
        data.error === 'CONSOLE_CLOSED'
          ? 'closed'
          : data.error === 'PLUGIN_RESTART_REQUIRED'
            ? 'restart'
            : [401, 403].includes(response.status)
              ? 'auth'
              : 'unavailable';
      if (error.state !== 'unavailable') presentFailure(error);
      throw error;
    }
    return data;
  }
  function mark() {
    dirty = true;
    $('dirty').hidden = false;
  }
  function clean() {
    dirty = false;
    $('dirty').hidden = true;
  }
  function presentFailure(error) {
    if (error.presented) return;
    lock(error.state || 'unavailable');
    notice(error.message, true);
    error.presented = true;
  }
  function lock(state = 'closed') {
    generation++;
    active = false;
    config = undefined;
    groups = [];
    targets = [];
    revision = undefined;
    accountsUI.lock();
    clean();
    $('workspace').hidden = true;
    $('landing').hidden = false;
    $('enter').hidden = true;
    $('sources').replaceChildren();
    $('states').replaceChildren();
    $('events').textContent = '';
    document
      .querySelectorAll('#tab-service input,#tab-service textarea')
      .forEach((i) => (i.value = ''));
    $('close-dialog').close();
    const messages = {
      closed: [
        '管理控制台已关闭',
        '到 XBoard → 插件管理 → 订阅桥接 → 配置，开启“开放管理控制台”并保存。订阅分发继续运行。',
      ],
      auth: ['需要管理员登录', '请在同一域名登录 XBoard 管理后台，然后点击“重新检查”。'],
      restart: [
        '插件组件需要重新加载',
        '请在 1Panel 重启 XBoard 容器，或重启部署中的 PHP / Octane 常驻服务，然后点击“重新检查”。若仍报错，请检查插件是否完整更新。',
      ],
      unavailable: [
        '管理控制台加载失败',
        '暂时无法读取管理数据；这不表示控制台开关已关闭。请按上方错误提示排查，然后点击“重新检查”。',
      ],
    };
    [$('access-title').textContent, $('access-help').textContent] = messages[state];
  }
  async function check(enter = false) {
    const state = await api('session');
    access = state.access;
    $('plugin-version').textContent = `XBOARD PLUGIN · v${state.version}`;
    if (state.release?.channel === 'development')
      $('plugin-version').textContent += ` · ${state.release.target_version} 开发版`;
    $('summary').textContent =
      `当前保存：${state.summary.sources} 个来源，${state.summary.enabled_sources} 个启用 · 转换服务 ${state.summary.converter_url}`;
    $('access-title').textContent = access.open ? '管理访问已开放' : '管理控制台已关闭';
    $('access-help').textContent = access.open
      ? `访问有效期至 ${stamp(access.expires_at)}。`
      : '在 XBoard 插件配置中开启“开放管理控制台”并保存后，可从这里进入。';
    $('enter').hidden = !access.open;
    if (!access.open && active) lock();
    if (enter && access.open) await open();
    else if (enter) notice('');
  }
  function input(labelText, value, oninput, type = 'text') {
    const label = document.createElement('label');
    label.textContent = labelText;
    const el = document.createElement(type === 'textarea' ? 'textarea' : 'input');
    if (type !== 'textarea') el.type = type;
    else el.rows = 2;
    el.value = value;
    el.autocomplete = 'off';
    el.spellcheck = false;
    el.addEventListener('input', () => {
      oninput(el.value);
      mark();
    });
    label.append(el);
    return label;
  }
  function button(text, action, cls = 'secondary') {
    const b = document.createElement('button');
    b.type = 'button';
    b.textContent = text;
    b.className = cls;
    b.onclick = action;
    return b;
  }
  function checks(items, selected, change) {
    const wrap = document.createElement('div');
    wrap.className = 'chips';
    for (const [value, name] of items) {
      const label = document.createElement('label');
      label.className = 'chip';
      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.value = String(value);
      cb.checked = selected.includes(String(value));
      cb.onchange = () => {
        change([...wrap.querySelectorAll('input:checked')].map((i) => i.value));
        mark();
      };
      label.append(cb, document.createTextNode(name));
      wrap.append(label);
    }
    return wrap;
  }
  function renderSources() {
    const container = $('sources');
    container.replaceChildren();
    $('empty').hidden = config.sources.length > 0;
    for (const s of config.sources) {
      const card = document.createElement('article');
      card.className = 'source';
      const head = document.createElement('div');
      head.className = 'source-head';
      const enabled = document.createElement('label');
      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.checked = s.enabled;
      cb.onchange = () => {
        s.enabled = cb.checked;
        mark();
      };
      enabled.append(cb, document.createTextNode('启用此来源'));
      head.append(
        enabled,
        button(
          '移除',
          () => {
            if (confirm('移除此来源？保存后生效。')) {
              config.sources = config.sources.filter((item) => item !== s);
              mark();
              renderSources();
            }
          },
          'danger',
        ),
      );
      const grid = document.createElement('div');
      grid.className = 'form-grid';
      grid.append(
        input('来源名称', s.name, (v) => (s.name = v)),
        input(
          '刷新间隔（分钟，至少 5）',
          s.interval / 60,
          (v) => (s.interval = Math.max(0, Number(v)) * 60),
          'number',
        ),
      );
      card.append(
        head,
        grid,
        input('订阅地址', s.url, (v) => (s.url = v), 'textarea'),
      );
      const title = document.createElement('label');
      title.textContent = '允许使用的 XBoard 权限组';
      card.append(title);
      const groupBox = checks(
        groups.map((g) => [String(g.id), g.name]),
        s.group_ids,
        (v) => (s.group_ids = v),
      );
      const buttons = document.createElement('div');
      buttons.append(
        button(
          '全选',
          () => {
            s.group_ids = groups.map((g) => String(g.id));
            mark();
            renderSources();
          },
          'inline-link',
        ),
        button(
          '清空',
          () => {
            s.group_ids = [];
            mark();
            renderSources();
          },
          'inline-link',
        ),
      );
      card.append(buttons, groupBox);
      if (!groups.length) {
        const p = document.createElement('p');
        p.textContent = '暂无权限组，请先在 XBoard 创建。';
        card.append(p);
      }
      const details = document.createElement('details');
      const summary = document.createElement('summary');
      summary.textContent = '高级设置';
      details.append(
        summary,
        input(
          '节点名称前缀（可留空；新来源默认使用来源名称）',
          s.prefix ?? '',
          (v) => (s.prefix = v),
        ),
      );
      const fmt = document.createElement('label');
      fmt.textContent = '输出格式';
      details.append(
        fmt,
        checks(
          targets.map((t) => [t, labels[t] || t]),
          s.targets,
          (v) => (s.targets = v),
        ),
        button(
          '启用全部格式',
          () => {
            s.targets = [...targets];
            mark();
            renderSources();
          },
          'inline-link',
        ),
      );
      const hint = document.createElement('p');
      hint.className = 'hint';
      hint.textContent =
        '转换器不支持的协议会跳过。格式支持不代表所有客户端版本都支持其中每种协议。内部来源编号由系统生成，编辑名称不会改变编号。';
      details.append(hint);
      card.append(details);
      container.append(card);
    }
  }
  async function open() {
    try {
      const data = await api('settings');
      config = data.config;
      revision = data.revision;
      groups = data.groups;
      targets = data.targets;
      access = data.access;
      debugActive = data.debug_active;
      active = true;
      $('landing').hidden = true;
      $('workspace').hidden = false;
      renderSources();
      for (const k of ['converter_url', 'upstream_user_agent', 'timeout']) $(k).value = config[k];
      $('max_stale').value = config.max_stale / 3600;
      const retired = config.retired_provider_keys || [];
      $('template-review').hidden = !retired.length;
      $('template-review').textContent = retired.length
        ? '升级检查：旧配置曾自动移除以下 provider：' +
          retired.join('、') +
          '。请从 XBoard 模板中删除对应定义及 use 引用；仍含这些引用的订阅会暂停输出（HTTP 503）。不再提供自动清理。'
        : '';
      clean();
      tick();
      if (!active) return;
      await status();
      if (active) await accountsUI.load(config.sources);
      if (active) notice('');
    } catch (error) {
      presentFailure(error);
      throw error;
    }
  }
  function collect() {
    if (!config) throw new Error('管理访问已关闭。');
    for (const k of ['converter_url', 'upstream_user_agent']) config[k] = $(k).value.trim();
    config.timeout = Number($('timeout').value);
    config.max_stale = Number($('max_stale').value) * 3600;
    for (const s of config.sources) {
      if (!s.name.trim() || !s.url.trim()) throw new Error('请填写来源名称和完整订阅地址。');
      s.url = s.url.trim();
      if (s.interval < 300) throw new Error('刷新间隔至少为 5 分钟。');
    }
    return config;
  }
  async function save() {
    const saved = await api('settings', { config: collect(), revision });
    revision = saved.revision;
    if (saved.config) {
      config = saved.config;
      renderSources();
    }
    await accountsUI.save(config.sources);
    await open();
    notice('配置已保存。需要更新节点时，请执行立即刷新。');
  }
  async function close() {
    await api('close', {});
    localStorage.setItem('EXTERNAL_BRIDGE_CLOSED', String(Date.now()));
    lock();
    notice('控制台已关闭，所有标签页的管理访问均已撤销。');
    await check();
  }
  function tick() {
    if (!active) return;
    const left = Math.max(0, access.expires_at - Math.floor(Date.now() / 1000));
    $('remaining').textContent = `剩余 ${Math.floor(left / 60)} 分 ${left % 60} 秒`;
    if (!left) {
      lock();
      notice('管理访问已到期。未保存的修改未写入。', true);
    }
  }
  async function status() {
    const report = await api('status');
    if (!active) return;
    debugActive = report.debug_active;
    $('debug').textContent = debugActive ? '关闭 Debug' : '开启 Debug';
    $('version-state').textContent = versionText(report);
    $('states').replaceChildren();
    for (const s of report.sources) {
      const tr = document.createElement('tr');
      for (const v of [
        config.sources.find((x) => x.id === s.source_id)?.name || s.source_id,
        labels[s.target] || s.target,
        s.count,
        stamp(s.updated_at),
        s.error === 'NO_COMPATIBLE_NODES'
          ? '无兼容节点，已跳过'
          : s.error || (s.usable ? '可用' : '待刷新'),
      ]) {
        const td = document.createElement('td');
        td.textContent = String(v);
        tr.append(td);
      }
      $('states').append(tr);
    }
    $('events').textContent = JSON.stringify(report.events.slice(-80), null, 2);
  }
  async function run(fn) {
    if (busy) return;
    busy = true;
    $('workspace').setAttribute('aria-busy', 'true');
    const controls = [
      ...document.querySelectorAll(
        '#workspace button:not(#close),#workspace input,#workspace textarea,#workspace select',
      ),
    ];
    const disabled = controls.map((b) => b.disabled);
    controls.forEach((b) => (b.disabled = true));
    try {
      await fn();
    } catch (e) {
      if (!e.presented) notice(accountsUI.explain(e.message), true);
    } finally {
      busy = false;
      $('workspace').setAttribute('aria-busy', 'false');
      controls.forEach((b, i) => (b.disabled = disabled[i]));
    }
  }
  $('preflight').onclick = () =>
    run(async () => {
      const report = await api('preflight', {});
      const failed = Object.entries(report.checks || {})
        .filter(([, ok]) => !ok)
        .map(([name]) => name);
      $('deployment-state').textContent = report.ok
        ? '文件与读写自检通过。重建后仍须保留插件目录、私有数据目录和原 APP_KEY。'
        : '检查未通过：' + failed.join('、') + '。状态：' + accountsUI.explain(report.state);
    });
  $('save').onclick = () => run(save);
  $('enter').onclick = () => run(open);
  $('retry').onclick = () => run(() => check(true).catch(presentFailure));
  $('add').onclick = () => {
    if (config.sources.length >= 20) return notice('最多支持 20 个来源。', true);
    config.sources.push({
      name: '',
      url: '',
      group_ids: [],
      targets: [...targets],
      enabled: true,
      interval: 3600,
    });
    mark();
    renderSources();
  };
  $('renew').onclick = () =>
    run(async () => {
      access = (await api('renew', {})).access;
      tick();
      notice('管理访问已续期 60 分钟。');
    });
  $('close').onclick = () => {
    if (dirty) $('close-dialog').showModal();
    else close().catch((e) => notice(e.message, true));
  };
  $('cancel-close').onclick = () => $('close-dialog').close();
  $('discard-close').onclick = () => close().catch((e) => notice(e.message, true));
  $('save-close').onclick = () =>
    run(async () => {
      await save();
      await close();
    });
  $('health').onclick = () =>
    run(async () => {
      if (dirty) throw new Error('请先保存配置，再检测连接。');
      const r = await api('health', {});
      $('version-state').textContent =
        `${r.ok ? '连接成功' : '检测失败'} · ${r.version || '版本未知'} · ${stamp(r.checked_at)}${r.error ? ' · ' + r.error : ''}`;
    });
  $('debug').onclick = () =>
    run(async () => {
      revision = (await api('debug', { enabled: !debugActive })).revision;
      await status();
    });
  $('reload-status').onclick = () => run(status);
  $('refresh-all').onclick = () =>
    run(async () => {
      if (dirty) throw new Error('请先保存配置。');
      let n = 0,
        failed = 0;
      const jobs = config.sources
        .filter((s) => s.enabled && s.group_ids.length)
        .flatMap((s) => s.targets.map((t) => [s.id, t]));
      for (const [source_id, target] of jobs) {
        if (!active) break;
        const r = await api('refresh', { source_id, target });
        if (!r.ok) failed++;
        notice(`正在刷新 ${++n}/${jobs.length}…`);
      }
      if (!active) return;
      await status();
      notice(`刷新结束：${n} 项，${failed} 项需要查看状态。`, failed > 0);
    });
  $('export').onclick = () =>
    run(async () => {
      const r = await api('export');
      if (!active) return;
      const url = URL.createObjectURL(
        new Blob([JSON.stringify(r, null, 2)], { type: 'application/json' }),
      );
      const a = document.createElement('a');
      a.href = url;
      a.download = 'external-node-bridge-diagnostics.json';
      a.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
  document.querySelectorAll('[data-tab]').forEach(
    (b) =>
      (b.onclick = () => {
        document
          .querySelectorAll('[data-tab]')
          .forEach((x) => x.classList.toggle('active', x === b));
        document
          .querySelectorAll('.tabpanel')
          .forEach((p) => (p.hidden = p.id !== 'tab-' + b.dataset.tab));
      }),
  );
  for (const k of ['converter_url', 'upstream_user_agent', 'timeout', 'max_stale'])
    $(k).addEventListener('input', mark);
  window.addEventListener('beforeunload', (e) => {
    if (dirty) {
      e.preventDefault();
      e.returnValue = '';
    }
  });
  window.addEventListener('storage', (e) => {
    if (e.key === 'EXTERNAL_BRIDGE_CLOSED') {
      lock();
      notice('另一标签页已关闭控制台。');
    }
    if (e.key === 'XBOARD_ACCESS_TOKEN') lock('auth');
  });
  setInterval(tick, 1000);
  setInterval(() => {
    if (active && !busy) check().catch(presentFailure);
  }, 10000);
  check(true).catch(presentFailure);
})();
