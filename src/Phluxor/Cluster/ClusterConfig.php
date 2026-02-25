<?php

declare(strict_types=1);

namespace Phluxor\Cluster;

final readonly class ClusterConfig
{
    public function __construct(
        private string $name,
        private string $host,
        private int $port,
        private ClusterProviderInterface $clusterProvider,
        private IdentityLookupInterface $identityLookup,
        private KindRegistry $kindRegistry,
        private int $gossipIntervalMs = 300,
        private int $gossipFanOut = 3,
        private int $heartbeatExpirationMs = 20000,
        private int $requestTimeoutSeconds = 5,
        private int $pubSubSubscriberTimeoutSeconds = 5
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function clusterProvider(): ClusterProviderInterface
    {
        return $this->clusterProvider;
    }

    public function identityLookup(): IdentityLookupInterface
    {
        return $this->identityLookup;
    }

    public function kindRegistry(): KindRegistry
    {
        return $this->kindRegistry;
    }

    public function gossipIntervalMs(): int
    {
        return $this->gossipIntervalMs;
    }

    public function gossipFanOut(): int
    {
        return $this->gossipFanOut;
    }

    public function heartbeatExpirationMs(): int
    {
        return $this->heartbeatExpirationMs;
    }

    public function requestTimeoutSeconds(): int
    {
        return $this->requestTimeoutSeconds;
    }

    public function pubSubSubscriberTimeoutSeconds(): int
    {
        return $this->pubSubSubscriberTimeoutSeconds;
    }

    public function address(): string
    {
        return $this->host . ':' . $this->port;
    }
}
