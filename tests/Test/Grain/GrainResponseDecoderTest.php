<?php

declare(strict_types=1);

namespace Test\Grain;

use PHPUnit\Framework\TestCase;
use Phluxor\Cluster\Exception\GrainResponseDecodeException;
use Phluxor\Cluster\Grain\GrainResponseDecoder;
use Phluxor\Cluster\ProtoBuf\GrainResponse;
use Phluxor\Cluster\ProtoBuf\MemberHeartbeat;

final class GrainResponseDecoderTest extends TestCase
{
    public function testDecodeReturnsTypedMessage(): void
    {
        $heartbeat = new MemberHeartbeat();

        $response = new GrainResponse();
        $response->setMessageData($heartbeat->serializeToString());
        $response->setMessageTypeName(MemberHeartbeat::class);

        $decoder = new GrainResponseDecoder();
        $decoded = $decoder->decode($response);

        self::assertInstanceOf(MemberHeartbeat::class, $decoded);
    }

    public function testDecodeThrowsWhenTypeNameIsEmpty(): void
    {
        $response = new GrainResponse();
        $response->setMessageData('dummy');
        $response->setMessageTypeName('');

        $decoder = new GrainResponseDecoder();

        $this->expectException(GrainResponseDecodeException::class);
        $this->expectExceptionMessage('message type name is empty');

        $decoder->decode($response);
    }

    public function testDecodeThrowsWhenClassNotFound(): void
    {
        $response = new GrainResponse();
        $response->setMessageData('dummy');
        $response->setMessageTypeName('NonExistent\\Class');

        $decoder = new GrainResponseDecoder();

        $this->expectException(GrainResponseDecodeException::class);
        $this->expectExceptionMessage('message class not found');

        $decoder->decode($response);
    }

    public function testDecodeThrowsWhenClassIsNotProtobufMessage(): void
    {
        $response = new GrainResponse();
        $response->setMessageData('dummy');
        $response->setMessageTypeName(\stdClass::class);

        $decoder = new GrainResponseDecoder();

        $this->expectException(GrainResponseDecodeException::class);
        $this->expectExceptionMessage('message class must extend');

        $decoder->decode($response);
    }
}
