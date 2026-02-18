<?php

declare(strict_types=1);

namespace Phluxor\Cluster;

use Phluxor\ActorSystem\Ref;

final readonly class DefaultClusterContext implements ClusterContextInterface
{
    public function __construct(
        private Cluster $cluster
    ) {
    }

    public function request(string $identity, string $kind, mixed $message, ?GrainCallConfig $config = null): mixed
    {
        $config ??= new GrainCallConfig(
            retryCount: 3,
            requestTimeoutSeconds: $this->cluster->config()->requestTimeoutSeconds()
        );

        $clusterIdentity = new ClusterIdentity($identity, $kind);

        for ($i = 0; $i < $config->retryCount(); $i++) {
            $pid = $this->getPid($clusterIdentity);
            if ($pid === null) {
                continue;
            }

            $future = $this->cluster->actorSystem()->root()->requestFuture(
                $pid,
                $message,
                $config->requestTimeoutSeconds()
            );
            $result = $future->result();

            if ($result->error() !== null) {
                $this->cluster->pidCache()->remove($clusterIdentity);
                $this->cluster->config()->identityLookup()->removePid($clusterIdentity, $pid);
                continue;
            }

            return $result->value();
        }

        return null;
    }

    private function getPid(ClusterIdentity $clusterIdentity): ?Ref
    {
        $ref = $this->cluster->pidCache()->get($clusterIdentity);
        if ($ref !== null) {
            return $ref;
        }

        $ref = $this->cluster->config()->identityLookup()->get($clusterIdentity);
        if ($ref !== null) {
            $this->cluster->pidCache()->set($clusterIdentity, $ref);
        }

        return $ref;
    }
}
