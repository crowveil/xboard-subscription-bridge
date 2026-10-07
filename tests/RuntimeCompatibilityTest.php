<?php

use PHPUnit\Framework\Attributes\{PreserveGlobalState, RunInSeparateProcess};
use Plugin\ExternalNodeBridge\Services\{BridgeException, RuntimeCompatibility, SubscriptionBridge};

final class MissingCompositionInterface
{
}

final class RuntimeCompatibilityTest extends PHPUnit\Framework\TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLoadedComponentWithoutCompositionInterfaceRequiresRestart(): void
    {
        class_alias(MissingCompositionInterface::class, SubscriptionBridge::class);
        $report = RuntimeCompatibility::inspect();
        $this->assertFalse($report['compatible']);
        $this->assertFalse($report['interfaces']['SubscriptionBridge::compose']);
        $this->expectException(BridgeException::class);
        $this->expectExceptionMessage('PLUGIN_RESTART_REQUIRED');
        RuntimeCompatibility::requireSupported();
    }
}
