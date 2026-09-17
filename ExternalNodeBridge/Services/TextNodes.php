<?php

namespace Plugin\ExternalNodeBridge\Services;

/** Preserve converted node syntax; never rebuild protocol-specific credentials. */
final class TextNodes
{
    public static function extract(string $body, string $target): array
    {
        if ($target === 'quanx' && !str_contains($body, '=')) {
            $body = base64_decode(trim($body), true) ?: '';
        }
        $nodes = [];
        $section = null;
        foreach (preg_split('/\r?\n/', trim($body)) as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^[#;]/', $line)) {
                continue;
            }
            if (preg_match('/^\[([^]]+)\]$/', $line, $m)) {
                $section = strtolower($m[1]);
                continue;
            }
            if ($section !== null && $section !== ($target === 'quanx' ? 'server_local' : 'proxy')) {
                throw new BridgeException('OUTPUT_INVALID');
            }
            if (preg_match('/(?:policy-path|server_remote|include-all-proxies|underlying-proxy|dialer-proxy)\s*=/i', $line)) {
                throw new BridgeException('PROVIDER_OUTPUT_REJECTED');
            }
            if ($target === 'quanx') {
                if (!preg_match('/^([a-z0-9-]+)\s*=/i', $line, $m) || !preg_match('/(?:^|,)\s*tag\s*=\s*([^,]+)$/i', $line, $n)) {
                    throw new BridgeException('NODE_INVALID');
                }
                $type = $m[1];
                $name = trim($n[1], ' "');
            } else {
                if (!preg_match('/^(.+?)\s*=\s*([a-z0-9-]+)\s*,/i', $line, $m)) {
                    throw new BridgeException('NODE_INVALID');
                }
                $name = trim($m[1], ' "');
                $type = $m[2];
            }
            if (!in_array(strtolower($type), ['ss','shadowsocks','ssr','shadowsocksr','vmess','vless','trojan','hysteria','hysteria2','tuic','tuic-v5','snell','socks5','socks5-tls','http','https','anytls','wireguard'], true)) {
                throw new BridgeException('NODE_INVALID');
            }
            if ($name === '' || strlen($name) > 1024 || preg_match('/[\x00-\x1f,=]/', $name)) {
                throw new BridgeException('NODE_INVALID');
            }
            $nodes[] = ['name' => $name, 'type' => strtolower($type), 'line' => $line];
        }
        return $nodes;
    }

    public static function merge(string $original, string $target, array $batches, array $settings, array $query): array
    {
        $ini = in_array($target, ['surge', 'surfboard'], true);
        $base = $target === 'quanx' ? base64_decode(preg_replace('/\s/', '', $original), true) : $original;
        if ($base === false) {
            throw new BridgeException('BASE_CONFIG_INVALID');
        }
        $lines = preg_split('/\r?\n/', $base);
        $section = null;
        $used = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*\[([^]]+)\]\s*$/', $line, $m)) {
                $section = strtolower($m[1]);
                continue;
            }
            if (!$ini || in_array($section, ['proxy', 'proxy group'], true)) {
                if ($target === 'quanx') {
                    if (preg_match('/,\s*tag\s*=\s*(.+)$/', $line, $m)) {
                        $used[trim($m[1], ' "')] = true;
                    }
                } elseif (str_contains($line, '=')) {
                    $used[trim(explode('=', $line, 2)[0], ' "')] = true;
                }
            }
        }
        $added = [];
        $names = [];
        $skipped = 0;
        foreach ($batches as $batch) {
            foreach ($batch['nodes'] as $node) {
                if (!Merger::accepts($node, $target, $query)) {
                    $skipped++;
                    continue;
                }
                $name = trim(($batch['prefix'] ?? '['.$batch['source_id'].']').' '.$node['name']);
                if (preg_match('/[\x00-\x1f,="\\\\]/', $name)) {
                    $skipped++;
                    continue;
                }
                $baseName = $name;
                $i = 2;
                while (isset($used[$name])) {
                    $name = $baseName.' ('.$i++.')';
                }
                $used[$name] = true;
                $names[] = $name;
                $added[] = $target === 'quanx'
                    ? preg_replace_callback('/(,\s*tag\s*=\s*)[^,]+$/i', fn ($m) => $m[1].$name, $node['line'])
                    : $name.' = '.ltrim(explode('=', $node['line'], 2)[1]);
            }
        }
        if (!$added) {
            return ['body' => $original, 'added' => 0, 'skipped' => $skipped];
        }
        if (count($used) > Merger::MAX_NODES) {
            throw new BridgeException('TOO_MANY_NODES');
        }
        if ($ini) {
            $output = [];
            $section = null;
            $inserted = false;
            $groups = 0;
            foreach ($lines as $line) {
                if (preg_match('/^\s*\[([^]]+)\]\s*$/', $line, $m)) {
                    if ($section === 'proxy' && !$inserted) {
                        $output = array_merge($output, $added);
                        $inserted = true;
                    }
                    $section = strtolower($m[1]);
                } elseif ($section === 'proxy group' && preg_match('/^\s*([^=]+?)\s*=\s*(select|url-test|fallback|load-balance)\s*(,.*)?$/i', $line, $m)) {
                    if (!$settings['ini_groups'] || in_array(trim($m[1]), $settings['ini_groups'], true)) {
                        $line = $m[1].' = '.$m[2].', '.implode(', ', $names).($m[3] ?? '');
                        $groups++;
                    }
                }
                $output[] = $line;
            }
            if ($section === 'proxy' && !$inserted) {
                $output = array_merge($output, $added);
                $inserted = true;
            }
            if (!$inserted || !$groups) {
                throw new BridgeException('NO_DESTINATION_GROUP');
            }
            $body = implode("\n", $output);
        } else {
            $body = rtrim($base)."\r\n".implode("\r\n", $added)."\r\n";
            if ($target === 'quanx') {
                $body = base64_encode($body);
            }
        }
        if (strlen($body) > Merger::MAX_BYTES) {
            throw new BridgeException('RESPONSE_TOO_LARGE');
        }
        return ['body' => $body, 'added' => count($added), 'skipped' => $skipped];
    }
}
