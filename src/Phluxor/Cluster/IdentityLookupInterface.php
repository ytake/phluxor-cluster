<?php

declare(strict_types=1);

namespace Phluxor\Cluster;

use Phluxor\ActorSystem\Ref;

interface IdentityLookupInterface
{
    public function get(ClusterIdentity $clusterIdentity): ?Ref;

    public function removePid(ClusterIdentity $clusterIdentity, Ref $pid): void;

    /**
     * @param list<string> $kinds
     */
    public function setup(Cluster $cluster, array $kinds, bool $isClient): void;

    public function shutdown(): void;
}
