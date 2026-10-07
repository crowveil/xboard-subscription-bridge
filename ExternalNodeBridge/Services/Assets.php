<?php

namespace Plugin\ExternalNodeBridge\Services;

use Illuminate\Support\Facades\File;

/** Public assets are disposable; private state is never part of this lifecycle. */
final class Assets
{
    public const FILES = ['console.html', 'console.css', 'console.js', 'accounts.js'];

    public static function source(): string
    {
        return dirname(__DIR__).'/resources/assets';
    }

    public static function destination(): string
    {
        return public_path('plugins/'.Settings::CODE);
    }

    public static function publish(bool $replace = false, bool $enabledOnly = false): void
    {
        // Boot restores a missing directory; explicit repair validates individual resources.
        if (!$replace && is_dir(self::destination())) {
            return;
        }
        self::locked(function () use ($replace, $enabledOnly) {
            if ($enabledOnly) {
                Settings::requireEnabled();
            }
            $destination = self::destination();
            if (!$replace && is_dir($destination)) {
                return;
            }
            $stage = $destination.'.stage-'.bin2hex(random_bytes(6));
            $backup = $destination.'.backup-'.bin2hex(random_bytes(6));
            try {
                if (!mkdir($stage, 0755)) {
                    throw new BridgeException('ASSET_PUBLISH_FAILED');
                }
                foreach (self::FILES as $name) {
                    $source = self::source().'/'.$name;
                    if (!is_file($source) || !is_readable($source) || !copy($source, $stage.'/'.$name)) {
                        throw new BridgeException('ASSET_PUBLISH_FAILED');
                    }
                    chmod($stage.'/'.$name, 0644);
                    if (hash_file('sha256', $source) !== hash_file('sha256', $stage.'/'.$name)) {
                        throw new BridgeException('ASSET_PUBLISH_FAILED');
                    }
                }
                if (file_exists($destination) && !rename($destination, $backup)) {
                    throw new BridgeException('ASSET_PUBLISH_FAILED');
                }
                if (!rename($stage, $destination)) {
                    throw new BridgeException('ASSET_PUBLISH_FAILED');
                }
            } finally {
                if (file_exists($backup) && !file_exists($destination)) {
                    rename($backup, $destination);
                }
                File::deleteDirectory($stage);
                if (is_dir($destination)) {
                    File::deleteDirectory($backup);
                }
            }
        });
    }

    public static function remove(): void
    {
        // Wait for an in-progress publication even when the final directory
        // has not appeared yet, then remove its completed result.
        self::locked(function () {
            $path = self::destination();
            if (is_link($path) || is_file($path)) {
                if (!unlink($path)) {
                    throw new BridgeException('ASSET_REMOVE_FAILED');
                }
            } elseif (is_dir($path) && !File::deleteDirectory($path)) {
                throw new BridgeException('ASSET_REMOVE_FAILED');
            }
        });
    }

    private static function locked(callable $operation): void
    {
        $parent = dirname(self::destination());
        File::ensureDirectoryExists($parent, 0755);
        $lock = fopen($parent.'/'.Settings::CODE.'.lock', 'c');
        if (!$lock) {
            throw new BridgeException('ASSET_STORAGE_UNAVAILABLE');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new BridgeException('ASSET_STORAGE_UNAVAILABLE');
            }
            $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function recordFailure(\Throwable $error): void
    {
        $context = ['error' => $error instanceof BridgeException ? $error->reason : 'ASSET_STORAGE_UNAVAILABLE'];
        (new Diagnostics(['debug' => false]))->record('ASSET_LIFECYCLE_FAILED', $context, true);
        // This still works when the private diagnostic directory is unavailable.
        error_log('ExternalNodeBridge ASSET_LIFECYCLE_FAILED '.$context['error']);
    }
}
