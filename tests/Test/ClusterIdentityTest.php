<?php

declare(strict_types=1);

namespace Test;

use PHPUnit\Framework\TestCase;
use Phluxor\Cluster\ClusterIdentity;

final class ClusterIdentityTest extends TestCase
{
    public function testToKey(): void
    {
        $identity = new ClusterIdentity('user-123', 'UserGrain');

        self::assertSame('user-123/UserGrain', $identity->toKey());
    }

    public function testEquals(): void
    {
        $base = new ClusterIdentity('user-123', 'UserGrain');
        $same = new ClusterIdentity('user-123', 'UserGrain');
        $differentIdentity = new ClusterIdentity('user-456', 'UserGrain');
        $differentKind = new ClusterIdentity('user-123', 'OrderGrain');

        self::assertTrue($base->equals($same));
        self::assertFalse($base->equals($differentIdentity));
        self::assertFalse($base->equals($differentKind));
    }

    public function testToString(): void
    {
        $identity = new ClusterIdentity('user-123', 'UserGrain');

        self::assertSame($identity->toKey(), (string) $identity);
    }
}
