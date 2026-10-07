<?php

namespace Plugin\ExternalNodeBridge\Services\Upstream;

use Illuminate\Support\Facades\Http;

/** One bounded request, no redirects, retries, credential forwarding or challenge solving. */
final class PanelHttp
{
    public function __construct(private string $baseUrl, private int $timeout = 10, private ?\Closure $observe = null)
    {
    }

    public function json(string $method, string $path, array $body = [], string $authorization = '', string $cookie = ''): array
    {
        \Plugin\ExternalNodeBridge\Services\Settings::requireEnabled();
        $allowed = ['/guest/comm/config', '/passport/auth/login', '/user/getSubscribe', '/user/info', '/user/resetSecurity'];
        if (!in_array($path, $allowed, true)) {
            throw new Failure('UPSTREAM_ENDPOINT_INVALID');
        }
        if ($this->observe) {
            ($this->observe)([
                '/guest/comm/config' => 'probe', '/passport/auth/login' => 'login',
                '/user/getSubscribe' => 'subscription', '/user/info' => 'account_info',
                '/user/resetSecurity' => 'reset',
            ][$path]);
        }
        $headers = ['Accept' => 'application/json', 'User-Agent' => 'XBoard-Subscription-Bridge/0.2'];
        foreach (['Authorization' => $authorization, 'Cookie' => $cookie] as $key => $value) {
            if ($value !== '') {
                if (preg_match('/[\r\n\x00]/', $value) || strlen($value) > 16384) {
                    throw new Failure('UPSTREAM_CREDENTIAL_INVALID', 0, true);
                }
                $headers[$key] = $value;
            }
        }
        try {
            $deadline = microtime(true) + $this->timeout;
            $client = Http::withOptions(['stream' => true, 'allow_redirects' => false, 'read_timeout' => $this->timeout])
                ->connectTimeout(3)->timeout($this->timeout)->withHeaders($headers)->asForm();
            $response = $method === 'POST' ? $client->post($this->baseUrl.$path, $body) : $client->get($this->baseUrl.$path);
            $stream = $response->toPsrResponse()->getBody();
            $text = '';
            try {
                while (!$stream->eof()) {
                    if (microtime(true) > $deadline) {
                        throw new Failure('UPSTREAM_NETWORK_ERROR');
                    }
                    $chunk = $stream->read(32768);
                    if ($chunk === '' && !$stream->eof()) {
                        throw new Failure('UPSTREAM_NETWORK_ERROR');
                    }
                    $text .= $chunk;
                    if (strlen($text) > 1048576) {
                        throw new Failure('UPSTREAM_RESPONSE_TOO_LARGE', $response->status(), true);
                    }
                }
            } finally {
                $stream->close();
            }
            ResponseGuard::check($response->status(), ['content-type' => $response->header('Content-Type'), 'cf-mitigated' => $response->header('cf-mitigated')], $text);
            $data = json_decode($text, true);
            if (!is_array($data)) {
                throw new Failure('UPSTREAM_RESPONSE_INVALID', $response->status(), true);
            }
            if (!$response->successful() || !array_key_exists('data', $data) || (isset($data['status']) && !in_array($data['status'], ['success', true, 200], true)) || !empty($data['error'])) {
                $message = is_string($data['message'] ?? null) ? $data['message'] : '';
                if (preg_match('/captcha|turnstile|验证|challenge/i', $message)) {
                    throw new Failure('UPSTREAM_VERIFICATION_REQUIRED', $response->status(), true);
                }
                if (preg_match('/未登录|登录.*失效|token.*(?:invalid|expired)|unauthenticated|登录已过期/i', $message)) {
                    throw new Failure('UPSTREAM_AUTH_EXPIRED', $response->status());
                }
                throw new Failure($path === '/passport/auth/login' ? 'UPSTREAM_LOGIN_FAILED' : 'UPSTREAM_API_REJECTED', $response->status(), true);
            }
            return $data;
        } catch (Failure $e) {
            if ($path === '/passport/auth/login' && $e->reason === 'UPSTREAM_AUTH_EXPIRED') {
                throw new Failure('UPSTREAM_LOGIN_FAILED', $e->httpStatus, true);
            }
            throw $e;
        } catch (\Throwable) {
            // Transport exceptions may contain email, form data or the full URL.
            throw new Failure('UPSTREAM_NETWORK_ERROR');
        }
    }
}
