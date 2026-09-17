<?php

namespace Plugin\ExternalNodeBridge\Services;

final class Diagnostics
{
    private const LIMIT = 1048576;
    private const COUNTS = ['count', 'added', 'skipped', 'duration_ms', 'http_status', 'generated', 'unsupported', 'total', 'age', 'error_line', 'xhttp_nodes', 'download_overrides', 'download_servers', 'download_paths', 'download_sni', 'insecure_nodes'];
    private const LABELS = ['source_id', 'target', 'trace', 'user_ref', 'error', 'state', 'error_class', 'error_file'];

    public function __construct(private array $config, private ?string $directory = null)
    {
    }

    public function active(): bool
    {
        return ($this->config['debug'] ?? false) && (!(int) ($this->config['debug_until'] ?? 0) || time() < (int) $this->config['debug_until']);
    }

    public function dir(): string
    {
        $dir = $this->directory ?? storage_path('app/external-node-bridge');
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new BridgeException('STORAGE_UNAVAILABLE');
        }
        return $dir;
    }

    public static function userRef(mixed $id): string
    {
        return substr(hash_hmac('sha256', (string) $id, (string) config('app.key')), 0, 12);
    }

    public static function errorSite(\Throwable $e): array
    {
        return ['error_class' => basename(str_replace('\\', '/', get_class($e))),
            'error_file' => basename($e->getFile()), 'error_line' => $e->getLine()];
    }

    // Only structured allowlisted fields are ever persisted. No exception messages,
    // bodies, URLs, headers, arbitrary context objects or stack traces.
    public function record(string $event, array $context = [], bool $error = false): void
    {
        if (!$error && !$this->active()) {
            return;
        }
        try {
            $row = ['time' => gmdate('c'), 'event' => preg_match('/^[A-Z0-9_]{1,64}$/D', $event) ? $event : 'UNKNOWN', 'level' => $error ? 'error' : 'debug'];
            foreach (self::COUNTS as $k) {
                if (isset($context[$k]) && is_numeric($context[$k])) {
                    $row[$k] = (int) $context[$k];
                }
            }
            foreach (self::LABELS as $k) {
                if (isset($context[$k]) && is_string($context[$k]) && preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/D', $context[$k])) {
                    $row[$k] = $context[$k];
                }
            }
            $dir = $this->dir();
            $lock = fopen($dir.'/diagnostics.lock', 'c');
            if (!$lock) {
                return;
            }
            try {
                if (!flock($lock, LOCK_EX)) {
                    return;
                }
                $path = $dir.'/diagnostics.jsonl';
                clearstatcache(true, $path);
                if (is_file($path) && filesize($path) > self::LIMIT) {
                    rename($path, $path.'.1');
                }
                file_put_contents($path, json_encode($row, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
                chmod($path, 0600);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } catch (\Throwable) { /* Diagnostics must never break subscriptions. */
        }
    }

    public function recent(int $limit = 300): array
    {
        $out = [];
        foreach (['diagnostics.jsonl.1', 'diagnostics.jsonl'] as $name) {
            $path = $this->dir().'/'.$name;
            if (!is_file($path)) {
                continue;
            }
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $row = json_decode($line, true);
                if (is_array($row) && strtotime($row['time'] ?? '') >= time() - 3 * 86400) {
                    $out[] = $row;
                }
            }
        }
        return array_slice($out, -max(1, min(2000, $limit)));
    }
}
