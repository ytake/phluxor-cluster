<?php

declare(strict_types=1);

namespace Phluxor\Cluster;

use Phluxor\Cluster\Hashing\RendezvousHashSelector;
use Phluxor\Cluster\Hashing\RoundRobinSelector;

final class DefaultMemberStrategy implements MemberStrategyInterface
{
    private RendezvousHashSelector $hashSelector;

    private RoundRobinSelector $roundRobin;

    /** @var list<Member> */
    private array $members = [];

    public function __construct()
    {
        $this->hashSelector = new RendezvousHashSelector();
        $this->roundRobin = new RoundRobinSelector();
    }

    /**
     * @return list<Member>
     */
    public function getAllMembers(): array
    {
        return $this->members;
    }

    public function addMember(Member $member): void
    {
        $this->members[] = $member;
    }

    public function removeMember(Member $member): void
    {
        $this->members = array_values(
            array_filter(
                $this->members,
                fn(Member $m): bool => $m->address() !== $member->address()
            )
        );
    }

    public function getPartition(string $key): ?string
    {
        return $this->hashSelector->getPartition($key, $this->members);
    }

    public function getActivator(string $senderAddress): ?string
    {
        return $this->roundRobin->getByRoundRobin($this->members);
    }
}
