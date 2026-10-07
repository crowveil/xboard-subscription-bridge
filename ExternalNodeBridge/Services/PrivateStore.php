<?php

namespace Plugin\ExternalNodeBridge\Services;

use Illuminate\Support\Facades\Crypt;

/** Small encrypted state files, shared by web workers and the scheduler. */
final class PrivateStore
{
    public static function locked(string $name, callable $callback): mixed
    {
        $dir = (new Diagnostics(['debug' => false]))->dir();
        try {
            $lock = fopen($dir.'/'.$name.'.lock', 'c');
        } catch (\Throwable) {
            throw new BridgeException('STORAGE_UNAVAILABLE');
        }
        if (!$lock) {
            throw new BridgeException('STORAGE_UNAVAILABLE');
        }
        try {
            try {
                $ready = chmod($dir.'/'.$name.'.lock', 0600) && flock($lock, LOCK_EX);
            } catch (\Throwable) {
                throw new BridgeException('STORAGE_UNAVAILABLE');
            }
            if (!$ready) {
                throw new BridgeException('STORAGE_UNAVAILABLE');
            }
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function read(string $name): array
    {
        $file = (new Diagnostics(['debug' => false]))->dir().'/'.$name.'.state';
        if (!is_file($file)) {
            return [];
        }
        if (!is_readable($file)) {
            throw new BridgeException('STORAGE_UNAVAILABLE');
        }
        try {
            return json_decode(Crypt::decryptString(file_get_contents($file)), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new BridgeException('PRIVATE_STATE_UNREADABLE');
        }
    }

    public static function write(string $name, array $data): void
    {
        $file = (new Diagnostics(['debug' => false]))->dir().'/'.$name.'.state';
        $temp = $file.'.'.bin2hex(random_bytes(6)).'.tmp';
        $encrypted = Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR));
        try {
            if (file_put_contents($temp, $encrypted) === false) {
                throw new BridgeException('STORAGE_UNAVAILABLE');
            }
            if (!chmod($temp, 0600)) {
                throw new BridgeException('STORAGE_UNAVAILABLE');
            }
            if (!rename($temp, $file)) {
                throw new BridgeException('STORAGE_UNAVAILABLE');
            }
        } catch (\Throwable) {
            throw new BridgeException('STORAGE_UNAVAILABLE');
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }
}
