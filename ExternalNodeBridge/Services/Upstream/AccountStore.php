<?php

namespace Plugin\ExternalNodeBridge\Services\Upstream;

use Plugin\ExternalNodeBridge\Services\{BridgeException, PrivateStore, Settings};

final class AccountStore
{
    public static function defaults(): array
    {
        return ['enabled' => false, 'adapter' => 'auto', 'panel_url' => '', 'api_url' => '',
            'auth_mode' => 'password', 'email' => '', 'password' => '', 'authorization' => '', 'cookie' => '',
            'check_interval' => 21600, 'remind_days' => 7, 'sync_subscription' => true,
            'rotation_mode' => 'manual', 'rotation_days' => 7, 'rotation_verified' => false];
    }

    public static function name(string $id): string
    {
        if (!preg_match('/^[a-z0-9_-]{1,32}$/D', $id)) {
            throw new BridgeException('SOURCE_ID_INVALID');
        }
        return 'upstream-'.$id;
    }

    public static function read(string $id): array
    {
        return PrivateStore::read(self::name($id));
    }

    public static function write(string $id, array $state): void
    {
        PrivateStore::write(self::name($id), $state);
    }

    /** Caller holds the source lock; cancelled formats are not completed refreshes. */
    public static function reconcileTargets(string $id, array $targets): array
    {
        $state = self::read($id);
        $pending = $state['refresh_pending'] ?? [];
        $active = array_values(array_intersect($pending, $targets));
        if ($active !== $pending) {
            $state['refresh_pending'] = $active;
            self::write($id, $state);
        }
        return $state;
    }

    public static function locked(string $id, callable $callback): mixed
    {
        return PrivateStore::locked(self::name($id), $callback);
    }

    /** Acquire source locks in a stable order before a multi-source settings change. */
    public static function lockedMany(array $ids, callable $callback): mixed
    {
        $ids = array_values(array_unique($ids));
        sort($ids);
        $next = function (int $index) use (&$next, $ids, $callback) {
            return isset($ids[$index]) ? self::locked($ids[$index], fn () => $next($index + 1)) : $callback();
        };
        return $next(0);
    }

