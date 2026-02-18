<?php

declare(strict_types=1);

namespace Test\Grain;

use PHPUnit\Framework\TestCase;
use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Message\ActorInterface;
use Phluxor\ActorSystem\Message\Started;
use Phluxor\Cluster\Grain\GrainBase;
use Phluxor\Cluster\ProtoBuf\GrainErrorResponse;
use Phluxor\Cluster\ProtoBuf\GrainRequest;
use Phluxor\Cluster\ProtoBuf\GrainResponse;
use Phluxor\Cluster\ProtoBuf\MemberHeartbeat;

final class GrainBaseTest extends TestCase
{
    public function testImplementsActorInterface(): void
    {
        $grain = new TestGrain();
        self::assertInstanceOf(ActorInterface::class, $grain);
    }

    public function testGrainRequestDispatchesToOnGrainRequest(): void
    {
        $grain = new TestGrain();
        $context = $this->createMock(ContextInterface::class);

        $request = new GrainRequest();
        $request->setMethodIndex(1);
        $request->setMessageData('test-data');
        $request->setMessageTypeName('TestType');

        $context->method('message')->willReturn($request);

        $grain->receive($context);

        self::assertTrue($grain->grainRequestReceived);
        self::assertSame(1, $grain->lastMethodIndex);
    }

    public function testNonGrainRequestDispatchesToOnMessage(): void
    {
        $grain = new TestGrain();
        $context = $this->createMock(ContextInterface::class);

        $started = new Started();
        $context->method('message')->willReturn($started);

        $grain->receive($context);

        self::assertFalse($grain->grainRequestReceived);
        self::assertTrue($grain->messageReceived);
    }

    public function testRespondGrainSendsGrainResponse(): void
    {
        $grain = new TestGrain();
        $context = $this->createMock(ContextInterface::class);

        $heartbeat = new MemberHeartbeat();

        $context->expects(self::once())
            ->method('respond')
            ->with(self::callback(function (mixed $response): bool {
                if (!$response instanceof GrainResponse) {
                    return false;
                }
                self::assertIsString($response->getMessageData());
                self::assertSame(MemberHeartbeat::class, $response->getMessageTypeName());
                return true;
            }));

        $grain->callRespondGrain($context, $heartbeat);
    }

    public function testRespondErrorSendsGrainErrorResponse(): void
    {
        $grain = new TestGrain();
        $context = $this->createMock(ContextInterface::class);

        $context->expects(self::once())
            ->method('respond')
            ->with(self::callback(function (mixed $response): bool {
                if (!$response instanceof GrainErrorResponse) {
                    return false;
                }
                self::assertSame('something went wrong', $response->getError());
                self::assertSame(500, $response->getCode());
                return true;
            }));

        $grain->callRespondError($context, 'something went wrong', 500);
    }
}

/**
 * @internal
 */
final class TestGrain extends GrainBase
{
    public bool $grainRequestReceived = false;
    public bool $messageReceived = false;
    public int $lastMethodIndex = -1;

    protected function onGrainRequest(GrainRequest $request, ContextInterface $context): void
    {
        $this->grainRequestReceived = true;
        $this->lastMethodIndex = $request->getMethodIndex();
    }

    protected function onMessage(mixed $message, ContextInterface $context): void
    {
        $this->messageReceived = true;
    }

    public function callRespondGrain(ContextInterface $context, \Google\Protobuf\Internal\Message $response): void
    {
        $this->respondGrain($context, $response);
    }

    public function callRespondError(ContextInterface $context, string $error, int $code): void
    {
        $this->respondError($context, $error, $code);
    }
}
