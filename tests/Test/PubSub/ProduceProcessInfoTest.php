<?php

declare(strict_types=1);

namespace Test\PubSub;

use Phluxor\Cluster\PubSub\ProduceProcessInfo;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Channel;

final class ProduceProcessInfoTest extends TestCase
{
    public function testInitialState(): void
    {
        $info = new ProduceProcessInfo();
        self::assertFalse($info->isFinished());
        self::assertFalse($info->isCancelled());
        self::assertNull($info->error());
    }

    public function testComplete(): void
    {
        $info = new ProduceProcessInfo();
        $info->complete();
        self::assertTrue($info->isFinished());
        self::assertNull($info->error());
    }

    public function testFail(): void
    {
        $info = new ProduceProcessInfo();
        $error = new \RuntimeException('publish failed');
        $info->fail($error);
        self::assertTrue($info->isFinished());
        self::assertSame($error, $info->error());
    }

    public function testCancel(): void
    {
        $info = new ProduceProcessInfo();
        $info->cancel();
        self::assertTrue($info->isCancelled());
        self::assertTrue($info->isFinished());
    }

    public function testCompleteIsIdempotent(): void
    {
        $info = new ProduceProcessInfo();
        $info->complete();
        $info->complete();
        self::assertTrue($info->isFinished());
    }
}
