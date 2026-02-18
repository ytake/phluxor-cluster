<?php

declare(strict_types=1);

namespace Phluxor\Cluster;

final readonly class Member
{
    /**
     * @param list<string> $kinds
     */
    public function __construct(
        private string $host,
        private int $port,
        private string $id,
        private array $kinds
    ) {
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * @return list<string>
     */
    public function kinds(): array
    {
        return $this->kinds;
    }

    public function address(): string
    {
        return $this->host . ':' . $this->port;
    }

    public function hasKind(string $kind): bool
    {
        return in_array($kind, $this->kinds, true);
    }
}
