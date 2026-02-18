<?php

declare(strict_types=1);

namespace Phluxor\Cluster\Gossip;

use Phluxor\ActorSystem\ProtoBuf\Pid;
use Phluxor\ActorSystem\Props;
use Phluxor\ActorSystem\Ref;
use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\ClusterTopologyEvent;
use Phluxor\Cluster\Member;
use Phluxor\Cluster\ProtoBuf\ActorStatistics;
use Phluxor\Cluster\ProtoBuf\GossipKeyValue;
use Phluxor\Cluster\ProtoBuf\GossipRequest;
use Phluxor\Cluster\ProtoBuf\GossipResponse;
use Phluxor\Cluster\ProtoBuf\GossipState;
use Phluxor\Cluster\ProtoBuf\MemberHeartbeat;
use Phluxor\EventStream\Subscription;
use Swoole\Lock;
use Swoole\Timer;

use const SWOOLE_RWLOCK;

final class Gossiper implements GossipRequestHandler
{
    private const string GOSSIP_ACTOR_NAME = 'cluster-gossip';

    private GossipInformer $informer;

    private Lock $mutex;

    private ?Ref $gossipActor = null;

    private ?int $timerId = null;

    private ?Subscription $topologySub = null;

    /** @var list<Member> */
    private array $currentMembers = [];

    /** @var array<string, ConsensusCheck> */
    private array $consensusChecks = [];

    public function __construct(
        private readonly Cluster $cluster
    ) {
        $memberId = $cluster->actorSystem()->getProcessRegistry()->getAddress();
        $this->informer = new GossipInformer($memberId);
        $this->mutex = new Lock(SWOOLE_RWLOCK);
    }

    public function start(): void
    {
        $gossiper = $this;
        $props = Props::fromProducer(
            fn() => new GossipActor($gossiper)
        );

        $result = $this->cluster->actorSystem()->root()->spawnNamed(
            $props,
            self::GOSSIP_ACTOR_NAME
        );
        $this->gossipActor = $result->getRef();

        $this->topologySub = $this->cluster->actorSystem()->getEventStream()?->subscribe(
            function (mixed $event): void {
                if ($event instanceof ClusterTopologyEvent) {
                    $this->onClusterTopology($event);
                }
            }
        );

        $timerId = Timer::tick(
            $this->cluster->config()->gossipIntervalMs(),
            fn() => $this->sendGossip()
        );
        if ($timerId !== false) {
            $this->timerId = $timerId;
        }
    }

    public function stop(): void
    {
        if ($this->timerId !== null) {
            Timer::clear($this->timerId);
            $this->timerId = null;
        }

        if ($this->topologySub !== null) {
            $this->cluster->actorSystem()->getEventStream()?->unsubscribe($this->topologySub);
            $this->topologySub = null;
        }

        if ($this->gossipActor !== null) {
            $future = $this->cluster->actorSystem()->root()->poisonFuture($this->gossipActor);
            $future?->wait();
            $this->gossipActor = null;
        }
    }

    public function setState(string $key, string $value, string $typeName): void
    {
        $this->mutex->lock();
        try {
            $this->informer->setState($key, $value, $typeName);
        } finally {
            $this->mutex->unlock();
        }
    }

    /**
     * @return array<string, GossipKeyValue>
     */
    public function getState(string $key): array
    {
        $this->mutex->lock_read();
        try {
            return $this->informer->getStateForKey($key);
        } finally {
            $this->mutex->unlock();
        }
    }

    public function registerConsensusCheck(ConsensusCheck $check): void
    {
        $this->mutex->lock();
        try {
            $this->consensusChecks[$check->id()] = $check;
        } finally {
            $this->mutex->unlock();
        }
    }

    public function removeConsensusCheck(string $id): void
    {
        $this->mutex->lock();
        try {
            unset($this->consensusChecks[$id]);
        } finally {
            $this->mutex->unlock();
        }
    }

    public function handleGossipRequest(GossipRequest $request): GossipResponse
    {
        $this->mutex->lock();
        try {
            $remoteState = $request->getState();
            if ($remoteState !== null) {
                $this->informer->mergeState($remoteState);
            }

            $response = new GossipResponse();
            $response->setState($this->informer->toProtoBuf());
            return $response;
        } finally {
            $this->mutex->unlock();
        }
    }

