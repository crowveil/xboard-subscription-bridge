<?php

namespace Plugin\ExternalNodeBridge\Services;

use App\Models\Plugin;
use App\Services\Plugin\PluginConfigService;

final class Settings
{
    public const CODE = 'external_node_bridge';
    public const TARGETS = ['mihomo', 'shadowrocket', 'singbox', 'stash', 'surge', 'surfboard', 'loon', 'quanx', 'mixed', 'sssub'];

    public static function defaults(): array
    {
        return ['converter_url' => 'http://subconverter-extended:25500', 'upstream_user_agent' => 'clash.meta',
            'cache_revision' => 'nodes-v2', 'debug' => false, 'debug_until' => 0, 'sources' => [],
            'retired_provider_keys' => [], 'timeout' => 15, 'max_stale' => 86400];
    }

    public static function requireEnabled(): void
    {
        if (!Plugin::query()->where('code', self::CODE)->where('is_enabled', true)->exists()) {
            throw new BridgeException('PLUGIN_DISABLED');
        }
    }

    public static function load(): array
    {
        $row = Plugin::query()->where('code', self::CODE)->first();
        if (!$row || !$row->is_enabled) {
            throw new BridgeException('PLUGIN_DISABLED');
        }
        $raw = self::raw($row);
        return PrivateStore::locked('settings', function () use ($raw) {
            $stored = PrivateStore::read('settings');
            // Private storage owns source settings; the native form owns its declared fields.
            if (!$stored) {
                $stored = self::normalize($raw);
                PrivateStore::write('settings', $stored);
            }
            return self::normalize(array_replace($stored, array_intersect_key($raw, ['converter_url' => true])));
        });
    }

    public static function raw(?Plugin $row = null): array
    {
        $row ??= Plugin::query()->where('code', self::CODE)->first();
        $raw = $row ? (is_array($row->config) ? $row->config : json_decode($row->config ?: '{}', true)) : [];
        return is_array($raw) ? $raw : [];
    }

