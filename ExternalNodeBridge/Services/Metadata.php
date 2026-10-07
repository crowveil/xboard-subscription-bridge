<?php

namespace Plugin\ExternalNodeBridge\Services;

/** Read release metadata from XBoard's plugin manifest. */
final class Metadata
{
    public static function version(): string
    {
        return self::release()['version'];
    }

    public static function release(): array
    {
        $m = json_decode(file_get_contents(dirname(__DIR__).'/config.json'), true, 64, JSON_THROW_ON_ERROR);
        return ['version' => $m['version'], 'channel' => $m['release_channel'] ?? 'stable', 'target_version' => $m['target_version'] ?? $m['version']];
    }
}
