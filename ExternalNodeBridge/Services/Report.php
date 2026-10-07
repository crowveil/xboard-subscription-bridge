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
        $accounts = array_map(fn ($source) => Upstream\AccountStore::summary($source['id'], null, true), $config['sources']);
        $converter = new Converter($config);
        return [
            'deployment' => DeploymentCheck::inspect(),
            'runtime' => RuntimeCompatibility::inspect(),
            'accounts' => $accounts,
            'schema_version' => 1, 'plugin_version' => Metadata::version(), 'exported_at' => gmdate('c'),
            'php_version' => PHP_VERSION, 'debug_active' => $log->active(), 'debug_until' => $config['debug_until'],
            'tested_xboard_reference' => '4f48e61',
            'installed_controller_sha256' => is_file($controller) ? hash_file('sha256', $controller) : null,
            'converter_version' => $converter->knownVersion(), 'sources' => $states,
            'converter' => $converter->metadata(),
            'upstream_accounts' => $accounts,
            'upstream_ua_mode' => $config['upstream_user_agent'] === 'clash.meta' ? 'mihomo_default' : 'custom',
            'upstream_ua_sha256' => hash('sha256', $config['upstream_user_agent']),
            'events' => $log->recent(1000),
        ];
    }
}
