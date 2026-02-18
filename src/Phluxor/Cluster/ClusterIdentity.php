<?php

declare(strict_types=1);

namespace Phluxor\Cluster;

final readonly class ClusterIdentity
{
    public function __construct(
        private string $identity,
        private string $kind
    ) {
    }

    public function identity(): string
    {
        return $this->identity;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function toKey(): string
    {
        return $this->identity . '/' . $this->kind;
    }

    public function equals(ClusterIdentity $other): bool
    {
        return $this->identity === $other->identity()
            && $this->kind === $other->kind();
    }

    public function __toString(): string
    {
        return $this->toKey();
    }
}