    public static function normalize(array $raw): array
    {
        $defaults = self::defaults();
        if (!isset($raw['retired_provider_keys']) && isset($raw['remove_provider_keys'])) {
            $raw['retired_provider_keys'] = $raw['remove_provider_keys'];
        }
        $c = array_replace($defaults, array_intersect_key($raw, $defaults));
        foreach (['sources', 'retired_provider_keys'] as $key) {
            if (is_string($c[$key])) {
                $c[$key] = json_decode($c[$key], true);
            }
            if (!is_array($c[$key]) || !array_is_list($c[$key])) {
                throw new BridgeException('CONFIG_INVALID');
            }
        }
        foreach (['retired_provider_keys'] as $key) {
            if (count($c[$key]) > 100) {
                throw new BridgeException('CONFIG_INVALID');
            }
            foreach ($c[$key] as $value) {
                if (!is_string($value) || $value === '' || strlen($value) > 256) {
                    throw new BridgeException('CONFIG_INVALID');
                }
            }
            $c[$key] = array_values(array_unique($c[$key]));
        }
        if (!is_string($c['converter_url']) || !self::httpUrl($c['converter_url']) || parse_url($c['converter_url'], PHP_URL_QUERY)) {
            throw new BridgeException('CONVERTER_URL_INVALID');
        }
        $c['converter_url'] = rtrim($c['converter_url'], '/');
        if (!is_string($c['upstream_user_agent']) || !preg_match('/^[\x20-\x7e]{1,256}$/D', $c['upstream_user_agent']) || trim($c['upstream_user_agent']) === '') {
            throw new BridgeException('UPSTREAM_UA_INVALID');
        }
        $c['upstream_user_agent'] = trim($c['upstream_user_agent']);
        $c['debug'] = filter_var($c['debug'], FILTER_VALIDATE_BOOLEAN);
        $c['debug_until'] = max(0, (int) $c['debug_until']);
        $c['timeout'] = max(2, min(30, (int) $c['timeout']));
        $c['max_stale'] = max(300, min(604800, (int) $c['max_stale']));
        if (!is_string($c['cache_revision']) || !preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $c['cache_revision'])) {
            throw new BridgeException('CONFIG_INVALID');
        }
        if (count($c['sources']) > 20) {
            throw new BridgeException('TOO_MANY_SOURCES');
        }
        $seen = [];
        foreach ($c['sources'] as &$s) {
            if (!is_array($s) || !isset($s['id']) || !is_string($s['id']) || !preg_match('/^[a-z0-9_-]{1,32}$/D', $s['id']) || isset($seen[$s['id']])) {
                throw new BridgeException('SOURCE_ID_INVALID');
            }
            $seen[$s['id']] = true;
            if (!isset($s['url']) || !is_string($s['url']) || !self::httpUrl($s['url']) || strlen($s['url']) > 4096) {
                throw new BridgeException('SOURCE_URL_INVALID');
            }
            $groups = $s['group_ids'] ?? [];
            if (!is_array($groups) || !array_is_list($groups)) {
                throw new BridgeException('GROUPS_INVALID');
            }
            foreach ($groups as $g) {
                if (!is_scalar($g) || !ctype_digit((string) $g) || (int) $g < 1) {
                    throw new BridgeException('GROUPS_INVALID');
                }
            }
            $targets = $s['targets'] ?? self::TARGETS;
            if (!is_array($targets) || !array_is_list($targets) || array_diff($targets, self::TARGETS)) {
                throw new BridgeException('TARGET_INVALID');
            }
            $prefix = (string) ($s['prefix'] ?? '['.$s['id'].']');
            if (mb_strlen($prefix) > 80 || preg_match('/[\x00-\x1f,="\\\\]/', $prefix)) {
                throw new BridgeException('PREFIX_INVALID');
            }
            $s = [
                'id' => $s['id'], 'name' => mb_substr((string) ($s['name'] ?? $s['id']), 0, 80),
                'prefix' => $prefix,
                'url' => $s['url'], 'group_ids' => array_values(array_unique(array_map('strval', $groups))),
                'targets' => array_values(array_unique($targets)),
                'enabled' => filter_var($s['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'interval' => max(300, min(86400, (int) ($s['interval'] ?? 3600))),
            ];
        }
        unset($s);
        return $c;
    }

    public static function authorized(array $c, mixed $groupId, string $target): array
    {
        if ($groupId === null) {
            return [];
        }
        return array_values(array_filter($c['sources'], fn ($s) => $s['enabled']
            && in_array((string) $groupId, $s['group_ids'], true)
            && in_array($target, $s['targets'], true)));
    }

    public static function revision(array $c): string
    {
        return hash_hmac('sha256', json_encode(self::normalize($c)), (string) config('app.key'));
    }

    public static function save(array $c, ?string $expectedRevision = null): void
    {
        $c = self::normalize($c);
        PrivateStore::locked('settings', function () use ($c, $expectedRevision) {
            $current = self::normalize(array_replace(PrivateStore::read('settings'), array_intersect_key(self::raw(), ['converter_url' => true])));
            if ($expectedRevision !== null && !hash_equals(self::revision($current), $expectedRevision)) {
                throw new BridgeException('SETTINGS_CHANGED');
            }
            PrivateStore::write('settings', $c);
        });
        // Preserve access state; saving the console can never reopen it.
        $raw = self::raw();
        $raw['converter_url'] = $c['converter_url'];
        app(PluginConfigService::class)->updateConfig(self::CODE, $raw);
    }

    public static function replaceSourceUrl(string $id, string $expectedUrl, string $url): void
    {
        PrivateStore::locked('settings', function () use ($id, $expectedUrl, $url) {
            $c = self::normalize(PrivateStore::read('settings'));
            foreach ($c['sources'] as &$source) {
                if ($source['id'] !== $id) {
                    continue;
                }
                if ($source['url'] !== $expectedUrl) {
                    throw new BridgeException('SETTINGS_CHANGED');
                }
                $source['url'] = $url;
                PrivateStore::write('settings', self::normalize($c));
                return;
            }
            throw new BridgeException('SOURCE_NOT_FOUND');
        });
    }

    public static function httpUrl(string $url): bool
    {
        $p = parse_url($url);
        return $p !== false && in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)
            && !empty($p['host']) && !isset($p['user']) && !isset($p['pass']) && !isset($p['fragment'])
            && !preg_match('/[\x00-\x20|]/', $url);
    }
}
