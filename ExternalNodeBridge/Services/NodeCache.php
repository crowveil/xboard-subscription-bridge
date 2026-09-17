<?php

namespace Plugin\ExternalNodeBridge\Services;

use Illuminate\Support\Facades\Crypt;

final class NodeCache
{
    public function __construct(private array $config, private Diagnostics $diagnostics)
    {
    }

    public function key(array $source, string $target): string
    {
        return hash('sha256', json_encode(['schema' => 2, 'id' => $source['id'], 'url' => $source['url'], 'target' => $target, 'converter' => $this->config['converter_url'], 'revision' => $this->config['cache_revision'], 'upstream_ua' => $this->config['upstream_user_agent']]));
    }

    private function path(array $source, string $target): string
    {
        return $this->diagnostics->dir().'/'.$this->key($source, $target).'.cache';
    }

    public function read(array $source, string $target): array
    {
        $path = $this->path($source, $target);
        if (!is_file($path)) {
            return [];
        }
        try {
            return json_decode(Crypt::decryptString(file_get_contents($path)), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->diagnostics->record('CACHE_UNREADABLE', ['source_id' => $source['id'], 'target' => $target], true);
            return [];
        }
    }

    public function write(array $source, string $target, array $entry): void
    {
        $path = $this->path($source, $target);
        $temp = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        try {
            $body = Crypt::encryptString(json_encode($entry, JSON_THROW_ON_ERROR));
            if (file_put_contents($temp, $body) === false) {
                throw new BridgeException('CACHE_WRITE_FAILED');
            }
            chmod($temp, 0600);
            if (!rename($temp, $path)) {
                throw new BridgeException('CACHE_WRITE_FAILED');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    public function locked(array $source, string $target, callable $callback): mixed
    {
        $lock = fopen($this->path($source, $target).'.lock', 'c');
        if (!$lock) {
            throw new BridgeException('STORAGE_UNAVAILABLE');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return null;
            }
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function usable(array $entry): bool
    {
        $age = time() - (int) ($entry['updated_at'] ?? 0);
        return !empty($entry['nodes']) && $age >= 0 && $age <= $this->config['max_stale'];
    }

    public function summary(array $source, string $target): array
    {
        $e = $this->read($source, $target);
        return [
            'source_id' => $source['id'], 'target' => $target,
            'updated_at' => $e['updated_at'] ?? null, 'attempted_at' => $e['attempted_at'] ?? null,
            'count' => count($e['nodes'] ?? []), 'usable' => $this->usable($e),
            'error' => $e['error'] ?? null,
        ];
    }
}
