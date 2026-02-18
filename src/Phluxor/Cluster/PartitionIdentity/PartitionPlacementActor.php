<?php

declare(strict_types=1);

namespace Phluxor\Cluster\PartitionIdentity;

use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Message\ActorInterface;
use Phluxor\ActorSystem\Message\Started;
use Phluxor\ActorSystem\Message\Stopping;
use Phluxor\ActorSystem\ProtoBuf\Terminated;
use Phluxor\ActorSystem\Ref;
use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\ClusterIdentity;
use Phluxor\Cluster\ClusterTopologyEvent;
use Phluxor\Cluster\Grain\ClusterInit;
use Phluxor\Cluster\Hashing\RendezvousHashSelector;
use Phluxor\Cluster\ProtoBuf\ActivationRequest;
use Phluxor\Cluster\ProtoBuf\ActivationResponse;
use Phluxor\Cluster\ProtoBuf\ActivationTerminated;

final class PartitionPlacementActor implements ActorInterface
{
    /** @var array<string, Ref> key="kind/identity" => spawned actor Ref */
    private array $actors = [];

    public function __construct(
        private readonly Cluster $cluster
    ) {
    }

    public function receive(ContextInterface $context): void
    {
        $message = $context->message();

        if ($message instanceof Started) {
            return;
        }

        if ($message instanceof ActivationRequest) {
            $this->onActivationRequest($message, $context);
        } elseif ($message instanceof ClusterTopologyEvent) {
            $this->onClusterTopology($message, $context);
        } elseif ($message instanceof ActivationTerminated) {
            $this->onActivationTerminated($message);
        } elseif ($message instanceof Terminated) {
            $this->onTerminated($message);
        } elseif ($message instanceof Stopping) {
            $this->onStopping($context);
        }
    }

    private function onActivationRequest(ActivationRequest $msg, ContextInterface $context): void
    {
        $clusterIdentity = $msg->getClusterIdentity();
        if ($clusterIdentity === null) {
            $this->respondFailed($context);
            return;
        }

        $key = $clusterIdentity->getKind() . '/' . $clusterIdentity->getIdentity();

        $existing = $this->actors[$key] ?? null;
        if ($existing instanceof Ref) {
            $response = new ActivationResponse();
            $response->setPid($existing->protobufPid());
            $context->respond($response);
            return;
        }

        $kind = $this->cluster->config()->kindRegistry()->find($clusterIdentity->getKind());
        if ($kind === null) {
            $this->respondFailed($context);
            return;
        }

        $ref = $context->spawnPrefix($kind->props(), $clusterIdentity->getIdentity());
        if (!$ref instanceof Ref) {
            $this->respondFailed($context);
            return;
        }

        $context->send(
            $ref,
            new ClusterInit(
                $this->cluster,
                new ClusterIdentity(
                    $clusterIdentity->getIdentity(),
                    $clusterIdentity->getKind()
                )
            )
        );

        $this->actors[$key] = $ref;

        $response = new ActivationResponse();
        $response->setPid($ref->protobufPid());
        $context->respond($response);
    }

    private function onClusterTopology(ClusterTopologyEvent $msg, ContextInterface $context): void
    {
        $rdv = new RendezvousHashSelector();
        $selfAddress = $this->cluster->config()->address();

        foreach ($this->actors as $key => $ref) {
            $owner = $rdv->getPartition($key, $msg->members());
            if ($owner !== $selfAddress) {
                $context->poison($ref);
            }
        }
    }

    private function onActivationTerminated(ActivationTerminated $msg): void
    {
        $clusterIdentity = $msg->getClusterIdentity();
        if ($clusterIdentity === null) {
            return;
        }

        $key = $clusterIdentity->getKind() . '/' . $clusterIdentity->getIdentity();
        $existing = $this->actors[$key] ?? null;
        if (!$existing instanceof Ref) {
            return;
        }

        $pid = $msg->getPid();
        if ($pid === null || $existing->protobufPid()->getId() === $pid->getId()) {
            unset($this->actors[$key]);
        }
    }

    private function onTerminated(Terminated $msg): void
    {
        $who = $msg->getWho();
        if ($who === null) {
            return;
        }

        foreach ($this->actors as $key => $ref) {
            if ($ref->protobufPid()->getId() === $who->getId()) {
                unset($this->actors[$key]);
                return;
            }
        }
    }

    private function onStopping(ContextInterface $context): void
    {
        foreach ($this->actors as $ref) {
            $context->poison($ref);
        }
    }

    private function respondFailed(ContextInterface $context): void
    {
        $response = new ActivationResponse();
        $response->setFailed(true);
        $context->respond($response);
    }
}
