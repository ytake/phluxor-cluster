<?php

declare(strict_types=1);

namespace Test\PubSub;

use Google\Protobuf\Internal\Message;
use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\ProtoBuf\Acknowledge;
use Phluxor\Cluster\ProtoBuf\PubSubBatchTransport;
use Phluxor\Cluster\ProtoBuf\PublishResponse;
use Phluxor\Cluster\ProtoBuf\PublishStatus;
use Phluxor\Cluster\ProtoBuf\SubscribeRequest;
use Phluxor\Cluster\ProtoBuf\SubscriberIdentity;
use Phluxor\Cluster\PubSub\PubSubBatch;
use Phluxor\Cluster\PubSub\Publisher;
use Phluxor\Cluster\PubSub\PublisherInterface;
use PHPUnit\Framework\TestCase;

final class PublisherTest extends TestCase
{
    private const string TOPIC_ACTOR_KIND = 'prototopic';

    public function testImplementsInterface(): void
    {
        $cluster = $this->createMock(Cluster::class);
        $publisher = new Publisher($cluster);

        self::assertInstanceOf(PublisherInterface::class, $publisher);
    }

    public function testPublishSendsToTopicActor(): void
    {
        $expectedResponse = new PublishResponse(['status' => PublishStatus::Ok]);

        $cluster = $this->createMock(Cluster::class);
        $cluster->expects($this->once())
            ->method('request')
            ->with(
                'my-topic',
                self::TOPIC_ACTOR_KIND,
                $this->isInstanceOf(PubSubBatchTransport::class),
                null
            )
            ->willReturn($expectedResponse);

        $publisher = new Publisher($cluster);
        $message = new SubscribeRequest();

        $result = $publisher->publish('my-topic', $message);

        self::assertInstanceOf(PublishResponse::class, $result);
        self::assertSame(PublishStatus::Ok, $result->getStatus());
    }

    public function testPublishBatchSendsTransportToTopicActor(): void
    {
        $expectedResponse = new PublishResponse(['status' => PublishStatus::Ok]);

        $cluster = $this->createMock(Cluster::class);
        $cluster->expects($this->once())
            ->method('request')
            ->with(
                'events-topic',
                self::TOPIC_ACTOR_KIND,
                $this->isInstanceOf(PubSubBatchTransport::class),
                null
            )
            ->willReturn($expectedResponse);

        $publisher = new Publisher($cluster);
        $batch = new PubSubBatch([new SubscribeRequest()]);

        $result = $publisher->publishBatch('events-topic', $batch);

        self::assertInstanceOf(PublishResponse::class, $result);
    }

    public function testPublishReturnsNullWhenClusterRequestFails(): void
    {
        $cluster = $this->createMock(Cluster::class);
        $cluster->method('request')->willReturn(null);

        $publisher = new Publisher($cluster);
        $result = $publisher->publish('my-topic', new SubscribeRequest());

        self::assertNull($result);
    }

    public function testInitializeSendsPubSubInitializeToTopicActor(): void
    {
        $expectedResponse = new Acknowledge();

        $cluster = $this->createMock(Cluster::class);
        $cluster->expects($this->once())
            ->method('request')
            ->with(
                'my-topic',
                self::TOPIC_ACTOR_KIND,
                $this->callback(function (mixed $msg): bool {
                    return $msg instanceof \Phluxor\Cluster\ProtoBuf\PubSubInitialize
                        && $msg->getIdleTimeoutMs() === 5000;
                }),
                null
            )
            ->willReturn($expectedResponse);

        $publisher = new Publisher($cluster);
        $result = $publisher->initialize('my-topic', 5000);

        self::assertInstanceOf(Acknowledge::class, $result);
    }

    public function testPublishBatchWithMultipleMessages(): void
    {
        $expectedResponse = new PublishResponse(['status' => PublishStatus::Ok]);

        $sentTransport = null;
        $cluster = $this->createMock(Cluster::class);
        $cluster->expects($this->once())
            ->method('request')
            ->willReturnCallback(function (
                string $identity,
                string $kind,
                mixed $message
            ) use (&$sentTransport, $expectedResponse) {
                $sentTransport = $message;
                return $expectedResponse;
            });

        $publisher = new Publisher($cluster);

        $msg1 = new SubscribeRequest();
        $msg2 = new SubscribeRequest();
        $batch = new PubSubBatch([$msg1, $msg2]);

        $publisher->publishBatch('multi-topic', $batch);

        self::assertInstanceOf(PubSubBatchTransport::class, $sentTransport);
        self::assertCount(2, $sentTransport->getEnvelopes());
    }
}
