<?php

declare(strict_types=1);

namespace Test\PubSub;

use Google\Protobuf\Internal\Message;
use PHPUnit\Framework\TestCase;
use Phluxor\Cluster\ProtoBuf\ClusterIdentity;
use Phluxor\Cluster\ProtoBuf\GrainRequest;
use Phluxor\Cluster\ProtoBuf\GrainResponse;
use Phluxor\Cluster\ProtoBuf\PubSubBatchTransport;
use Phluxor\Cluster\PubSub\PubSubBatch;

final class PubSubBatchTest extends TestCase
{
    public function testToTransportSingleMessage(): void
    {
        $message = new ClusterIdentity(['identity' => 'user-1', 'kind' => 'UserGrain']);
        $batch = new PubSubBatch([$message]);

        $transport = $batch->toTransport();

        self::assertCount(1, $transport->getTypeNames());
        self::assertSame(ClusterIdentity::class, $transport->getTypeNames()[0]);
        self::assertCount(1, $transport->getEnvelopes());
        self::assertSame(0, $transport->getEnvelopes()[0]->getTypeId());
        self::assertSame(0, $transport->getEnvelopes()[0]->getSerializerId());
    }

    public function testToTransportMultipleMessagesSameType(): void
    {
        $msg1 = new ClusterIdentity(['identity' => 'user-1', 'kind' => 'UserGrain']);
        $msg2 = new ClusterIdentity(['identity' => 'user-2', 'kind' => 'OrderGrain']);
        $batch = new PubSubBatch([$msg1, $msg2]);

        $transport = $batch->toTransport();

        self::assertCount(1, $transport->getTypeNames());
        self::assertSame(ClusterIdentity::class, $transport->getTypeNames()[0]);
        self::assertCount(2, $transport->getEnvelopes());
        self::assertSame(0, $transport->getEnvelopes()[0]->getTypeId());
        self::assertSame(0, $transport->getEnvelopes()[1]->getTypeId());
    }

    public function testToTransportMultipleMessagesDifferentTypes(): void
    {
        $msg1 = new ClusterIdentity(['identity' => 'user-1', 'kind' => 'UserGrain']);
        $msg2 = new GrainRequest(['method_index' => 1, 'message_data' => 'test', 'message_type_name' => 'Test']);
        $batch = new PubSubBatch([$msg1, $msg2]);

        $transport = $batch->toTransport();

        self::assertCount(2, $transport->getTypeNames());
        self::assertSame(ClusterIdentity::class, $transport->getTypeNames()[0]);
        self::assertSame(GrainRequest::class, $transport->getTypeNames()[1]);
        self::assertSame(0, $transport->getEnvelopes()[0]->getTypeId());
        self::assertSame(1, $transport->getEnvelopes()[1]->getTypeId());
    }

    public function testFromTransportRestoresMessages(): void
    {
        $original = new ClusterIdentity(['identity' => 'user-1', 'kind' => 'UserGrain']);
        $batch = new PubSubBatch([$original]);
        $transport = $batch->toTransport();

        $restored = PubSubBatch::fromTransport($transport);
        $messages = $restored->getEnvelopes();

        self::assertCount(1, $messages);
        self::assertInstanceOf(ClusterIdentity::class, $messages[0]);
        self::assertSame('user-1', $messages[0]->getIdentity());
        self::assertSame('UserGrain', $messages[0]->getKind());
    }

    public function testRoundTripMultipleTypes(): void
    {
        $msg1 = new ClusterIdentity(['identity' => 'user-1', 'kind' => 'UserGrain']);
        $msg2 = new GrainResponse(['message_data' => 'data', 'message_type_name' => 'Test']);
        $msg3 = new ClusterIdentity(['identity' => 'order-2', 'kind' => 'OrderGrain']);

        $batch = new PubSubBatch([$msg1, $msg2, $msg3]);
        $transport = $batch->toTransport();
        $restored = PubSubBatch::fromTransport($transport);

        $messages = $restored->getEnvelopes();
        self::assertCount(3, $messages);

        self::assertInstanceOf(ClusterIdentity::class, $messages[0]);
        self::assertSame('user-1', $messages[0]->getIdentity());

        self::assertInstanceOf(GrainResponse::class, $messages[1]);
        self::assertSame('data', $messages[1]->getMessageData());

        self::assertInstanceOf(ClusterIdentity::class, $messages[2]);
        self::assertSame('order-2', $messages[2]->getIdentity());
    }

    public function testEmptyBatch(): void
    {
        $batch = new PubSubBatch([]);
        $transport = $batch->toTransport();

        self::assertCount(0, $transport->getTypeNames());
        self::assertCount(0, $transport->getEnvelopes());

        $restored = PubSubBatch::fromTransport($transport);
        self::assertCount(0, $restored->getEnvelopes());
    }

    public function testFromTransportWithUnknownTypeThrows(): void
    {
        $transport = new PubSubBatchTransport();
        $transport->setTypeNames(['NonExistent\\Class\\Name']);

        $envelope = new \Phluxor\Cluster\ProtoBuf\PubSubEnvelope([
            'type_id' => 0,
            'message_data' => '',
            'serializer_id' => 0,
        ]);
        $transport->setEnvelopes([$envelope]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown message type');

        PubSubBatch::fromTransport($transport);
    }

    public function testTransportSerializationRoundTrip(): void
    {
        $msg = new ClusterIdentity(['identity' => 'user-1', 'kind' => 'UserGrain']);
        $batch = new PubSubBatch([$msg]);
        $transport = $batch->toTransport();

        $bytes = $transport->serializeToString();
        $decodedTransport = new PubSubBatchTransport();
        $decodedTransport->mergeFromString($bytes);

        $restored = PubSubBatch::fromTransport($decodedTransport);
        $messages = $restored->getEnvelopes();

        self::assertCount(1, $messages);
        self::assertInstanceOf(ClusterIdentity::class, $messages[0]);
        self::assertSame('user-1', $messages[0]->getIdentity());
    }
}
