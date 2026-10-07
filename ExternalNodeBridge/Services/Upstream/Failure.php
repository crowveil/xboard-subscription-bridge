<?php

namespace Plugin\ExternalNodeBridge\Services\Upstream;

final class Failure extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $httpStatus = 0, public readonly bool $pause = false)
    {
        parent::__construct($reason);
    }
}