    public static function publicUrl(string $url, bool $https = false): bool
    {
        if (!Settings::httpUrl($url) || strlen($url) > 4096 || ($https && parse_url($url, PHP_URL_SCHEME) !== 'https')) {
            return false;
        }
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if ($host === 'localhost' || !str_contains($host, '.') || preg_match('/\.(?:localhost|local|internal)$/', $host)) {
            return false;
        }
        return !filter_var($host, FILTER_VALIDATE_IP) || (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    public static function normalize(array $input, array $previous = []): array
    {
        // Nullable form fields follow Laravel conventions; structured values remain invalid.
        foreach (self::defaults() as $key => $default) {
            if (is_string($default) && array_key_exists($key, $input) && $input[$key] === null) {
                $input[$key] = '';
            }
        }
        $c = array_replace(self::defaults(), $previous, array_intersect_key($input, self::defaults()));
        // Empty password/header inputs retain the existing value, only within the same endpoint/account.
        foreach (['password', 'authorization', 'cookie'] as $key) {
            if (($input[$key] ?? null) === '' && isset($previous[$key])) {
                $c[$key] = $previous[$key];
            }
            if (!is_string($c[$key]) || strlen($c[$key]) > 16384) {
                throw new BridgeException('UPSTREAM_CREDENTIAL_INVALID');
            }
            if (preg_match('/[\x00\r\n]/', $c[$key])) {
                throw new BridgeException('UPSTREAM_CREDENTIAL_CONTROL_CHARACTERS');
            }
        }
        if (!in_array($c['adapter'], ['auto', 'v2board'], true) || !in_array($c['auth_mode'], ['password', 'token', 'cookie'], true)) {
            throw new BridgeException('UPSTREAM_ADAPTER_UNSUPPORTED');
        }
        if (!is_string($c['panel_url']) || !is_string($c['api_url']) || !is_string($c['email'])) {
            throw new BridgeException('UPSTREAM_CONFIG_INVALID');
        }
        $panel = parse_url(trim($c['panel_url']));
        $origin = ($panel['scheme'] ?? '').'://'.($panel['host'] ?? '').(isset($panel['port']) ? ':'.$panel['port'] : '');
        if (!self::publicUrl($origin, true) || isset($panel['user']) || isset($panel['pass'])) {
            throw new BridgeException('UPSTREAM_URL_INVALID');
        }
        $c['panel_url'] = $origin;
        $c['api_url'] = rtrim(trim($c['api_url']) ?: $origin.'/api/v1', '/');
        if (!self::publicUrl($c['api_url'], true) || parse_url($c['api_url'], PHP_URL_QUERY) || !str_starts_with($c['api_url'], $origin.'/') || !str_ends_with($c['api_url'], '/api/v1')) {
            throw new BridgeException('UPSTREAM_URL_INVALID');
        }
        foreach (['password', 'authorization', 'cookie'] as $key) {
            if ($previous && (($previous['api_url'] ?? '') !== $c['api_url'] || ($previous['email'] ?? '') !== trim($c['email']) || ($previous['auth_mode'] ?? '') !== $c['auth_mode']) && empty($input[$key])) {
                $c[$key] = '';
            }
        }
        $c['email'] = trim($c['email']);
        if (($c['auth_mode'] === 'password' && (!filter_var($c['email'], FILTER_VALIDATE_EMAIL) || $c['password'] === '')) || ($c['auth_mode'] === 'token' && $c['authorization'] === '') || ($c['auth_mode'] === 'cookie' && $c['cookie'] === '')) {
            throw new BridgeException('UPSTREAM_CREDENTIAL_REQUIRED');
        }
        foreach (['password' => 'password', 'authorization' => 'token', 'cookie' => 'cookie'] as $key => $mode) {
            if ($c['auth_mode'] !== $mode) {
                $c[$key] = '';
            }
        }
        foreach (['enabled', 'sync_subscription', 'rotation_verified'] as $key) {
            $c[$key] = filter_var($c[$key], FILTER_VALIDATE_BOOLEAN);
        }
        $c['check_interval'] = max(3600, min(86400, (int) $c['check_interval']));
        $c['remind_days'] = max(1, min(30, (int) $c['remind_days']));
        $c['rotation_days'] = max(1, min(90, (int) $c['rotation_days']));
        if (!in_array($c['rotation_mode'], ['manual', 'interval', 'user_expiry'], true)) {
            throw new BridgeException('UPSTREAM_CONFIG_INVALID');
        }
        if ($c['rotation_mode'] !== 'manual' && (!$c['rotation_verified'] || !$c['sync_subscription'])) {
            throw new BridgeException('UPSTREAM_ROTATION_OPT_IN_REQUIRED');
        }
        return $c;
    }

    public static function save(string $id, array $input, ?string $revision): array
    {
        return self::locked($id, function () use ($id, $input, $revision) {
            AccountManager::source($id);
            $old = self::read($id);
            if (($old['revision'] ?? null) !== $revision) {
                throw new BridgeException('UPSTREAM_CONFIG_CHANGED');
            }
            $config = self::normalize($input, $old['config'] ?? []);
            $oldConfig = $old['config'] ?? [];
            $credentialsChanged = array_intersect_key($oldConfig, array_flip(['api_url', 'auth_mode', 'email', 'password', 'authorization', 'cookie'])) !== array_intersect_key($config, array_flip(['api_url', 'auth_mode', 'email', 'password', 'authorization', 'cookie']));
            if ($credentialsChanged && self::suspended($old)) {
                throw new BridgeException('UPSTREAM_ROTATION_PENDING');
            }
            $state = $credentialsChanged ? [] : $old;
            $state['config'] = $config;
            $state['revision'] = bin2hex(random_bytes(12));
            if ($credentialsChanged || !($oldConfig['enabled'] ?? false) || ($oldConfig['rotation_mode'] ?? '') !== $config['rotation_mode']) {
                $state['expiry_cursor'] = time();
                $state['rotation_anchor'] = time();
            }
            $state['next_check_at'] = 0;
            // Saving alone never resumes an edge block or repeats an uncertain reset.
            self::write($id, $state);
            return self::summary($id, $state);
        });
    }

    public static function summary(string $id, ?array $state = null, bool $diagnostic = false): array
    {
        $s = $state ?? self::read($id);
        $config = $s['config'] ?? null;
        if ($config) {
            foreach (['password', 'authorization', 'cookie'] as $key) {
                $config['has_'.$key] = $config[$key] !== '';
                unset($config[$key]);
            }
        }
        $result = ['source_id' => $id, 'configured' => $config !== null, 'revision' => $s['revision'] ?? null,
            'config' => $config, 'paused' => (bool) ($s['paused'] ?? false), 'error' => $s['error'] ?? null,
            'http_status' => $s['http_status'] ?? null, 'checked_at' => $s['checked_at'] ?? null,
            'probe_at' => $s['probe_at'] ?? null,
            'last_step' => $s['last_step'] ?? null,
            'attempted_at' => $s['attempted_at'] ?? null, 'next_check_at' => $s['next_check_at'] ?? null,
            'rotation_at' => $s['rotation_at'] ?? null, 'rotation_phase' => $s['rotation']['phase'] ?? null,
            'suspended' => self::suspended($s), 'info' => array_intersect_key($s['info'] ?? [], array_flip(['family', 'response_style', 'expired_at', 'used_bytes', 'total_bytes', 'remaining_bytes', 'reset_day', 'next_reset_at'])),
            'credential_changed' => $s['rotation']['credential_changed'] ?? null,
            'refresh_pending' => $s['refresh_pending'] ?? []];
        if ($diagnostic) {
            unset($result['config'], $result['revision'], $result['info']);
        }
        return $result;
    }

    public static function suspended(array $state): bool
    {
        return isset($state['rotation']['phase']) && $state['rotation']['phase'] !== 'complete';
    }

    public static function remove(string $id): void
    {
        self::locked($id, function () use ($id) {
            if (self::suspended(self::read($id))) {
                throw new BridgeException('UPSTREAM_ROTATION_PENDING');
            }
            self::write($id, []);
        });
    }
}
