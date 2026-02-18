<?php

declare(strict_types=1);

namespace Test\Gossip;

use PHPUnit\Framework\TestCase;
use Phluxor\Cluster\Gossip\GossipInformer;
use Phluxor\Cluster\Gossip\GossipKeys;
use Phluxor\Cluster\ProtoBuf\GossipKeyValue;
use Phluxor\Cluster\ProtoBuf\GossipMemberState;
use Phluxor\Cluster\ProtoBuf\GossipState;

final class GossipHeartbeatTest extends TestCase
{
    public function testFindExpiredHeartbeatsReturnsExpiredMembers(): void
    {
        $informer = new GossipInformer('local-node');

        $now = (int) (microtime(true) * 1000);
        $expired = $now - 25000;

        $remoteState = $this->buildGossipStateWithTimestamp(
            'remote-1',
            GossipKeys::HEARTBEAT,
            'alive',
            'MemberHeartbeat',
            1,
            $expired
        );
        $informer->mergeState($remoteState);

        $expiredIds = $informer->findExpiredMembers(
            GossipKeys::HEARTBEAT,
            'local-node',
            20000,
            $now
        );

        self::assertSame(['remote-1'], $expiredIds);
    }

    public function testFindExpiredHeartbeatsDoesNotReturnActiveMembers(): void
    {
        $informer = new GossipInformer('local-node');

        $now = (int) (microtime(true) * 1000);
        $recent = $now - 5000;

        $remoteState = $this->buildGossipStateWithTimestamp(
            'remote-1',
            GossipKeys::HEARTBEAT,
            'alive',
            'MemberHeartbeat',
            1,
            $recent
        );
        $informer->mergeState($remoteState);

        $expiredIds = $informer->findExpiredMembers(
            GossipKeys::HEARTBEAT,
            'local-node',
            20000,
            $now
        );

        self::assertSame([], $expiredIds);
    }

    public function testFindExpiredHeartbeatsSkipsLocalMember(): void
    {
        $informer = new GossipInformer('local-node');

        $informer->setState(GossipKeys::HEARTBEAT, 'alive', 'MemberHeartbeat');

        $now = (int) (microtime(true) * 1000);

        $expiredIds = $informer->findExpiredMembers(
            GossipKeys::HEARTBEAT,
            'local-node',
            0,
            $now
        );

        self::assertSame([], $expiredIds);
    }

    public function testFindExpiredHeartbeatsMultipleMembers(): void
    {
        $informer = new GossipInformer('local-node');

        $now = (int) (microtime(true) * 1000);
        $expired = $now - 25000;
        $recent = $now - 5000;

        $state = new GossipState();

        $member1State = new GossipMemberState();
        $kv1 = $this->createKeyValueWithTimestamp('alive', 'MemberHeartbeat', 1, $expired);
        $member1State->getValues()[GossipKeys::HEARTBEAT] = $kv1;

        $member2State = new GossipMemberState();
        $kv2 = $this->createKeyValueWithTimestamp('alive', 'MemberHeartbeat', 1, $recent);
        $member2State->getValues()[GossipKeys::HEARTBEAT] = $kv2;

        $member3State = new GossipMemberState();
        $kv3 = $this->createKeyValueWithTimestamp('alive', 'MemberHeartbeat', 1, $expired);
        $member3State->getValues()[GossipKeys::HEARTBEAT] = $kv3;

        $state->getMembers()['remote-1'] = $member1State;
        $state->getMembers()['remote-2'] = $member2State;
        $state->getMembers()['remote-3'] = $member3State;

        $informer->mergeState($state);

        $expiredIds = $informer->findExpiredMembers(
            GossipKeys::HEARTBEAT,
            'local-node',
            20000,
            $now
        );

        sort($expiredIds);
        self::assertSame(['remote-1', 'remote-3'], $expiredIds);
    }

    public function testFindMembersWithKeyReturnsMatchingMembers(): void
    {
        $informer = new GossipInformer('local-node');

        $remoteState = $this->buildGossipStateWithTimestamp(
            'remote-1',
            GossipKeys::GRACEFULLY_LEFT,
            '',
            'Empty',
            1,
            (int) (microtime(true) * 1000)
        );
        $informer->mergeState($remoteState);

        $leftIds = $informer->findMembersWithKey(GossipKeys::GRACEFULLY_LEFT, 'local-node');

        self::assertSame(['remote-1'], $leftIds);
    }

    public function testFindMembersWithKeySkipsLocalMember(): void
    {
        $informer = new GossipInformer('local-node');

        $informer->setState(GossipKeys::GRACEFULLY_LEFT, '', 'Empty');

        $leftIds = $informer->findMembersWithKey(GossipKeys::GRACEFULLY_LEFT, 'local-node');

        self::assertSame([], $leftIds);
    }

    public function testFindMembersWithKeyReturnsEmptyWhenNoMatch(): void
    {
        $informer = new GossipInformer('local-node');

        $leftIds = $informer->findMembersWithKey(GossipKeys::GRACEFULLY_LEFT, 'local-node');

        self::assertSame([], $leftIds);
    }

    public function testGossipKeysConstants(): void
    {
        self::assertSame('topology', GossipKeys::TOPOLOGY);
        self::assertSame('heartbeat', GossipKeys::HEARTBEAT);
        self::assertSame('left', GossipKeys::GRACEFULLY_LEFT);
    }

    private function buildGossipStateWithTimestamp(
        string $memberId,
        string $key,
        string $value,
        string $typeName,
        int $sequenceNumber,
        int $timestampMs,
    ): GossipState {
        $kv = $this->createKeyValueWithTimestamp($value, $typeName, $sequenceNumber, $timestampMs);
        $memberState = new GossipMemberState();
        $memberState->getValues()[$key] = $kv;
        $state = new GossipState();
        $state->getMembers()[$memberId] = $memberState;
        return $state;
    }

    private function createKeyValueWithTimestamp(
        string $value,
        string $typeName,
        int $sequenceNumber,
        int $timestampMs,
    ): GossipKeyValue {
        $kv = new GossipKeyValue();
        $kv->setValue($value);
        $kv->setValueTypeName($typeName);
        $kv->setSequenceNumber($sequenceNumber);
        $kv->setLocalTimestampUnixMilliseconds($timestampMs);
        return $kv;
    }
}
