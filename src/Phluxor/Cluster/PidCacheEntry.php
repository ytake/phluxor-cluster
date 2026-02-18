<?php

declare(strict_types=1);

namespace Phluxor\Cluster;

use Phluxor\ActorSystem\Ref;

final readonly class PidCacheEntry
{
    public function __construct(
        private ClusterIdentity $clusterIdentity,
        private Ref $ref
    ) {
    }

    public function clusterIdentity(): ClusterIdentity
    {
        return $this->clusterIdentity;
    }

    public function ref(): Ref
    {
        return $this->ref;
    }
}
