<?php

use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\Http;
use Plugin\ExternalNodeBridge\Services\{Diagnostics, Merger, Refresher, Settings};
use Symfony\Component\Yaml\Yaml;

final class TemplateBridgeTest extends BridgeTestCase
{
    private function enableAll(): void
    {
        $this->settings['sources'][0]['targets'] = Settings::TARGETS;
        $this->saveSettings($this->settings);
    }

    public function testNativeYamlTemplatesFilterExternalNamesWithoutPersonalGroups(): void
    {
        $this->enableAll();
        foreach (['meta' => 'clashmeta', 'clash' => 'clash', 'stash' => 'stash'] as $flag => $template) {
            $target = $flag === 'stash' ? 'stash' : 'mihomo';
            $GLOBALS['test_templates'][$template] = Yaml::dump(['proxies' => [], 'proxy-groups' => [
                ['name' => 'CustomAll', 'type' => 'select', 'proxies' => []],
                ['name' => 'JapanOnly', 'type' => 'url-test', 'proxies' => ['/JP/'], 'url' => 'https://example.test/check', 'interval' => 123],
            ], 'dns' => ['enable' => false], 'rules' => ['MATCH,CustomAll']], 8);
            $this->cache('a', $target, [self::node('HK'), self::node('JP')]);
            // Request filters remove all native nodes, but external-only templates still work.
            $body = $this->subscribe('user-a-token', $flag, ['filter' => 'JP|HK', 'types' => 'trojan'])->getContent();
            $config = Yaml::parse($body);
            $groups = array_column($config['proxy-groups'], null, 'name');
            $this->assertSame(['[a] JP'], $groups['JapanOnly']['proxies']);
            $this->assertSame(['[a] HK', '[a] JP'], $groups['CustomAll']['proxies']);
            $this->assertSame(123, $groups['JapanOnly']['interval']);
            $this->assertSame(['enable' => false], $config['dns']);
            $this->assertCount(2, $groups);
            $this->assertStringNotContainsString('.invalid', $body);
        }
    }

    public function testXhttpPayloadAndDifferentCredentialsSurviveNativeLayout(): void
    {
        $node = ['name' => 'XHTTP', 'type' => 'vless', 'server' => 'up.example', 'port' => 443, 'uuid' => 'EXTERNAL_A', 'network' => 'xhttp', 'tls' => true,
            'xhttp-opts' => ['mode' => 'stream-up', 'path' => '/up', 'download-settings' => ['server' => 'down.example', 'path' => '/down', 'port' => 443, 'servername' => 'sni.example']]];
        $this->cache('a', 'mihomo', [$node, self::node('Trojan', 'EXTERNAL_B')]);
        $config = Yaml::parse($this->subscribe('user-a-token')->getContent());
        $by = array_column($config['proxies'], null, 'name');
        $node['name'] = '[a] XHTTP';
        $this->assertSame($node, $by['[a] XHTTP']);
        $this->assertSame('EXTERNAL_B', $by['[a] Trojan']['password']);
        $this->assertSame('user-a-private', $by['Own-1']['password']);
        $only = Yaml::parse($this->subscribe('user-a-token', 'meta', ['types' => 'vless'])->getContent());
        $this->assertSame(['[a] XHTTP'], array_column($only['proxies'], 'name'));
    }

