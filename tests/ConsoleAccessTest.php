<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Plugin\ExternalNodeBridge\Services\{ConsoleAccess, Converter, Diagnostics, NodeCache, PrivateStore, Refresher, Settings};

final class ConsoleAccessTest extends BridgeTestCase
{
    private function call(string $method, string $path, array $body = [])
    {
        app('router')->aliasMiddleware('admin', App\Http\Middleware\Admin::class);
        if (!collect(app('router')->getRoutes()->getRoutes())->contains(fn ($r) => str_ends_with($r->uri(), '/admin/settings'))) {
            require dirname(__DIR__).'/ExternalNodeBridge/routes/api.php';
        }
        $r = Request::create('/api/v1/external-node-bridge/admin/'.$path, $method, $body);
        $r->headers->set('Accept', 'application/json');
        app()->instance('request', $r);
        return app('router')->dispatch($r);
    }
    private function enable(): void
    {
        app('auth')->admin = (object)['is_admin' => true];
        app(App\Services\Plugin\PluginConfigService::class)->updateConfig(Settings::CODE, ['converter_url' => $this->settings['converter_url'], 'console_open' => true]);
    }

    public function testClosedConsoleRejectsAllOperationsAndDoesNotAffectSubscriptions(): void
    {
        $this->enable();
        $this->assertSame(200, $this->call('GET', 'settings')->getStatusCode());
        $this->cache('a', 'mihomo', [self::node()]);
        $this->assertSame(200, $this->call('POST', 'close')->getStatusCode());
        foreach ([['GET', 'settings'], ['POST', 'settings'], ['POST', 'debug'], ['GET', 'status'], ['GET', 'export'], ['POST', 'health'], ['POST', 'refresh'], ['POST', 'renew']] as [$m, $p]) {
            $r = $this->call($m, $p, ['config' => $this->settings, 'enabled' => true, 'source_id' => 'a', 'target' => 'mihomo']);
            $this->assertSame(403, $r->getStatusCode(), $p);
            $this->assertStringNotContainsString('UPSTREAM_SECRET', $r->getContent());
        }
        $this->assertStringContainsString('EXTERNAL_PASSWORD', $this->subscribe('user-a-token')->getContent());
        $summary = $this->call('GET', 'session');
        $this->assertSame(200, $summary->getStatusCode());
        $this->assertStringNotContainsString('UPSTREAM_SECRET', $summary->getContent());
        $this->enable();
        $this->assertSame(200, $this->call('GET', 'settings')->getStatusCode());
    }
    public function testExpiryCannotBeRenewedAndNativeFormKeepsPrivateSources(): void
    {
        $this->enable();
        ConsoleAccess::status();
        PrivateStore::write('console', ['expires_at' => time() - 1]);
        $this->assertSame(403, $this->call('POST', 'renew')->getStatusCode());
        $this->assertFalse(Settings::raw()['console_open']);
        $this->assertSame($this->settings['sources'], Settings::load()['sources']);
        $this->assertStringNotContainsString('UPSTREAM_SECRET', file_get_contents(storage_path('app/external-node-bridge/settings.state')));
    }
    public function testLegacyMigrationKeepsIdsPrefixesAndRevision(): void
    {
        unlink(storage_path('app/external-node-bridge/settings.state'));
        $legacy = $this->settings;
        unset($legacy['sources'][0]['prefix']);
        $legacy['cache_revision'] = 'sce-v1.9.6';
        App\Models\Plugin::query()->update(['config' => json_encode($legacy)]);
        $c = Settings::load();
        $this->assertSame('[a]', $c['sources'][0]['prefix']);
        $this->assertSame('a', $c['sources'][0]['id']);
        $this->assertSame('sce-v1.9.6', $c['cache_revision']);
        app(App\Services\Plugin\PluginConfigService::class)->updateConfig(Settings::CODE, ['converter_url' => $c['converter_url'], 'console_open' => true]);
        $this->assertSame($c, Settings::load());
    }
    public function testNewSourceIdsAreGeneratedStableAndUrlsArePlainOnlyForAdmins(): void
    {
        $this->enable();
        $c = $this->settings;
        $new = self::source('tmp', [1]);
        unset($new['id']);
        $new['name'] = '外部 A';
        $c['sources'][] = $new;
        $this->assertSame(200, $this->call('POST', 'settings', ['config' => $c])->getStatusCode());
        $saved = Settings::load();
        $id = $saved['sources'][2]['id'];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $id);
        $this->assertSame('[外部 A]', $saved['sources'][2]['prefix']);
        $saved['sources'][2]['name'] = '改名';
        $this->call('POST', 'settings', ['config' => $saved]);
        $this->assertSame($id, Settings::load()['sources'][2]['id']);
        app('auth')->admin = (object)['is_admin' => false];
        $this->assertSame(403, $this->call('GET', 'session')->getStatusCode());
        $this->assertSame(403, $this->call('GET', 'settings')->getStatusCode());
    }
    public function testConverterVersionFailureKeepsCacheAndChangedVersionSchedulesRefresh(): void
    {
        $c = $this->settings;
        $c['debug'] = false;
        $source = $c['sources'][0];
        $this->cache('a', 'mihomo', [self::node()]);
        Http::fake(['*/version' => Http::sequence()->push('SubConverter-Extended v1.9.6 backend', 200)->push('private failure body', 503)->push('SubConverter-Extended v1.9.7 backend', 200), '*/sub*' => Http::response(Symfony\Component\Yaml\Yaml::dump(['proxies' => [self::node('New')]], 6), 200)]);
        $converter = new Converter($c);
        $first = $converter->version(true);
        $this->assertTrue($first['ok']);
        $this->assertSame('v1.9.6', $first['version']);
        $bad = $converter->version(true);
        $this->assertFalse($bad['ok']);
        $this->assertSame('v1.9.6', $bad['version']);
        $cache = new NodeCache($c, new Diagnostics($c));
        $this->assertTrue($cache->usable($cache->read($source, 'mihomo')));
        $converter->version(true);
        $entry = $cache->read($source, 'mihomo');
        $entry['attempted_at'] = time() - 301;
        $entry['converter_version'] = 'v1.9.6';
        $cache->write($source, 'mihomo', $entry);
        Refresher::create($c)->refresh($source, 'mihomo');
        $new = $cache->read($source, 'mihomo');
        $this->assertSame('v1.9.7', $new['converter_version']);
        $this->assertSame('New', $new['nodes'][0]['name']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/version') && $r->hasHeader('Sec-Fetch-Mode', 'cors') && $r->hasHeader('Sec-Fetch-Dest', 'empty'));
    }
}
