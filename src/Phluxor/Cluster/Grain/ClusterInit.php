<?php

declare(strict_types=1);

namespace Phluxor\Cluster\Grain;

use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\ClusterIdentity;

final readonly class ClusterInit
{
    public function __construct(
        public Cluster $cluster,
        public ClusterIdentity $identity,
    ) {
    }
}
