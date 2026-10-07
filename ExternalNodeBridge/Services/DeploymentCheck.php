<?php

namespace Plugin\ExternalNodeBridge\Services;

final class DeploymentCheck
{
    public static function inspect(bool $probe = false): array
    {
        $checks = [];
        foreach (Assets::FILES as $name) {
            $checks['source_'.$name] = is_readable(Assets::source().'/'.$name);
            $checks['public_'.$name] = is_readable(Assets::destination().'/'.$name);
        }
        foreach (['public' => dirname(Assets::destination()), 'private' => storage_path('app/external-node-bridge')] as $name => $path) {
            $checks[$name.'_writable'] = is_dir($path) && is_writable($path);
            if ($probe && $checks[$name.'_writable']) {
                $file = $path.'/.bridge-check-'.bin2hex(random_bytes(6));
                try {
                    $checks[$name.'_writable'] = file_put_contents($file, 'check', LOCK_EX) === 5;
                } catch (\Throwable) {
                    $checks[$name.'_writable'] = false;
                } finally {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
        }
        $state = 'ok';
        try {
            foreach (glob(storage_path('app/external-node-bridge/*.state')) ?: [] as $file) {
                PrivateStore::read(basename($file, '.state'));
            }
        } catch (\Throwable $e) {
            $state = $e instanceof BridgeException ? $e->reason : 'STORAGE_UNAVAILABLE';
        }
        $checks['private_state_readable'] = $state === 'ok';
        return ['ok' => !in_array(false, $checks, true), 'checks' => $checks, 'state' => $state,
            'process_uid' => function_exists('posix_geteuid') ? posix_geteuid() : null,
            'write_probe' => $probe,
            'persistence' => 'Persist the plugin directory, storage/app/external-node-bridge and the original APP_KEY; mount persistence cannot be verified here.'];
    }
}
