<?php

namespace Plugin\ExternalNodeBridge\Services\Upstream;

interface PanelAdapter
{
    public function probe(): array;
    public function login(string $email, string $password): string;
    public function subscription(string $authorization, string $cookie = ''): array;
    public function reset(string $authorization, string $cookie = ''): void;
}
