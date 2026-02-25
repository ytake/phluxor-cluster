<?php

declare(strict_types=1);

namespace Phluxor\Cluster\PubSub;

use Google\Protobuf\Internal\Message;
use Phluxor\Cluster\GrainCallConfig;
use Phluxor\Cluster\ProtoBuf\Acknowledge;
use Phluxor\Cluster\ProtoBuf\PublishResponse;

interface PublisherInterface
{
    public function initialize(string $topic, int $idleTimeoutMs, ?GrainCallConfig $config = null): ?Acknowledge;

    public function publish(
        string $topic,
        Message $message,
        ?GrainCallConfig $config = null,
    ): ?PublishResponse;

    public function publishBatch(
        string $topic,
        PubSubBatch $batch,
        ?GrainCallConfig $config = null,
    ): ?PublishResponse;
}
