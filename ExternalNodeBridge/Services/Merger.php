<?php

namespace Plugin\ExternalNodeBridge\Services;

use Symfony\Component\Yaml\Yaml;

final class Merger
{
    public const MAX_BYTES = 4194304;
    public const MAX_NODES = 3000;

    public static function target(string $protocolClass): ?string
    {
        // Use XBoard's actual selected generator, never independently guess a UA.
        return match ($protocolClass) {
            'App\\Protocols\\ClashMeta', 'App\\Protocols\\Clash' => 'mihomo',
            'App\\Protocols\\Shadowrocket' => 'shadowrocket',
            'App\\Protocols\\SingBox' => 'singbox',
            'App\\Protocols\\Stash' => 'stash',
            'App\\Protocols\\Surge' => 'surge',
            'App\\Protocols\\Surfboard' => 'surfboard',
            'App\\Protocols\\Loon' => 'loon',
            'App\\Protocols\\QuantumultX' => 'quanx',
            'App\\Protocols\\General' => 'mixed',
            'App\\Protocols\\Shadowsocks' => 'sssub',
            default => null,
        };
    }

    private static function yaml(string $target): bool
    {
        return in_array($target, ['mihomo', 'stash'], true);
    }

    public static function extract(string $body, string $target): array
    {
        if (strlen($body) > self::MAX_BYTES) {
            throw new BridgeException('RESPONSE_TOO_LARGE');
        }
        try {
            if (in_array($target, ['shadowrocket', 'mixed'], true)) {
                $nodes = self::uriLines($body, false);
            } elseif (in_array($target, ['surge', 'surfboard', 'loon', 'quanx'], true)) {
                $nodes = TextNodes::extract($body, $target);
            } elseif ($target === 'sssub') {
                $config = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
                $nodes = $config['servers'] ?? $config;
                if (!is_array($nodes) || !array_is_list($nodes)) {
                    throw new BridgeException('OUTPUT_INVALID');
                }
                foreach ($nodes as $node) {
                    if (!is_array($node) || !isset($node['server'], $node['server_port'], $node['method'], $node['password']) || !is_string($node['remarks'] ?? '')) {
                        throw new BridgeException('NODE_INVALID');
                    }
                }
            } else {
                $config = self::yaml($target) ? Yaml::parse($body, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE) : json_decode($body, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($config)) {
                    throw new BridgeException('OUTPUT_INVALID');
                }
                if (!empty($config['proxy-providers'])) {
                    throw new BridgeException('PROVIDER_OUTPUT_REJECTED');
                }
                $nodes = $config[self::yaml($target) ? 'proxies' : 'outbounds'] ?? (array_is_list($config) ? $config : null);
                if (!is_array($nodes) || !array_is_list($nodes)) {
                    throw new BridgeException('OUTPUT_INVALID');
                }
                $nodes = array_values(array_filter($nodes, function ($n) use ($target) {
                    if (!is_array($n) || !is_string($n['type'] ?? null)) {
                        throw new BridgeException('NODE_INVALID');
                    }
                    if (in_array($n['type'], ['direct', 'block', 'dns', 'selector', 'urltest', 'reject', 'select', 'url-test', 'fallback', 'load-balance'], true)) {
                        return false;
                    }
                    $name = $n[self::yaml($target) ? 'name' : 'tag'] ?? null;
                    if (!is_string($name) || $name === '' || strlen($name) > 1024 || preg_match('/[\x00-\x1f]/', $name)) {
                        throw new BridgeException('NODE_INVALID');
                    }
                    return true;
                }));
            }
        } catch (BridgeException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new BridgeException('OUTPUT_INVALID');
        }
        if (count($nodes) > self::MAX_NODES) {
            throw new BridgeException('TOO_MANY_NODES');
        }
        if (!$nodes) {
            throw new BridgeException('NO_COMPATIBLE_NODES');
        }
        return $nodes;
    }

