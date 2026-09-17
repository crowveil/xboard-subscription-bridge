<?php

use Plugin\ExternalNodeBridge\Services\{BridgeException, Converter, Diagnostics, Merger, NodeCache, Refresher, Report, Settings};
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;

final class BridgeTest extends BridgeTestCase
{
    public function testNativeTokenGateRejectsUnknownTokenBeforePlugin(): void
    {
        $this->expectException(App\Exceptions\ApiException::class);
        try {
            $this->subscribe('wrong');
        } finally {
            $this->assertSame(0, App\Services\ServerService::$calls);
        }
    }
    public function testNativeAccountGateRunsBeforePlugin(): void
    {
        $this->assertSame(403, $this->subscribe('expired-token')->getStatusCode());
        $this->assertSame(0, App\Services\ServerService::$calls);
    }
    public function testGroupsAreIsolatedAcrossSequentialRequestsAndSpoofedGroupIsIgnored(): void
    {
        $this->cache('a', 'mihomo', [self::node('A')]);
        $this->cache('b', 'mihomo', [self::node('B')]);
        foreach ([['user-a-token','[a] A','[b] B'],['user-b-token','[b] B','[a] A'],['user-a-token','[a] A','[b] B']] as [$token,$yes,$no]) {
            $r = $this->subscribe($token, 'meta', ['group_id' => 2]);
            $this->assertStringContainsString($yes, $r->getContent());
            $this->assertStringNotContainsString($no, $r->getContent());
            $this->assertTrue($r->headers->hasCacheControlDirective('private'));
            $this->assertTrue($r->headers->hasCacheControlDirective('no-store'));
            $this->assertNotNull($r->headers->get('X-External-Bridge-Trace'));
        }
        $this->assertSame(3, App\Services\ServerService::$calls, 'doSubscribe explicit servers must not recurse');
    }
    public function testGroupRevocationAndPluginDisableTakeEffectWithExistingCache(): void
    {
        $this->cache('a', 'mihomo', [self::node()]);
        $this->assertStringContainsString('[a] HK', $this->subscribe('user-a-token')->getContent());
        $c = $this->settings;
        $c['sources'][0]['group_ids'] = [];
        $this->saveSettings($c);
        $this->assertStringNotContainsString('[a] HK', $this->subscribe('user-a-token')->getContent());
        $this->saveSettings($this->settings);
        App\Models\Plugin::query()->update(['is_enabled' => false]);
        $this->assertStringNotContainsString('[a] HK', $this->subscribe('user-a-token')->getContent());
    }
    public function testCacheMissAndExpiryDoNotCallConverter(): void
    {
        Http::fake();
        $this->assertStringContainsString('Own-1', $this->subscribe('user-a-token')->getContent());
        $this->cache('a', 'mihomo', [self::node()], 86401);
        $this->assertStringNotContainsString('[a] HK', $this->subscribe('user-a-token')->getContent());
        Http::assertNothingSent();
    }
    public function testShadowrocketPreservesOwnAndExternalCredentials(): void
    {
        $uri = 'trojan://EXTERNAL_PASSWORD@external.example:443#Outside';
        $this->cache('a', 'shadowrocket', [$uri]);
        $body = base64_decode($this->subscribe('user-a-token', 'shadowrocket')->getContent(), true);
        $this->assertStringContainsString(explode('#', $uri)[0].'#%5Ba%5D%20Outside', $body);
        $this->assertStringContainsString('user-a-private', $body);
        $this->assertStringContainsString('STATUS=', $body);
    }
    public function testSingboxNativeResponseCanBeMerged(): void
    {
        $this->cache('a', 'singbox', [['type' => 'trojan','tag' => 'Outside','server' => 'external.example','server_port' => 443,'password' => 'EXTERNAL_PASSWORD','tls' => ['enabled' => true]]]);
        $body = json_decode($this->subscribe('user-a-token', 'sing-box/1.12.0')->getContent(), true);
        $byTag = array_column($body['outbounds'], null, 'tag');
        $this->assertSame('EXTERNAL_PASSWORD', $byTag['[a] Outside']['password']);
        $this->assertContains('[a] Outside', $byTag['Select']['outbounds']);
    }
    public function testMergeFailureReturnsExactOriginalResponse(): void
    {
        $this->settings['mihomo_groups'] = [];
        $this->saveSettings($this->settings);
        $original = $this->subscribe('user-a-token')->getContent();
        $this->cache('a', 'mihomo', [self::node()]);
        $this->assertSame($original, $this->subscribe('user-a-token')->getContent());
        $this->assertStringContainsString('NO_DESTINATION_GROUP', json_encode((new Diagnostics($this->settings))->recent()));
    }
    public function testNativeAdminGateRejectsAnonymousAndNonAdmin(): void
    {
        $r = Illuminate\Http\Request::create('/admin/export');
        $middleware = new App\Http\Middleware\Admin();
        $this->assertSame(403, $middleware->handle($r, fn () => response('secret'))->getStatusCode());
        app('auth')->admin = (object)['is_admin' => false];
        $this->assertSame(403, $middleware->handle($r, fn () => response('secret'))->getStatusCode());
        app('auth')->admin = (object)['is_admin' => true];
        $this->assertSame('allowed', $middleware->handle($r, fn () => response('allowed'))->getContent());
    }
    public function testDiagnosticsDiscardSecretsAndDebugExpiry(): void
    {
        $log = new Diagnostics($this->settings);
        $log->record('CHECK', ['url' => 'https://secret.example/?token=LEAK','password' => 'LEAK','body' => 'LEAK','error' => 'https://secret.example','count' => 3,'source_id' => 'a']);
        $report = json_encode(Report::make($this->settings));
        foreach (['LEAK','UPSTREAM_SECRET','EXTERNAL_PASSWORD','user-a-token','secret.example'] as $secret) {
            $this->assertStringNotContainsString($secret, $report);
        }
        $this->assertStringContainsString('CHECK', $report);
        $expired = new Diagnostics(array_replace($this->settings, ['debug_until' => time() - 1]));
        $expired->record('SHOULD_NOT_APPEAR');
        $this->assertStringNotContainsString('SHOULD_NOT_APPEAR', json_encode($expired->recent()));
    }
    public function testCacheIsEncryptedAndRevisionInvalidatesIt(): void
    {
        $this->cache('a', 'mihomo', [self::node()]);
        $dir = (new Diagnostics($this->settings))->dir();
        $file = glob($dir.'/*.cache')[0];
        $this->assertStringNotContainsString('EXTERNAL_PASSWORD', file_get_contents($file));
        $c = $this->settings;
        $c['cache_revision'] = 'next';
        $cache = new NodeCache($c, new Diagnostics($c));
        $this->assertSame([], $cache->read($c['sources'][0], 'mihomo'));
    }
    public function testProviderRemovalPreservesRuleProvidersAndRebuildsEmptyGroups(): void
    {
        $base = ['proxies' => [],'proxy-providers' => ['old' => ['url' => 'secret'],'keep' => ['url' => 'other']],'rule-providers' => ['rules' => ['url' => 'rules']],'proxy-groups' => [['name' => '🚀 节点选择','type' => 'select','proxies' => ['DIRECT'],'use' => ['old','keep']]],'rules' => ['MATCH,🚀 节点选择']];
        $c = $this->settings;
        $c['remove_provider_keys'] = ['old'];
        $r = Merger::merge(Yaml::dump($base, 8), 'mihomo', [['source_id' => 'a','nodes' => [self::node()]]], $c);
        $out = Yaml::parse($r['body']);
        $this->assertArrayNotHasKey('old', $out['proxy-providers']);
        $this->assertSame($base['rule-providers'], $out['rule-providers']);
        $this->assertSame(['keep'], $out['proxy-groups'][0]['use']);
        $auto = collect($out['proxy-groups'])->firstWhere('name', '♻️ 自动选择');
        $this->assertSame('url-test', $auto['type']);
        $this->assertContains('[a] HK', $auto['proxies']);
    }
    public function testDependenciesAreRemappedAndBrokenChainsRemoved(): void
    {
        $nodes = [self::node('Exit'),self::node('Relay') + ['dialer-proxy' => 'Exit'],self::node('Broken') + ['dialer-proxy' => 'Missing'],self::node('Cycle') + ['dialer-proxy' => 'Cycle']];
        $base = "proxies: []\nproxy-groups: []\nrules: []\n";
        $out = Merger::merge($base, 'mihomo', [['source_id' => 'a','nodes' => $nodes]], $this->settings);
        $byName = array_column(Yaml::parse($out['body'])['proxies'], null, 'name');
        $this->assertSame('[a] Exit', $byName['[a] Relay']['dialer-proxy']);
        $this->assertCount(2, $byName);
        $this->assertSame(2, $out['skipped']);
    }
    public function testTypesAndNameFilterApplyToExternalNodes(): void
    {
        $this->cache('a', 'mihomo', [self::node('HK'),self::node('US')]);
        $body = $this->subscribe('user-a-token', 'meta', ['filter' => 'HK','types' => 'trojan'])->getContent();
        $this->assertStringContainsString('[a] HK', $body);
        $this->assertStringNotContainsString('[a] US', $body);
    }
    public function testRefreshUsesListModeAndRecoversFromTransientFailure(): void
    {
        $c = $this->settings;
        $c['debug'] = false;
        $log = new Diagnostics($c);
        $cache = new NodeCache($c, $log);
        $source = $c['sources'][0];
        $ref = new Refresher($c, $log, $cache, new Converter($c));
        Http::fake(['*' => Http::sequence()->push(Yaml::dump(['proxies' => [self::node()]], 6), 200)->push('secret error body', 503)->push('unsupported', 400)]);
        $first = $ref->refresh($source, 'mihomo', true);
        $this->assertTrue($first['usable']);
        $second = $ref->refresh($source, 'mihomo', true);
        $this->assertTrue($second['usable']);
        $this->assertSame('CONVERTER_HTTP_ERROR', $second['error']);
        $third = $ref->refresh($source, 'mihomo', true);
        $this->assertFalse($third['usable']);
        $this->assertSame(0, $third['count']);
        Http::assertSent(fn ($r) => $r['target'] === 'clash' && $r['list'] === 'true' && $r['insert'] === 'false' && $r->hasHeader('User-Agent', 'clash.meta'));
        $this->assertStringNotContainsString('secret error body', json_encode($log->recent()));
    }
    public function testMalformedAndProviderOutputAreRejected(): void
    {
        foreach (['<html>error</html>',"proxy-providers:\n  a: {type: http}\nproxies: []"] as $input) {
            try {
                Merger::extract($input, 'mihomo');
                $this->fail('Expected rejection');
            } catch (BridgeException $e) {
                $this->assertContains($e->reason, ['OUTPUT_INVALID','PROVIDER_OUTPUT_REJECTED']);
            }
        }
    }
    public function testOnlyExplicitGeneratorMappingsAreSupported(): void
    {
        $this->assertSame('mihomo', Merger::target('App\\Protocols\\Clash'));
        $this->assertSame('mixed', Merger::target('App\\Protocols\\General'));
        $this->assertNull(Merger::target('App\\Protocols\\Unrecognized'));
        $this->assertSame('mihomo', Merger::target('App\\Protocols\\ClashMeta'));
    }
    public function testUpstreamUaIsValidatedAndSeparatesCache(): void
    {
        $this->cache('a','mihomo',[self::node()]);
        $c = $this->settings;
        $c['upstream_user_agent'] = 'Clash-Verge/test';
        $cache = new NodeCache($c,new Diagnostics($c));
        $this->assertSame([],$cache->read($c['sources'][0],'mihomo'));
        $c['upstream_user_agent'] = "clash.meta\r\nX-Injected: value";
        $this->expectException(BridgeException::class);
        Settings::normalize($c);
    }
}
