<?php

declare(strict_types=1);

namespace Phluxor\Cluster\PartitionIdentity;

use Phluxor\ActorSystem\ProtoBuf\Pid;
use Phluxor\ActorSystem\Props;
use Phluxor\ActorSystem\Ref;
use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\ClusterIdentity;
use Phluxor\Cluster\ClusterTopologyEvent;
use Phluxor\Cluster\Hashing\RendezvousHashSelector;
use Phluxor\Cluster\Member;
use Phluxor\Cluster\ProtoBuf\ActivationRequest;
use Phluxor\Cluster\ProtoBuf\ActivationResponse;
use Phluxor\Cluster\ProtoBuf\ActivationTerminated;
use Phluxor\Cluster\ProtoBuf\ClusterIdentity as ProtoBufClusterIdentity;
use Phluxor\EventStream\Subscription;
use Swoole\Lock;

use const SWOOLE_RWLOCK;

final class PartitionManager
{
    // アクター名の定数は PartitionPlacementActor で定義（共有）
    private const string PARTITION_ACTIVATOR_ACTOR_NAME = PartitionPlacementActor::PARTITION_ACTIVATOR_ACTOR_NAME;

    private ?Ref $placementActor = null;

    private ?Subscription $topologySub = null;

    private RendezvousHashSelector $rdv;

    private Lock $rdvMutex;

    /** @var list<Member> */
    private array $currentMembers = [];

    private int $currentTopologyHash = 0;

    private bool $started = false;

    public function __construct(
        private readonly Cluster $cluster
    ) {
        $this->rdv = new RendezvousHashSelector();
        $this->rdvMutex = new Lock(SWOOLE_RWLOCK);
    }

    public function start(bool $isClient = false): void
    {
        $system = $this->cluster->actorSystem();
        if (!$isClient) {
            $cluster = $this->cluster;

            $props = Props::fromProducer(
                fn() => new PartitionPlacementActor($cluster)
            );

            $result = $system->root()->spawnNamed($props, self::PARTITION_ACTIVATOR_ACTOR_NAME);
            $this->placementActor = $result->getRef();
        }

        $this->topologySub = $system->getEventStream()?->subscribe(
            function (mixed $event): void {
                if ($event instanceof ClusterTopologyEvent) {
                    $this->onClusterTopology($event);
                }
            }
        );
        $this->started = true;
    }

    public function stop(): void
    {
        $system = $this->cluster->actorSystem();

        if ($this->topologySub !== null) {
            $system->getEventStream()?->unsubscribe($this->topologySub);
            $this->topologySub = null;
        }

        if ($this->placementActor !== null) {
            $future = $system->root()->poisonFuture($this->placementActor);
            $future?->wait();
            $this->placementActor = null;
        }

        $this->started = false;
    }

    public function get(ClusterIdentity $clusterIdentity): ?Ref
    {
        // RWLOCK 保持中にブロッキング I/O を行うとデッドロックが発生するため、
        // ロック内ではトポロジー情報の読み取りのみを行い、
        // ネットワークリクエストはロック解放後に実行する。
        $this->rdvMutex->lock_read();
        try {
            $ownerAddress = $this->rdv->getPartition(
                $clusterIdentity->toKey(),
                $this->currentMembers
            );
            $topologyHash = $this->currentTopologyHash;
        } finally {
            $this->rdvMutex->unlock();
        }

        if ($ownerAddress === null) {
            return null;
        }

        $ownerRef = $this->pidOfActivatorActor($ownerAddress);

        $pbIdentity = new ProtoBufClusterIdentity();
        $pbIdentity->setIdentity($clusterIdentity->identity());
        $pbIdentity->setKind($clusterIdentity->kind());

        $request = new ActivationRequest();
        $request->setClusterIdentity($pbIdentity);
        $request->setTopologyHash($topologyHash);
        // 冪等性保証のためユニークなリクエストIDを付与する
        $request->setRequestId(uniqid('activation-', true));

        $future = $this->cluster->actorSystem()->root()->requestFuture(
            $ownerRef,
            $request,
            $this->cluster->config()->requestTimeoutSeconds()
        );

        $result = $future->result();
        if ($result->error() !== null) {
            return null;
        }

        $response = $result->value();
        if (!$response instanceof ActivationResponse) {
            return null;
        }

        $responseTopologyHash = (int) $response->getTopologyHash();
        if ($responseTopologyHash !== 0 && $responseTopologyHash !== $topologyHash) {
            return null;
        }

        if ($response->getFailed()) {
            return null;
        }

        $pid = $response->getPid();
        return $pid !== null ? new Ref($pid) : null;
    }

    public function removePid(ClusterIdentity $clusterIdentity, Ref $pid): void
    {
        if (!$this->started) {
            return;
        }

        $ownerAddress = $pid->protobufPid()->getAddress();
        $ownerRef = $this->pidOfActivatorActor($ownerAddress);

        $pbIdentity = new ProtoBufClusterIdentity();
        $pbIdentity->setIdentity($clusterIdentity->identity());
        $pbIdentity->setKind($clusterIdentity->kind());

        $terminated = new ActivationTerminated();
        $terminated->setClusterIdentity($pbIdentity);
        $terminated->setPid($pid->protobufPid());

        $this->cluster->actorSystem()->root()->send($ownerRef, $terminated);
    }

    public function pidOfActivatorActor(string $address): Ref
    {
        $pid = new Pid();
        $pid->setAddress($address);
        $pid->setId(self::PARTITION_ACTIVATOR_ACTOR_NAME);
        return new Ref($pid);
    }

    private function onClusterTopology(ClusterTopologyEvent $topology): void
    {
        $this->rdvMutex->lock();
        try {
            $this->currentMembers = $topology->members();
            $this->currentTopologyHash = $topology->topologyHash();
            if ($this->placementActor !== null) {
                $this->cluster->actorSystem()->root()->send($this->placementActor, $topology);
            }
        } finally {
            $this->rdvMutex->unlock();
        }
    }
}
