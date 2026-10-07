'use strict';
window.BridgeAccounts = {
  create({ api, run, notice, mark, stamp, canAct, reload }) {
    const $ = (id) => document.getElementById(id);
    const messages = {
      STORAGE_UNAVAILABLE: '插件存储不可读写，请检查 PHP 运行用户的目录权限。',
      PRIVATE_STATE_UNREADABLE:
        '已保存数据无法解密或损坏，请检查原 APP_KEY 和持久化目录，不要删除原数据。',
      CREDENTIAL_JSON_REQUIRED: '账户保存必须使用 JSON 请求，请更新控制台页面。',
      PLUGIN_RESTART_REQUIRED:
        '插件升级后仍加载着旧组件。请重启 XBoard 容器或 PHP 服务，再重新检查。',
      ADMIN_OPERATION_FAILED: 'XBoard 插件处理请求时发生异常，请根据下方诊断信息排查。',
      UPSTREAM_SECURITY_CHALLENGE: '网站要求 Cloudflare／人机验证，自动账户任务已暂停。',
      UPSTREAM_SECURITY_BLOCKED: '网站安全防护拦截了服务器请求，自动账户任务已暂停。',
      UPSTREAM_ACCESS_DENIED: '网站拒绝访问（403），请检查网站权限或防护规则。',
      UPSTREAM_HTML_RESPONSE: '接口返回网页而非 JSON，可能是登录页或安全验证页。',
      UPSTREAM_REDIRECT: '接口要求跳转；请核对后台域名与 API 地址。',
      UPSTREAM_AUTH_EXPIRED: '登录状态已失效，请更新 Token／Cookie，或检查账户登录。',
      UPSTREAM_LOGIN_FAILED: '登录失败，请核对账号密码；不会连续尝试。',
      UPSTREAM_VERIFICATION_REQUIRED: '登录需要验证码或额外验证，自动账户任务已暂停。',
      UPSTREAM_LOGIN_INVALID: '登录响应缺少有效 auth_data，需适配此面板。',
      UPSTREAM_SUBSCRIPTION_INVALID: '订阅响应不符合兼容格式，请导出诊断供排查。',
      UPSTREAM_UNRECOGNIZED: '尚未识别为 V2Board/XBoard 兼容接口。',
      UPSTREAM_RESPONSE_INVALID: '网站返回了无法识别的数据。',
      UPSTREAM_NETWORK_ERROR: '连接上游失败，将按退避间隔重试。',
      UPSTREAM_UNAVAILABLE: '上游暂时不可用，将按退避间隔重试。',
      UPSTREAM_RATE_LIMITED: '上游限流，已降低重试频率。',
      UPSTREAM_URL_INVALID: '请填写 HTTPS 后台地址；API 地址须同域并以 /api/v1 结尾。',
      UPSTREAM_CREDENTIAL_REQUIRED: '请填写此鉴权方式需要的账号密码、登录 Token 或 Cookie。',
      UPSTREAM_CREDENTIAL_INVALID: '凭据必须为文本，且长度不能超过 16384 字节。',
      UPSTREAM_CREDENTIAL_CONTROL_CHARACTERS:
        '密码、登录 Token 或 Cookie 中含有换行或空字符，请检查复制内容。',
      UPSTREAM_CONFIG_CHANGED: '账户配置已被其他页面修改，请更新状态后再保存。',
      SETTINGS_CHANGED: '来源配置已更新，可能已自动轮换。请重新载入后再编辑。',
      UPSTREAM_ROTATION_PENDING: '有未完成的轮换，请先重新检测并恢复；不会重复重置。',
      UPSTREAM_RESET_UNCONFIRMED: '尚未确认重置结果；已暂停此来源下发。请检查上游后重新检测。',
      UPSTREAM_ROTATION_COOLDOWN: '距离上次轮换不足一小时，请稍后再试。',
      UPSTREAM_ROTATION_OPT_IN_REQUIRED: '开启自动轮换前，请勾选已验证旧节点失效，并开启自动同步。',
      NOTIFICATION_CONFIG_INVALID: '请填写有效的机器人 Token 和数字 Chat ID。',
      NOTIFICATION_FAILED: '通知发送失败，请检查 Token、Chat ID 与服务器网络。',
      NOTIFICATION_DISABLED: '请先启用并保存 Telegram 提醒。',
    };
    let sources = [],
      accounts = new Map(),
      drafts = new Map(),
      notification = {},
      notificationDirty = false;
    const explain = (code) => messages[code] || code;
    const defaults = () => ({
      enabled: false,
      adapter: 'auto',
      panel_url: '',
      api_url: '',
      auth_mode: 'password',
      email: '',
      password: '',
      authorization: '',
      cookie: '',
      check_interval: 21600,
      remind_days: 7,
      sync_subscription: true,
      rotation_mode: 'manual',
      rotation_days: 7,
      rotation_verified: false,
    });
    function field(labelText, value, change, type = 'text', help = '') {
      const label = document.createElement('label');
      label.append(document.createTextNode(labelText));
      const el = document.createElement('input');
      el.type = type;
      el.autocomplete = type === 'password' ? 'new-password' : 'off';
      el.value = value ?? '';
      el.spellcheck = false;
      el.addEventListener('input', () => change(el.value));
      label.append(el);
      if (help) {
        const hint = document.createElement('small');
        hint.textContent = help;
        label.append(hint);
      }
      return label;
    }
    function select(labelText, choices, value, change) {
      const label = document.createElement('label');
      label.textContent = labelText;
      const el = document.createElement('select');
      for (const [id, text] of choices) {
        const option = document.createElement('option');
        option.value = id;
        option.textContent = text;
        el.append(option);
      }
      el.value = value;
      el.onchange = () => change(el.value);
      label.append(el);
      return label;
    }
    function check(text, value, change) {
      const label = document.createElement('label');
      label.className = 'account-check';
      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.checked = value;
      cb.onchange = () => change(cb.checked);
      label.append(cb, document.createTextNode(text));
      return label;
    }
    function btn(text, fn, danger = false) {
      const b = document.createElement('button');
      b.type = 'button';
      b.textContent = text;
      b.className = danger ? 'danger' : 'secondary';
      b.onclick = () => run(fn);
      return b;
    }
    function paragraph(text, cls = '') {
      const p = document.createElement('p');
      p.textContent = text;
      p.className = cls;
      return p;
    }
    function edit(id, key, value) {
      if (!drafts.has(id)) drafts.set(id, { ...defaults(), ...(accounts.get(id)?.config || {}) });
      drafts.get(id)[key] = value;
      mark();
    }
    function render() {
      const expanded = new Set(
        [...$('accounts').querySelectorAll('details.account[open]')].map(
          (el) => el.dataset.sourceId,
        ),
      );
      $('accounts').replaceChildren();
      $('accounts-empty').hidden = sources.length > 0;
      for (const source of sources) {
        const a = accounts.get(source.id) || {};
        const d = drafts.get(source.id) || { ...defaults(), ...(a.config || {}) };
        const card = document.createElement('details');
        card.className = 'account';
        card.dataset.sourceId = source.id;
        card.open = expanded.has(source.id);
        const summary = document.createElement('summary');
        summary.textContent = `${source.name} · ${a.paused ? '已暂停' : a.configured ? '已关联' : '未关联'}`;
        card.append(summary);
        if (a.error)
          card.append(
            paragraph(
              explain(a.error) + (a.last_step ? `（步骤：${a.last_step}）` : ''),
              'account-alert',
            ),
          );
        if (a.suspended)
          card.append(
            paragraph(
              '轮换尚未确认完成，暂停下发该来源，其他来源继续运行。重新检测只核对结果，不会再次重置。',
              'account-alert',
            ),
          );
        const info = a.info || {};
        const bytes = (n) => (Number.isFinite(n) ? (n / 1073741824).toFixed(2) + ' GiB' : '—');
        card.append(
          paragraph(
            a.checked_at
              ? `最近检查：${stamp(a.checked_at)} · 套餐到期：${info.expired_at === null ? '无到期时间' : stamp(info.expired_at)} · 剩余流量：${bytes(info.remaining_bytes)}`
              : '尚未完成账户检查。',
            'status-box',
          ),
        );
        if (a.checked_at) card.append(paragraph('接口：V2Board / XBoard 兼容；具体面板版本未知。'));
        if (a.rotation_at)
          card.append(
            paragraph(
              `最近轮换：${stamp(a.rotation_at)} · ${a.credential_changed === true ? '检测到 UUID 变更' : '未证实节点凭据变更'}。${a.refresh_pending?.length ? ' 部分格式等待刷新。' : ''}`,
            ),
          );
        if (a.configured && !a.checked_at)
          card.append(paragraph('账户配置已保存，尚未验证登录。请点击「检查并同步」。', 'hint'));
        if (a.rotation_phase)
          card.append(
            paragraph(
              '轮换阶段：' +
                ({
                  reset_requested: '已发起重置，等待确认',
                  reset_confirmed: '重置接口已响应，等待核对新凭据',
                  sync_pending: '已确认上游变化，等待同步地址',
                  complete: '新订阅地址已同步',
                }[a.rotation_phase] || '等待检查'),
            ),
          );
        if (a.refresh_pending?.length)
          card.append(
            paragraph(
              '待刷新格式：' +
                a.refresh_pending.join('、') +
                '。可使用来源的「立即刷新」重试，无需再次重置。',
            ),
          );
        if (a.rotation_phase === 'complete' && !a.refresh_pending?.length)
          card.append(
            paragraph(
              '服务端缓存刷新已完成；客户端仍需更新订阅，系统无法确认客户端是否已更新。',
              'hint',
            ),
          );
        card.append(check('启用账户自动检查', d.enabled, (v) => edit(source.id, 'enabled', v)));
        const grid = document.createElement('div');
        grid.className = 'form-grid';
        grid.append(
          field(
            '机场后台地址',
            d.panel_url,
            (v) => {
              edit(source.id, 'panel_url', v);
              edit(source.id, 'api_url', '');
            },
            'url',
            '可粘贴登录页地址，保存时提取域名。',
          ),
          select(
            '面板适配',
            [
              ['auto', '自动识别兼容接口'],
              ['v2board', 'V2Board / XBoard'],
            ],
            d.adapter,
            (v) => edit(source.id, 'adapter', v),
          ),
        );
        const auth = document.createElement('div');
        auth.className = 'form-grid';
        const renderAuth = () => {
          auth.replaceChildren();
          const current = drafts.get(source.id) || d;
          const secretHint = (key) =>
            a.config?.['has_' + key] ? '已保存，留空保留。更改域名或账户后需重新填写。' : '';
          if (current.auth_mode === 'password')
            auth.append(
              field('账户邮箱', current.email, (v) => edit(source.id, 'email', v), 'email'),
              field(
                '账户密码',
                current.password || '',
                (v) => edit(source.id, 'password', v),
                'password',
                secretHint('password'),
              ),
            );
          if (current.auth_mode === 'token')
            auth.append(
              field(
                '登录 Token（auth_data）',
                current.authorization || '',
                (v) => edit(source.id, 'authorization', v),
                'password',
                secretHint('authorization') || '使用登录接口的 auth_data，不是订阅地址中的 token。',
              ),
            );
          if (current.auth_mode === 'cookie')
            auth.append(
              field(
                '登录 Cookie',
                current.cookie || '',
                (v) => edit(source.id, 'cookie', v),
                'password',
                secretHint('cookie') || '仅适用于 Cookie 登录；不能用于自动通过网站安全验证。',
              ),
            );
        };
        card.append(
          grid,
          select(
            '账户鉴权方式',
            [
              ['password', '账号密码'],
              ['token', '登录 Token'],
              ['cookie', 'Cookie'],
            ],
            d.auth_mode,
            (v) => {
              edit(source.id, 'auth_mode', v);
              renderAuth();
            },
          ),
          auth,
        );
        renderAuth();
        const frequency = document.createElement('div');
        frequency.className = 'form-grid';
        frequency.append(
          select(
            '检查间隔',
            [
              ['3600', '1 小时'],
              ['21600', '6 小时'],
              ['43200', '12 小时'],
              ['86400', '每天'],
            ],
            String(d.check_interval),
            (v) => edit(source.id, 'check_interval', Number(v)),
          ),
          field(
            '提前提醒（天）',
            d.remind_days,
            (v) => edit(source.id, 'remind_days', Number(v)),
            'number',
          ),
        );
        card.append(
          frequency,
          check('自动同步上游最新订阅地址', d.sync_subscription, (v) =>
            edit(source.id, 'sync_subscription', v),
          ),
        );
        const advanced = document.createElement('details');
        const advSummary = document.createElement('summary');
        advSummary.textContent = '轮换策略与高级设置';
        advanced.append(advSummary);
        advanced.append(
          select(
            '轮换方式',
            [
              ['manual', '仅手动重置'],
              ['interval', '定期重置整个来源'],
              ['user_expiry', '授权组内用户到期后重置'],
            ],
            d.rotation_mode,
            (v) => edit(source.id, 'rotation_mode', v),
          ),
          field(
            '定期轮换间隔（天）',
            d.rotation_days,
            (v) => edit(source.id, 'rotation_days', Number(v)),
            'number',
          ),
          check('我已实测旧节点会失效，接受轮换影响全部共享用户', d.rotation_verified, (v) =>
            edit(source.id, 'rotation_verified', v),
          ),
          paragraph(
            '用户到期模式从启用时开始观察 XBoard 当前权限组的 expired_at；多次到期事件至少间隔一小时合并轮换。有效用户也需要更新订阅，已有连接何时失效由上游决定。',
          ),
          field('API 地址（留空自动生成）', d.api_url, (v) => edit(source.id, 'api_url', v), 'url'),
        );
        card.append(advanced);
        const actions = document.createElement('div');
        actions.className = 'actions';
        actions.append(
          btn('检测面板', () => action(source.id, 'probe')),
          btn(a.paused || a.suspended ? '重新检测并恢复' : '检查并同步', () =>
            action(source.id, 'resume'),
          ),
          btn(
            '重置上游订阅',
            async () => {
              canAct();
              if (
                confirm(
                  `重置「${source.name}」的整个上游账户订阅？所有共享该来源的有效用户都需要更新订阅。此操作无法撤销。`,
                )
              )
                await action(source.id, 'rotate');
            },
            true,
          ),
          btn(
            '解除账户关联',
            async () => {
              canAct();
              if (confirm('清除已保存的上游账户凭据？当前订阅地址保留。'))
                await action(source.id, 'remove');
            },
            true,
          ),
        );
        actions.querySelectorAll('button').forEach((b) => {
          b.disabled = !a.configured;
          if (!a.configured) b.title = '请先填写并保存账户配置，保存成功后即可使用。';
        });
        card.append(
          actions,
          paragraph(
            a.configured
              ? '修改账户配置后，先点击页面上方「保存」，再执行账户操作。'
              : '请先填写账户信息并点击页面上方「保存」；保存成功后，账户操作按钮即可使用。',
            'hint',
          ),
          paragraph('凭据加密保存，不写入诊断文件。', 'hint'),
        );
        $('accounts').append(card);
      }
      renderNotification();
    }
    function renderNotification() {
      const container = $('notification-settings');
      container.replaceChildren();
      const change = (key, value) => {
        notification[key] = value;
        notificationDirty = true;
        mark();
      };
      container.append(
        check('启用 Telegram 通知', notification.enabled || false, (v) => change('enabled', v)),
        field(
          '机器人 Token',
          notification.bot_token || '',
          (v) => change('bot_token', v),
          'password',
          notification.has_token ? '已保存，留空保留。' : '',
        ),
        field('接收提醒的 Chat ID', notification.chat_id || '', (v) => change('chat_id', v)),
      );
    }
    async function action(id, actionName) {
      canAct();
      const result = await api('account-action', {
        source_id: id,
        action: actionName,
        confirm: ['rotate', 'remove'].includes(actionName),
      });
      try {
        await reload();
      } catch (e) {
        throw new Error(
          '账户操作已有响应，但页面状态刷新失败；请重新检查状态，不要重复重置。' +
            explain(e.message),
        );
      }
      notice(
        result.ok
          ? actionName === 'rotate'
            ? '轮换结果已保存，调度器会刷新新节点。'
            : '账户操作完成。'
          : explain(result.account?.error || '操作失败'),
        !result.ok,
      );
    }
    $('reload-accounts').onclick = () =>
      run(async () => {
        canAct();
        await reload();
      });
    $('test-notification').onclick = () =>
      run(async () => {
        canAct();
        await api('notifications', { action: 'test' });
        notice('测试消息已发送。');
      });
    return {
      explain,
      async load(items) {
        sources = items.filter((s) => s.id);
        const data = await api('accounts');
        accounts = new Map((data.accounts || []).map((a) => [a.source_id, a]));
        drafts.clear();
        notification = data.notifications || {};
        notificationDirty = false;
        render();
      },
      async save(currentSources) {
        const retained = new Set(currentSources.map((s) => s.id));
        const failures = [];
        sources = currentSources.filter((s) => s.id);
        for (const [id, config] of drafts) {
          if (!retained.has(id)) {
            drafts.delete(id);
            continue;
          }
          try {
            const result = await api('account-save', {
              source_id: id,
              config,
              revision: accounts.get(id)?.revision || null,
            });
            accounts.set(id, result.account);
            drafts.delete(id);
          } catch (e) {
            if (e.presented) throw e;
            failures.push(
              `${currentSources.find((s) => s.id === id)?.name || id}：${explain(e.message)}`,
            );
          }
        }
        if (notificationDirty) {
          try {
            const result = await api('notifications', { action: 'save', config: notification });
            notification = result.notifications;
            notificationDirty = false;
          } catch (e) {
            if (e.presented) throw e;
            failures.push('Telegram 通知：' + explain(e.message));
          }
        }
        render();
        if (failures.length) {
          throw new Error(
            '来源配置已保存。以下设置未保存：' +
              failures.join('；') +
              '。未保存的输入已保留，修正后再次保存即可。',
          );
        }
      },
      lock() {
        sources = [];
        accounts.clear();
        drafts.clear();
        notification = {};
        notificationDirty = false;
        $('accounts').replaceChildren();
        $('notification-settings').replaceChildren();
      },
    };
  },
};
