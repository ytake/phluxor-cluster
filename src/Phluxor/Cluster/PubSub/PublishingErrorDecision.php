<?php

declare(strict_types=1);

namespace Phluxor\Cluster\PubSub;

final readonly class PublishingErrorDecision
{
    private function __construct(
        private PublishingErrorAction $action,
        private int $delaySeconds = 0
    ) {
    }

    public static function failBatchAndStop(): self
    {
        return new self(PublishingErrorAction::FailBatchAndStop);
    }

    public static function failBatchAndContinue(): self
    {
        return new self(PublishingErrorAction::FailBatchAndContinue);
    }

    public static function retryBatchImmediately(): self
    {
        return new self(PublishingErrorAction::RetryBatch);
    }

    public static function retryBatchAfter(int $delaySeconds): self
    {
        return new self(PublishingErrorAction::RetryBatch, $delaySeconds);
    }

    public function action(): PublishingErrorAction
    {
        return $this->action;
    }

    public function delaySeconds(): int
    {
        return $this->delaySeconds;
    }
}
