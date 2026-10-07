<?php

namespace Plugin\ExternalNodeBridge\Services\Upstream;

use Illuminate\Support\Facades\Http;
use Plugin\ExternalNodeBridge\Services\{BridgeException, Diagnostics, PrivateStore};

/** Outbound-only Telegram notifications; never takes over a bot's webhook. */
final class Notifier
{
    public static function settings(bool $secrets = false): array
    {
        $s = array_replace(['enabled' => false, 'bot_token' => '', 'chat_id' => ''], PrivateStore::read('upstream-notifier'));
        if (!$secrets) {
            $s['has_token'] = $s['bot_token'] !== '';
            unset($s['bot_token']);
        }
        return $s;
    }

    public static function save(array $input): void
    {
        PrivateStore::locked('upstream-notifier', function () use ($input) {
            $s = self::settings(true);
            $s['enabled'] = filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $s['chat_id'] = trim((string) ($input['chat_id'] ?? ''));
            if (!empty($input['bot_token'])) {
                $s['bot_token'] = trim((string) $input['bot_token']);
            }
            if ($s['enabled'] && (!preg_match('/^\d{5,20}:[a-zA-Z0-9_-]{20,200}$/D', $s['bot_token']) || !preg_match('/^-?[1-9]\d{0,19}$/D', $s['chat_id']))) {
                throw new BridgeException('NOTIFICATION_CONFIG_INVALID');
            }
            PrivateStore::write('upstream-notifier', $s);
            // A different destination must not receive messages queued for the previous one.
            PrivateStore::locked('upstream-outbox', fn () => PrivateStore::write('upstream-outbox', []));
        });
    }

    public static function enqueue(string $id, string $key, string $text): void
    {
        try {
            $settings = self::settings(true);
            if (!$settings['enabled']) {
                return;
            }
            PrivateStore::locked('upstream-outbox', function () use ($id, $key, $text, $settings) {
                $outbox = PrivateStore::read('upstream-outbox');
                $hash = hash('sha256', $id.':'.$key);
                $outbox = array_filter($outbox, fn ($entry) => ($entry['created_at'] ?? 0) > time() - 7 * 86400);
                if (!isset($outbox[$hash])) {
                    $outbox[$hash] = ['source_id' => $id, 'text' => mb_substr($text, 0, 1500), 'created_at' => time(), 'attempts' => 0, 'next_at' => time(), 'sent' => false,
                        'destination' => hash('sha256', $settings['bot_token'].':'.$settings['chat_id'])];
                }
                PrivateStore::write('upstream-outbox', array_slice($outbox, -100, null, true));
            });
        } catch (\Throwable) {
            (new Diagnostics([]))->record('NOTIFICATION_QUEUE_FAILED', ['source_id' => $id], true);
        }
    }

    public static function flush(): void
    {
        \Plugin\ExternalNodeBridge\Services\Settings::requireEnabled();
        $settings = self::settings(true);
        if (!$settings['enabled']) {
            return;
        }
        PrivateStore::locked('upstream-outbox', function () use ($settings) {
            $outbox = PrivateStore::read('upstream-outbox');
            $sent = 0;
            foreach ($outbox as &$entry) {
                if ($sent >= 2 || $entry['sent'] || $entry['attempts'] >= 5 || $entry['next_at'] > time()) {
                    continue;
                }
                if (!hash_equals($entry['destination'], hash('sha256', $settings['bot_token'].':'.$settings['chat_id']))) {
                    $entry['sent'] = true;
                    continue;
                }
                $entry['attempts']++;
                $entry['next_at'] = time() + min(21600, 300 * (2 ** $entry['attempts']));
                try {
                    self::send($settings, $entry['text']);
                    $entry['sent'] = true;
                } catch (BridgeException) {
                    (new Diagnostics([]))->record('NOTIFICATION_SEND_FAILED', ['source_id' => $entry['source_id']], true);
                }
                $sent++;
            }
            unset($entry);
            PrivateStore::write('upstream-outbox', $outbox);
        });
    }

    public static function test(): void
    {
        $s = self::settings(true);
        if (!$s['enabled']) {
            throw new BridgeException('NOTIFICATION_DISABLED');
        }
        self::send($s, 'XBoard 订阅桥接：上游账户通知已连接。此机器人仅用于发送提醒，不会更改现有 Webhook。');
    }

    private static function send(array $settings, string $text): void
    {
        \Plugin\ExternalNodeBridge\Services\Settings::requireEnabled();
        try {
            $response = Http::withOptions(['allow_redirects' => false])->connectTimeout(3)->timeout(8)->asJson()
                ->post('https://api.telegram.org/bot'.$settings['bot_token'].'/sendMessage', [
                    'chat_id' => $settings['chat_id'], 'text' => str_replace('\\n', "\n", $text), 'link_preview_options' => ['is_disabled' => true],
                ]);
            if (!$response->successful() || $response->json('ok') !== true) {
                throw new BridgeException('NOTIFICATION_FAILED');
            }
        } catch (\Throwable) {
            throw new BridgeException('NOTIFICATION_FAILED');
        }
    }
}
