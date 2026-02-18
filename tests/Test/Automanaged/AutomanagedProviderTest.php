<?php

declare(strict_types=1);

namespace Test\Automanaged;

use PHPUnit\Framework\TestCase;
use Phluxor\Cluster\Automanaged\AutomanagedConfig;
use Phluxor\Cluster\Automanaged\AutomanagedProvider;
use Phluxor\Cluster\ClusterProviderInterface;

final class AutomanagedProviderTest extends TestCase
{
    public function testImplementsClusterProviderInterface(): void
    {
        $config = new AutomanagedConfig(seeds: ['127.0.0.1:6330']);
        $provider = new AutomanagedProvider($config);

        self::assertInstanceOf(ClusterProviderInterface::class, $provider);
    }

    public function testShutdownWithoutStartDoesNotThrow(): void
    {
        $config = new AutomanagedConfig(seeds: ['127.0.0.1:6330']);
        $provider = new AutomanagedProvider($config);

        $provider->shutdown(true);
        $provider->shutdown(false);

        self::assertTrue(true);
    }
}
