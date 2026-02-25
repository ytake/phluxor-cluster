<?php

declare(strict_types=1);

namespace Test\Grain;

use PHPUnit\Framework\TestCase;
use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Message\ActorInterface;
use Phluxor\ActorSystem\Message\Started;
use Phluxor\ActorSystem\Message\Stopping;
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

    public function testStartedMessageDispatchesToOnStarted(): void
    {
        $grain = new TestGrain();
        $context = $this->createMock(ContextInterface::class);

        $started = new Started();
        $context->method('message')->willReturn($started);

        $grain->receive($context);

        // Started は onStarted() にディスパッチされ、onMessage() には流れない
        self::assertFalse($grain->grainRequestReceived);
        self::assertFalse($grain->messageReceived);
        self::assertTrue($grain->startedReceived);
    }

    public function testStoppingMessageDispatchesToOnStopping(): void
    {
        $grain = new TestGrain();
        $context = $this->createMock(ContextInterface::class);

        $stopping = new Stopping();
        $context->method('message')->willReturn($stopping);

        $grain->receive($context);

        // Stopping は onStopping() にディスパッチされ、onMessage() には流れない
        self::assertFalse($grain->grainRequestReceived);
        self::assertFalse($grain->messageReceived);
        self::assertTrue($grain->stoppingReceived);
    }

    public function testNonGrainRequestDispatchesToOnMessage(): void
    {
        $grain = new TestGrain();
        $context = $this->createMock(ContextInterface::class);

        // 上記以外のメッセージ（例: カスタムメッセージ）は onMessage() に流れる
        $heartbeat = new MemberHeartbeat();
        $context->method('message')->willReturn($heartbeat);

        $grain->receive($context);

        self::assertFalse($grain->grainRequestReceived);
        self::assertTrue($grain->messageReceived);
        self::assertFalse($grain->startedReceived);
        self::assertFalse($grain->stoppingReceived);
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
    public bool $startedReceived = false;
    public bool $stoppingReceived = false;
    public int $lastMethodIndex = -1;

    protected function onGrainRequest(GrainRequest $request, ContextInterface $context): void
    {
        $this->grainRequestReceived = true;
        $this->lastMethodIndex = $request->getMethodIndex();
    }

    protected function onMessage(object $message, ContextInterface $context): void
    {
        $this->messageReceived = true;
    }

    protected function onStarted(ContextInterface $context): void
    {
        $this->startedReceived = true;
    }

    protected function onStopping(ContextInterface $context): void
    {
        $this->stoppingReceived = true;
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
