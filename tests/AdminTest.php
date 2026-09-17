<?php

use Illuminate\Http\Request;
use Plugin\ExternalNodeBridge\Services\{Diagnostics, Settings};

final class AdminTest extends BridgeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $raw = Settings::raw();
        $raw['console_open'] = true;
        app(App\Services\Plugin\PluginConfigService::class)->updateConfig(Settings::CODE, $raw);
    }
    private function callAdmin(string $method, string $path, array $body = [])
    {
        $router = app('router');
        $router->aliasMiddleware('admin', App\Http\Middleware\Admin::class);
        if (!collect($router->getRoutes()->getRoutes())->contains(fn ($r) => str_ends_with($r->uri(), '/admin/settings'))) {
            require dirname(__DIR__).'/ExternalNodeBridge/routes/api.php';
        }
        $request = Request::create('/api/v1/external-node-bridge/admin/'.$path, $method, $body);
        $request->headers->set('Accept', 'application/json');
        app()->instance('request', $request);
        return $router->dispatch($request);
    }

    public function testEveryAdminRouteRejectsAnonymousAndOrdinaryUsers(): void
    {
        $routes = [['GET','session'],['POST','renew'],['POST','close'],['GET','settings'],['POST','settings'],['POST','debug'],['GET','status'],['GET','export'],['POST','health'],['POST','refresh']];
        foreach ([null, (object)['is_admin' => false]] as $user) {
            app('auth')->admin = $user;
            foreach ($routes as [$method,$path]) {
                $this->assertSame(403, $this->callAdmin($method, $path)->getStatusCode(), $path);
            }
        }
    }

    public function testSettingsReturnPlainUrlsToAuthorizedOpenConsole(): void
    {
        app('auth')->admin = (object)['is_admin' => true];
        $response = $this->callAdmin('GET', 'settings');
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('UPSTREAM_SECRET', $response->getContent());
        $this->assertArrayNotHasKey('cache_revision', $data['config']);
        $data['config']['sources'][0]['group_ids'] = ['2'];
        $response = $this->callAdmin('POST', 'settings', ['config' => $data['config']]);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $saved = Settings::load();
        $this->assertSame($this->settings['sources'][0]['url'], $saved['sources'][0]['url']);
        $this->assertSame(['2'], $saved['sources'][0]['group_ids']);
        $this->assertSame([], Settings::authorized($saved, 1, 'mihomo'));
    }

    public function testMissingGroupCannotBeSavedAndDisabledPluginCannotBeRead(): void
    {
        app('auth')->admin = (object)['is_admin' => true];
        $c = $this->settings;
        $c['sources'][0]['group_ids'] = [999];
        $response = $this->callAdmin('POST', 'settings', ['config' => $c]);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('GROUP_NOT_FOUND', json_decode($response->getContent(), true)['error']);
        $this->assertSame(['1'], Settings::load()['sources'][0]['group_ids']);
        App\Models\Plugin::query()->update(['is_enabled' => false]);
        $response = $this->callAdmin('GET', 'export');
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('PLUGIN_DISABLED', json_decode($response->getContent(), true)['error']);
    }

    public function testDebugTogglePersistsSettingsAndExportIsNoStore(): void
    {
        app('auth')->admin = (object)['is_admin' => true];
        $this->assertSame(200, $this->callAdmin('POST', 'debug', ['enabled' => true])->getStatusCode());
        $c = Settings::load();
        $this->assertTrue((new Diagnostics($c))->active());
        $this->assertGreaterThanOrEqual(time() + 3598, $c['debug_until']);
        $this->assertLessThanOrEqual(time() + 3600, $c['debug_until']);
        $response = $this->callAdmin('GET', 'export');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('UPSTREAM_SECRET', $response->getContent());
        $this->assertSame(200, $this->callAdmin('POST', 'debug', ['enabled' => false])->getStatusCode());
        $this->assertFalse((new Diagnostics(Settings::load()))->active());
    }
}