    public function testSingboxNativeIncludeExcludeFallbackAndCredentials(): void
    {
        if (!class_exists('Log')) {
            class_alias(\Illuminate\Support\Facades\Log::class, 'Log');
        }
        $this->app->instance('log', new \Psr\Log\NullLogger());
        $GLOBALS['test_templates']['singbox']['outbounds'] = [
            ['type' => 'selector', 'tag' => 'All', 'outbounds' => []],
            ['type' => 'urltest', 'tag' => 'Japan', 'outbounds' => [], 'include' => 'JP', 'exclude' => 'HK'],
            ['type' => 'selector', 'tag' => 'Fallback', 'outbounds' => [], 'include' => 'NONE', 'fallback' => 'JP'],
        ];
        $nodes = [
            ['tag' => 'HK', 'type' => 'vless', 'server' => 'hk.example', 'server_port' => 443, 'uuid' => 'UPSTREAM_A'],
            ['tag' => 'JP', 'type' => 'trojan', 'server' => 'jp.example', 'server_port' => 443, 'password' => 'UPSTREAM_B', 'tls' => ['enabled' => true]],
        ];
        $this->cache('a', 'singbox', $nodes);
        foreach ([[], ['filter' => 'JP|HK']] as $query) {
            $body = $this->subscribe('user-a-token', 'sing-box/1.12.0', $query)->getContent();
            $by = array_column(json_decode($body, true)['outbounds'], null, 'tag');
            $this->assertSame(['[a] JP'], $by['Japan']['outbounds']);
            $this->assertSame(['[a] JP'], $by['Fallback']['outbounds']);
            foreach ($nodes as $node) {
                $node['tag'] = '[a] '.$node['tag'];
                $this->assertSame($node, $by[$node['tag']]);
            }
            $this->assertStringNotContainsString('.invalid', $body);
        }
    }

    public function testNativeIniTemplateControlsExactlyWhichGroupsReceiveNodes(): void
    {
        $this->enableAll();
        foreach (['surge', 'surfboard'] as $target) {
            $GLOBALS['test_templates'][$target] = "[General]\nloglevel = notify\n[Proxy]\n\$proxies\n[Proxy Group]\nAll = select, \$proxy_group\nFixed = select, DIRECT\n[Rule]\nFINAL,All\n";
            $this->cache('a', $target, Merger::extract('JP = trojan, jp.example, 443, password=UPSTREAM_A', $target));
            $body = $this->subscribe('user-a-token', $target)->getContent();
            $this->assertStringContainsString('[a] JP = trojan, jp.example, 443, password=UPSTREAM_A', $body);
            $this->assertStringContainsString('All = select, Own-1, [a] JP', $body);
            $this->assertStringContainsString('Fixed = select, DIRECT', $body);
            $this->assertStringNotContainsString('.invalid', $body);
        }
    }

    public function testTemplateAndNativeNameCollisionsRemapDependencies(): void
    {
        $this->settings['sources'][0]['prefix'] = '';
        $this->saveSettings($this->settings);
        $this->cache('a', 'mihomo', [self::node('Own-1'), self::node('Relay') + ['dialer-proxy' => 'Own-1'], self::node('GLOBAL')]);
        $by = array_column(Yaml::parse($this->subscribe('user-a-token')->getContent())['proxies'], null, 'name');
        $this->assertSame('Own-1 (2)', $by['Relay']['dialer-proxy']);
        $this->assertArrayHasKey('GLOBAL (2)', $by);
        $this->assertSame('user-a-private', $by['Own-1']['password']);
    }

    public function testTamperedCarrierFallsBackAndRequestStateIsCleaned(): void
    {
        $original = $this->subscribe('user-a-token')->getContent();
        $this->cache('a', 'mihomo', [self::node()]);
        HookManager::registerFilter('protocol.servers.filtered', function ($servers) {
            return array_values(array_filter($servers, fn ($s) => !str_ends_with($s['host'], '.invalid')));
        }, 10001);
        $this->assertSame($original, $this->subscribe('user-a-token')->getContent());
        $this->assertFalse(request()->attributes->has('external_node_bridge.layout'));
        $this->assertFalse(request()->attributes->has('external_node_bridge.running'));
        $this->assertStringContainsString('TEMPLATE_REPLACEMENT_FAILED', json_encode((new Diagnostics($this->settings))->recent()));
    }

