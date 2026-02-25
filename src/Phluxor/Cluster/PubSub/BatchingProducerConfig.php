<?php

declare(strict_types=1);

namespace Phluxor\Cluster\PubSub;

use Closure;

final readonly class BatchingProducerConfig
{
    private const int DEFAULT_BATCH_SIZE = 2000;
    private const int DEFAULT_PUBLISH_TIMEOUT_SECONDS = 5;

    /** @var Closure(int, \Throwable, PubSubBatch): PublishingErrorDecision */
    private Closure $onPublishingError;

    /**
     * @param int $batchSize バッチ最大サイズ
     * @param int $maxQueueSize キュー最大サイズ（0=無制限）
     * @param int $publishTimeoutSeconds パブリッシュのタイムアウト秒数
     * @param int $publisherIdleTimeoutSeconds アイドルタイムアウト秒数（0=無効）
     * @param null|Closure(int, \Throwable, PubSubBatch): PublishingErrorDecision $onPublishingError
     */
    public function __construct(
        private int $batchSize = self::DEFAULT_BATCH_SIZE,
        private int $maxQueueSize = 0,
        private int $publishTimeoutSeconds = self::DEFAULT_PUBLISH_TIMEOUT_SECONDS,
        private int $publisherIdleTimeoutSeconds = 0,
        ?Closure $onPublishingError = null,
    ) {
        $this->onPublishingError = $onPublishingError ?? static function (
            int $retries,
            \Throwable $e,
            PubSubBatch $batch
        ): PublishingErrorDecision {
            return PublishingErrorDecision::failBatchAndStop();
        };
    }

    public function batchSize(): int
    {
        return $this->batchSize;
    }

    public function maxQueueSize(): int
    {
        return $this->maxQueueSize;
    }

    public function publishTimeoutSeconds(): int
    {
        return $this->publishTimeoutSeconds;
    }

    public function publisherIdleTimeoutSeconds(): int
    {
        return $this->publisherIdleTimeoutSeconds;
    }

    /**
     * @return Closure(int, \Throwable, PubSubBatch): PublishingErrorDecision
     */
    public function onPublishingError(): Closure
    {
        return $this->onPublishingError;
    }
}
