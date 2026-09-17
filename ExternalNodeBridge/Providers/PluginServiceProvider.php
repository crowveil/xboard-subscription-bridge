<?php

namespace Plugin\ExternalNodeBridge\Providers;

use App\Services\Plugin\PluginConfigService;
use Illuminate\Support\ServiceProvider;
use Plugin\ExternalNodeBridge\Services\ConsoleConfigService;

final class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(PluginConfigService::class, static function (PluginConfigService $service) {
            return $service instanceof ConsoleConfigService ? $service : new ConsoleConfigService($service);
        });
    }
}
