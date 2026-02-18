<?php

declare(strict_types=1);

namespace Test\Automanaged;

use PHPUnit\Framework\TestCase;
use Phluxor\Cluster\Automanaged\AutomanagedConfig;

final class AutomanagedConfigTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new AutomanagedConfig(
            seeds: ['127.0.0.1:6330']
        );

        self::assertSame(['127.0.0.1:6330'], $config->seeds());
        self::assertSame(6330, $config->autoManagePort());
        self::assertSame(2000, $config->refreshIntervalMs());
        self::assertSame(2000, $config->httpTimeoutMs());
    }

    public function testCustomValues(): void
    {
        $config = new AutomanagedConfig(
            seeds: ['10.0.0.1:7000', '10.0.0.2:7000'],
            autoManagePort: 7000,
            refreshIntervalMs: 5000,
            httpTimeoutMs: 3000
        );

        self::assertSame(['10.0.0.1:7000', '10.0.0.2:7000'], $config->seeds());
        self::assertSame(7000, $config->autoManagePort());
        self::assertSame(5000, $config->refreshIntervalMs());
        self::assertSame(3000, $config->httpTimeoutMs());
    }

    public function testMultipleSeeds(): void
    {
        $seeds = ['node1:6330', 'node2:6330', 'node3:6330'];
        $config = new AutomanagedConfig(seeds: $seeds);

        self::assertCount(3, $config->seeds());
        self::assertSame($seeds, $config->seeds());
    }
}
