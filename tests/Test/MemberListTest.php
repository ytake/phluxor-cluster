<?php

declare(strict_types=1);

namespace Test;

use PHPUnit\Framework\TestCase;
use Phluxor\Cluster\BlockList;
use Phluxor\Cluster\ClusterTopologyEvent;
use Phluxor\Cluster\Member;
use Phluxor\Cluster\MemberList;
use Phluxor\Cluster\MemberSet;

final class MemberListTest extends TestCase
{
    public function testInitiallyEmpty(): void
    {
        $memberList = new MemberList(new BlockList());

        self::assertTrue($memberList->members()->isEmpty());
    }

    public function testUpdateTopologyWithJoinedMembers(): void
    {
        $memberList = new MemberList(new BlockList());
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);
        $m2 = new Member('host2', 50052, 'node-2', ['grain1']);

        $event = $memberList->updateClusterTopology(new MemberSet($m1, $m2));

        self::assertNotNull($event);
        self::assertInstanceOf(ClusterTopologyEvent::class, $event);
        self::assertCount(2, $event->members());
        self::assertCount(2, $event->joined());
        self::assertCount(0, $event->left());
    }

    public function testUpdateTopologyWithLeftMembers(): void
    {
        $memberList = new MemberList(new BlockList());
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);
        $m2 = new Member('host2', 50052, 'node-2', ['grain1']);

        $memberList->updateClusterTopology(new MemberSet($m1, $m2));

        $event = $memberList->updateClusterTopology(new MemberSet($m1));

        self::assertNotNull($event);
        self::assertCount(1, $event->members());
        self::assertCount(0, $event->joined());
        self::assertCount(1, $event->left());
        self::assertSame('host2:50052', $event->left()[0]->address());
    }

    public function testUpdateTopologyReturnsNullWhenNoChanges(): void
    {
        $memberList = new MemberList(new BlockList());
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);

        $memberList->updateClusterTopology(new MemberSet($m1));
        $event = $memberList->updateClusterTopology(new MemberSet($m1));

        self::assertNull($event);
    }

    public function testUpdateTopologyEventContainsLeftMembersForCaller(): void
    {
        $blockList = new BlockList();
        $memberList = new MemberList($blockList);
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);
        $m2 = new Member('host2', 50052, 'node-2', ['grain1']);

        $memberList->updateClusterTopology(new MemberSet($m1, $m2));
        $event = $memberList->updateClusterTopology(new MemberSet($m1));

        // MemberList は left メンバーを ClusterTopologyEvent に含めて返す。
        // BlockList への追加は呼び出し元（Cluster）の責務。
        self::assertNotNull($event);
        self::assertCount(1, $event->left());
        self::assertSame('node-2', $event->left()[0]->id());

        // MemberList 単体では BlockList には追加しない
        self::assertFalse($blockList->isBlocked('node-2'));
    }

    public function testBlockedMembersAreFilteredFromNewTopology(): void
    {
        $blockList = new BlockList();
        $memberList = new MemberList($blockList);
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);
        $m2 = new Member('host2', 50052, 'node-2', ['grain1']);

        // step1: m1, m2 で初期化
        $memberList->updateClusterTopology(new MemberSet($m1, $m2));

        // step2: BlockList への書き込みは Cluster の責務。
        // テストでは Cluster 相当の処理として直接 block() を呼ぶ。
        $blockList->block('node-2');

        // step3: m1 のみのトポロジーで内部状態を [m1] に更新
        $memberList->updateClusterTopology(new MemberSet($m1));

        // step4: node-2 がブロックされた状態で再度 m1, m2 のトポロジーが来ても
        // MemberList は node-2 をフィルタリングし、変化なし（null）を返す。
        $event = $memberList->updateClusterTopology(new MemberSet($m1, $m2));

        self::assertNull($event);
        self::assertSame(1, $memberList->members()->count());
    }

    public function testTopologyHashChangesOnMemberChange(): void
    {
        $memberList = new MemberList(new BlockList());
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);
        $m2 = new Member('host2', 50052, 'node-2', ['grain1']);
        $m3 = new Member('host3', 50053, 'node-3', ['grain1']);

        $event1 = $memberList->updateClusterTopology(new MemberSet($m1, $m2));
        self::assertNotNull($event1);

        $event2 = $memberList->updateClusterTopology(new MemberSet($m1, $m2, $m3));
        self::assertNotNull($event2);

        self::assertNotSame($event1->topologyHash(), $event2->topologyHash());
    }

    public function testGetPartitionDelegatesPerKind(): void
    {
        $memberList = new MemberList(new BlockList());
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);
        $m2 = new Member('host2', 50052, 'node-2', ['grain1', 'grain2']);

        $memberList->updateClusterTopology(new MemberSet($m1, $m2));

        $partition = $memberList->getPartition('grain1', 'some-key');
        self::assertNotNull($partition);

        $partition2 = $memberList->getPartition('grain2', 'some-key');
        self::assertNotNull($partition2);
        self::assertSame('host2:50052', $partition2);
    }

    public function testGetPartitionReturnsNullForUnknownKind(): void
    {
        $memberList = new MemberList(new BlockList());
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);

        $memberList->updateClusterTopology(new MemberSet($m1));

        self::assertNull($memberList->getPartition('unknown-kind', 'some-key'));
    }

    public function testGetActivatorReturnsNullForUnknownKind(): void
    {
        $memberList = new MemberList(new BlockList());

        self::assertNull($memberList->getActivator('unknown-kind', 'sender'));
    }

    public function testMultipleKindsTrackedSeparately(): void
    {
        $memberList = new MemberList(new BlockList());
        $m1 = new Member('host1', 50051, 'node-1', ['grainA']);
        $m2 = new Member('host2', 50052, 'node-2', ['grainB']);

        $memberList->updateClusterTopology(new MemberSet($m1, $m2));

        // grainA partition should only resolve to m1
        self::assertSame('host1:50051', $memberList->getPartition('grainA', 'key'));
        // grainB partition should only resolve to m2
        self::assertSame('host2:50052', $memberList->getPartition('grainB', 'key'));
    }

    public function testMemberLeaveRemovesFromKindStrategy(): void
    {
        $memberList = new MemberList(new BlockList());
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);
        $m2 = new Member('host2', 50052, 'node-2', ['grain1']);

        $memberList->updateClusterTopology(new MemberSet($m1, $m2));

        // Remove m2
        $memberList->updateClusterTopology(new MemberSet($m1));

        // grain1 partition should only resolve to m1
        self::assertSame('host1:50051', $memberList->getPartition('grain1', 'any-key'));
    }

    public function testEventBlockedFieldContainsBlockedIds(): void
    {
        $blockList = new BlockList();
        $blockList->block('pre-blocked');
        $memberList = new MemberList($blockList);
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);

        $event = $memberList->updateClusterTopology(new MemberSet($m1));

        self::assertNotNull($event);
        self::assertSame(['pre-blocked'], $event->blocked());
    }
}
