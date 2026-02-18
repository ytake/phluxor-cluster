<?php

declare(strict_types=1);

namespace Test\PartitionIdentity;

use Phluxor\ActorSystem\Ref;
use Phluxor\Cluster\ClusterIdentity;
use Phluxor\Cluster\IdentityLookupInterface;
use Phluxor\Cluster\PartitionIdentity\PartitionIdentityLookup;
use PHPUnit\Framework\TestCase;

final class PartitionIdentityLookupTest extends TestCase
{
    public function testImplementsIdentityLookupInterface(): void
    {
        $lookup = new PartitionIdentityLookup();
        self::assertInstanceOf(IdentityLookupInterface::class, $lookup);
    }

    public function testGetReturnsNullBeforeSetup(): void
    {
        $lookup = new PartitionIdentityLookup();
        $identity = new ClusterIdentity('user-1', 'UserGrain');
        self::assertNull($lookup->get($identity));
    }

    public function testRemovePidBeforeSetupDoesNotThrow(): void
    {
        $lookup = new PartitionIdentityLookup();
        $identity = new ClusterIdentity('user-1', 'UserGrain');
        $ref = $this->createMock(Ref::class);

        $lookup->removePid($identity, $ref);
        self::assertTrue(true);
    }

    public function testShutdownBeforeSetupDoesNotThrow(): void
    {
        $lookup = new PartitionIdentityLookup();
        $lookup->shutdown();
        self::assertTrue(true);
    }
}
