<?php

declare(strict_types=1);

namespace Phluxor\Cluster\PubSub;

use Phluxor\Cluster\ProtoBuf\Subscribers;

final class EmptyKeyValueStore implements KeyValueStoreInterface
{
    public function set(string $key, Subscribers $value): void
    {
    }

    public function get(string $key): ?Subscribers
    {
        return null;
    }

    public function clear(string $key): void
    {
    }
}
