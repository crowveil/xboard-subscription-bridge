<?php

namespace Plugin\ExternalNodeBridge\Services;

use Illuminate\Http\Request;

final class CredentialInput
{
    public static function config(Request $request): array
    {
        $config = $request->input('config', []);
        if (!$request->isJson()) {
            throw new BridgeException('CREDENTIAL_JSON_REQUIRED');
        }
        try {
            $raw = json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BridgeException('UPSTREAM_CONFIG_INVALID');
        }
        // Credentials retain their submitted bytes; authentication and validation remain active.
        foreach (['password', 'authorization', 'cookie'] as $key) {
            if (array_key_exists($key, $raw['config'] ?? [])) {
                $config[$key] = $raw['config'][$key];
            }
        }
        return $config;
    }
}
