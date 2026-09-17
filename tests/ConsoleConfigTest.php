<?php

use App\Services\Plugin\PluginConfigService;
use Illuminate\Http\Request;
use Plugin\ExternalNodeBridge\Services\ConsoleConfigService;

final class ConsoleConfigTest extends BridgeTestCase
{
    private function nativeService(): PluginConfigService
    {
        app(App\Services\Plugin\PluginManager::class)->initializeEnabledPlugins();
        $service = app(PluginConfigService::class);
        $this->assertInstanceOf(ConsoleConfigService::class, $service);

        return $service;
    }

    public function testNativePluginLoaderAddsUrlWithoutChangingValuesOrStorage(): void
    {
        $before = App\Models\Plugin::where('code', 'external_node_bridge')->value('config');
        $native = (new PluginConfigService())->getConfig('external_node_bridge');
        $GLOBALS['test_settings']['app_url'] = 'https://frontend.example';
        app('url')->forceRootUrl('https://cdn.example');
        app()->instance('request', Request::create('https://admin.example:8443/api/v2/admin/plugin/getConfig'));
        $actual = $this->nativeService()->getConfig('external_node_bridge');
        $this->assertStringContainsString('https://admin.example:8443/plugins/external_node_bridge/console.html', $actual['console_open']['description']);
        unset($actual['console_open']['description'], $native['console_open']['description']);
        $this->assertSame($native, $actual);
        $this->assertSame($before, App\Models\Plugin::where('code', 'external_node_bridge')->value('config'));
    }

    public function testUrlIsRecomputedAcrossRequestsAndUsesTrustedProxyHeaders(): void
    {
        $service = $this->nativeService();
        app()->instance('request', Request::create('http://first.example/api/config'));
        $this->assertStringContainsString('http://first.example/plugins/', $service->getConfig('external_node_bridge')['console_open']['description']);
        Request::setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);
        try {
            app()->instance('request', Request::create('http://internal.example/api/config', 'GET', [], [], [], [
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_HOST' => 'second.example',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_PORT' => '9443',
            ]));
            $description = $service->getConfig('external_node_bridge')['console_open']['description'];
            $this->assertStringContainsString('https://second.example:9443/plugins/', $description);
            $this->assertStringNotContainsString('first.example', $description);
            $this->assertStringNotContainsString('internal.example', $description);
        } finally {
            Request::setTrustedProxies([], 0);
        }
    }

    public function testUntrustedForwardedHeadersAreIgnoredAndHttpsSettingIsHonored(): void
    {
        Request::setTrustedProxies([], 0);
        app()->instance('request', Request::create('http://admin.example/api/config', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_HOST' => 'untrusted.example',
        ]));
        $GLOBALS['test_settings']['force_https'] = true;
        $description = $this->nativeService()->getConfig('external_node_bridge')['console_open']['description'];
        $this->assertStringContainsString('https://admin.example/plugins/', $description);
        $this->assertStringNotContainsString('untrusted.example', $description);
    }

    public function testSubdirectoryUsesPublicBasePathNotFrontController(): void
    {
        app()->instance('request', Request::create('https://admin.example/panel/index.php/api/config', 'GET', [], [], [], [
            'SCRIPT_NAME' => '/panel/index.php', 'SCRIPT_FILENAME' => '/public/panel/index.php', 'PHP_SELF' => '/panel/index.php/api/config',
        ]));
        $description = $this->nativeService()->getConfig('external_node_bridge')['console_open']['description'];
        $this->assertStringContainsString('https://admin.example/panel/plugins/', $description);
        $this->assertStringNotContainsString('index.php', $description);
    }

    public function testDisablingPluginLeavesNativeDescriptionEvenWithExistingWrapper(): void
    {
        $service = $this->nativeService();
        App\Models\Plugin::where('code', 'external_node_bridge')->update(['is_enabled' => false]);
        $this->assertSame((new PluginConfigService())->getConfig('external_node_bridge'), $service->getConfig('external_node_bridge'));
    }

    public function testOtherPluginsAndWritesAreDelegatedUnchanged(): void
    {
        $inner = Mockery::mock(PluginConfigService::class);
        $other = ['field' => ['type' => 'string', 'description' => 'unchanged', 'value' => 'value']];
        $inner->shouldReceive('getConfig')->once()->with('other_plugin')->andReturn($other);
        $inner->shouldReceive('getDbConfig')->once()->with('other_plugin')->andReturn(['field' => 'value']);
        $inner->shouldReceive('updateConfig')->once()->with('external_node_bridge', ['console_open' => true])->andReturn(true);
        $service = new ConsoleConfigService($inner);
        $this->assertSame($other, $service->getConfig('other_plugin'));
        $this->assertSame(['field' => 'value'], $service->getDbConfig('other_plugin'));
        $this->assertTrue($service->updateConfig('external_node_bridge', ['console_open' => true]));
    }
}
