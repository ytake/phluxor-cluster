<?php

declare(strict_types=1);

namespace Phluxor\Cluster\Gossip;

use Phluxor\Cluster\ProtoBuf\GossipKeyValue;
use Phluxor\Cluster\ProtoBuf\GossipMemberState;
use Phluxor\Cluster\ProtoBuf\GossipState;

final class GossipInformer
{
    /** @var array<string, array<string, GossipKeyValue>> member_id => key => GossipKeyValue */
    private array $state = [];

    private int $sequenceNumber = 0;

    public function __construct(
        private readonly string $localMemberId
    ) {
    }

    public function localMemberId(): string
    {
        return $this->localMemberId;
    }

    public function setState(string $key, string $value, string $typeName): void
    {
        $this->sequenceNumber++;

        $kv = new GossipKeyValue();
        $kv->setValue($value);
        $kv->setValueTypeName($typeName);
        $kv->setSequenceNumber($this->sequenceNumber);
        $kv->setLocalTimestampUnixMilliseconds((int)(microtime(true) * 1000));

        $this->state[$this->localMemberId][$key] = $kv;
    }

    public function mergeState(GossipState $remoteState): bool
    {
        $changed = false;
        foreach ($remoteState->getMembers() as $memberId => $remoteMemberState) {
            if ($memberId === $this->localMemberId) {
                continue;
            }

            if (!$remoteMemberState instanceof GossipMemberState) {
                continue;
            }

            foreach ($remoteMemberState->getValues() as $key => $remoteKv) {
                if (!$remoteKv instanceof GossipKeyValue) {
                    continue;
                }
                $localKv = $this->state[$memberId][$key] ?? null;

                if ($localKv === null || $remoteKv->getSequenceNumber() > $localKv->getSequenceNumber()) {
                    $this->state[$memberId][$key] = $remoteKv;
                    $changed = true;
                }
            }
        }
        return $changed;
    }

    public function toProtoBuf(): GossipState
    {
        $gossipState = new GossipState();
        foreach ($this->state as $memberId => $values) {
            $memberState = new GossipMemberState();
            foreach ($values as $key => $kv) {
                $memberState->getValues()[$key] = $kv;
            }
            $gossipState->getMembers()[$memberId] = $memberState;
        }
        return $gossipState;
    }

    /**
     * @return array<string, GossipKeyValue>
     */
    public function getStateForKey(string $key): array
    {
        $result = [];
        foreach ($this->state as $memberId => $values) {
            if (isset($values[$key])) {
                $result[$memberId] = $values[$key];
            }
        }
        return $result;
    }

    public function removeMember(string $memberId): void
    {
        unset($this->state[$memberId]);
    }

    /**
     * @return list<string>
     */
    public function findExpiredMembers(
        string $key,
        string $localMemberId,
        int $expirationMs,
        int $nowMs,
    ): array {
        $expired = [];
        foreach ($this->state as $memberId => $values) {
            if ($memberId === $localMemberId) {
                continue;
            }
            if (!isset($values[$key])) {
                continue;
            }
            $elapsed = $nowMs - (int) $values[$key]->getLocalTimestampUnixMilliseconds();
            if ($elapsed > $expirationMs) {
                $expired[] = $memberId;
            }
        }
        return $expired;
    }

    /**
     * @return list<string>
     */
    public function findMembersWithKey(string $key, string $localMemberId): array
    {
        $result = [];
        foreach ($this->state as $memberId => $values) {
            if ($memberId === $localMemberId) {
                continue;
            }
            if (isset($values[$key])) {
                $result[] = $memberId;
            }
        }
        return $result;
    }
}
