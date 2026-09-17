<?php

namespace Plugin\ExternalNodeBridge\Services;

final class Report
{
    public static function make(array $config): array
    {
        $log = new Diagnostics($config);
        $cache = new NodeCache($config, $log);
        $states = [];
        foreach ($config['sources'] as $source) {
            foreach ($source['targets'] as $target) {
                $states[] = $cache->summary($source, $target) + ['enabled' => $source['enabled'], 'authorized_group_count' => count($source['group_ids'])];
            }
        }
        $controller = app_path('Http/Controllers/V1/Client/ClientController.php');
        return [
            'schema_version' => 1, 'plugin_version' => Metadata::version(), 'exported_at' => gmdate('c'),
            'php_version' => PHP_VERSION, 'debug_active' => $log->active(), 'debug_until' => $config['debug_until'],
            'tested_xboard_reference' => '4f48e61',
            'installed_controller_sha256' => is_file($controller) ? hash_file('sha256', $controller) : null,
            'converter_version' => (new Converter($config))->knownVersion(), 'sources' => $states,
            'converter' => (new Converter($config))->metadata(),
            'upstream_ua_mode' => $config['upstream_user_agent'] === 'clash.meta' ? 'mihomo_default' : 'custom',
            'upstream_ua_sha256' => hash('sha256', $config['upstream_user_agent']),
            'events' => $log->recent(1000),
        ];
    }
}
