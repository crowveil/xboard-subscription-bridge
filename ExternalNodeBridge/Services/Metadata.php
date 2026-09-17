<?php

namespace Plugin\ExternalNodeBridge\Services;

/** Read release metadata from XBoard's plugin manifest. */
final class Metadata
{
    public static function version(): string
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__).'/config.json'), true, 64, JSON_THROW_ON_ERROR);

        return $manifest['version'];
    }
}
