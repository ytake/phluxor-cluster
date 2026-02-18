<?php

declare(strict_types=1);

namespace Phluxor\Cluster\PartitionIdentity;

use Phluxor\ActorSystem\Ref;
use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\ClusterIdentity;
use Phluxor\Cluster\IdentityLookupInterface;

final class PartitionIdentityLookup implements IdentityLookupInterface
{
    private ?PartitionManager $manager = null;

    public function get(ClusterIdentity $clusterIdentity): ?Ref
    {
        return $this->manager?->get($clusterIdentity);
    }

    public function removePid(ClusterIdentity $clusterIdentity, Ref $pid): void
    {
        $this->manager?->removePid($clusterIdentity, $pid);
    }

    /**
     * @param list<string> $kinds
     */
    public function setup(Cluster $cluster, array $kinds, bool $isClient): void
    {
        // クライアントノードはGrainをアクティベートしないため、
        // PartitionPlacementActorをスポーンしない
        if ($isClient) {
            return;
        }

        $this->manager = new PartitionManager($cluster);
        $this->manager->start();
    }

    public function shutdown(): void
    {
        $this->manager?->stop();
        $this->manager = null;
    }
}
