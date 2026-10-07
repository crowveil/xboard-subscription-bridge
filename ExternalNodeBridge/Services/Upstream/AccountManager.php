<?php

namespace Plugin\ExternalNodeBridge\Services\Upstream;

use App\Models\User;
use Plugin\ExternalNodeBridge\Services\{BridgeException, Diagnostics, NodeCache, Settings};

final class AccountManager
{
    public static function source(string $id): array
    {
        $source = collect(Settings::load()['sources'])->firstWhere('id', $id);
        return $source ?? throw new BridgeException('SOURCE_NOT_FOUND');
    }

    public static function all(bool $diagnostic = false): array
    {
        return array_map(fn ($source) => AccountStore::summary($source['id'], null, $diagnostic), Settings::load()['sources']);
    }

    public static function run(string $id, string $action = 'check', bool $scheduled = false): array
    {
        if (!in_array($action, ['probe', 'check', 'rotate', 'resume'], true)) {
            throw new BridgeException('ACTION_INVALID');
        }
        return AccountStore::locked($id, function () use ($id, $action, $scheduled) {
            $source = self::source($id);
            $s = AccountStore::read($id);
            if (empty($s['config'])) {
                throw new BridgeException('UPSTREAM_NOT_CONFIGURED');
            }
            if ($scheduled && (empty($s['config']['enabled']) || !empty($s['paused']) || !$source['enabled'])) {
                return AccountStore::summary($id, $s);
            }
            if ($scheduled && !empty($s['error']) && ($s['next_check_at'] ?? 0) > time()) {
                return AccountStore::summary($id, $s);
            }
            if ($scheduled && $action === 'rotate' && !self::rotationDue($source, $s)) {
                return AccountStore::summary($id, $s);
            }
            if ($scheduled && $action === 'check' && ($s['next_check_at'] ?? 0) > time()) {
                return AccountStore::summary($id, $s);
            }
            $log = new Diagnostics(Settings::load());
            $s['attempted_at'] = time();
            AccountStore::write($id, $s);
            $adapter = Adapters::make($s['config'], function ($step) use ($id, &$s, $log) {
                $s['last_step'] = $step;
                AccountStore::write($id, $s);
                $log->record('UPSTREAM_REQUEST', ['source_id' => $id, 'state' => $step]);
            });
            $log->record('UPSTREAM_START', ['source_id' => $id, 'state' => $action]);
            try {
                if ($action === 'probe') {
                    $adapter->probe();
                    // Probe verifies only the public API, not account authentication.
                    $s['probe_at'] = time();
                } else {
                    if ($action === 'rotate') {
                        self::rotate($id, $source, $s, $adapter);
                    } elseif (AccountStore::suspended($s)) {
                        self::recover($id, $source, $s, $adapter);
                    } else {
                        $info = self::subscription($id, $s, $adapter);
                        $s['info'] = $info;
                        if ($s['config']['sync_subscription']) {
                            self::adopt($id, $source, $s, $info['subscribe_url']);
                        }
                    }
                    $s['checked_at'] = time();
                    $s['paused'] = false;
                    $s['failures'] = 0;
                    $s['next_check_at'] = time() + $s['config']['check_interval'];
                    self::expiryNotice($id, $source, $s);
                    $s['error'] = null;
                    $s['http_status'] = null;
                }
                $log->record('UPSTREAM_OK', ['source_id' => $id, 'state' => $action]);
            } catch (Failure $e) {
                $s['error'] = $e->reason;
                $s['http_status'] = $e->httpStatus;
                $s['failures'] = min(8, ($s['failures'] ?? 0) + 1);
                $s['paused'] = $e->pause || AccountStore::suspended($s);
                $s['next_check_at'] = time() + min(86400, max(900, 300 * (2 ** $s['failures'])));
                $log->record('UPSTREAM_FAILED', ['source_id' => $id, 'error' => $e->reason, 'http_status' => $e->httpStatus, 'state' => $s['paused'] ? 'paused' : 'backoff'], true);
                Notifier::enqueue($id, 'failure:'.$e->reason.':'.gmdate('Y-m-d'), '订阅桥接：'.$source['name'].'\n上游检查失败：'.$e->reason.($s['paused'] ? '\n自动账户操作已暂停，请到控制台处理后点击重新检测。' : '\n稍后会按退避间隔重试。'));
            } catch (BridgeException $e) {
                $s['error'] = $e->reason;
                $s['paused'] = true;
                $log->record('UPSTREAM_FAILED', ['source_id' => $id, 'error' => $e->reason], true);
                Notifier::enqueue($id, 'failure:'.$e->reason.':'.gmdate('Y-m-d'), '订阅桥接：'.$source['name'].'\n账户操作已暂停：'.$e->reason.'。请检查控制台。');
            } catch (\Throwable $e) {
                $s['error'] = 'UPSTREAM_INTERNAL_ERROR';
                $s['paused'] = true;
                $log->record('UPSTREAM_FAILED', ['source_id' => $id, 'error' => $s['error']] + Diagnostics::errorSite($e), true);
                Notifier::enqueue($id, 'failure:internal:'.gmdate('Y-m-d'), '订阅桥接：'.$source['name'].'\n账户操作异常，已暂停。请导出脱敏诊断检查。');
            }
            AccountStore::write($id, $s);
            return AccountStore::summary($id, $s);
        });
    }

