<?php

declare(strict_types=1);

namespace Test\Hashing;

use Phluxor\Cluster\Hashing\RoundRobinSelector;
use Phluxor\Cluster\Member;
use PHPUnit\Framework\TestCase;

final class RoundRobinSelectorTest extends TestCase
{
    public function testReturnsNullForEmptyMembers(): void
    {
        $selector = new RoundRobinSelector();
        self::assertNull($selector->getByRoundRobin([]));
    }

    public function testReturnsSingleMember(): void
    {
        $selector = new RoundRobinSelector();
        $member = new Member('host1', 50051, 'node-1', ['grain1']);
        self::assertSame($member->address(), $selector->getByRoundRobin([$member]));
    }

    public function testRoundRobinCycles(): void
    {
        $selector = new RoundRobinSelector();
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);
        $m2 = new Member('host2', 50052, 'node-2', ['grain1']);
        $m3 = new Member('host3', 50053, 'node-3', ['grain1']);
        $members = [$m1, $m2, $m3];

        self::assertSame($m2->address(), $selector->getByRoundRobin($members));
        self::assertSame($m3->address(), $selector->getByRoundRobin($members));
        self::assertSame($m1->address(), $selector->getByRoundRobin($members));
        self::assertSame($m2->address(), $selector->getByRoundRobin($members));
    }

    public function testRoundRobinWrapsAround(): void
    {
        $selector = new RoundRobinSelector();
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);
        $m2 = new Member('host2', 50052, 'node-2', ['grain1']);
        $members = [$m1, $m2];

        self::assertSame($m2->address(), $selector->getByRoundRobin($members));
        self::assertSame($m1->address(), $selector->getByRoundRobin($members));
        self::assertSame($m2->address(), $selector->getByRoundRobin($members));
    }
}
