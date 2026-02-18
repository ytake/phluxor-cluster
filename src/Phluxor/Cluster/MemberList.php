<?php

declare(strict_types=1);

namespace Phluxor\Cluster;

use Swoole\Lock;

use const SWOOLE_RWLOCK;

final class MemberList
{
    private MemberSet $members;

    /** @var array<string, MemberStrategyInterface> */
    private array $memberStrategyByKind = [];

    private Lock $mutex;

    public function __construct(
        private readonly BlockList $blockList
    ) {
        $this->members = new MemberSet();
        $this->mutex = new Lock(SWOOLE_RWLOCK);
    }

    /**
     * Update the cluster topology with a new member set.
     * Returns a ClusterTopologyEvent if changes were detected, null otherwise.
     * The caller (Cluster) is responsible for publishing the event to EventStream.
     */
    public function updateClusterTopology(MemberSet $newMembers): ?ClusterTopologyEvent
    {
        $this->mutex->lock();
        try {
            $blockedIds = $this->blockList->blockedMembers();
            $filtered = $blockedIds !== []
                ? $newMembers->exceptIds(...$blockedIds)
                : $newMembers;

            $joined = $filtered->except($this->members);
            $left = $this->members->except($filtered);

            if ($joined->isEmpty() && $left->isEmpty()) {
                return null;
            }

            foreach ($left->all() as $member) {
                $this->memberLeave($member);
            }

            foreach ($joined->all() as $member) {
                $this->memberJoin($member);
            }

            $this->members = $filtered;

            return new ClusterTopologyEvent(
                topologyHash: $filtered->topologyHash(),
                members: $filtered->all(),
                joined: $joined->all(),
                left: $left->all(),
                blocked: $blockedIds
            );
        } finally {
            $this->mutex->unlock();
        }
    }

    public function members(): MemberSet
    {
        $this->mutex->lock_read();
        try {
            return $this->members;
        } finally {
            $this->mutex->unlock();
        }
    }

    public function getPartition(string $kind, string $key): ?string
    {
        $this->mutex->lock_read();
        try {
            if (!isset($this->memberStrategyByKind[$kind])) {
                return null;
            }
            return $this->memberStrategyByKind[$kind]->getPartition($key);
        } finally {
            $this->mutex->unlock();
        }
    }

    public function getActivator(string $kind, string $senderAddress): ?string
    {
        $this->mutex->lock_read();
        try {
            if (!isset($this->memberStrategyByKind[$kind])) {
                return null;
            }
            return $this->memberStrategyByKind[$kind]->getActivator($senderAddress);
        } finally {
            $this->mutex->unlock();
        }
    }

    private function memberJoin(Member $member): void
    {
        foreach ($member->kinds() as $kind) {
            $strategy = $this->getMemberStrategyByKind($kind);
            $strategy->addMember($member);
        }
    }

    private function memberLeave(Member $member): void
    {
        foreach ($member->kinds() as $kind) {
            if (isset($this->memberStrategyByKind[$kind])) {
                $this->memberStrategyByKind[$kind]->removeMember($member);
            }
        }
        $this->blockList->block($member->id());
    }

    private function getMemberStrategyByKind(string $kind): MemberStrategyInterface
    {
        if (!isset($this->memberStrategyByKind[$kind])) {
            $this->memberStrategyByKind[$kind] = new DefaultMemberStrategy();
        }
        return $this->memberStrategyByKind[$kind];
    }
}
