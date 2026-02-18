<?php

declare(strict_types=1);

namespace Phluxor\Cluster;

interface ClusterContextInterface
{
    public function request(string $identity, string $kind, mixed $message, ?GrainCallConfig $config = null): mixed;
}
