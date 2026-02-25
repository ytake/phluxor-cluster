<?php

declare(strict_types=1);

namespace Phluxor\Cluster\PubSub;

use Phluxor\Cluster\ProtoBuf\Subscribers;

final class InMemoryKeyValueStore implements KeyValueStoreInterface
{
    /** @var array<string, Subscribers> */
    private array $store = [];

    public function set(string $key, Subscribers $value): void
    {
        $this->store[$key] = $value;
    }

    public function get(string $key): ?Subscribers
    {
        return $this->store[$key] ?? null;
    }

    public function clear(string $key): void
    {
        unset($this->store[$key]);
    }
}
