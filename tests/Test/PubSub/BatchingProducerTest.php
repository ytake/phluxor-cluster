<?php

declare(strict_types=1);

namespace Test\PubSub;

use Google\Protobuf\Internal\Message;
use Phluxor\Cluster\Exception\InvalidProducerOperationException;
use Phluxor\Cluster\Exception\ProducerQueueFullException;
use Phluxor\Cluster\PubSub\BatchingProducer;
use Phluxor\Cluster\PubSub\BatchingProducerConfig;
use Phluxor\Cluster\PubSub\ProduceProcessInfo;
use Phluxor\Cluster\PubSub\PublishingErrorAction;
use Phluxor\Cluster\PubSub\PublishingErrorDecision;
use Phluxor\Cluster\PubSub\PublisherInterface;
use Phluxor\Cluster\PubSub\PubSubBatch;
use Phluxor\Cluster\ProtoBuf\PublishResponse;
use Phluxor\Cluster\ProtoBuf\PublishStatus;
use Phluxor\Cluster\ProtoBuf\SubscribeRequest;
use PHPUnit\Framework\TestCase;

final class BatchingProducerTest extends TestCase
{
    private function createMessage(): Message
    {
        return new SubscribeRequest();
    }

    private function createSuccessResponse(): PublishResponse
    {
        return new PublishResponse(['status' => PublishStatus::Ok]);
    }

    public function testProduceReturnsProcessInfo(): void
    {
        \Co\run(function () {
            $publisher = $this->createMock(PublisherInterface::class);
            $publisher->method('publishBatch')->willReturn($this->createSuccessResponse());

            $config = new BatchingProducerConfig(batchSize: 10);
            $producer = new BatchingProducer($publisher, 'test-topic', $config);

            $info = $producer->produce($this->createMessage());
            self::assertInstanceOf(ProduceProcessInfo::class, $info);

            $producer->dispose();
        });
    }

    public function testBatchIsPublishedWhenBatchSizeReached(): void
    {
        \Co\run(function () {
            $publishCount = 0;
            $publisher = $this->createMock(PublisherInterface::class);
            $publisher->method('publishBatch')
                ->willReturnCallback(function () use (&$publishCount) {
                    $publishCount++;
                    return $this->createSuccessResponse();
                });

            $config = new BatchingProducerConfig(batchSize: 3);
            $producer = new BatchingProducer($publisher, 'test-topic', $config);

            for ($i = 0; $i < 3; $i++) {
                $producer->produce($this->createMessage());
            }

            // publishLoop にスケジュールを渡すため少し待機
            \Swoole\Coroutine::sleep(0.1);

            self::assertGreaterThanOrEqual(1, $publishCount);

            $producer->dispose();
        });
    }

    public function testProcessInfoCompletedOnSuccessfulPublish(): void
    {
        \Co\run(function () {
            $publisher = $this->createMock(PublisherInterface::class);
            $publisher->method('publishBatch')->willReturn($this->createSuccessResponse());

            $config = new BatchingProducerConfig(batchSize: 1);
            $producer = new BatchingProducer($publisher, 'test-topic', $config);

            $info = $producer->produce($this->createMessage());

            \Swoole\Coroutine::sleep(0.1);

            self::assertTrue($info->isFinished());
            self::assertNull($info->error());

            $producer->dispose();
        });
    }

    public function testErrorHandlerFailBatchAndStop(): void
    {
        \Co\run(function () {
            $publisher = $this->createMock(PublisherInterface::class);
            $publisher->method('publishBatch')
                ->willThrowException(new \RuntimeException('publish error'));

            $config = new BatchingProducerConfig(
                batchSize: 1,
                onPublishingError: static function (
                    int $retries,
                    \Throwable $e,
                    PubSubBatch $batch
                ): PublishingErrorDecision {
                    return PublishingErrorDecision::failBatchAndStop();
                }
            );

            $producer = new BatchingProducer($publisher, 'test-topic', $config);

            $info = $producer->produce($this->createMessage());

            \Swoole\Coroutine::sleep(0.1);

            self::assertTrue($info->isFinished());
            self::assertNotNull($info->error());

            $producer->dispose();
        });
    }

    public function testErrorHandlerFailBatchAndContinue(): void
    {
        \Co\run(function () {
            $callCount = 0;
            $publisher = $this->createMock(PublisherInterface::class);
            $publisher->method('publishBatch')
                ->willReturnCallback(function () use (&$callCount) {
                    $callCount++;
                    if ($callCount === 1) {
                        throw new \RuntimeException('first batch fails');
                    }
                    return $this->createSuccessResponse();
                });

            $config = new BatchingProducerConfig(
                batchSize: 1,
                onPublishingError: static function (
                    int $retries,
                    \Throwable $e,
                    PubSubBatch $batch
                ): PublishingErrorDecision {
                    return PublishingErrorDecision::failBatchAndContinue();
                }
            );

            $producer = new BatchingProducer($publisher, 'test-topic', $config);

            $info1 = $producer->produce($this->createMessage());

            \Swoole\Coroutine::sleep(0.1);

            self::assertTrue($info1->isFinished());
            self::assertNotNull($info1->error());

            // Producer should still accept new messages
            $info2 = $producer->produce($this->createMessage());

            \Swoole\Coroutine::sleep(0.1);

            self::assertTrue($info2->isFinished());
            self::assertNull($info2->error());

            $producer->dispose();
        });
    }