    public function testExactConverterEmptyResponseIsNotAnOrdinaryHttpFailure(): void
    {
        $c = $this->settings;
        $c['debug'] = false;
        $source = $c['sources'][0];
        \Plugin\ExternalNodeBridge\Services\Upstream\AccountStore::save('a', ['enabled' => true, 'panel_url' => 'https://panel.example', 'auth_mode' => 'token', 'authorization' => 'synthetic-auth'], null);
        $account = \Plugin\ExternalNodeBridge\Services\Upstream\AccountStore::read('a');
        $account['refresh_pending'] = ['mihomo'];
        \Plugin\ExternalNodeBridge\Services\Upstream\AccountStore::write('a', $account);
        $this->cache('a', 'mihomo', [self::node()]);
        $empty = "Invalid request: none of the parsed proxy nodes can be represented by the selected output target.\n无效请求：解析到的代理节点均无法由所选输出目标表示。";
        Http::fake(['*' => Http::sequence()->push($empty, 400)->push('other error', 400)->push(Yaml::dump(['proxies' => [self::node()]]), 200)]);
        $refresher = Refresher::create($c);
        $state = $refresher->refresh($source, 'mihomo', true);
        $this->assertSame('NO_COMPATIBLE_NODES', $state['error']);
        $this->assertFalse($state['usable']);
        $this->assertSame(0, $state['count']);
        $this->assertSame([], \Plugin\ExternalNodeBridge\Services\Upstream\AccountStore::read('a')['refresh_pending']);
        $this->assertSame('NO_COMPATIBLE_NODES', $refresher->refresh($source, 'mihomo')['error']);
        Http::assertSentCount(1);
        $state = $refresher->refresh($source, 'mihomo', true);
        $this->assertSame('CONVERTER_HTTP_ERROR', $state['error']);
        $state = $refresher->refresh($source, 'mihomo', true);
        $this->assertTrue($state['usable']);
    }

    public function testNodeListFormatsKeepNativeOutputAndExternalCredentials(): void
    {
        $this->enableAll();
        foreach (['shadowrocket' => 'shadowrocket', 'general' => 'mixed'] as $flag => $target) {
            $uri = 'trojan://EXTERNAL_PASSWORD@external.example:443#Outside';
            $this->cache('a', $target, Merger::extract(base64_encode($uri), $target));
            $body = base64_decode($this->subscribe('user-a-token', $flag)->getContent(), true);
            $this->assertStringContainsString('user-a-private', $body);
            $this->assertStringContainsString('trojan://EXTERNAL_PASSWORD@external.example:443#%5Ba%5D%20Outside', $body);
        }
        $line = 'Outside = trojan, external.example, 443, EXTERNAL_PASSWORD, tls-name=example.test';
        $this->cache('a', 'loon', Merger::extract($line, 'loon'));
        $body = $this->subscribe('user-a-token', 'loon/3.2.1')->getContent();
        $this->assertStringContainsString('[a] '.$line, $body);
        $this->assertStringContainsString('user-a-private', $body);
        $line = 'trojan=external.example:443,password=EXTERNAL_PASSWORD,tag=Outside';
        foreach (['', 'X', 'XX'] as $suffix) {
            // Exercise all Base64 padding lengths as returned by different converters.
            $raw = $line.$suffix;
            $nodes = Merger::extract(base64_encode($raw), 'quanx');
            $this->assertSame($nodes, Merger::extract("# nodes\n".$raw, 'quanx'));
            $this->cache('a', 'quanx', $nodes);
            $body = base64_decode($this->subscribe('user-a-token', 'quantumult-x')->getContent(), true);
            $this->assertStringContainsString('password=EXTERNAL_PASSWORD,tag=[a] Outside'.$suffix, $body);
            $this->assertStringContainsString('user-a-private', $body);
        }
        $node = ['remarks' => 'Outside', 'server' => 'external.example', 'server_port' => 443, 'method' => 'aes-128-gcm', 'password' => 'EXTERNAL_PASSWORD'];
        $this->cache('a', 'sssub', Merger::extract(json_encode(['servers' => [$node]]), 'sssub'));
        $body = json_decode($this->subscribe('user-a-token', 'shadowsocks')->getContent(), true);
        $this->assertSame('EXTERNAL_PASSWORD', $body['servers'][0]['password']);
        $this->assertSame('[a] Outside', $body['servers'][0]['remarks']);
        $this->assertArrayHasKey('bytes_remaining', $body);
    }
}
