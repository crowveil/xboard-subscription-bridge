<?php

namespace Plugin\ExternalNodeBridge\Services\Upstream;

use Plugin\ExternalNodeBridge\Services\Settings;

/** Shared account API contract for XBoard and V2Board-compatible panels. */
final class V2BoardAdapter implements PanelAdapter
{
    public function __construct(private PanelHttp $http)
    {
    }

    public function probe(): array
    {
        $body = $this->http->json('GET', '/guest/comm/config');
        $data = $body['data'] ?? null;
        if (!is_array($data) || !array_intersect(['app_name', 'tos_url', 'is_email_verify', 'is_invite_force', 'recaptcha_enable', 'currency', 'email_whitelist_suffix'], array_keys($data))) {
            throw new Failure('UPSTREAM_UNRECOGNIZED', 0, true);
        }
        return ['adapter' => 'v2board', 'family' => 'v2board_compatible'];
    }

    public function login(string $email, string $password): string
    {
        $data = $this->http->json('POST', '/passport/auth/login', ['email' => $email, 'password' => $password]);
        $auth = $data['data']['auth_data'] ?? null;
        if (!is_string($auth) || $auth === '' || strlen($auth) > 8192 || preg_match('/[\r\n\x00]/', $auth)) {
            throw new Failure('UPSTREAM_LOGIN_INVALID', 0, true);
        }
        // auth_data is the account authorization; data.token is the subscription token.
        return $auth;
    }

    public function subscription(string $authorization, string $cookie = ''): array
    {
        $body = $this->http->json('GET', '/user/getSubscribe', [], $authorization, $cookie);
        $data = $body['data'] ?? null;
        if (!is_array($data) || !array_key_exists('expired_at', $data) || !isset($data['transfer_enable'], $data['u'], $data['d']) || !is_string($data['subscribe_url'] ?? null) || !Settings::httpUrl($data['subscribe_url'])) {
            throw new Failure('UPSTREAM_SUBSCRIPTION_INVALID', 0, true);
        }
        foreach (['u', 'd', 'transfer_enable'] as $key) {
            if (!is_numeric($data[$key]) || $data[$key] < 0) {
                throw new Failure('UPSTREAM_SUBSCRIPTION_INVALID', 0, true);
            }
        }
        if ($data['expired_at'] !== null && (!is_numeric($data['expired_at']) || $data['expired_at'] < 0)) {
            throw new Failure('UPSTREAM_SUBSCRIPTION_INVALID', 0, true);
        }
        $uuid = $data['uuid'] ?? null;
        if (!is_string($uuid) || $uuid === '') {
            $info = $this->http->json('GET', '/user/info', [], $authorization, $cookie);
            $uuid = $info['data']['uuid'] ?? null;
        }
        return [
            'family' => 'v2board_compatible',
            'response_style' => ($body['status'] ?? null) === 'success' ? 'status_envelope' : 'data_envelope',
            'subscribe_url' => $data['subscribe_url'],
            'credential_fingerprint' => is_string($uuid) && $uuid !== '' ? hash('sha256', $uuid) : null,
            'expired_at' => $data['expired_at'] === null ? null : (int) $data['expired_at'],
            'used_bytes' => (int) $data['u'] + (int) $data['d'], 'total_bytes' => (int) $data['transfer_enable'],
            'remaining_bytes' => max(0, (int) $data['transfer_enable'] - (int) $data['u'] - (int) $data['d']),
            'reset_day' => is_numeric($data['reset_day'] ?? null) ? (int) $data['reset_day'] : null,
            'next_reset_at' => is_numeric($data['next_reset_at'] ?? null) ? (int) $data['next_reset_at'] : null,
        ];
    }

    public function reset(string $authorization, string $cookie = ''): void
    {
        $body = $this->http->json('GET', '/user/resetSecurity', [], $authorization, $cookie);
        if (($body['data'] ?? null) !== true && (!is_string($body['data'] ?? null) || !Settings::httpUrl($body['data']))) {
            throw new Failure('UPSTREAM_RESET_UNCONFIRMED', 0, true);
        }
    }
}
