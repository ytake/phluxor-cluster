<?php

declare(strict_types=1);

namespace Phluxor\Cluster\Gossip;

use Closure;
use Phluxor\Cluster\ProtoBuf\GossipKeyValue;

final readonly class ConsensusCheck
{
    /**
     * @param list<string> $affectedKeys
     * @param Closure(array<string, GossipKeyValue>): bool $handler
     */
    public function __construct(
        private string $id,
        private array $affectedKeys,
        private Closure $handler
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * @return list<string>
     */
    public function affectedKeys(): array
    {
        return $this->affectedKeys;
    }

    /**
     * @param array<string, GossipKeyValue> $values
     */
    public function hasConsensus(array $values): bool
    {
        return ($this->handler)($values);
    }
}
