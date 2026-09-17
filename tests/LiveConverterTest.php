<?php

use Plugin\ExternalNodeBridge\Services\{Converter, Diagnostics, Merger, NodeCache, Refresher};

final class LiveConverterTest extends BridgeTestCase
{
    public function testEveryNativeGeneratorThroughRealConverter(): void
    {
        $url = getenv('BRIDGE_LIVE_CONVERTER_URL');
        $sourceUrl = getenv('BRIDGE_LIVE_SOURCE_URL');
        if (!$url || !$sourceUrl) {
            $this->markTestSkipped('Needs the local fixture server.');
        }
        $this->settings['converter_url'] = $url;
        $this->settings['sources'][0]['url'] = $sourceUrl;
        $this->settings['sources'][0]['targets'] = Plugin\ExternalNodeBridge\Services\Settings::TARGETS;
        $this->settings['debug'] = false;
        $this->saveSettings($this->settings);
        $converter = new Converter($this->settings);
        $version = $converter->version(true);
        $this->assertTrue($version['ok'], json_encode($version));
        $source = $this->settings['sources'][0];
        $proof = [];
        foreach (['mihomo' => ['clash','meta'],'stash' => ['stash'],'surge' => ['surge'],'surfboard' => ['surfboard'],'loon' => ['loon'],'quanx' => ['quantumult-x'],'mixed' => ['general','v2rayn','v2rayng'],'sssub' => ['shadowsocks'],'shadowrocket' => ['shadowrocket'],'singbox' => ['sing-box/1.12.0']] as $target => $flags) {
            $result = Refresher::create($this->settings)->refresh($source, $target, true);
            $this->assertNull($result['error'], $target.' '.json_encode($result));
            $this->assertGreaterThan(0, $result['count']);
            foreach ($flags as $flag) {
                $response = $this->subscribe('user-a-token', $flag);
                $this->assertSame(200, $response->getStatusCode(), $flag);
                $body = $response->getContent();
                if (in_array($target, ['quanx','mixed','shadowrocket'])) {
                    $body = base64_decode($body, true);
                }
                $this->assertStringContainsString('ss.example.test', $body, $flag.' external SS');
                if ($target !== 'sssub') {
                    $this->assertStringContainsString('user-a-private', $body, $flag.' own node');
                }
                $unauthorized = $this->subscribe('user-b-token', $flag)->getContent();
                if (in_array($target, ['quanx','mixed','shadowrocket'])) {
                    $unauthorized = base64_decode($unauthorized, true);
                }
                $this->assertStringNotContainsString('EXTERNAL-TROJAN-PASSWORD', $unauthorized);
                $this->assertStringNotContainsString('ss.example.test', $unauthorized);
            }
            $proof[$target] = ['flags' => $flags,'external_nodes' => $result['count']];
        }
        file_put_contents(__DIR__.'/runtime/live/all-formats.json', json_encode($proof, JSON_PRETTY_PRINT));
    }

    public function testUaDependentXhttpSourcePreservesFieldsThroughActualConversionAndMerge(): void
    {
        $url = getenv('BRIDGE_LIVE_CONVERTER_URL');
        $sourceUrl = getenv('BRIDGE_LIVE_SOURCE_URL');
        if (!$url || !$sourceUrl) {
            $this->markTestSkipped('Needs the local fixture server.');
        }
        $this->settings['converter_url'] = $url;
        $this->settings['sources'][0]['url'] = $sourceUrl.'/xhttp';
        $this->saveSettings($this->settings);
        $source = $this->settings['sources'][0];
        $old = $this->settings;
        $old['upstream_user_agent'] = 'XBoard-ExternalNodeBridge/0.1.0';
        $broken = Merger::extract((new Converter($old))->convert($source, 'mihomo'), 'mihomo')[0];
        $this->assertSame(['port' => 443], $broken['xhttp-opts']['download-settings']);
        $this->assertArrayNotHasKey('skip-cert-verify', $broken);
        $result = Refresher::create($this->settings)->refresh($source, 'mihomo', true);
        $this->assertNull($result['error']);
        $this->assertSame(1, $result['count']);
        $out = Symfony\Component\Yaml\Yaml::parse($this->subscribe('user-a-token')->getContent());
        $external = collect($out['proxies'])->firstWhere('name', '[a] XHTTP-test');
        $this->assertNotNull($external);
        $this->assertSame(true, $external['skip-cert-verify']);
        $this->assertSame('/up', $external['xhttp-opts']['path']);
        $this->assertSame('down.example.test', $external['xhttp-opts']['download-settings']['server']);
        $this->assertSame('/down', $external['xhttp-opts']['download-settings']['path']);
        $this->assertSame('down-tls.example.test', $external['xhttp-opts']['download-settings']['servername']);
        $this->assertSame(443, $external['xhttp-opts']['download-settings']['port']);
        $events = (new Diagnostics($this->settings))->recent();
        $shape = collect($events)->firstWhere('event', 'XHTTP_FIELDS');
        $this->assertSame(1, $shape['download_servers']);
        $this->assertSame(1, $shape['download_paths']);
        $this->assertSame(1, $shape['download_sni']);
        $this->assertSame(1, $shape['insecure_nodes']);
        $this->assertStringNotContainsString('down.example.test', json_encode($events));
    }

