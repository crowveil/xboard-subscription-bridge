<?php

final class LifecycleTest extends BridgeTestCase
{
    public function testNativeManagerInstallsPublishesAssetsEnablesAndSchedulesPlugin(): void
    {
        App\Models\Plugin::query()->delete();
        unlink(storage_path('app/external-node-bridge/settings.state'));
        $manager = app(App\Services\Plugin\PluginManager::class);
        $this->assertTrue($manager->install('external_node_bridge'));
        $row = App\Models\Plugin::query()->first();
        $this->assertFalse((bool)$row->is_enabled);
        $this->assertSame(Plugin\ExternalNodeBridge\Services\Metadata::version(), $row->version);
        $this->assertFileExists(public_path('plugins/external_node_bridge/console.html'));
        $this->assertFileExists(public_path('plugins/external_node_bridge/console.js'));
        $this->assertFileExists(public_path('plugins/external_node_bridge/console.css'));
        $this->assertTrue($manager->enable('external_node_bridge'));
        $this->assertSame([], Plugin\ExternalNodeBridge\Services\Settings::load()['sources']);
        $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/external-node-bridge/admin/'));
        $this->assertCount(10, $routes);
        foreach ($routes as $route) {
            $this->assertContains('api', $route->middleware());
            $this->assertContains('admin', $route->middleware());
        }
        $schedule = new Illuminate\Console\Scheduling\Schedule();
        $manager->registerPluginSchedules($schedule);
        $this->assertCount(1, $schedule->events());
        $this->assertSame('* * * * *', $schedule->events()[0]->expression);
        $this->assertTrue($schedule->events()[0]->withoutOverlapping);
        $this->assertTrue($manager->disable('external_node_bridge'));
        $this->assertFalse((bool)App\Models\Plugin::query()->first()->is_enabled);
    }

    public function testUpgradeRepublishesConsoleAndAddsDefaultUaWithoutLosingSources(): void
    {
        App\Models\Plugin::query()->update(['version' => '0.0.9']);
        $c = $this->settings;
        unset($c['upstream_user_agent']);
        $this->saveSettings($c);
        $dir = public_path('plugins/external_node_bridge');
        mkdir($dir, 0700, true);
        file_put_contents($dir.'/console.html', 'old UI');
        $this->assertTrue(app(App\Services\Plugin\PluginManager::class)->update('external_node_bridge'));
        $this->assertStringContainsString('id="plugin-version"', file_get_contents($dir.'/console.html'));
        $saved = Plugin\ExternalNodeBridge\Services\Settings::load();
        $this->assertSame('clash.meta', $saved['upstream_user_agent']);
        $this->assertSame($c['sources'], $saved['sources']);
        $this->assertSame(Plugin\ExternalNodeBridge\Services\Metadata::version(), App\Models\Plugin::query()->first()->version);
    }
}
