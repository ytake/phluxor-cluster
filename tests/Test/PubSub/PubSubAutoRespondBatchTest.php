<?php

declare(strict_types=1);

namespace Test\PubSub;

use PHPUnit\Framework\TestCase;
use Phluxor\ActorSystem\AutoRespondInterface;
use Phluxor\ActorSystem\Message\MessageBatchInterface;
use Phluxor\Cluster\ProtoBuf\ClusterIdentity;
use Phluxor\Cluster\ProtoBuf\GrainResponse;
use Phluxor\Cluster\ProtoBuf\PubSubAutoRespondBatchTransport;
use Phluxor\Cluster\ProtoBuf\PublishResponse;
use Phluxor\Cluster\ProtoBuf\PublishStatus;
use Phluxor\Cluster\PubSub\PubSubAutoRespondBatch;

final class PubSubAutoRespondBatchTest extends TestCase
{
    public function testImplementsMessageBatchInterface(): void
    {
        $batch = new PubSubAutoRespondBatch([]);
        self::assertInstanceOf(MessageBatchInterface::class, $batch);
    }

    public function testImplementsAutoRespondInterface(): void
    {
        $batch = new PubSubAutoRespondBatch([]);
        self::assertInstanceOf(AutoRespondInterface::class, $batch);
    }

    public function testGetMessagesReturnsEnvelopes(): void
    {
        $msg1 = new ClusterIdentity(['identity' => 'user-1', 'kind' => 'UserGrain']);
        $msg2 = new GrainResponse(['message_data' => 'data', 'message_type_name' => 'Test']);

        $batch = new PubSubAutoRespondBatch([$msg1, $msg2]);
        $messages = $batch->getMessages();

        self::assertCount(2, $messages);
        self::assertInstanceOf(ClusterIdentity::class, $messages[0]);
        self::assertInstanceOf(GrainResponse::class, $messages[1]);
    }

    public function testGetAutoResponseReturnsPublishResponseOk(): void
    {
        $batch = new PubSubAutoRespondBatch([]);

        $mock = $this->createMock(\Phluxor\ActorSystem\Context\ContextInterface::class);
        $response = $batch->getAutoResponse($mock);

        self::assertInstanceOf(PublishResponse::class, $response);
        self::assertSame(PublishStatus::Ok, $response->getStatus());
    }

    public function testToTransport(): void
    {
        $msg = new ClusterIdentity(['identity' => 'user-1', 'kind' => 'UserGrain']);
        $batch = new PubSubAutoRespondBatch([$msg]);

        $transport = $batch->toTransport();

        self::assertCount(1, $transport->getTypeNames());
        self::assertSame(ClusterIdentity::class, $transport->getTypeNames()[0]);
        self::assertCount(1, $transport->getEnvelopes());
    }

    public function testFromTransport(): void
    {
        $msg = new ClusterIdentity(['identity' => 'user-1', 'kind' => 'UserGrain']);
        $batch = new PubSubAutoRespondBatch([$msg]);

        $transport = $batch->toTransport();
        $restored = PubSubAutoRespondBatch::fromTransport($transport);

        $messages = $restored->getMessages();
        self::assertCount(1, $messages);
        self::assertInstanceOf(ClusterIdentity::class, $messages[0]);
        self::assertSame('user-1', $messages[0]->getIdentity());
    }

    public function testRoundTripThroughProtobufSerialization(): void
    {
        $msg1 = new ClusterIdentity(['identity' => 'user-1', 'kind' => 'UserGrain']);
        $msg2 = new GrainResponse(['message_data' => 'payload', 'message_type_name' => 'Test']);
        $batch = new PubSubAutoRespondBatch([$msg1, $msg2]);

        $transport = $batch->toTransport();
        $bytes = $transport->serializeToString();

        $decodedTransport = new PubSubAutoRespondBatchTransport();
        $decodedTransport->mergeFromString($bytes);

        $restored = PubSubAutoRespondBatch::fromTransport($decodedTransport);
        $messages = $restored->getMessages();

        self::assertCount(2, $messages);
        self::assertInstanceOf(ClusterIdentity::class, $messages[0]);
        self::assertSame('user-1', $messages[0]->getIdentity());
        self::assertInstanceOf(GrainResponse::class, $messages[1]);
        self::assertSame('payload', $messages[1]->getMessageData());
    }

    public function testEmptyBatch(): void
    {
        $batch = new PubSubAutoRespondBatch([]);

        self::assertCount(0, $batch->getMessages());

        $transport = $batch->toTransport();
        self::assertCount(0, $transport->getTypeNames());
        self::assertCount(0, $transport->getEnvelopes());

        $restored = PubSubAutoRespondBatch::fromTransport($transport);
        self::assertCount(0, $restored->getMessages());
    }
}