    public static function uriLines(string $body, bool $allowStatus): array
    {
        $raw = trim($body);
        if ($raw === '') {
            return [];
        }
        if (!preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $raw) && !str_starts_with($raw, 'STATUS=')) {
            $raw = base64_decode(strtr(preg_replace('/\s+/', '', $raw), '-_', '+/'), true);
            if ($raw === false) {
                throw new BridgeException('URI_OUTPUT_INVALID');
            }
        }
        $lines = preg_split('/\r?\n/', trim($raw));
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($allowStatus && str_starts_with($line, 'STATUS=')) {
                $out[] = $line;
                continue;
            }
            if (str_starts_with($line, 'STATUS=')) {
                continue;
            }
            if (!preg_match('/^(ss|ssr|vmess|vless|trojan|hysteria|hysteria2|hy2|tuic|anytls|socks|socks5|http|https|wireguard|snell|mieru|mierus):\/\/\S+$/iD', $line)) {
                throw new BridgeException('URI_OUTPUT_INVALID');
            }
            $out[] = $line;
        }
        return array_values(array_unique($out));
    }

    private static function type(string $type): string
    {
        return match (strtolower($type)) {
            'ss' => 'shadowsocks', 'hysteria2', 'hy2' => 'hysteria', 'socks5' => 'socks',
            default => strtolower($type),
        };
    }

    public static function accepts(array|string $node, string $target, array $query): bool
    {
        $type = is_array($node) ? ($node['type'] ?? ($target === 'sssub' ? 'ss' : '')) : explode('://', $node, 2)[0];
        $rawTypes = $query['types'] ?? '';
        if (is_string($rawTypes) && $rawTypes !== '' && $rawTypes !== 'all') {
            $types = array_map('trim', preg_split('/[|,｜]+/u', $rawTypes));
            if (!in_array(self::type($type), $types, true)) {
                return false;
            }
        }
        $filter = $query['filter'] ?? '';
        if (!is_string($filter) || $filter === '' || mb_strlen($filter) > 20) {
            return true;
        }
        $name = is_array($node) ? ($node['name'] ?? $node['tag'] ?? $node['remarks'] ?? '') : self::uriName($node);
        foreach (preg_split('/[|,｜]+/u', $filter) as $word) {
            if (trim($word) !== '' && mb_stripos($name, trim($word)) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function uriName(string $uri): string
    {
        if (str_starts_with($uri, 'vmess://')) {
            $data = json_decode(base64_decode(substr($uri, 8), true) ?: '', true);
            if (is_array($data)) {
                return (string) ($data['ps'] ?? '');
            }
        }
        if (str_starts_with($uri, 'ssr://')) {
            $raw = base64_decode(strtr(substr($uri, 6), '-_', '+/'), true) ?: '';
            parse_str(explode('?', $raw, 2)[1] ?? '', $args);
            return base64_decode(strtr($args['remarks'] ?? '', '-_', '+/'), true) ?: '';
        }
        return rawurldecode(explode('#', $uri, 2)[1] ?? '');
    }

    private static function renameUri(string $uri, string $prefix): string
    {
        if ($prefix === '') {
            return $uri;
        }
        $name = trim($prefix.' '.self::uriName($uri));
        if (str_starts_with($uri, 'vmess://')) {
            $data = json_decode(base64_decode(substr($uri, 8), true) ?: '', true);
            if (!is_array($data)) {
                throw new BridgeException('URI_OUTPUT_INVALID');
            }
            $data['ps'] = $name;
            return 'vmess://'.base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        if (str_starts_with($uri, 'ssr://')) {
            $raw = base64_decode(strtr(substr($uri, 6), '-_', '+/'), true);
            if ($raw === false) {
                throw new BridgeException('URI_OUTPUT_INVALID');
            }
            $value = rtrim(strtr(base64_encode($name), '+/', '-_'), '=');
            if (preg_match('/([?&])remarks=[^&]*/', $raw)) {
                $raw = preg_replace_callback('/([?&])remarks=[^&]*/', fn ($m) => $m[1].'remarks='.$value, $raw);
            } else {
                $raw .= (str_contains($raw, '?') ? '&' : '/?').'remarks='.$value;
            }
            return 'ssr://'.rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        }
        return explode('#', $uri, 2)[0].'#'.rawurlencode($name);
    }

    /** $batches contain only sources authorized for the current request. */
    public static function merge(string $original, string $target, array $batches, array $settings, array $query = []): array
    {
        if (strlen($original) > self::MAX_BYTES) {
            throw new BridgeException('RESPONSE_TOO_LARGE');
        }
        if (in_array($target, ['surge', 'surfboard', 'loon', 'quanx'], true)) {
            return TextNodes::merge($original, $target, $batches, $settings, $query);
        }
        if ($target === 'sssub') {
            return self::mergeSip008($original, $batches, $query);
        }
        if (in_array($target, ['shadowrocket', 'mixed'], true)) {
            $lines = self::uriLines($original, true);
            $originalCount = count($lines);
            $skipped = 0;
            foreach ($batches as $batch) {
                foreach ($batch['nodes'] as $line) {
                    if (!self::accepts($line, $target, $query)) {
                        $skipped++;
                        continue;
                    }
                    $lines[] = self::renameUri($line, $batch['prefix'] ?? '');
                }
            }
            $lines = array_values(array_unique($lines));
            if (count($lines) > self::MAX_NODES) {
                throw new BridgeException('TOO_MANY_NODES');
            }
            $body = base64_encode(implode("\r\n", $lines)."\r\n");
            if (strlen($body) > self::MAX_BYTES) {
                throw new BridgeException('RESPONSE_TOO_LARGE');
            }
            return ['body' => $body, 'added' => count($lines) - $originalCount, 'skipped' => $skipped];
        }
        throw new BridgeException('TEMPLATE_RENDER_REQUIRED');
    }

    private static function mergeSip008(string $original, array $batches, array $query): array
    {
        $config = json_decode($original, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($config['servers'] ?? null)) {
            throw new BridgeException('BASE_CONFIG_INVALID');
        }
        $count = 0;
        $skipped = 0;
        foreach ($batches as $batch) {
            foreach ($batch['nodes'] as $node) {
                if (!self::accepts($node, 'sssub', $query)) {
                    $skipped++;
                    continue;
                }
                $node['remarks'] = trim(($batch['prefix'] ?? '['.$batch['source_id'].']').' '.($node['remarks'] ?? 'SS'));
                $node['id'] = 'ext-'.substr(hash('sha256', $batch['source_id'].json_encode($node)), 0, 24);
                $config['servers'][] = $node;
                $count++;
            }
        }
        if (count($config['servers']) > self::MAX_NODES) {
            throw new BridgeException('TOO_MANY_NODES');
        }
        $body = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($body) > self::MAX_BYTES) {
            throw new BridgeException('RESPONSE_TOO_LARGE');
        }
        return ['body' => $body, 'added' => $count, 'skipped' => $skipped];
    }
}
