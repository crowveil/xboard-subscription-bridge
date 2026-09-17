<?php

namespace Plugin\ExternalNodeBridge\Services;

use Illuminate\Support\Facades\Http;

final class Converter
{
    public function __construct(private array $config)
    {
    }

    public function convert(array $source, string $target, bool $explain = false): string
    {
        // Valid inline external config disables the default remote rule preset.
        // In list mode SCE ignores external base overrides. Stash still reads
        // its built-in base/stash.yaml, which must exist in the converter image.
        $profile = "[custom]\nenable_rule_generator=false\noverwrite_original_rules=false\n";
        return $this->request('/sub', [
            'target' => $target === 'mihomo' ? 'clash' : (in_array($target, Settings::TARGETS, true) ? $target : throw new BridgeException('TARGET_INVALID')),
            'ver' => $target === 'surge' ? '5' : '4',
            'url' => $source['url'], 'list' => 'true', 'insert' => 'false', 'upload' => 'false',
            'config' => 'data:;base64,'.base64_encode($profile),
            'append_info' => 'false', 'explain' => $explain ? 'true' : 'false',
        ]);
    }

    public function health(): bool
    {
        return trim($this->request('/healthz', [])) === 'ok';
    }

    public function version(bool $force = false): array
    {
        return PrivateStore::locked('converter', function () use ($force) {
            $key = hash('sha256', $this->config['converter_url']);
            $state = PrivateStore::read('converter');
            if (($state['service'] ?? '') !== $key) {
                $state = ['service' => $key];
            }
            if (!$force && time() - ($state['checked_at'] ?? 0) < 300) {
                return $state;
            }
            try {
                $text = trim($this->request('/version', [], ['Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty']));
                if (!preg_match('/^SubConverter-Extended\s+([a-zA-Z0-9._+-]{1,96})(?:\s+backend)?$/D', $text, $match)) {
                    throw new BridgeException('VERSION_UNRECOGNIZED');
                }
                $state['version'] = $match[1];
                $state['ok'] = true;
                $state['error'] = null;
            } catch (BridgeException $e) {
                $state['ok'] = false;
                $state['error'] = $e->reason;
            }
            $state['checked_at'] = time();
            PrivateStore::write('converter', $state);
            return $state;
        });
    }

    public function knownVersion(): ?string
    {
        return $this->metadata()['version'] ?? null;
    }

    public function metadata(): array
    {
        $state = PrivateStore::read('converter');
        return ($state['service'] ?? '') === hash('sha256', $this->config['converter_url']) ? array_intersect_key($state, array_flip(['version','ok','error','checked_at'])) : [];
    }

    private function request(string $path, array $query, array $headers = []): string
    {
        try {
            $deadline = microtime(true) + $this->config['timeout'];
            $r = Http::withOptions(['stream' => true, 'allow_redirects' => false, 'read_timeout' => $this->config['timeout']])
                ->connectTimeout(3)->timeout($this->config['timeout'])
                // SCE forwards this header to the subscription origin. An unknown
                // plugin UA can make the origin return a lossy URI subscription.
                ->withHeaders(['User-Agent' => $this->config['upstream_user_agent']] + $headers)
                ->get($this->config['converter_url'].$path, $query);
            $stream = $r->toPsrResponse()->getBody();
            try {
                if (!$r->successful()) {
                    throw new BridgeException('CONVERTER_HTTP_ERROR', $r->status());
                }
                $result = '';
                while (!$stream->eof()) {
                    if (microtime(true) > $deadline) {
                        throw new BridgeException('CONVERTER_UNREACHABLE');
                    }
                    $chunk = $stream->read(65536);
                    if ($chunk === '' && !$stream->eof()) {
                        throw new BridgeException('CONVERTER_UNREACHABLE');
                    }
                    $result .= $chunk;
                    if (strlen($result) > Merger::MAX_BYTES) {
                        throw new BridgeException('RESPONSE_TOO_LARGE');
                    }
                }
                return $result;
            } finally {
                $stream->close();
            }
        } catch (BridgeException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new BridgeException('CONVERTER_UNREACHABLE');
        }
    }
}
