<?php

declare(strict_types=1);

namespace Phluxor\Cluster;

final readonly class GrainCallConfig
{
    public function __construct(
        private int $retryCount = 3,
        private int $requestTimeoutSeconds = 5
    ) {
    }

    public function retryCount(): int
    {
        return $this->retryCount;
    }

    public function requestTimeoutSeconds(): int
    {
        return $this->requestTimeoutSeconds;
    }
}
