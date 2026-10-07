<?php

namespace Plugin\ExternalNodeBridge\Services\Upstream;

/** Classify edge blocking before interpreting a panel's authentication response. */
final class ResponseGuard
{
    public static function check(int $status, array $headers, string $body): void
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $type = strtolower((string) ($headers['content-type'] ?? ''));
        $html = str_contains($type, 'text/html') || preg_match('/^\s*<(?:!doctype|html)/i', $body);
        if (strtolower((string) ($headers['cf-mitigated'] ?? '')) === 'challenge') {
            throw new Failure('UPSTREAM_SECURITY_CHALLENGE', $status, true);
        }
        $sample = substr($body, 0, 131072);
        if ($html && preg_match('/cf-chl-|_cf_chl_opt|challenge-platform|challenges\.cloudflare\.com|checking your browser|verify you are human|captcha-container|验证码验证|人机验证/i', $sample)) {
            throw new Failure('UPSTREAM_SECURITY_CHALLENGE', $status, true);
        }
        if ($html && preg_match('/attention required.{0,40}cloudflare|sorry, you have been blocked|access denied|request blocked|安全拦截|访问被拒绝/i', $sample)) {
            throw new Failure('UPSTREAM_SECURITY_BLOCKED', $status, true);
        }
        if ($status === 429) {
            throw new Failure('UPSTREAM_RATE_LIMITED', $status);
        }
        if ($status === 401 || $status === 419) {
            throw new Failure('UPSTREAM_AUTH_EXPIRED', $status);
        }
        if ($status === 403) {
            // A Cloudflare Server header alone does not identify a WAF response.
            throw new Failure('UPSTREAM_ACCESS_DENIED', $status, true);
        }
        if ($status >= 300 && $status < 400) {
            throw new Failure('UPSTREAM_REDIRECT', $status, true);
        }
        if ($status >= 500) {
            throw new Failure('UPSTREAM_UNAVAILABLE', $status);
        }
        if ($html) {
            throw new Failure('UPSTREAM_HTML_RESPONSE', $status, true);
        }
    }
}
