<?php

require_once dirname(__DIR__).'/tools/migrations/ReleaseInstaller.php';

final class ReleaseInstallerTest extends BridgeTestCase
{
    public function testMigrationFromLegacyDatabaseOnlyAndFailureRollback(): void
    {
        unlink(storage_path('app/external-node-bridge/settings.state'));
        $legacy = $this->settings;
        unset($legacy['sources'][0]['prefix']);
        App\Models\Plugin::query()->update(['version' => '0.1.1','config' => json_encode($legacy)]);
        $dir = storage_path('old-plugin');
        mkdir($dir, 0700, true);
        $manifest = json_decode(file_get_contents(dirname(__DIR__).'/ExternalNodeBridge/config.json'), true);
        $manifest['version'] = '0.1.1';
        file_put_contents($dir.'/config.json', json_encode($manifest));
        $public = public_path('plugins/external_node_bridge');
        mkdir($public, 0700, true);
        file_put_contents($public.'/console.html', 'old public');
        $installer = new ReleaseInstaller(dirname(__DIR__).'/ExternalNodeBridge', $dir, $public);
        $backup = $installer->apply();
        $c = Plugin\ExternalNodeBridge\Services\Settings::load();
        $this->assertSame('[a]', $c['sources'][0]['prefix']);
        $this->assertSame($legacy['sources'][0]['url'], $c['sources'][0]['url']);
        $installer->restore($backup);
        $bad = storage_path('incomplete-release');
        mkdir($bad, 0700);
        copy(dirname(__DIR__).'/ExternalNodeBridge/config.json', $bad.'/config.json');
        try {
            (new ReleaseInstaller($bad, $dir, $public))->apply();
            $this->fail('Expected rollback');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('已恢复备份', $e->getMessage());
        }
        $this->assertSame('0.1.1', App\Models\Plugin::first()->version);
        $this->assertSame('old public', file_get_contents($public.'/console.html'));
        $this->assertSame($legacy, Plugin\ExternalNodeBridge\Services\Settings::raw());
    }

    public function testPrototypeRebasePreservesDataAndRollbackRestoresFilesAndVersion(): void
    {
        $dir = storage_path('fixture-plugin');
        mkdir($dir, 0700, true);
        mkdir($dir.'/resources/assets', 0700, true);
        $legacy = json_decode(file_get_contents(dirname(__DIR__).'/ExternalNodeBridge/config.json'), true);
        $legacy['version'] = '0.1.1';
        file_put_contents($dir.'/config.json', json_encode($legacy));
        file_put_contents($dir.'/README.md', 'old plugin');
        file_put_contents($dir.'/resources/assets/console.html', 'old UI');
        $public = public_path('plugins/external_node_bridge');
        mkdir($public, 0700, true);
        file_put_contents($public.'/console.html', 'old public');
        App\Models\Plugin::query()->update(['version' => '0.1.1']);
        $this->cache('a', 'mihomo', [self::node()]);
        $installer = new ReleaseInstaller(dirname(__DIR__).'/ExternalNodeBridge', $dir, $public);
        $backup = $installer->apply();
        $this->assertSame('0.1.0', App\Models\Plugin::first()->version);
        $this->assertSame($this->settings['sources'], Plugin\ExternalNodeBridge\Services\Settings::load()['sources']);
        $this->assertFalse(Plugin\ExternalNodeBridge\Services\ConsoleAccess::status()['open']);
        $this->assertStringContainsString('EXTERNAL_PASSWORD', $this->subscribe('user-a-token')->getContent());
        $this->assertStringContainsString('id="plugin-version"', file_get_contents($public.'/console.html'));
        $this->assertStringNotContainsString('UPSTREAM_SECRET', file_get_contents($backup.'/database.enc'));
        $installer->restore($backup);
        $this->assertSame('0.1.1', App\Models\Plugin::first()->version);
        $this->assertSame('old plugin',file_get_contents($dir.'/README.md'));
        $this->assertSame('old public',file_get_contents($public.'/console.html'));
    }
}
