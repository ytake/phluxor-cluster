<?php

declare(strict_types=1);

namespace Phluxor\Cluster;

final class KindRegistry
{
    /** @var array<string, ActivatedKind> */
    private array $kinds = [];

    public function __construct(ActivatedKind ...$kinds)
    {
        foreach ($kinds as $kind) {
            $this->register($kind);
        }
    }

    public function register(ActivatedKind $kind): void
    {
        $this->kinds[$kind->kind()] = $kind;
    }

    public function find(string $kind): ?ActivatedKind
    {
        return $this->kinds[$kind] ?? null;
    }

    public function has(string $kind): bool
    {
        return isset($this->kinds[$kind]);
    }

    /**
     * @return list<string>
     */
    public function allKindNames(): array
    {
        return array_keys($this->kinds);
    }
}
