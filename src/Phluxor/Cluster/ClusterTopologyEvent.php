<?php

declare(strict_types=1);

namespace Phluxor\Cluster;

final readonly class ClusterTopologyEvent
{
    /**
     * @param list<Member> $members  Current active members
     * @param list<Member> $joined   Members that joined in this update
     * @param list<Member> $left     Members that left in this update
     * @param list<string> $blocked  Blocked member IDs
     */
    public function __construct(
        private int $topologyHash,
        private array $members,
        private array $joined,
        private array $left,
        private array $blocked
    ) {
    }

    public function topologyHash(): int
    {
        return $this->topologyHash;
    }

    /**
     * @return list<Member>
     */
    public function members(): array
    {
        return $this->members;
    }

    /**
     * @return list<Member>
     */
    public function joined(): array
    {
        return $this->joined;
    }

    /**
     * @return list<Member>
     */
    public function left(): array
    {
        return $this->left;
    }

    /**
     * @return list<string>
     */
    public function blocked(): array
    {
        return $this->blocked;
    }
}