    private function sendGossip(): void
    {
        $this->blockExpiredHeartbeats();
        $this->blockGracefullyLeft();
        $this->sendHeartbeat();

        $this->mutex->lock_read();
        $members = $this->currentMembers;
        $this->mutex->unlock();

        $selfAddress = $this->cluster->config()->address();
        $others = array_values(
            array_filter($members, fn(Member $m) => $m->address() !== $selfAddress)
        );

        if ($others === []) {
            return;
        }

        $fanOut = min($this->cluster->config()->gossipFanOut(), count($others));
        $selected = $this->selectRandomMembers($others, $fanOut);

        $this->mutex->lock_read();
        $state = $this->informer->toProtoBuf();
        $localMemberId = $this->informer->localMemberId();
        $this->mutex->unlock();

        foreach ($selected as $member) {
            $this->sendGossipToMember($member, $state, $localMemberId);
        }

        $this->evaluateConsensusChecks();
    }

    private function sendHeartbeat(): void
    {
        $heartbeat = new MemberHeartbeat();
        $heartbeat->setActorStatistics(new ActorStatistics());
        $this->setState(GossipKeys::HEARTBEAT, $heartbeat->serializeToString(), 'MemberHeartbeat');
    }

    private function blockExpiredHeartbeats(): void
    {
        $this->mutex->lock_read();
        try {
            $localId = $this->informer->localMemberId();
            $nowMs = (int) (microtime(true) * 1000);
            $expirationMs = $this->cluster->config()->heartbeatExpirationMs();
            $expiredIds = $this->informer->findExpiredMembers(
                GossipKeys::HEARTBEAT,
                $localId,
                $expirationMs,
                $nowMs
            );
        } finally {
            $this->mutex->unlock();
        }

        $blockList = $this->cluster->blockList();
        foreach ($expiredIds as $id) {
            if (!$blockList->isBlocked($id)) {
                $blockList->block($id);
            }
        }
    }

    private function blockGracefullyLeft(): void
    {
        $this->mutex->lock_read();
        try {
            $localId = $this->informer->localMemberId();
            $leftIds = $this->informer->findMembersWithKey(GossipKeys::GRACEFULLY_LEFT, $localId);
        } finally {
            $this->mutex->unlock();
        }

        $blockList = $this->cluster->blockList();
        foreach ($leftIds as $id) {
            if (!$blockList->isBlocked($id)) {
                $blockList->block($id);
            }
        }
    }

    private function sendGossipToMember(Member $member, GossipState $state, string $localMemberId): void
    {
        $request = new GossipRequest();
        $request->setState($state);
        $request->setMemberId($localMemberId);

        $targetPid = new Pid();
        $targetPid->setAddress($member->address());
        $targetPid->setId(self::GOSSIP_ACTOR_NAME);
        $targetRef = new Ref($targetPid);

        $future = $this->cluster->actorSystem()->root()->requestFuture(
            $targetRef,
            $request,
            $this->cluster->config()->requestTimeoutSeconds()
        );

        $result = $future->result();
        if ($result->error() !== null) {
            return;
        }

        $response = $result->value();
        if ($response instanceof GossipResponse && $response->getState() !== null) {
            $this->mutex->lock();
            try {
                $this->informer->mergeState($response->getState());
            } finally {
                $this->mutex->unlock();
            }
        }
    }

    private function evaluateConsensusChecks(): void
    {
        $this->mutex->lock_read();
        try {
            foreach ($this->consensusChecks as $check) {
                foreach ($check->affectedKeys() as $key) {
                    $values = $this->informer->getStateForKey($key);
                    $check->hasConsensus($values);
                }
            }
        } finally {
            $this->mutex->unlock();
        }
    }

    private function onClusterTopology(ClusterTopologyEvent $event): void
    {
        $this->mutex->lock();
        try {
            $this->currentMembers = $event->members();
            foreach ($event->left() as $member) {
                $this->informer->removeMember($member->id());
            }
        } finally {
            $this->mutex->unlock();
        }
    }

    /**
     * @param list<Member> $members
     * @return list<Member>
     */
    private function selectRandomMembers(array $members, int $count): array
    {
        if (count($members) <= $count) {
            return $members;
        }

        shuffle($members);
        return array_slice($members, 0, $count);
    }
}
