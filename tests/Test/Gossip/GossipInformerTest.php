<?php

declare(strict_types=1);

namespace Test\Gossip;

use Phluxor\Cluster\Gossip\GossipInformer;
use Phluxor\Cluster\ProtoBuf\GossipKeyValue;
use Phluxor\Cluster\ProtoBuf\GossipMemberState;
use Phluxor\Cluster\ProtoBuf\GossipState;
use PHPUnit\Framework\TestCase;

final class GossipInformerTest extends TestCase
{
    public function testSetStateAndRetrieve(): void
    {
        $informer = new GossipInformer('member-1');

        $informer->setState('heartbeat', 'alive', 'MemberHeartbeat');

        $entries = $informer->getStateForKey('heartbeat');
        self::assertCount(1, $entries);
        self::assertArrayHasKey('member-1', $entries);

        $kv = $entries['member-1'];
        self::assertInstanceOf(GossipKeyValue::class, $kv);
        self::assertSame('alive', $kv->getValue());
        self::assertSame('MemberHeartbeat', $kv->getValueTypeName());
        self::assertSame(1, $kv->getSequenceNumber());
        self::assertGreaterThan(0, $kv->getLocalTimestampUnixMilliseconds());
    }

    public function testSetStateIncrementsSequenceNumber(): void
    {
        $informer = new GossipInformer('member-1');

        $informer->setState('key1', 'value1', 'Type1');
        $informer->setState('key2', 'value2', 'Type2');
        $informer->setState('key1', 'value1-updated', 'Type1');

        $entries1 = $informer->getStateForKey('key1');
        $entries2 = $informer->getStateForKey('key2');

        self::assertSame(3, $entries1['member-1']->getSequenceNumber());
        self::assertSame(2, $entries2['member-1']->getSequenceNumber());
    }

    public function testGetStateForKeyReturnsEmptyWhenNotSet(): void
    {
        $informer = new GossipInformer('member-1');

        $entries = $informer->getStateForKey('nonexistent');
        self::assertSame([], $entries);
    }

    public function testMergeStateFromRemote(): void
    {
        $informer = new GossipInformer('member-1');

        $remoteState = $this->buildGossipState('member-2', 'heartbeat', 'alive-2', 'Type', 5);
        $changed = $informer->mergeState($remoteState);

        self::assertTrue($changed);

        $entries = $informer->getStateForKey('heartbeat');
        self::assertCount(1, $entries);
        self::assertArrayHasKey('member-2', $entries);
        self::assertSame('alive-2', $entries['member-2']->getValue());
        self::assertSame(5, $entries['member-2']->getSequenceNumber());
    }

    public function testMergeStateDoesNotOverwriteLocalMember(): void
    {
        $informer = new GossipInformer('member-1');
        $informer->setState('heartbeat', 'local-value', 'Type');

        $remoteState = $this->buildGossipState('member-1', 'heartbeat', 'remote-value', 'Type', 999);
        $changed = $informer->mergeState($remoteState);

        self::assertFalse($changed);

        $entries = $informer->getStateForKey('heartbeat');
        self::assertSame('local-value', $entries['member-1']->getValue());
    }

    public function testMergeStateLWWHigherSequenceWins(): void
    {
        $informer = new GossipInformer('member-1');

        $state1 = $this->buildGossipState('member-2', 'key', 'old-value', 'Type', 3);
        $informer->mergeState($state1);

        $state2 = $this->buildGossipState('member-2', 'key', 'new-value', 'Type', 7);
        $changed = $informer->mergeState($state2);

        self::assertTrue($changed);
        $entries = $informer->getStateForKey('key');
        self::assertSame('new-value', $entries['member-2']->getValue());
        self::assertSame(7, $entries['member-2']->getSequenceNumber());
    }

    public function testMergeStateDoesNotDowngrade(): void
    {
        $informer = new GossipInformer('member-1');

        $state1 = $this->buildGossipState('member-2', 'key', 'newer', 'Type', 10);
        $informer->mergeState($state1);

        $state2 = $this->buildGossipState('member-2', 'key', 'older', 'Type', 5);
        $changed = $informer->mergeState($state2);

        self::assertFalse($changed);
        $entries = $informer->getStateForKey('key');
        self::assertSame('newer', $entries['member-2']->getValue());
    }

