<?php

declare(strict_types=1);

namespace Phluxor\Cluster;

final class MemberSet
{
    /** @var array<string, Member> address => Member */
    private array $members;

    public function __construct(Member ...$members)
    {
        $this->members = [];
        foreach ($members as $member) {
            $this->members[$member->address()] = $member;
        }
    }

    /**
     * @param list<Member> $members
     */
    public static function fromArray(array $members): self
    {
        return new self(...$members);
    }

    /**
     * @return list<Member>
     */
    public function all(): array
    {
        return array_values($this->members);
    }

    public function count(): int
    {
        return count($this->members);
    }

    public function isEmpty(): bool
    {
        return $this->members === [];
    }

    public function has(Member $member): bool
    {
        return isset($this->members[$member->address()]);
    }

    /**
     * Members in this set but not in $other.
     */
    public function except(self $other): self
    {
        $result = [];
        foreach ($this->members as $address => $member) {
            if (!isset($other->members[$address])) {
                $result[] = $member;
            }
        }
        return new self(...$result);
    }

    /**
     * Members in this set whose IDs are not in the given list.
     */
    public function exceptIds(string ...$ids): self
    {
        $idSet = array_flip($ids);
        $result = [];
        foreach ($this->members as $member) {
            if (!isset($idSet[$member->id()])) {
                $result[] = $member;
            }
        }
        return new self(...$result);
    }

    /**
     * Compute a deterministic hash of the current member set.
     * Members are identified by ID, sorted, concatenated, and hashed.
     */
    public function topologyHash(): int
    {
        $ids = [];
        foreach ($this->members as $member) {
            $ids[] = $member->id();
        }
        sort($ids);
        return crc32(implode('', $ids));
    }
}
