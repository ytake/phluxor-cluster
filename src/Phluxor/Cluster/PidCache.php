<?php

declare(strict_types=1);

namespace Phluxor\Cluster;

use Phluxor\ActorSystem\ConcurrentMap;
use Phluxor\ActorSystem\Ref;
use Swoole\Lock;

use const SWOOLE_RWLOCK;

final class PidCache
{
    private ConcurrentMap $cache;

    /** @var array<string, list<string>> */
    private array $memberKeyIndex = [];

    private Lock $memberKeyIndexLock;

    public function __construct()
    {
        $this->cache = new ConcurrentMap();
        $this->memberKeyIndexLock = new Lock(SWOOLE_RWLOCK);
    }

    public function get(ClusterIdentity $identity): ?Ref
    {
        $result = $this->cache->get($identity->toKey());
        if (!$result->exists || !$result->value instanceof PidCacheEntry) {
            return null;
        }

        return $result->value->ref();
    }

    public function set(ClusterIdentity $identity, Ref $ref): void
    {
        $key = $identity->toKey();
        $entry = new PidCacheEntry($identity, $ref);
        $this->cache->setIfAbsent($key, $entry);

        $memberAddress = $ref->protobufPid()->getAddress();
        $this->memberKeyIndexLock->lock();
        try {
            if (!isset($this->memberKeyIndex[$memberAddress])) {
                $this->memberKeyIndex[$memberAddress] = [];
            }
            if (!in_array($key, $this->memberKeyIndex[$memberAddress], true)) {
                $this->memberKeyIndex[$memberAddress][] = $key;
            }
        } finally {
            $this->memberKeyIndexLock->unlock();
        }
    }

    public function remove(ClusterIdentity $identity): void
    {
        $key = $identity->toKey();
        $removed = $this->cache->pop($key);
        if (!$removed->exists || !$removed->value instanceof PidCacheEntry) {
            return;
        }

        $memberAddress = $removed->value->ref()->protobufPid()->getAddress();
        $this->memberKeyIndexLock->lock();
        try {
            if (isset($this->memberKeyIndex[$memberAddress])) {
                $this->memberKeyIndex[$memberAddress] = array_values(
                    array_filter(
                        $this->memberKeyIndex[$memberAddress],
                        static fn(string $k): bool => $k !== $key
                    )
                );
                if ($this->memberKeyIndex[$memberAddress] === []) {
                    unset($this->memberKeyIndex[$memberAddress]);
                }
            }
        } finally {
            $this->memberKeyIndexLock->unlock();
        }
    }

    public function removeByMember(string $memberAddress): void
    {
        /** @var list<string> $keys */
        $keys = [];

        $this->memberKeyIndexLock->lock();
        try {
            if (isset($this->memberKeyIndex[$memberAddress])) {
                $keys = $this->memberKeyIndex[$memberAddress];
                unset($this->memberKeyIndex[$memberAddress]);
            }
        } finally {
            $this->memberKeyIndexLock->unlock();
        }

        foreach ($keys as $key) {
            $this->cache->pop($key);
        }
    }
}