    private static function subscription(string $id, array &$s, PanelAdapter $adapter): array
    {
        $c = $s['config'];
        $cookie = $c['auth_mode'] === 'cookie' ? $c['cookie'] : '';
        $authorization = $c['auth_mode'] === 'token' ? $c['authorization'] : ($s['session'] ?? '');
        if ($c['auth_mode'] === 'password' && $authorization === '') {
            $authorization = $adapter->login($c['email'], $c['password']);
            $s['session'] = $authorization;
            AccountStore::write($id, $s);
            return self::authenticatedSubscription($adapter, $authorization);
        }
        try {
            return $adapter->subscription($authorization, $cookie);
        } catch (Failure $e) {
            if ($e->reason !== 'UPSTREAM_AUTH_EXPIRED' || $c['auth_mode'] !== 'password') {
                if ($e->reason === 'UPSTREAM_AUTH_EXPIRED') {
                    throw new Failure($e->reason, $e->httpStatus, true);
                }
                throw $e;
            }
            // Reauthenticate at most once, and never on edge blocking or reset requests.
            $s['session'] = '';
            AccountStore::write($id, $s);
            $s['session'] = $adapter->login($c['email'], $c['password']);
            AccountStore::write($id, $s);
            return self::authenticatedSubscription($adapter, $s['session']);
        }
    }

    private static function authenticatedSubscription(PanelAdapter $adapter, string $authorization): array
    {
        try {
            return $adapter->subscription($authorization);
        } catch (Failure $e) {
            if ($e->reason === 'UPSTREAM_AUTH_EXPIRED') {
                throw new Failure($e->reason, $e->httpStatus, true);
            }
            throw $e;
        }
    }

    private static function rotate(string $id, array $source, array &$s, PanelAdapter $adapter): void
    {
        if (AccountStore::suspended($s)) {
            throw new Failure('UPSTREAM_ROTATION_PENDING', 0, true);
        }
        if (!$source['enabled'] || !$source['group_ids'] || !$source['targets']) {
            throw new Failure('SOURCE_NOT_ACTIVE', 0, true);
        }
        if (time() - ($s['rotation_at'] ?? 0) < 3600) {
            throw new Failure('UPSTREAM_ROTATION_COOLDOWN');
        }
        $before = self::subscription($id, $s, $adapter);
        // Persist reset intent before the request so retries can query its outcome.
        $s['rotation'] = ['phase' => 'reset_requested', 'id' => bin2hex(random_bytes(12)), 'started_at' => time(),
            'before_url' => $before['subscribe_url'], 'before_fingerprint' => $before['credential_fingerprint'],
            'source_url' => $source['url']];
        AccountStore::write($id, $s);
        $c = $s['config'];
        $adapter->reset($c['auth_mode'] === 'token' ? $c['authorization'] : ($s['session'] ?? ''), $c['auth_mode'] === 'cookie' ? $c['cookie'] : '');
        $s['rotation']['phase'] = 'reset_confirmed';
        AccountStore::write($id, $s);
        self::recover($id, $source, $s, $adapter);
    }

    private static function recover(string $id, array $source, array &$s, PanelAdapter $adapter): void
    {
        $info = self::subscription($id, $s, $adapter);
        $r = &$s['rotation'];
        $urlChanged = $info['subscribe_url'] !== $r['before_url'];
        $credentialChanged = $r['before_fingerprint'] !== null && $info['credential_fingerprint'] !== null
            ? !hash_equals($r['before_fingerprint'], $info['credential_fingerprint']) : null;
        if (!$urlChanged && $credentialChanged !== true) {
            throw new Failure('UPSTREAM_RESET_UNCONFIRMED', 0, true);
        }
        $r['credential_changed'] = $credentialChanged;
        $r['phase'] = 'sync_pending';
        $s['info'] = $info;
        AccountStore::write($id, $s);
        // Recovering a reset always adopts the current upstream URL, even in manual-sync mode.
        self::adopt($id, $source, $s, $info['subscribe_url'], true);
        $r['phase'] = 'complete';
        $s['rotation_at'] = time();
        $s['expiry_cursor'] = max((int) ($s['expiry_cursor'] ?? 0), (int) $r['started_at']);
        $r = array_intersect_key($r, array_flip(['phase', 'id', 'started_at', 'credential_changed']));
        Notifier::enqueue($id, 'rotation:'.$r['id'], '订阅桥接：'.$source['name'].'\n上游轮换完成，新地址已保存，节点正在刷新。'.($credentialChanged === true ? '\n检测到 UUID 已变更；旧连接的实际失效时间仍由上游决定。' : '\n尚未证实节点凭据变更，请实测旧节点是否失效。'));
    }

