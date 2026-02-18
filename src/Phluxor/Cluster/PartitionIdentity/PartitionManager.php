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
    private const string PARTITION_ACTIVATOR_ACTOR_NAME = 'partition-activator';

    private ?Ref $placementActor = null;

    private ?Subscription $topologySub = null;

    private RendezvousHashSelector $rdv;

    private Lock $rdvMutex;

    /** @var list<Member> */
    private array $currentMembers = [];

    public function __construct(
        private readonly Cluster $cluster
    ) {
        $this->rdv = new RendezvousHashSelector();
        $this->rdvMutex = new Lock(SWOOLE_RWLOCK);
    }

    public function start(): void
    {
        $system = $this->cluster->actorSystem();
        $cluster = $this->cluster;

        $props = Props::fromProducer(
            fn() => new PartitionPlacementActor($cluster)
        );

        $result = $system->root()->spawnNamed($props, self::PARTITION_ACTIVATOR_ACTOR_NAME);
        $this->placementActor = $result->getRef();

        $this->topologySub = $system->getEventStream()?->subscribe(
            function (mixed $event): void {
                if ($event instanceof ClusterTopologyEvent) {
                    $this->onClusterTopology($event);
                }
            }
        );
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
    }

    public function get(ClusterIdentity $clusterIdentity): ?Ref
    {
        $this->rdvMutex->lock_read();
        try {
            $ownerAddress = $this->rdv->getPartition(
                $clusterIdentity->toKey(),
                $this->currentMembers
            );

            if ($ownerAddress === null) {
                return null;
            }

            $ownerRef = $this->pidOfActivatorActor($ownerAddress);

            $pbIdentity = new ProtoBufClusterIdentity();
            $pbIdentity->setIdentity($clusterIdentity->identity());
            $pbIdentity->setKind($clusterIdentity->kind());

            $request = new ActivationRequest();
            $request->setClusterIdentity($pbIdentity);

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
            if (!$response instanceof ActivationResponse || $response->getFailed()) {
                return null;
            }

            $pid = $response->getPid();
            return $pid !== null ? new Ref($pid) : null;
        } finally {
            $this->rdvMutex->unlock();
        }
    }

    public function removePid(ClusterIdentity $clusterIdentity, Ref $pid): void
    {
        if ($this->placementActor === null) {
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
            if ($this->placementActor !== null) {
                $this->cluster->actorSystem()->root()->send($this->placementActor, $topology);
            }
        } finally {
            $this->rdvMutex->unlock();
        }
    }
}