    public function testRealConverterAndNativeXboardGenerators(): void
    {
        $url = getenv('BRIDGE_LIVE_CONVERTER_URL');
        $sourceUrl = getenv('BRIDGE_LIVE_SOURCE_URL');
        if (!$url || !$sourceUrl) {
            $this->markTestSkipped('Run tests/live_converter.py with a verified v1.9.5 portable package.');
        }
        $this->settings['converter_url'] = $url;
        $this->settings['sources'][0]['url'] = $sourceUrl;
        $this->saveSettings($this->settings);
        $source = $this->settings['sources'][0];
        $ref = Refresher::create($this->settings);
        $proof = [];
        foreach (['mihomo' => 'meta','shadowrocket' => 'shadowrocket','singbox' => 'sing-box/1.12.0'] as $target => $flag) {
            $result = $ref->refresh($source, $target, true);
            $this->assertNull($result['error'], $target.' conversion error');
            $this->assertTrue($result['usable'], $target.' usable');
            $this->assertGreaterThanOrEqual(3, $result['count']);
            $entry = (new NodeCache($this->settings, new Diagnostics($this->settings)))->read($source, $target);
            $proof[$target] = ['count' => $result['count'],'protocols' => array_map(fn ($n) => is_array($n) ? $n['type'] : explode('://', $n, 2)[0], $entry['nodes'])];
            $this->assertSame($target === 'shadowrocket' ? 4 : 5, $result['count']);
            if ($target === 'shadowrocket') {
                $this->assertNotContains('tuic', $proof[$target]['protocols']);
            }
            $response = $this->subscribe('user-a-token', $flag);
            $this->assertSame(200, $response->getStatusCode());
            $body = $response->getContent();
            if ($target === 'shadowrocket') {
                $body = base64_decode($body, true);
            }
            $this->assertStringContainsString('EXTERNAL-TROJAN-PASSWORD', $body);
            $this->assertStringContainsString('user-a-private', $body);
            if ($target !== 'shadowrocket') {
                $out = $target === 'mihomo' ? Symfony\Component\Yaml\Yaml::parse($body) : json_decode($body, true);
                $nodes = $out[$target === 'mihomo' ? 'proxies' : 'outbounds'];
                $tuic = collect($nodes)->firstWhere('type', 'tuic');
                if ($tuic) {
                    $this->assertSame('TUIC-DIFFERENT-PASSWORD', $tuic['password']);
                    $this->assertSame('11111111-1111-4111-8111-111111111111', $tuic['uuid']);
                }
            }
        }
        $export = json_encode(Plugin\ExternalNodeBridge\Services\Report::make($this->settings));
        $this->assertStringNotContainsString('EXTERNAL-TROJAN-PASSWORD',$export);
        $this->assertStringNotContainsString($sourceUrl,$export);
        if (!is_dir(__DIR__.'/runtime/live')) {
            mkdir(__DIR__.'/runtime/live',0700,true);
        }
        file_put_contents(__DIR__.'/runtime/live/proof.json',json_encode($proof,JSON_PRETTY_PRINT));
    }
}
