<?php

namespace Plugin\ExternalNodeBridge\Services;

/** Validate loaded component interfaces independently of the manifest on disk. */
final class RuntimeCompatibility
{
    public static function inspect(): array
    {
        $interfaces = [];
        foreach ([
            Settings::class => ['requireEnabled', 'revision', 'replaceSourceUrl'],
            Metadata::class => ['release'],
            SubscriptionBridge::class => ['compose'],
            TemplateBridge::class => ['supports', 'render', 'inject'],
            Upstream\AccountStore::class => ['reconcileTargets'],
        ] as $class => $methods) {
            foreach ($methods as $method) {
                $interfaces[class_basename($class).'::'.$method] = is_callable([$class, $method]);
            }
        }
        return ['compatible' => !in_array(false, $interfaces, true), 'interfaces' => $interfaces,
            'process_id' => getmypid()];
    }

    public static function requireSupported(): void
    {
        if (!self::inspect()['compatible']) {
            throw new BridgeException('PLUGIN_RESTART_REQUIRED');
        }
    }
}
