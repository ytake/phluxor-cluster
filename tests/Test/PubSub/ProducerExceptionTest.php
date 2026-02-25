<?php

declare(strict_types=1);

namespace Test\PubSub;

use Phluxor\Cluster\Exception\InvalidProducerOperationException;
use Phluxor\Cluster\Exception\ProducerQueueFullException;
use PHPUnit\Framework\TestCase;

final class ProducerExceptionTest extends TestCase
{
    public function testProducerQueueFullException(): void
    {
        $e = new ProducerQueueFullException('my-topic');
        self::assertSame('Producer for topic my-topic has full queue', $e->getMessage());
        self::assertSame('my-topic', $e->topic());
    }

    public function testInvalidProducerOperationException(): void
    {
        $e = new InvalidProducerOperationException('my-topic');
        self::assertSame('Producer for topic my-topic is stopped', $e->getMessage());
        self::assertSame('my-topic', $e->topic());
    }
}
