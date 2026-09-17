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
                // Explicit origin revocation still clears data. A new converter
                // emitting malformed output must not destroy the last valid set.
                if (($e->httpStatus >= 400 && $e->httpStatus < 500 && $e->httpStatus !== 429) || in_array($e->reason, ['NO_COMPATIBLE_NODES', 'OUTPUT_INVALID', 'NODE_INVALID', 'PROVIDER_OUTPUT_REJECTED', 'URI_OUTPUT_INVALID'], true)) {
                    if (!$changed || ($e->httpStatus >= 400 && $e->httpStatus < 500 && $e->httpStatus !== 429) || $e->reason === 'NO_COMPATIBLE_NODES') {
                        $entry['nodes'] = [];
                        $entry['updated_at'] = null;
                    }
                }
                $this->log->record('REFRESH_FAILED', $ctx + ['error' => $e->reason, 'http_status' => $e->httpStatus], true);
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
        $start = time();
        $this->converter->version();
        foreach ($this->config['sources'] as $source) {
            if (!$source['enabled'] || !$source['group_ids']) {
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
}
