<?php

namespace Plugin\ExternalNodeBridge\Services;

use App\Http\Controllers\V1\Client\ClientController;
use Illuminate\Http\Request;
use Symfony\Component\Yaml\Yaml;

/** Native templates organize names; converter payloads supply connection details. */
final class TemplateBridge
{
    private const CONTEXT = 'external_node_bridge.layout';

    public static function supports(string $target): bool
    {
        return in_array($target, ['mihomo', 'stash', 'singbox', 'surge', 'surfboard'], true);
    }

    /** Registered once, with all mutable state owned by the current request. */
    public static function inject(array $servers): array
    {
        $context = request()->attributes->get(self::CONTEXT);
        if (!$context instanceof \stdClass || $context->calls !== 0) {
            return $servers;
        }
        $context->calls++;
        return array_merge($servers, $context->carriers);
    }

    private static function template(string $target): mixed
    {
        $key = match ($target) {
            'mihomo' => str_ends_with(app('protocols.manager')->matchProtocolClassName(strtolower(request()->input('flag') ?? request()->header('User-Agent', ''))) ?? '', '\\Clash') ? 'clash' : 'clashmeta',
            default => $target,
        };
        $raw = subscribe_template($key);
        return match ($target) {
            'mihomo', 'stash' => Yaml::parse($raw, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE),
            'singbox' => is_array($raw) ? $raw : json_decode($raw, true, 64, JSON_THROW_ON_ERROR),
            default => $raw,
        };
    }

    /** Protected provider references must not bypass source authorization. */
    public static function checkTemplate(string $target, array $settings): void
    {
        if (!in_array($target, ['mihomo', 'stash'], true) || empty($settings['retired_provider_keys'])) {
            return;
        }
        $config = self::template($target);
        $keys = array_keys($config['proxy-providers'] ?? []);
        foreach ($config['proxy-groups'] ?? [] as $group) {
            $keys = array_merge($keys, $group['use'] ?? []);
        }
        if (array_intersect($keys, $settings['retired_provider_keys'])) {
            throw new BridgeException('TEMPLATE_PROVIDER_REVIEW_REQUIRED');
        }
    }

    private static function usedNames(string $body, string $target): array
    {
        $used = array_fill_keys(['DIRECT', 'REJECT', 'REJECT-DROP', 'PASS', 'COMPATIBLE', 'GLOBAL'], true);
        if (in_array($target, ['surge', 'surfboard'], true)) {
            foreach ([$body, self::template($target)] as $text) {
                if (preg_match_all('/^\s*([^\r\n=]+?)\s*=/m', $text, $m)) {
                    foreach ($m[1] as $name) {
                        $used[trim($name, ' "')] = true;
                    }
                }
            }
            return $used;
        }
        $config = $target === 'singbox' ? json_decode($body, true, 64, JSON_THROW_ON_ERROR) : Yaml::parse($body);
        foreach ([$config, self::template($target)] as $document) {
            foreach (array_merge($document['proxies'] ?? [], $document['proxy-groups'] ?? [], $document['outbounds'] ?? []) as $node) {
                $name = $node[$target === 'singbox' ? 'tag' : 'name'] ?? null;
                if (is_string($name)) {
                    $used[$name] = true;
                }
            }
        }
        return $used;
    }

    public static function prepare(string $original, string $target, array $batches, array $query): array
    {
        $used = self::usedNames($original, $target);
        $nameKey = $target === 'singbox' ? 'tag' : 'name';
        $depKey = $target === 'singbox' ? 'detour' : 'dialer-proxy';
        $text = in_array($target, ['surge', 'surfboard'], true);
        $payloads = [];
        $skipped = 0;
        foreach ($batches as $batch) {
            $map = [];
            $candidates = [];
            foreach ($batch['nodes'] as $node) {
                $old = $node[$nameKey];
                if (isset($map[$old])) {
                    throw new BridgeException('DUPLICATE_SOURCE_NAME');
                }
                $base = trim(($batch['prefix'] ?? '['.$batch['source_id'].']').' '.$old);
                $name = $base;
                for ($i = 2; isset($used[$name]); $i++) {
                    $name = $base.' ('.$i.')';
                }
                $map[$old] = $name;
                $used[$name] = true;
                $node[$nameKey] = $name;
                if (!Merger::accepts($node, $target, $query) || ($text && preg_match('/[\x00-\x1f,="\\\\]/', $name)) || isset($node['domain_resolver']) || isset($node['proxy']) || isset($node['download-detour'])) {
                    $skipped++;
                    continue;
                }
                $candidates[$name] = $node;
            }
            foreach ($candidates as $name => &$node) {
                if (isset($node[$depKey])) {
                    if (!is_string($node[$depKey]) || !isset($map[$node[$depKey]])) {
                        unset($candidates[$name]);
                        $skipped++;
                        continue;
                    }
                    $node[$depKey] = $map[$node[$depKey]];
                }
            }
            unset($node);
            foreach ($candidates as $name => $node) {
                $walk = $name;
                $seen = [];
                while (isset($candidates[$walk][$depKey])) {
                    if (isset($seen[$walk]) || !isset($candidates[$candidates[$walk][$depKey]])) {
                        unset($candidates[$name]);
                        $skipped++;
                        break;
                    }
                    $seen[$walk] = true;
                    $walk = $candidates[$walk][$depKey];
                }
            }
            foreach ($candidates as $name => $node) {
                $payloads[$name] = $text ? $name.' = '.ltrim(explode('=', $node['line'], 2)[1]) : $node;
            }
        }
        if (count($used) > Merger::MAX_NODES) {
            throw new BridgeException('TOO_MANY_NODES');
        }
        return [$payloads, $skipped];
    }

