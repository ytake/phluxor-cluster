<?php

declare(strict_types=1);

namespace Test\Grain;

use Google\Protobuf\Internal\Message;
use PHPUnit\Framework\TestCase;
use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\Exception\GrainCallException;
use Phluxor\Cluster\Grain\GrainBase;
use Phluxor\Cluster\Grain\GrainClient;
use Phluxor\Cluster\Grain\GrainResponseDecoder;
use Phluxor\Cluster\ProtoBuf\GrainErrorResponse;
use Phluxor\Cluster\ProtoBuf\GrainRequest;
use Phluxor\Cluster\ProtoBuf\GrainResponse;
use Phluxor\Cluster\ProtoBuf\MemberHeartbeat;

final class GrainIntegrationTest extends TestCase
{
    public function testRoundTripSerializationThroughGrainLayer(): void
    {
        // 1. GrainBase がGrainRequest を受けて GrainResponse を返す
        $grain = new IntegrationTestGrain();
        $grainContext = $this->createMock(ContextInterface::class);

        $request = new GrainRequest();
        $request->setMethodIndex(1);
        $originalHeartbeat = new MemberHeartbeat();
        $request->setMessageData($originalHeartbeat->serializeToString());
        $request->setMessageTypeName(MemberHeartbeat::class);

        $grainContext->method('message')->willReturn($request);

        $capturedResponse = null;
        $grainContext->expects(self::once())
            ->method('respond')
            ->with(self::callback(function (mixed $response) use (&$capturedResponse): bool {
                if (!$response instanceof GrainResponse) {
                    return false;
                }
                $capturedResponse = $response;
                return true;
            }));

        $grain->receive($grainContext);

        // 2. GrainResponseDecoder でデシリアライズ
        self::assertInstanceOf(GrainResponse::class, $capturedResponse);
        $decoder = new GrainResponseDecoder();
        $decoded = $decoder->decode($capturedResponse);

        self::assertInstanceOf(MemberHeartbeat::class, $decoded);
    }

    public function testGrainClientBuildsRequestAndHandlesResponse(): void
    {
        $cluster = $this->createMock(Cluster::class);

        // GrainBase が返すのと同じ GrainResponse を模擬
        $heartbeat = new MemberHeartbeat();
        $grainResponse = new GrainResponse();
        $grainResponse->setMessageData($heartbeat->serializeToString());
        $grainResponse->setMessageTypeName(MemberHeartbeat::class);

        $cluster->expects(self::once())
            ->method('request')
            ->with(
                'user-1',
                'TestGrain',
                self::callback(function (mixed $msg): bool {
                    if (!$msg instanceof GrainRequest) {
                        return false;
                    }
                    self::assertSame(1, $msg->getMethodIndex());
                    self::assertSame(MemberHeartbeat::class, $msg->getMessageTypeName());
                    return true;
                }),
                null
            )
            ->willReturn($grainResponse);

        $client = new IntegrationTestGrainClient($cluster, 'user-1', 'TestGrain');
        $response = $client->callHeartbeat(new MemberHeartbeat());

        self::assertInstanceOf(GrainResponse::class, $response);

        $decoder = new GrainResponseDecoder();
        $decoded = $decoder->decode($response);
        self::assertInstanceOf(MemberHeartbeat::class, $decoded);
    }

    public function testGrainClientThrowsOnGrainErrorResponse(): void
    {
        $cluster = $this->createMock(Cluster::class);

        $errorResponse = new GrainErrorResponse();
        $errorResponse->setError('grain failed');
        $errorResponse->setCode(500);

        $cluster->method('request')->willReturn($errorResponse);

        $client = new IntegrationTestGrainClient($cluster, 'user-1', 'TestGrain');

        $this->expectException(GrainCallException::class);
        $this->expectExceptionMessage('grain failed');
        $this->expectExceptionCode(500);

        $client->callHeartbeat(new MemberHeartbeat());
    }

    public function testGrainClientReturnsNullOnUnexpectedResponse(): void
    {
        $cluster = $this->createMock(Cluster::class);
        $cluster->method('request')->willReturn(null);

        $client = new IntegrationTestGrainClient($cluster, 'user-1', 'TestGrain');
        $response = $client->callHeartbeat(new MemberHeartbeat());

        self::assertNull($response);
    }
}

/**
 * @internal
 */
final class IntegrationTestGrainClient extends GrainClient
{
    public function callHeartbeat(Message $request): ?GrainResponse
    {
        return $this->callGrain(1, $request);
    }
}

/**
 * @internal
 */
final class IntegrationTestGrain extends GrainBase
{
    protected function onGrainRequest(GrainRequest $request, ContextInterface $context): void
    {
        $heartbeat = new MemberHeartbeat();
        $this->respondGrain($context, $heartbeat);
    }
}
