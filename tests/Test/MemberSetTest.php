<?php

declare(strict_types=1);

namespace Test;

use PHPUnit\Framework\TestCase;
use Phluxor\Cluster\Member;
use Phluxor\Cluster\MemberSet;

final class MemberSetTest extends TestCase
{
    public function testFromArrayAndAll(): void
    {
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);
        $m2 = new Member('host2', 50052, 'node-2', ['grain1']);

        $set = MemberSet::fromArray([$m1, $m2]);

        self::assertCount(2, $set->all());
    }

    public function testCount(): void
    {
        $set = new MemberSet(
            new Member('host1', 50051, 'node-1', ['grain1']),
            new Member('host2', 50052, 'node-2', ['grain1'])
        );

        self::assertSame(2, $set->count());
    }

    public function testIsEmpty(): void
    {
        self::assertTrue((new MemberSet())->isEmpty());

        $set = new MemberSet(new Member('host1', 50051, 'node-1', ['grain1']));
        self::assertFalse($set->isEmpty());
    }

    public function testHas(): void
    {
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);
        $m2 = new Member('host2', 50052, 'node-2', ['grain1']);
        $absent = new Member('host3', 50053, 'node-3', ['grain1']);

        $set = new MemberSet($m1, $m2);

        self::assertTrue($set->has($m1));
        self::assertTrue($set->has($m2));
        self::assertFalse($set->has($absent));
    }

    public function testDeduplicatesByAddress(): void
    {
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);
        $m2 = new Member('host1', 50051, 'node-2', ['grain2']);

        $set = new MemberSet($m1, $m2);

        self::assertSame(1, $set->count());
    }

    public function testExcept(): void
    {
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);
        $m2 = new Member('host2', 50052, 'node-2', ['grain1']);
        $m3 = new Member('host3', 50053, 'node-3', ['grain1']);

        $setA = new MemberSet($m1, $m2, $m3);
        $setB = new MemberSet($m2);

        $diff = $setA->except($setB);

        self::assertSame(2, $diff->count());
        self::assertTrue($diff->has($m1));
        self::assertFalse($diff->has($m2));
        self::assertTrue($diff->has($m3));
    }

    public function testExceptIds(): void
    {
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);
        $m2 = new Member('host2', 50052, 'node-2', ['grain1']);
        $m3 = new Member('host3', 50053, 'node-3', ['grain1']);

        $set = new MemberSet($m1, $m2, $m3);
        $filtered = $set->exceptIds('node-2');

        self::assertSame(2, $filtered->count());
        self::assertTrue($filtered->has($m1));
        self::assertFalse($filtered->has($m2));
        self::assertTrue($filtered->has($m3));
    }

    public function testTopologyHashDeterministic(): void
    {
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);
        $m2 = new Member('host2', 50052, 'node-2', ['grain1']);

        $set = new MemberSet($m1, $m2);

        self::assertSame($set->topologyHash(), $set->topologyHash());
    }

    public function testTopologyHashOrderIndependent(): void
    {
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);
        $m2 = new Member('host2', 50052, 'node-2', ['grain1']);

        $setAB = new MemberSet($m1, $m2);
        $setBA = new MemberSet($m2, $m1);

        self::assertSame($setAB->topologyHash(), $setBA->topologyHash());
    }

    public function testTopologyHashChangesWithDifferentMembers(): void
    {
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);
        $m2 = new Member('host2', 50052, 'node-2', ['grain1']);
        $m3 = new Member('host3', 50053, 'node-3', ['grain1']);

        $setA = new MemberSet($m1, $m2);
        $setB = new MemberSet($m1, $m3);

        self::assertNotSame($setA->topologyHash(), $setB->topologyHash());
    }
}
