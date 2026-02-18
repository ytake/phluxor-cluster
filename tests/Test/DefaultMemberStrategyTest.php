<?php

declare(strict_types=1);

namespace Test;

use PHPUnit\Framework\TestCase;
use Phluxor\Cluster\DefaultMemberStrategy;
use Phluxor\Cluster\Hashing\RendezvousHashSelector;
use Phluxor\Cluster\Member;
use Phluxor\Cluster\MemberStrategyInterface;

final class DefaultMemberStrategyTest extends TestCase
{
    public function testInitiallyEmptyMembers(): void
    {
        $strategy = new DefaultMemberStrategy();

        self::assertSame([], $strategy->getAllMembers());
    }

    public function testAddMember(): void
    {
        $strategy = new DefaultMemberStrategy();
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);

        $strategy->addMember($m1);

        self::assertSame([$m1], $strategy->getAllMembers());
    }

    public function testRemoveMember(): void
    {
        $strategy = new DefaultMemberStrategy();
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);
        $m2 = new Member('host2', 50052, 'node-2', ['grain1']);

        $strategy->addMember($m1);
        $strategy->addMember($m2);

        $strategy->removeMember($m1);

        self::assertSame([$m2], $strategy->getAllMembers());
    }

    public function testRemoveMemberByAddress(): void
    {
        $strategy = new DefaultMemberStrategy();
        $sameAddress1 = new Member('host1', 50051, 'node-1', ['grain1']);
        $sameAddress2 = new Member('host1', 50051, 'node-2', ['grain1']);
        $other = new Member('host2', 50052, 'node-3', ['grain1']);

        $strategy->addMember($sameAddress1);
        $strategy->addMember($sameAddress2);
        $strategy->addMember($other);

        $strategy->removeMember($sameAddress1);

        self::assertSame([$other], $strategy->getAllMembers());
    }

    public function testGetPartitionDelegates(): void
    {
        $strategy = new DefaultMemberStrategy();
        $selector = new RendezvousHashSelector();
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);
        $m2 = new Member('host2', 50052, 'node-2', ['grain1']);
        $m3 = new Member('host3', 50053, 'node-3', ['grain1']);

        $strategy->addMember($m1);
        $strategy->addMember($m2);
        $strategy->addMember($m3);

        $key = 'test-key';
        $expected = $selector->getPartition($key, [$m1, $m2, $m3]);

        self::assertSame($expected, $strategy->getPartition($key));
        self::assertSame($expected, $strategy->getPartition($key));
    }

    public function testGetPartitionReturnsNullForEmpty(): void
    {
        $strategy = new DefaultMemberStrategy();

        self::assertNull($strategy->getPartition('any-key'));
    }

    public function testGetActivatorUsesRoundRobin(): void
    {
        $strategy = new DefaultMemberStrategy();
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);
        $m2 = new Member('host2', 50052, 'node-2', ['grain1']);
        $m3 = new Member('host3', 50053, 'node-3', ['grain1']);

        $strategy->addMember($m1);
        $strategy->addMember($m2);
        $strategy->addMember($m3);

        self::assertSame($m2->address(), $strategy->getActivator('sender-A'));
        self::assertSame($m3->address(), $strategy->getActivator('sender-B'));
        self::assertSame($m1->address(), $strategy->getActivator('sender-C'));
        self::assertSame($m2->address(), $strategy->getActivator('sender-D'));
    }

    public function testImplementsInterface(): void
    {
        $strategy = new DefaultMemberStrategy();

        self::assertInstanceOf(MemberStrategyInterface::class, $strategy);
    }
}
