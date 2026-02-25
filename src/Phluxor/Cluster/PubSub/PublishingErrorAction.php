<?php

declare(strict_types=1);

namespace Phluxor\Cluster\PubSub;

enum PublishingErrorAction
{
    case FailBatchAndStop;
    case FailBatchAndContinue;
    case RetryBatch;
}
