<?php

namespace Plugin\ExternalNodeBridge\Services;

final class BridgeException extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $httpStatus = 0)
    {
        parent::__construct($reason);
    }
}
