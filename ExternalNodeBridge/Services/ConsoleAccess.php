<?php

namespace Plugin\ExternalNodeBridge\Services;

use App\Services\Plugin\PluginConfigService;

final class ConsoleAccess
{
    public const TTL = 3600;

    public static function status(bool $renew = false, bool $close = false): array
    {
        return PrivateStore::locked('console', fn () => self::state($renew, $close));
    }

    private static function state(bool $renew = false, bool $close = false): array
    {
        $raw = Settings::raw();
        $open = filter_var($raw['console_open'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $state = PrivateStore::read('console');
        if (!$open || $close) {
            $state = ['expires_at' => 0];
        } elseif (!$state) {
            $state = ['expires_at' => time() + self::TTL];
        } elseif (empty($state['expires_at'])) {
            $state = ['expires_at' => time() + self::TTL];
        } elseif ($state['expires_at'] <= time()) {
            $close = true;
            $state = ['expires_at' => 0];
        } elseif ($renew) {
            $state['expires_at'] = time() + self::TTL;
        }
        if ($close) {
            $raw['console_open'] = false;
            app(PluginConfigService::class)->updateConfig(Settings::CODE, $raw);
        }
        PrivateStore::write('console', $state);
        return ['open' => $open && !$close && $state['expires_at'] > time(), 'expires_at' => $state['expires_at']];
    }

    public static function withOpen(callable $fn): mixed
    {
        // Serialize close with in-flight administration. When close returns,
        // no older save/refresh request can commit behind it.
        return PrivateStore::locked('console', function () use ($fn) {
            $state = self::state();
            if (!$state['open']) {
                throw new BridgeException('CONSOLE_CLOSED');
            }
            $response = $fn($state);
            if (!self::state()['open']) {
                throw new BridgeException('CONSOLE_CLOSED');
            }
            return $response;
        });
    }

    public static function renew(): array
    {
        return self::withOpen(fn () => self::state(true));
    }

    public static function requireOpen(): void
    {
        if (!self::status()['open']) {
            throw new BridgeException('CONSOLE_CLOSED');
        }
    }
}
