<?php

namespace Plugin\ExternalNodeBridge;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Plugin\ExternalNodeBridge\Services\Diagnostics;
use Plugin\ExternalNodeBridge\Services\Refresher;
use Plugin\ExternalNodeBridge\Services\Settings;
use Plugin\ExternalNodeBridge\Services\SubscriptionBridge;

final class Plugin extends AbstractPlugin
{
    public function install(): void
    {
        \Plugin\ExternalNodeBridge\Services\Assets::publish(true);
    }

    public function update(string $oldVersion, string $newVersion): void
    {
        \Plugin\ExternalNodeBridge\Services\Assets::publish(true);
    }

    public function cleanup(): void
    {
        // XBoard calls cleanup on both disable and uninstall. Keep encrypted data.
        $raw = Settings::raw();
        $raw['console_open'] = false;
        app(\App\Services\Plugin\PluginConfigService::class)->updateConfig(Settings::CODE, $raw);
        try {
            \Plugin\ExternalNodeBridge\Services\Assets::remove();
        } catch (\Throwable $e) {
            \Plugin\ExternalNodeBridge\Services\Assets::recordFailure($e);
            throw $e;
        }
    }

    public function boot(): void
    {
        try {
            Settings::requireEnabled();
        } catch (\Throwable) {
            return;
        }
        try {
            \Plugin\ExternalNodeBridge\Services\Assets::publish(false, true);
        } catch (\Throwable $e) {
            \Plugin\ExternalNodeBridge\Services\Assets::recordFailure($e);
        }
        try {
            Settings::load();
            \Plugin\ExternalNodeBridge\Services\ConsoleAccess::status();
        } catch (\Throwable) {
            (new Diagnostics(['debug' => false]))->record('SETTINGS_LOAD_FAILED', [], true);
        }
        // Static callable identity avoids duplicate registrations in one request.
        $this->filter('protocol.servers.filtered', [\Plugin\ExternalNodeBridge\Services\TemplateBridge::class, 'inject'], 10000);
        $this->filter('client.subscribe.servers', [SubscriptionBridge::class, 'handle'], 10000);
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(function () {
            try {
                Settings::load();
            } catch (\Throwable) {
                return;
            }
            try {
                \Plugin\ExternalNodeBridge\Services\Upstream\AccountManager::due();
            } catch (\Throwable) {
                (new Diagnostics(['debug' => false]))->record('UPSTREAM_SCHEDULE_FAILED', [], true);
            }
            try {
                \Plugin\ExternalNodeBridge\Services\Upstream\Notifier::flush();
            } catch (\Throwable) {
                (new Diagnostics(['debug' => false]))->record('NOTIFICATION_SEND_FAILED', [], true);
            }
        })->name('external-node-bridge-accounts')->everyMinute()->withoutOverlapping(10);
        $schedule->call(function () {
            try {
                Refresher::create(Settings::load())->due();
            } catch (\Throwable) {
                (new Diagnostics(['debug' => false]))->record('SCHEDULE_CONFIG_FAILED', [], true);
            }
        })->name('external-node-bridge-refresh')->everyMinute()->withoutOverlapping(10);
    }
}
