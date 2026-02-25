<?php

declare(strict_types=1);

namespace Test\PubSub;

use Phluxor\Cluster\PubSub\BatchingProducerConfig;
use Phluxor\Cluster\PubSub\PublishingErrorAction;
use Phluxor\Cluster\PubSub\PublishingErrorDecision;
use Phluxor\Cluster\PubSub\PubSubBatch;
use PHPUnit\Framework\TestCase;

final class BatchingProducerConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new BatchingProducerConfig();
        self::assertSame(2000, $config->batchSize());
        self::assertSame(0, $config->maxQueueSize());
        self::assertSame(5, $config->publishTimeoutSeconds());
        self::assertSame(0, $config->publisherIdleTimeoutSeconds());
    }

    public function testCustomValues(): void
    {
        $config = new BatchingProducerConfig(
            batchSize: 500,
            maxQueueSize: 1000,
            publishTimeoutSeconds: 10,
            publisherIdleTimeoutSeconds: 30
        );
        self::assertSame(500, $config->batchSize());
        self::assertSame(1000, $config->maxQueueSize());
        self::assertSame(10, $config->publishTimeoutSeconds());
        self::assertSame(30, $config->publisherIdleTimeoutSeconds());
    }

    public function testDefaultErrorHandler(): void
    {
        $config = new BatchingProducerConfig();
        $handler = $config->onPublishingError();
        $batch = new PubSubBatch([]);
        $decision = $handler(0, new \RuntimeException('test'), $batch);
        self::assertSame(PublishingErrorAction::FailBatchAndStop, $decision->action());
    }

    public function testCustomErrorHandler(): void
    {
        $customHandler = static function (int $retries, \Throwable $e, PubSubBatch $batch): PublishingErrorDecision {
            if ($retries < 3) {
                return PublishingErrorDecision::retryBatchImmediately();
            }
            return PublishingErrorDecision::failBatchAndContinue();
        };

        $config = new BatchingProducerConfig(onPublishingError: $customHandler);
        $handler = $config->onPublishingError();
        $batch = new PubSubBatch([]);

        $first = $handler(0, new \RuntimeException('test'), $batch);
        self::assertSame(PublishingErrorAction::RetryBatch, $first->action());

        $last = $handler(3, new \RuntimeException('test'), $batch);
        self::assertSame(PublishingErrorAction::FailBatchAndContinue, $last->action());
    }
}
