<?php

declare(strict_types=1);

namespace Test\PubSub;

use PHPUnit\Framework\TestCase;
use Phluxor\Cluster\ProtoBuf\ClusterIdentity;
use Phluxor\Cluster\ProtoBuf\SubscriberIdentity;
use Phluxor\Cluster\ProtoBuf\Subscribers;
use Phluxor\Cluster\PubSub\EmptyKeyValueStore;
use Phluxor\Cluster\PubSub\InMemoryKeyValueStore;
use Phluxor\Cluster\PubSub\KeyValueStoreInterface;

final class KeyValueStoreTest extends TestCase
{
    public function testEmptyKeyValueStoreImplementsInterface(): void
    {
        $store = new EmptyKeyValueStore();
        self::assertInstanceOf(KeyValueStoreInterface::class, $store);
    }

    public function testEmptyKeyValueStoreGetReturnsNull(): void
    {
        $store = new EmptyKeyValueStore();
        self::assertNull($store->get('any-key'));
    }

    public function testEmptyKeyValueStoreSetDoesNotThrow(): void
    {
        $store = new EmptyKeyValueStore();
        $store->set('key', $this->createSubscribers());
        self::assertNull($store->get('key'));
    }

    public function testEmptyKeyValueStoreClearDoesNotThrow(): void
    {
        $store = new EmptyKeyValueStore();
        $store->clear('key');
        self::assertNull($store->get('key'));
    }

    public function testInMemoryKeyValueStoreImplementsInterface(): void
    {
        $store = new InMemoryKeyValueStore();
        self::assertInstanceOf(KeyValueStoreInterface::class, $store);
    }

    public function testInMemoryKeyValueStoreSetAndGet(): void
    {
        $store = new InMemoryKeyValueStore();
        $subscribers = $this->createSubscribers();

        $store->set('topic-1', $subscribers);

        $result = $store->get('topic-1');
        self::assertInstanceOf(Subscribers::class, $result);
        self::assertCount(1, $result->getSubscribers());
    }

    public function testInMemoryKeyValueStoreGetReturnsNullForUnknownKey(): void
    {
        $store = new InMemoryKeyValueStore();
        self::assertNull($store->get('unknown'));
    }

    public function testInMemoryKeyValueStoreClear(): void
    {
        $store = new InMemoryKeyValueStore();
        $store->set('topic-1', $this->createSubscribers());
        $store->clear('topic-1');

        self::assertNull($store->get('topic-1'));
    }

    public function testInMemoryKeyValueStoreOverwrite(): void
    {
        $store = new InMemoryKeyValueStore();

        $sub1 = $this->createSubscribers();
        $store->set('topic-1', $sub1);

        $sub2 = new Subscribers();
        $store->set('topic-1', $sub2);

        $result = $store->get('topic-1');
        self::assertCount(0, $result->getSubscribers());
    }

    public function testInMemoryKeyValueStoreMultipleKeys(): void
    {
        $store = new InMemoryKeyValueStore();

        $store->set('topic-1', $this->createSubscribers());
        $store->set('topic-2', new Subscribers());

        self::assertCount(1, $store->get('topic-1')->getSubscribers());
        self::assertCount(0, $store->get('topic-2')->getSubscribers());
    }

    private function createSubscribers(): Subscribers
    {
        $identity = new SubscriberIdentity();
        $identity->setClusterIdentity(
            new ClusterIdentity(['identity' => 'user-1', 'kind' => 'UserGrain'])
        );
        $subscribers = new Subscribers();
        $subscribers->setSubscribers([$identity]);
        return $subscribers;
    }
}
