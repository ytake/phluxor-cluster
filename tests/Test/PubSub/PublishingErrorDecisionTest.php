<?php

declare(strict_types=1);

namespace Test\PubSub;

use Phluxor\Cluster\PubSub\PublishingErrorAction;
use Phluxor\Cluster\PubSub\PublishingErrorDecision;
use PHPUnit\Framework\TestCase;

final class PublishingErrorDecisionTest extends TestCase
{
    public function testFailBatchAndStop(): void
    {
        $decision = PublishingErrorDecision::failBatchAndStop();
        self::assertSame(PublishingErrorAction::FailBatchAndStop, $decision->action());
        self::assertSame(0, $decision->delaySeconds());
    }

    public function testFailBatchAndContinue(): void
    {
        $decision = PublishingErrorDecision::failBatchAndContinue();
        self::assertSame(PublishingErrorAction::FailBatchAndContinue, $decision->action());
        self::assertSame(0, $decision->delaySeconds());
    }

    public function testRetryBatchImmediately(): void
    {
        $decision = PublishingErrorDecision::retryBatchImmediately();
        self::assertSame(PublishingErrorAction::RetryBatch, $decision->action());
        self::assertSame(0, $decision->delaySeconds());
    }

    public function testRetryBatchAfter(): void
    {
        $decision = PublishingErrorDecision::retryBatchAfter(3);
        self::assertSame(PublishingErrorAction::RetryBatch, $decision->action());
        self::assertSame(3, $decision->delaySeconds());
    }
}
