<?php

namespace Plugin\ExternalNodeBridge\Services\Upstream;

final class Adapters
{
    public static function make(array $account, ?\Closure $observe = null): PanelAdapter
    {
        return match ($account['adapter']) {
            'auto', 'v2board' => new V2BoardAdapter(new PanelHttp($account['api_url'], 10, $observe)),
            default => throw new Failure('UPSTREAM_ADAPTER_UNSUPPORTED', 0, true),
        };
    }
}