    public static function render(Request $request, mixed $user, array $servers, string $original, string $target, array $batches): array
    {
        [$payloads, $skipped] = self::prepare($original, $target, $batches, $request->only(['types', 'filter']));
        if (!$payloads) {
            return ['body' => $original, 'added' => 0, 'skipped' => $skipped];
        }
        $marker = 'enb'.bin2hex(random_bytes(24));
        $context = (object) ['calls' => 0, 'carriers' => []];
        foreach ($payloads as $name => $payload) {
            $context->carriers[] = ['type' => 'shadowsocks', 'name' => $name, 'host' => $marker.'.invalid', 'port' => 1,
                'password' => $marker, 'tags' => [], 'protocol_settings' => ['cipher' => 'aes-128-gcm']];
        }
        if ($request->attributes->has(self::CONTEXT)) {
            throw new BridgeException('TEMPLATE_CONTEXT_CONFLICT');
        }
        $request->attributes->set(self::CONTEXT, $context);
        try {
            $response = app(ClientController::class)->doSubscribe($request, $user, $servers);
            if ($context->calls !== 1 || $response->getStatusCode() !== 200) {
                throw new BridgeException('TEMPLATE_GENERATION_FAILED');
            }
            $body = self::replace($response->getContent(), $target, $payloads, $marker);
            return ['body' => $body, 'added' => count($payloads), 'skipped' => $skipped];
        } catch (BridgeException $e) {
            throw $e;
        } catch (\Throwable) {
            // Never allow a response intercepted by another plugin to escape with carriers.
            throw new BridgeException('TEMPLATE_GENERATION_FAILED');
        } finally {
            $request->attributes->remove(self::CONTEXT);
        }
    }

    public static function replace(string $body, string $target, array $payloads, string $marker): string
    {
        if (strlen($body) > Merger::MAX_BYTES) {
            throw new BridgeException('RESPONSE_TOO_LARGE');
        }
        $seen = [];
        if (in_array($target, ['surge', 'surfboard'], true)) {
            $section = '';
            $lines = preg_split('/\r?\n/', $body);
            foreach ($lines as &$line) {
                if (preg_match('/^\s*\[([^]]+)\]\s*$/', $line, $m)) {
                    $section = strtolower($m[1]);
                } elseif ($section === 'proxy' && str_contains($line, '=')) {
                    $name = trim(explode('=', $line, 2)[0], ' "');
                    if (isset($payloads[$name])) {
                        if (isset($seen[$name]) || !str_contains($line, $marker.'.invalid')) {
                            throw new BridgeException('TEMPLATE_REPLACEMENT_FAILED');
                        }
                        $line = $payloads[$name];
                        $seen[$name] = true;
                    }
                }
            }
            unset($line);
            $body = implode("\n", $lines);
        } else {
            $json = $target === 'singbox';
            $config = $json ? json_decode($body, true, 64, JSON_THROW_ON_ERROR) : Yaml::parse($body, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
            $key = $json ? 'outbounds' : 'proxies';
            if (!is_array($config[$key] ?? null)) {
                throw new BridgeException('TEMPLATE_REPLACEMENT_FAILED');
            }
            foreach ($config[$key] as &$node) {
                $name = $node[$json ? 'tag' : 'name'] ?? '';
                if (isset($payloads[$name])) {
                    if (isset($seen[$name]) || ($node['server'] ?? '') !== $marker.'.invalid' || ($node['password'] ?? '') !== $marker) {
                        throw new BridgeException('TEMPLATE_REPLACEMENT_FAILED');
                    }
                    $node = $payloads[$name];
                    $seen[$name] = true;
                }
            }
            unset($node);
            $body = $json ? json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : Yaml::dump($config, 12, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        }
        if (count($seen) !== count($payloads) || str_contains($body, $marker)) {
            throw new BridgeException('TEMPLATE_REPLACEMENT_FAILED');
        }
        if (strlen($body) > Merger::MAX_BYTES) {
            throw new BridgeException('RESPONSE_TOO_LARGE');
        }
        return $body;
    }
}