    public function testMergeStateMultipleMembers(): void
    {
        $informer = new GossipInformer('member-1');
        $informer->setState('heartbeat', 'alive-1', 'Type');

        $remoteState = new GossipState();
        $member2State = new GossipMemberState();
        $kv2 = $this->createKeyValue('alive-2', 'Type', 1);
        $member2State->getValues()['heartbeat'] = $kv2;

        $member3State = new GossipMemberState();
        $kv3 = $this->createKeyValue('alive-3', 'Type', 2);
        $member3State->getValues()['heartbeat'] = $kv3;

        $remoteState->getMembers()['member-2'] = $member2State;
        $remoteState->getMembers()['member-3'] = $member3State;

        $informer->mergeState($remoteState);

        $entries = $informer->getStateForKey('heartbeat');
        self::assertCount(3, $entries);
        self::assertSame('alive-1', $entries['member-1']->getValue());
        self::assertSame('alive-2', $entries['member-2']->getValue());
        self::assertSame('alive-3', $entries['member-3']->getValue());
    }

    public function testToProtoBufConvertsState(): void
    {
        $informer = new GossipInformer('member-1');
        $informer->setState('heartbeat', 'alive', 'MemberHeartbeat');

        $remoteState = $this->buildGossipState('member-2', 'heartbeat', 'alive-2', 'Type', 1);
        $informer->mergeState($remoteState);

        $protobuf = $informer->toProtoBuf();

        self::assertInstanceOf(GossipState::class, $protobuf);
        self::assertCount(2, $protobuf->getMembers());

        // MapField への代入が実際に反映されていることを検証する
        $members = $protobuf->getMembers();
        self::assertTrue(isset($members['member-1']), 'member-1 が GossipState に含まれている');
        self::assertTrue(isset($members['member-2']), 'member-2 が GossipState に含まれている');

        /** @var \Phluxor\Cluster\ProtoBuf\GossipMemberState $member1State */
        $member1State = $members['member-1'];
        $member1Values = $member1State->getValues();
        self::assertCount(1, $member1Values, 'member-1 の values に 1 件含まれている');
        self::assertTrue(isset($member1Values['heartbeat']), 'member-1 の heartbeat キーが存在する');

        /** @var \Phluxor\Cluster\ProtoBuf\GossipMemberState $member2State */
        $member2State = $members['member-2'];
        $member2Values = $member2State->getValues();
        self::assertCount(1, $member2Values, 'member-2 の values に 1 件含まれている');
        self::assertTrue(isset($member2Values['heartbeat']), 'member-2 の heartbeat キーが存在する');
    }

    public function testRemoveMember(): void
    {
        $informer = new GossipInformer('member-1');

        $remoteState = $this->buildGossipState('member-2', 'heartbeat', 'alive-2', 'Type', 1);
        $informer->mergeState($remoteState);

        $entries = $informer->getStateForKey('heartbeat');
        self::assertArrayHasKey('member-2', $entries);

        $informer->removeMember('member-2');

        $entries = $informer->getStateForKey('heartbeat');
        self::assertArrayNotHasKey('member-2', $entries);
    }

    public function testLocalMemberIdAccessor(): void
    {
        $informer = new GossipInformer('my-node-id');
        self::assertSame('my-node-id', $informer->localMemberId());
    }

    public function testMergeStateReturnsFalseWhenNoChanges(): void
    {
        $informer = new GossipInformer('member-1');

        $emptyState = new GossipState();
        $changed = $informer->mergeState($emptyState);

        self::assertFalse($changed);
    }

    private function buildGossipState(
        string $memberId,
        string $key,
        string $value,
        string $typeName,
        int $sequenceNumber
    ): GossipState {
        $kv = $this->createKeyValue($value, $typeName, $sequenceNumber);

        $memberState = new GossipMemberState();
        $memberState->getValues()[$key] = $kv;

        $state = new GossipState();
        $state->getMembers()[$memberId] = $memberState;

        return $state;
    }

    private function createKeyValue(string $value, string $typeName, int $sequenceNumber): GossipKeyValue
    {
        $kv = new GossipKeyValue();
        $kv->setValue($value);
        $kv->setValueTypeName($typeName);
        $kv->setSequenceNumber($sequenceNumber);
        $kv->setLocalTimestampUnixMilliseconds((int)(microtime(true) * 1000));
        return $kv;
    }
}
