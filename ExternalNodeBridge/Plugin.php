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
    public function update(string $oldVersion, string $newVersion): void
    {
        // XBoard's native update path does not republish static assets.
        $path = public_path('plugins/'.Settings::CODE);
        \Illuminate\Support\Facades\File::ensureDirectoryExists($path);
        \Illuminate\Support\Facades\File::copyDirectory(__DIR__.'/resources/assets', $path);
    }

    public function boot(): void
    {
        try {
            Settings::load();
            \Plugin\ExternalNodeBridge\Services\ConsoleAccess::status();
        } catch (\Throwable) {
            (new Diagnostics(['debug' => false]))->record('SETTINGS_MIGRATION_FAILED', [], true);
        }
        // Static callable identity avoids duplicate registrations in one request.
        $this->filter('client.subscribe.servers', [SubscriptionBridge::class, 'handle'], 10000);
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(function () {
            try {
                Refresher::create(Settings::load())->due();
            } catch (\Throwable) {
                (new Diagnostics(['debug' => false]))->record('SCHEDULE_CONFIG_FAILED', [], true);
            }
        })->name('external-node-bridge-refresh')->everyMinute()->withoutOverlapping(10);
    }
}
