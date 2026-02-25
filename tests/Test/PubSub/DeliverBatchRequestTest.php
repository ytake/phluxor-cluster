<?php

declare(strict_types=1);

namespace Test\PubSub;

use PHPUnit\Framework\TestCase;
use Phluxor\ActorSystem\ProtoBuf\Pid;
use Phluxor\Cluster\ProtoBuf\ClusterIdentity;
use Phluxor\Cluster\ProtoBuf\DeliverBatchRequestTransport;
use Phluxor\Cluster\ProtoBuf\SubscriberIdentity;
use Phluxor\Cluster\ProtoBuf\Subscribers;
use Phluxor\Cluster\PubSub\DeliverBatchRequest;
use Phluxor\Cluster\PubSub\PubSubBatch;

final class DeliverBatchRequestTest extends TestCase
{
    public function testToTransport(): void
    {
        $sub = new SubscriberIdentity();
        $sub->setPid(new Pid(['address' => 'node-1:8080', 'id' => 'actor-1']));
        $subscribers = new Subscribers();
        $subscribers->setSubscribers([$sub]);

        $msg = new ClusterIdentity(['identity' => 'user-1', 'kind' => 'UserGrain']);
        $batch = new PubSubBatch([$msg]);

        $request = new DeliverBatchRequest($subscribers, $batch, 'test-topic');
        $transport = $request->toTransport();

        self::assertSame('test-topic', $transport->getTopic());
        self::assertCount(1, $transport->getSubscribers()->getSubscribers());
        self::assertCount(1, $transport->getBatch()->getEnvelopes());
    }

    public function testFromTransport(): void
    {
        $sub = new SubscriberIdentity();
        $sub->setPid(new Pid(['address' => 'node-1:8080', 'id' => 'actor-1']));
        $subscribers = new Subscribers();
        $subscribers->setSubscribers([$sub]);

        $msg = new ClusterIdentity(['identity' => 'user-1', 'kind' => 'UserGrain']);
        $batch = new PubSubBatch([$msg]);

        $original = new DeliverBatchRequest($subscribers, $batch, 'my-topic');
        $transport = $original->toTransport();
        $restored = DeliverBatchRequest::fromTransport($transport);

        self::assertSame('my-topic', $restored->getTopic());
        self::assertCount(1, $restored->getSubscribers()->getSubscribers());

        $restoredMessages = $restored->getPubSubBatch()->getEnvelopes();
        self::assertCount(1, $restoredMessages);
        self::assertInstanceOf(ClusterIdentity::class, $restoredMessages[0]);
        self::assertSame('user-1', $restoredMessages[0]->getIdentity());
    }

    public function testRoundTripThroughProtobufSerialization(): void
    {
        $sub1 = new SubscriberIdentity();
        $sub1->setPid(new Pid(['address' => 'node-1:8080', 'id' => 'actor-1']));
        $sub2 = new SubscriberIdentity();
        $sub2->setClusterIdentity(new ClusterIdentity(['identity' => 'order-1', 'kind' => 'OrderGrain']));
        $subscribers = new Subscribers();
        $subscribers->setSubscribers([$sub1, $sub2]);

        $msg = new ClusterIdentity(['identity' => 'user-1', 'kind' => 'UserGrain']);
        $batch = new PubSubBatch([$msg]);

        $request = new DeliverBatchRequest($subscribers, $batch, 'events');
        $transport = $request->toTransport();

        $bytes = $transport->serializeToString();
        $decodedTransport = new DeliverBatchRequestTransport();
        $decodedTransport->mergeFromString($bytes);

        $restored = DeliverBatchRequest::fromTransport($decodedTransport);

        self::assertSame('events', $restored->getTopic());
        self::assertCount(2, $restored->getSubscribers()->getSubscribers());
        self::assertCount(1, $restored->getPubSubBatch()->getEnvelopes());
    }

    public function testAccessors(): void
    {
        $subscribers = new Subscribers();
        $batch = new PubSubBatch([]);
        $request = new DeliverBatchRequest($subscribers, $batch, 'my-topic');

        self::assertSame($subscribers, $request->getSubscribers());
        self::assertSame($batch, $request->getPubSubBatch());
        self::assertSame('my-topic', $request->getTopic());
    }
}
