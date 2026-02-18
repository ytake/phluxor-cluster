<?php

declare(strict_types=1);

namespace Phluxor\Cluster\Automanaged;

final readonly class AutomanagedConfig
{
    /**
     * @param list<string> $seeds
     */
    public function __construct(
        private array $seeds,
        private int $autoManagePort = 6330,
        private int $refreshIntervalMs = 2000,
        private int $httpTimeoutMs = 2000,
    ) {
    }

    /**
     * @return list<string>
     */
    public function seeds(): array
    {
        return $this->seeds;
    }

    public function autoManagePort(): int
    {
        return $this->autoManagePort;
    }

    public function refreshIntervalMs(): int
    {
        return $this->refreshIntervalMs;
    }

    public function httpTimeoutMs(): int
    {
        return $this->httpTimeoutMs;
    }
}
