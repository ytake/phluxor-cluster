<?php

declare(strict_types=1);

namespace Test\Grain;

use PHPUnit\Framework\TestCase;
use Phluxor\Cluster\Exception\GrainCallException;
use Phluxor\Cluster\Grain\GrainClient;
use Phluxor\Cluster\ProtoBuf\GrainErrorResponse;
use Phluxor\Cluster\ProtoBuf\GrainResponse;

final class GrainClientTest extends TestCase
{
    public function testGrainClientIsAbstract(): void
    {
        $reflection = new \ReflectionClass(GrainClient::class);
        self::assertTrue($reflection->isAbstract());
    }

    public function testGrainCallExceptionContainsErrorInfo(): void
    {
        $exception = new GrainCallException('grain error', 42);

        self::assertSame('grain error', $exception->getMessage());
        self::assertSame(42, $exception->getCode());
    }

    public function testGrainResponseAccessors(): void
    {
        $response = new GrainResponse();
        $response->setMessageData('test-data');
        $response->setMessageTypeName('TestType');

        self::assertSame('test-data', $response->getMessageData());
        self::assertSame('TestType', $response->getMessageTypeName());
    }

    public function testGrainErrorResponseAccessors(): void
    {
        $response = new GrainErrorResponse();
        $response->setError('something failed');
        $response->setCode(500);

        self::assertSame('something failed', $response->getError());
        self::assertSame(500, $response->getCode());
    }
}
