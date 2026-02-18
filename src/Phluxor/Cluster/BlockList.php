<?php

declare(strict_types=1);

namespace Phluxor\Cluster;

use Swoole\Lock;

use const SWOOLE_RWLOCK;

final class BlockList
{
    /** @var array<string, true> */
    private array $blockedMemberIds = [];

    private Lock $lock;

    public function __construct()
    {
        $this->lock = new Lock(SWOOLE_RWLOCK);
    }

    public function block(string ...$memberIds): void
    {
        $this->lock->lock();
        try {
            foreach ($memberIds as $memberId) {
                $this->blockedMemberIds[$memberId] = true;
            }
        } finally {
            $this->lock->unlock();
        }
    }

    public function isBlocked(string $memberId): bool
    {
        $this->lock->lock_read();
        try {
            return isset($this->blockedMemberIds[$memberId]);
        } finally {
            $this->lock->unlock();
        }
    }

    /**
     * @return list<string>
     */
    public function blockedMembers(): array
    {
        $this->lock->lock_read();
        try {
            return array_keys($this->blockedMemberIds);
        } finally {
            $this->lock->unlock();
        }
    }

    public function count(): int
    {
        $this->lock->lock_read();
        try {
            return count($this->blockedMemberIds);
        } finally {
            $this->lock->unlock();
        }
    }
}
