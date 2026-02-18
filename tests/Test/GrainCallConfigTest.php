<?php

declare(strict_types=1);

namespace Test;

use Phluxor\Cluster\GrainCallConfig;
use PHPUnit\Framework\TestCase;

final class GrainCallConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new GrainCallConfig();
        self::assertSame(3, $config->retryCount());
        self::assertSame(5, $config->requestTimeoutSeconds());
    }

    public function testCustomValues(): void
    {
        $config = new GrainCallConfig(retryCount: 5, requestTimeoutSeconds: 10);
        self::assertSame(5, $config->retryCount());
        self::assertSame(10, $config->requestTimeoutSeconds());
    }
}