    private static function adopt(string $id, array $source, array &$s, string $url, bool $invalidate = false): void
    {
        if (!AccountStore::publicUrl($url)) {
            throw new Failure('UPSTREAM_SUBSCRIPTION_INVALID', 0, true);
        }
        if ($url === $source['url'] && !$invalidate) {
            return;
        }
        $s['refresh_pending'] = $source['targets'];
        AccountStore::write($id, $s);
        Settings::replaceSourceUrl($id, $source['url'], $url);
        // A same-URL reset also needs fresh credentials in every format.
        $cache = new NodeCache(Settings::load(), new Diagnostics(Settings::load()));
        $new = array_replace($source, ['url' => $url]);
        foreach (Settings::TARGETS as $target) {
            foreach ([$source, $new] as $item) {
                $cache->locked($item, $target, fn () => $cache->write($item, $target, []));
            }
        }
    }

    private static function expiryNotice(string $id, array $source, array $s): void
    {
        $expires = $s['info']['expired_at'] ?? null;
        if ($expires === null) {
            return;
        }
        $days = (int) ceil(($expires - time()) / 86400);
        $thresholds = array_unique([$s['config']['remind_days'], 3, 1, 0]);
        sort($thresholds);
        foreach ($thresholds as $threshold) {
            if ($threshold > $s['config']['remind_days'] || $days > $threshold) {
                continue;
            }
            Notifier::enqueue($id, 'expiry:'.$expires.':'.$threshold, '订阅桥接：'.$source['name'].($days <= 0 ? '\n上游套餐已到期。' : '\n上游套餐将在 '.$days.' 天内到期。').'\n到期时间：'.gmdate('Y-m-d H:i', $expires).' UTC');
            break;
        }
    }

    public static function due(): void
    {
        $deadline = microtime(true) + 40;
        foreach (Settings::load()['sources'] as $source) {
            if (microtime(true) > $deadline) {
                break;
            }
            $id = $source['id'];
            try {
                $s = AccountStore::locked($id, fn () => AccountStore::reconcileTargets($id, self::source($id)['targets']));
                $c = $s['config'] ?? [];
                if (empty($c['enabled']) || !empty($s['paused']) || !$source['enabled']) {
                    continue;
                }
                $rotate = self::rotationDue($source, $s);
                if ($rotate || ($s['next_check_at'] ?? 0) <= time()) {
                    self::run($id, $rotate ? 'rotate' : 'check', true);
                }
            } catch (\Throwable $e) {
                (new Diagnostics([]))->record('UPSTREAM_SCHEDULE_FAILED', ['source_id' => $id] + Diagnostics::errorSite($e), true);
            }
        }
    }

    private static function rotationDue(array $source, array $s): bool
    {
        $c = $s['config'] ?? [];
        if (AccountStore::suspended($s) || empty($c['rotation_verified']) || empty($c['sync_subscription']) || !$source['group_ids'] || !$source['targets'] || time() - ($s['rotation_at'] ?? 0) < 3600) {
            return false;
        }
        if ($c['rotation_mode'] === 'interval') {
            return time() - ($s['rotation_at'] ?? $s['rotation_anchor'] ?? time()) >= $c['rotation_days'] * 86400;
        }
        if ($c['rotation_mode'] === 'user_expiry') {
            return User::query()->whereIn('group_id', $source['group_ids'])
                ->where('expired_at', '>', $s['expiry_cursor'] ?? time())
                ->where('expired_at', '<=', time())->exists();
        }
        return false;
    }

    public static function refreshed(string $id, string $target, array $source, array $result): void
    {
        AccountStore::locked($id, function () use ($id, $target, $source, $result) {
            $current = self::source($id);
            $s = AccountStore::reconcileTargets($id, $current['targets']);
            if (!isset($s['config']) || $current['url'] !== $source['url'] || !in_array($target, $s['refresh_pending'] ?? [], true)) {
                return;
            }
            if (empty($result['error']) || $result['error'] === 'NO_COMPATIBLE_NODES') {
                $s['refresh_pending'] = array_values(array_diff($s['refresh_pending'], [$target]));
                AccountStore::write($id, $s);
                if (!$s['refresh_pending']) {
                    Notifier::enqueue($id, 'refreshed:'.($s['rotation_at'] ?? $s['checked_at'] ?? time()), '订阅桥接：'.$source['name'].'\n新订阅各格式已刷新；不支持的协议按转换器能力跳过。');
                }
            }
        });
    }
}
