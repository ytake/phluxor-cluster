<?php

declare(strict_types=1);

namespace Phluxor\Cluster;

interface MemberStrategyInterface
{
    /**
     * @return list<Member>
     */
    public function getAllMembers(): array;

    public function addMember(Member $member): void;

    public function removeMember(Member $member): void;

    public function getPartition(string $key): ?string;

    public function getActivator(string $senderAddress): ?string;
}
