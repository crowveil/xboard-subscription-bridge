<?php

namespace Plugin\ExternalNodeBridge\Services;

final class Refresher
{
    public function __construct(private array $config, private Diagnostics $log, private NodeCache $cache, private Converter $converter)
    {
    }

    public static function create(array $config): self
    {
        $log = new Diagnostics($config);
        return new self($config, $log, new NodeCache($config, $log), new Converter($config));
    }

    public function refresh(array $source, string $target, bool $force = false): array
    {
        Settings::requireEnabled();
        $result = Upstream\AccountStore::locked($source['id'], function () use ($source, $target, $force) {
            $state = Upstream\AccountStore::read($source['id']);
            if (Upstream\AccountStore::suspended($state)) {
                throw new BridgeException('UPSTREAM_ROTATION_PENDING');
            }
            if (!empty($state['config']) && Upstream\AccountManager::source($source['id'])['url'] !== $source['url']) {
                throw new BridgeException('SETTINGS_CHANGED');
            }
            return $this->refreshLocked($source, $target, $force);
        });
        Upstream\AccountManager::refreshed($source['id'], $target, $source, $result);
        return $result;
    }

    private function refreshLocked(array $source, string $target, bool $force): array
    {
        if (!$source['enabled'] || !$source['group_ids'] || !in_array($target, $source['targets'], true)) {
            throw new BridgeException('SOURCE_NOT_ACTIVE');
        }
        $this->cache->locked($source, $target, function () use ($source, $target, $force) {
            $entry = $this->cache->read($source, $target);
            $version = $this->converter->knownVersion();
            $changed = $version !== null && ($entry['converter_version'] ?? null) !== $version;
            if (!$force && time() - ($entry['attempted_at'] ?? 0) < ($changed ? 300 : $source['interval'])) {
                return;
            }
            $trace = bin2hex(random_bytes(8));
            $ctx = ['source_id' => $source['id'], 'target' => $target, 'trace' => $trace];
            $start = microtime(true);
            $entry['attempted_at'] = time();
            $this->cache->write($source, $target, $entry);
            $this->log->record('REFRESH_START', $ctx);
            try {
                $nodes = Merger::extract($this->converter->convert($source, $target), $target);
                if ($target === 'mihomo' && $this->log->active()) {
                    $counts = array_fill_keys(['xhttp_nodes', 'download_overrides', 'download_servers', 'download_paths', 'download_sni', 'insecure_nodes'], 0);
                    foreach ($nodes as $node) {
                        if (($node['network'] ?? '') !== 'xhttp') {
                            continue;
                        }
                        $counts['xhttp_nodes']++;
                        $down = $node['xhttp-opts']['download-settings'] ?? null;
                        if (is_array($down)) {
                            $counts['download_overrides']++;
                            foreach (['server' => 'download_servers', 'path' => 'download_paths', 'servername' => 'download_sni'] as $field => $counter) {
                                if (isset($down[$field]) && $down[$field] !== '') {
                                    $counts[$counter]++;
                                }
                            }
                        }
                        if (($node['skip-cert-verify'] ?? false) === true) {
                            $counts['insecure_nodes']++;
                        }
                    }
                    if ($counts['xhttp_nodes']) {
                        $this->log->record('XHTTP_FIELDS', $ctx + $counts);
                    }
                }
                $entry = ['nodes' => $nodes, 'updated_at' => time(), 'attempted_at' => time(), 'error' => null, 'converter_version' => $version];
                $this->log->record('REFRESH_OK', $ctx + ['count' => count($nodes), 'duration_ms' => (int) ((microtime(true) - $start) * 1000)]);
            } catch (BridgeException $e) {
                $entry['error'] = $e->reason;
                if (self::invalidatesCache($e, $changed)) {
                    $entry['nodes'] = [];
                    $entry['updated_at'] = null;
                }
                if ($e->reason === 'NO_COMPATIBLE_NODES') {
                    $entry['converter_version'] = $version;
                    $this->log->record('REFRESH_EMPTY', $ctx + ['count' => 0]);
                } else {
                    $this->log->record('REFRESH_FAILED', $ctx + ['error' => $e->reason, 'http_status' => $e->httpStatus], true);
                }
            }
            $this->cache->write($source, $target, $entry);
            if ($this->log->active()) {
                try {
                    $report = json_decode($this->converter->convert($source, $target, true), true, 64, JSON_THROW_ON_ERROR);
                    $stats = $report['nodes'] ?? [];
                    $this->log->record('CONVERTER_COUNTS', $ctx + array_intersect_key($stats, array_flip(['total', 'generated', 'unsupported'])));
                } catch (\Throwable) {
                    $this->log->record('EXPLAIN_UNAVAILABLE', $ctx);
                }
            }
        });
        return $this->cache->summary($source, $target);
    }

    public function due(): void
    {
        Settings::requireEnabled();
        $start = time();
        $this->converter->version();
        foreach ($this->config['sources'] as $source) {
            if (!$source['enabled'] || !$source['group_ids'] || Upstream\AccountStore::suspended(Upstream\AccountStore::read($source['id']))) {
                continue;
            }
            foreach ($source['targets'] as $target) {
                if (time() - $start > 50) {
                    return;
                }
                try {
                    $this->refresh($source, $target);
                } catch (\Throwable) {
                    $this->log->record('SCHEDULE_FAILED', ['source_id' => $source['id'], 'target' => $target], true);
                }
            }
        }
    }

    private static function invalidatesCache(BridgeException $error, bool $converterChanged): bool
    {
        $rejected = $error->httpStatus >= 400 && $error->httpStatus < 500 && $error->httpStatus !== 429;
        if ($rejected || $error->reason === 'NO_COMPATIBLE_NODES') {
            return true;
        }
        // A converter change cannot discard valid cached nodes solely for malformed output.
        return !$converterChanged && in_array($error->reason, [
            'OUTPUT_INVALID', 'NODE_INVALID', 'PROVIDER_OUTPUT_REJECTED', 'URI_OUTPUT_INVALID',
        ], true);
    }
}
