<?php

use Illuminate\Support\Facades\Http;
use Plugin\ExternalNodeBridge\Services\{Diagnostics, NodeCache, Settings};
use Plugin\ExternalNodeBridge\Services\Upstream\{AccountManager, AccountStore};

final class UpstreamRecoveryTest extends BridgeTestCase
{
    public function testSchedulerPrunesCancelledFormatsWithoutResumingPausedAccounts(): void
    {
        $this->account();
        $state = AccountStore::read('a');
        $state['refresh_pending'] = ['mihomo', 'sssub'];
        $state['paused'] = true;
        $state['error'] = 'UPSTREAM_SECURITY_CHALLENGE';
        AccountStore::write('a', $state);
        Http::fake();
        AccountManager::due();
        $current = AccountStore::read('a');
        $this->assertSame(['mihomo'], $current['refresh_pending']);
        $this->assertTrue($current['paused']);
        $this->assertSame($state['error'], $current['error']);
        $this->settings['sources'][0]['targets'] = [];
        $this->saveSettings($this->settings);
        AccountManager::due();
        $this->assertSame([], AccountStore::read('a')['refresh_pending']);
        Http::assertNothingSent();
    }

    private function account(): void
    {
        AccountStore::save('a', ['enabled' => true, 'panel_url' => 'https://panel.example', 'auth_mode' => 'token', 'authorization' => 'synthetic-auth'], null);
    }

    public function testExpiryRotationHonorsGroupCursorAndCooldown(): void
    {
        AccountStore::save('a', ['enabled' => true, 'panel_url' => 'https://panel.example', 'auth_mode' => 'token',
            'authorization' => 'synthetic-auth', 'rotation_mode' => 'user_expiry', 'rotation_verified' => true], null);
        $state = AccountStore::read('a');
        $state['expiry_cursor'] = time() - 60;
        $state['next_check_at'] = time() + 3600;
        AccountStore::write('a', $state);
        App\Models\User::where('id', 2)->update(['expired_at' => time() - 10]);
        App\Models\User::where('id', 1)->update(['expired_at' => time() - 120]);
        $source = $this->settings['sources'][0];
        $resets = 0;
        Http::fake(function ($request) use (&$resets, $source) {
            if (str_contains($request->url(), '/resetSecurity')) {
                $resets++;
                return Http::response(['data' => true]);
            }
            return Http::response($this->subscriptionData($resets ? 'https://upstream.example/rotated' : $source['url'], $resets ? 'rotated-credential' : 'old-credential'));
        });
        AccountManager::due();
        Http::assertNothingSent();
        App\Models\User::where('id', 1)->update(['expired_at' => time() - 10]);
        $this->cache('a', 'mihomo', [self::node()]);
        AccountManager::due();
        $this->assertSame(1, $resets);
        $this->assertSame('complete', AccountStore::read('a')['rotation']['phase']);
        $this->assertSame('https://upstream.example/rotated', Settings::load()['sources'][0]['url']);
        $this->assertSame([], (new NodeCache($this->settings, new Diagnostics($this->settings)))->read($source, 'mihomo'));
        $state = AccountStore::read('a');
        $state['expiry_cursor'] = time() - 60;
        AccountStore::write('a', $state);
        AccountManager::due();
        $this->assertSame(1, $resets);
    }

    private function subscriptionData(string $url, string $uuid = 'old-credential'): array
    {
        return ['data' => ['subscribe_url' => $url, 'uuid' => $uuid, 'expired_at' => null, 'transfer_enable' => 1000, 'u' => 1, 'd' => 2]];
    }

    public function testChallengePausesAccountWithoutRetryingOrSendingReset(): void
    {
        $this->account();
        Http::fake(fn () => Http::response('<html>challenge-platform</html>', 403, ['Content-Type' => 'text/html', 'cf-mitigated' => 'challenge']));
        $result = AccountManager::run('a', 'check');
        $this->assertSame('UPSTREAM_SECURITY_CHALLENGE', $result['error']);
        $this->assertTrue($result['paused']);
        AccountManager::run('a', 'check', true);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'resetSecurity'));
    }

    public function testLostResetResponseOnlyQueriesOnResumeAndInvalidatesOldCache(): void
    {
        $this->account();
        $source = $this->settings['sources'][0];
        $this->cache('a', 'mihomo', [self::node('old cached node')]);
        $new = false;
        $resets = 0;
        Http::fake(function ($request) use (&$new, &$resets, $source) {
            if (str_contains($request->url(), '/resetSecurity')) {
                $resets++;
                throw new Illuminate\Http\Client\ConnectionException('synthetic lost response');
            }
            return Http::response($this->subscriptionData($new ? 'https://upstream.example/new' : $source['url'], $new ? 'new-credential' : 'old-credential'));
        });
        $first = AccountManager::run('a', 'rotate');
        $this->assertTrue($first['suspended']);
        $this->assertSame('reset_requested', $first['rotation_phase']);
        $this->assertStringNotContainsString('old cached node', $this->subscribe('user-a-token')->getContent());
        $second = AccountManager::run('a', 'resume');
        $this->assertSame('UPSTREAM_RESET_UNCONFIRMED', $second['error']);
        $this->assertSame(1, $resets);
        $new = true;
        $third = AccountManager::run('a', 'resume');
        $this->assertFalse($third['suspended']);
        $this->assertSame('complete', $third['rotation_phase']);
        $this->assertSame(1, $resets);
        $this->assertSame('https://upstream.example/new', Settings::load()['sources'][0]['url']);
        $this->assertSame($source['targets'], $third['refresh_pending']);
        $cache = new NodeCache($this->settings, new Diagnostics($this->settings));
        $this->assertSame([], $cache->read($source, 'mihomo'));
    }
}