    public function testErrorHandlerRetryBatch(): void
    {
        \Co\run(function () {
            $callCount = 0;
            $publisher = $this->createMock(PublisherInterface::class);
            $publisher->method('publishBatch')
                ->willReturnCallback(function () use (&$callCount) {
                    $callCount++;
                    if ($callCount <= 2) {
                        throw new \RuntimeException('transient error');
                    }
                    return $this->createSuccessResponse();
                });

            $config = new BatchingProducerConfig(
                batchSize: 1,
                onPublishingError: static function (
                    int $retries,
                    \Throwable $e,
                    PubSubBatch $batch
                ): PublishingErrorDecision {
                    if ($retries < 3) {
                        return PublishingErrorDecision::retryBatchImmediately();
                    }
                    return PublishingErrorDecision::failBatchAndStop();
                }
            );

            $producer = new BatchingProducer($publisher, 'test-topic', $config);

            $info = $producer->produce($this->createMessage());

            \Swoole\Coroutine::sleep(0.2);

            self::assertTrue($info->isFinished());
            self::assertNull($info->error());
            self::assertSame(3, $callCount);

            $producer->dispose();
        });
    }

    public function testProduceAfterDisposeThrowsException(): void
    {
        \Co\run(function () {
            $publisher = $this->createMock(PublisherInterface::class);
            $config = new BatchingProducerConfig(batchSize: 10);
            $producer = new BatchingProducer($publisher, 'test-topic', $config);

            $producer->dispose();

            $thrown = false;
            try {
                $producer->produce($this->createMessage());
            } catch (InvalidProducerOperationException) {
                $thrown = true;
            }
            self::assertTrue($thrown, 'Expected InvalidProducerOperationException');
        });
    }

    public function testBoundedQueueRejectsWhenFull(): void
    {
        \Co\run(function () {
            $latch = new \Swoole\Coroutine\Channel(1);
            $publishCount = 0;
            $publisher = $this->createMock(PublisherInterface::class);
            $publisher->method('publishBatch')->willReturnCallback(function () use ($latch, &$publishCount) {
                $publishCount++;
                if ($publishCount === 1) {
                    // 先頭バッチのみラッチでブロックしてキューを満杯にする
                    $latch->pop(1.0);
                }
                return $this->createSuccessResponse();
            });

            $config = new BatchingProducerConfig(
                batchSize: 1,
                maxQueueSize: 2,
            );
            $producer = new BatchingProducer($publisher, 'test-topic', $config);

            // msg1: publishLoop に消費され publishBatch のラッチでブロック
            $producer->produce($this->createMessage());
            \Swoole\Coroutine::sleep(0.05);

            // msg2, msg3: チャネルに蓄積（capacity=2）
            $producer->produce($this->createMessage());
            $producer->produce($this->createMessage());

            // msg4: チャネルが満杯で例外
            $thrown = false;
            try {
                $producer->produce($this->createMessage());
            } catch (ProducerQueueFullException) {
                $thrown = true;
            }
            self::assertTrue($thrown, 'Expected ProducerQueueFullException');

            $latch->push(true);
            $producer->dispose();
        });
    }

    public function testDisposeCompletesGracefully(): void
    {
        \Co\run(function () {
            $publisher = $this->createMock(PublisherInterface::class);
            $publisher->method('publishBatch')->willReturn($this->createSuccessResponse());

            $config = new BatchingProducerConfig(batchSize: 100);
            $producer = new BatchingProducer($publisher, 'test-topic', $config);

            $info = $producer->produce($this->createMessage());

            $producer->dispose();

            // Pending messages should be cancelled on dispose
            self::assertTrue($info->isFinished());
        });
    }

    public function testBoundedQueueScenarioIsStableAcrossMultipleRuns(): void
    {
        \Co\run(function () {
            for ($i = 0; $i < 20; $i++) {
                $latch = new \Swoole\Coroutine\Channel(1);
                $publishCount = 0;
                $publisher = $this->createMock(PublisherInterface::class);
                $publisher->method('publishBatch')->willReturnCallback(
                    function () use ($latch, &$publishCount) {
                        $publishCount++;
                        if ($publishCount === 1) {
                            $latch->pop(1.0);
                        }
                        return $this->createSuccessResponse();
                    }
                );

                $config = new BatchingProducerConfig(
                    batchSize: 1,
                    maxQueueSize: 2,
                );
                $producer = new BatchingProducer($publisher, 'test-topic', $config);

                $producer->produce($this->createMessage());
                \Swoole\Coroutine::sleep(0.01);
                $producer->produce($this->createMessage());
                $producer->produce($this->createMessage());

                $thrown = false;
                try {
                    $producer->produce($this->createMessage());
                } catch (ProducerQueueFullException) {
                    $thrown = true;
                }
                self::assertTrue($thrown, "Expected ProducerQueueFullException at iteration {$i}");

                $latch->push(true);
                $producer->dispose();
            }
        });
    }
}
