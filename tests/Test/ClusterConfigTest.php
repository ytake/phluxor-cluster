<?php

declare(strict_types=1);

namespace Test;

use PHPUnit\Framework\TestCase;
use Phluxor\Cluster\ClusterConfig;
use Phluxor\Cluster\ClusterProviderInterface;
use Phluxor\Cluster\IdentityLookupInterface;
use Phluxor\Cluster\KindRegistry;

final class ClusterConfigTest extends TestCase
{
    public function testAddress(): void
    {
        $config = $this->createConfig();

        self::assertSame('localhost:50052', $config->address());
    }

    public function testDefaults(): void
    {
        $config = $this->createConfig();

        self::assertSame(300, $config->gossipIntervalMs());
        self::assertSame(3, $config->gossipFanOut());
        self::assertSame(20000, $config->heartbeatExpirationMs());
        self::assertSame(5, $config->requestTimeoutSeconds());
    }

    public function testNameAndAccessors(): void
    {
        $config = $this->createConfig();

        self::assertSame('test-cluster', $config->name());
        self::assertSame('localhost', $config->host());
        self::assertSame(50052, $config->port());
    }

    private function createConfig(): ClusterConfig
    {
        return new ClusterConfig(
            'test-cluster',
            'localhost',
            50052,
            $this->createMock(ClusterProviderInterface::class),
            $this->createMock(IdentityLookupInterface::class),
            new KindRegistry()
        );
    }
}
