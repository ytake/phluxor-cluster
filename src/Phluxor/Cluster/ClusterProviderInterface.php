<?php

declare(strict_types=1);

namespace Phluxor\Cluster;

interface ClusterProviderInterface
{
    public function startMember(Cluster $cluster): void;

    public function startClient(Cluster $cluster): void;

    public function shutdown(bool $graceful = true): void;
}
