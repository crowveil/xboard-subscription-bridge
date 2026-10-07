<?php

use Illuminate\Support\Facades\{File, Http};
use Plugin\ExternalNodeBridge\Services\{Assets, BridgeException, ConsoleAccess, Converter, DeploymentCheck, PrivateStore, Settings};
use Plugin\ExternalNodeBridge\Services\Upstream\{AccountStore, Notifier, PanelHttp};

final class RecoveryTest extends BridgeTestCase
{
    public function testRuntimeReportChecksLoadedInterfaces(): void
    {
        $runtime = \Plugin\ExternalNodeBridge\Services\RuntimeCompatibility::inspect();
        $this->assertTrue($runtime['compatible']);
        $this->assertTrue($runtime['interfaces']['SubscriptionBridge::compose']);
        $this->assertTrue($runtime['interfaces']['TemplateBridge::inject']);
        $this->assertSame(getmypid(), $runtime['process_id']);
    }

    public function testRebuildRestoresAllAssetsButExistingDirectoryIsNotRewritten(): void
    {
        File::deleteDirectory(Assets::destination());
        (new Plugin\ExternalNodeBridge\Plugin(Settings::CODE))->boot();
        foreach (Assets::FILES as $file) {
            $this->assertSame(file_get_contents(Assets::source().'/'.$file), file_get_contents(Assets::destination().'/'.$file));
        }
        file_put_contents(Assets::destination().'/console.html', 'existing page');
        unlink(Assets::destination().'/accounts.js');
        (new Plugin\ExternalNodeBridge\Plugin(Settings::CODE))->boot();
        $this->assertSame('existing page', file_get_contents(Assets::destination().'/console.html'));
        $this->assertFileDoesNotExist(Assets::destination().'/accounts.js');
        $this->assertFalse(DeploymentCheck::inspect(true)['ok']);
        Assets::publish(true, true);
        $this->assertTrue(DeploymentCheck::inspect(true)['ok']);
    }

    public function testFailedPublishLeavesNoEmptySuccessDirectoryAndCanRetry(): void
    {
        File::deleteDirectory(Assets::destination());
        $file = Assets::source().'/accounts.js';
        rename($file, $file.'.test-backup');
        try {
            try {
                Assets::publish();
                $this->fail('Missing source must fail publication');
            } catch (BridgeException $e) {
                $this->assertSame('ASSET_PUBLISH_FAILED', $e->reason);
            }
            $this->assertDirectoryDoesNotExist(Assets::destination());
            $this->assertSame([], glob(Assets::destination().'.stage-*'));
        } finally {
            rename($file.'.test-backup', $file);
        }
        Assets::publish();
        $this->assertFileExists(Assets::destination().'/accounts.js');
    }

    public function testFailedUpdatePreservesExistingPage(): void
    {
        file_put_contents(Assets::destination().'/console.html', 'previous valid page');
        $file = Assets::source().'/accounts.js';
        rename($file, $file.'.test-backup');
        try {
            try {
                Assets::publish(true);
                $this->fail('Update must fail');
            } catch (BridgeException) {
                $this->assertSame('previous valid page', file_get_contents(Assets::destination().'/console.html'));
            }
        } finally {
            rename($file.'.test-backup', $file);
        }
    }

    public function testDisableAndReenablePreservePrivateDataAndOtherPluginAssets(): void
    {
        $manager = app(App\Services\Plugin\PluginManager::class);
        mkdir(public_path('plugins/other'));
        file_put_contents(public_path('plugins/other/keep'), 'keep');
        $raw = Settings::raw();
        $raw['console_open'] = true;
        app(App\Services\Plugin\PluginConfigService::class)->updateConfig(Settings::CODE, $raw);
        $this->assertTrue(ConsoleAccess::status()['open']);
        ConsoleAccess::status(false, true);
        $this->assertDirectoryExists(Assets::destination());
        $this->assertSame($this->settings['sources'], Settings::load()['sources']);
        $this->assertTrue($manager->disable(Settings::CODE));
        $this->assertDirectoryDoesNotExist(Assets::destination());
        $this->assertFileExists(public_path('plugins/other/keep'));
        $this->assertSame($this->settings['sources'], PrivateStore::read('settings')['sources']);
        (new Plugin\ExternalNodeBridge\Plugin(Settings::CODE))->boot();
        $this->assertDirectoryDoesNotExist(Assets::destination());
        $this->assertTrue($manager->enable(Settings::CODE));
        $this->assertFileExists(Assets::destination().'/accounts.js');
        $this->assertFalse(ConsoleAccess::status()['open']);
    }

    public function testDisabledLongLivedServicesNeverSendNewNetworkRequests(): void
    {
        Http::fake();
        $converter = new Converter($this->settings);
        $panel = new PanelHttp('https://panel.example/api/v1');
        App\Models\Plugin::query()->update(['is_enabled' => false]);
        foreach ([fn () => $converter->convert($this->settings['sources'][0], 'mihomo'), fn () => $panel->json('GET', '/user/resetSecurity'), fn () => Notifier::flush()] as $action) {
            try {
                $action();
                $this->fail('Disabled service must stop');
            } catch (BridgeException $e) {
                $this->assertSame('PLUGIN_DISABLED', $e->reason);
            }
        }
        Http::assertNothingSent();
    }

    public function testUnconfirmedRotationCannotDistributeCachedNodes(): void
    {
        $this->cache('a', 'mihomo', [self::node('must not distribute')]);
        AccountStore::write('a', ['rotation' => ['phase' => 'reset_requested']]);
        $response = $this->subscribe('user-a-token');
        $this->assertStringNotContainsString('must not distribute', $response->getContent());
    }

    public function testDecryptionFailureIsVisibleWithoutOverwritingOriginalState(): void
    {
        $file = storage_path('app/external-node-bridge/settings.state');
        $original = file_get_contents($file);
        app('config')->set('app.key', 'base64:'.base64_encode(str_repeat('z', 32)));
        app()->forgetInstance('encrypter');
        Illuminate\Support\Facades\Crypt::clearResolvedInstance('encrypter');
        $report = DeploymentCheck::inspect(true);
        $this->assertFalse($report['ok']);
        $this->assertSame('PRIVATE_STATE_UNREADABLE', $report['state']);
        $this->assertSame($original, file_get_contents($file));
    }
}
