<?php

declare(strict_types=1);

namespace Test;

use Phluxor\Cluster\ClusterExtension;
use Phluxor\Value\ContextExtensionId;
use Phluxor\Value\ExtensionInterface;
use PHPUnit\Framework\TestCase;

final class ClusterExtensionTest extends TestCase
{
    public function testImplementsExtensionInterface(): void
    {
        $cluster = $this->createMock(\Phluxor\Cluster\Cluster::class);
        $ext = new ClusterExtension($cluster);
        self::assertInstanceOf(ExtensionInterface::class, $ext);
    }

    public function testClusterAccessor(): void
    {
        $cluster = $this->createMock(\Phluxor\Cluster\Cluster::class);
        $ext = new ClusterExtension($cluster);
        self::assertSame($cluster, $ext->cluster());
    }

    public function testExtensionIdReturnsContextExtensionId(): void
    {
        $cluster = $this->createMock(\Phluxor\Cluster\Cluster::class);
        $ext = new ClusterExtension($cluster);
        self::assertInstanceOf(ContextExtensionId::class, $ext->extensionID());
    }

    public function testExtensionIdIsSameAcrossInstances(): void
    {
        $cluster1 = $this->createMock(\Phluxor\Cluster\Cluster::class);
        $cluster2 = $this->createMock(\Phluxor\Cluster\Cluster::class);
        $ext1 = new ClusterExtension($cluster1);
        $ext2 = new ClusterExtension($cluster2);
        self::assertSame($ext1->extensionID(), $ext2->extensionID());
    }

    public function testStaticClusterExtensionId(): void
    {
        $cluster = $this->createMock(\Phluxor\Cluster\Cluster::class);
        $ext = new ClusterExtension($cluster);
        self::assertSame(
            ClusterExtension::clusterExtensionId()->value(),
            $ext->extensionID()->value()
        );
    }
}
