<?php

declare(strict_types=1);

namespace Phluxor\Cluster;

use Phluxor\ActorSystem;
use Phluxor\ActorSystem\Ref;
use Phluxor\Cluster\Gossip\GossipKeys;
use Phluxor\Cluster\Gossip\Gossiper;
use Phluxor\EventStream\Subscription;

class Cluster
{
    private BlockList $blockList;

    private PidCache $pidCache;

    private ?MemberList $memberList = null;

    private ?ClusterContextInterface $clusterContext = null;

    private ?Gossiper $gossiper = null;

    private ?Subscription $topologySub = null;

    public function __construct(
        private readonly ActorSystem $actorSystem,
        private readonly ClusterConfig $config
    ) {
        $this->blockList = new BlockList();
        $this->pidCache = new PidCache();
    }

    public function actorSystem(): ActorSystem
    {
        return $this->actorSystem;
    }

    public function config(): ClusterConfig
    {
        return $this->config;
    }

    public function blockList(): BlockList
    {
        return $this->blockList;
    }

    public function pidCache(): PidCache
    {
        return $this->pidCache;
    }

    public function memberList(): ?MemberList
    {
        return $this->memberList;
    }

    public function gossiper(): ?Gossiper
    {
        return $this->gossiper;
    }

    public function startMember(): void
    {
        $this->actorSystem->getProcessRegistry()->setAddress($this->config->address());
        $this->actorSystem->extensions()->set(new ClusterExtension($this));

        $this->memberList = new MemberList($this->blockList);
        $this->clusterContext = new DefaultClusterContext($this);

        $this->subscribeToTopologyEvents();

        $this->gossiper = new Gossiper($this);
        $this->gossiper->start();

        $kinds = $this->config->kindRegistry()->allKindNames();
        $this->config->identityLookup()->setup($this, $kinds, false);
        $this->config->clusterProvider()->startMember($this);
    }

    public function startClient(): void
    {
        $this->actorSystem->getProcessRegistry()->setAddress($this->config->address());
        $this->actorSystem->extensions()->set(new ClusterExtension($this));

        $this->memberList = new MemberList($this->blockList);
        $this->clusterContext = new DefaultClusterContext($this);

        $this->subscribeToTopologyEvents();

        $this->gossiper = new Gossiper($this);
        $this->gossiper->start();

        $this->config->identityLookup()->setup($this, [], true);
        $this->config->clusterProvider()->startClient($this);
    }

    public function shutdown(bool $graceful = true): void
    {
        if ($graceful && $this->gossiper !== null) {
            $this->gossiper->setState(GossipKeys::GRACEFULLY_LEFT, '', 'Empty');
        }

        if ($graceful) {
            $this->config->clusterProvider()->shutdown(true);
            $this->config->identityLookup()->shutdown();
        }

        $this->gossiper?->stop();
        $this->gossiper = null;

        if ($this->topologySub !== null) {
            $this->actorSystem->getEventStream()?->unsubscribe($this->topologySub);
            $this->topologySub = null;
        }

        $this->clusterContext = null;
    }

    public function get(string $identity, string $kind): ?Ref
    {
        return $this->config->identityLookup()->get(
            new ClusterIdentity($identity, $kind)
        );
    }

    public function request(string $identity, string $kind, mixed $message, ?GrainCallConfig $config = null): mixed
    {
        return $this->clusterContext?->request($identity, $kind, $message, $config);
    }

    private function subscribeToTopologyEvents(): void
    {
        $this->topologySub = $this->actorSystem->getEventStream()?->subscribe(
            function (mixed $event): void {
                if ($event instanceof ClusterTopologyEvent) {
                    $this->onClusterTopology($event);
                }
            }
        );
    }

    private function onClusterTopology(ClusterTopologyEvent $event): void
    {
        foreach ($event->left() as $member) {
            $this->pidCache->removeByMember($member->address());
        }
    }
}
