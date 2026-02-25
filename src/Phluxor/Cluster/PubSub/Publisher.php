<?php

declare(strict_types=1);

namespace Phluxor\Cluster\PubSub;

use Google\Protobuf\Internal\Message;
use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\GrainCallConfig;
use Phluxor\Cluster\ProtoBuf\Acknowledge;
use Phluxor\Cluster\ProtoBuf\PubSubInitialize;
use Phluxor\Cluster\ProtoBuf\PublishResponse;

final readonly class Publisher implements PublisherInterface
{
    private const string TOPIC_ACTOR_KIND = 'prototopic';

    public function __construct(
        private Cluster $cluster,
    ) {
    }

    public function initialize(string $topic, int $idleTimeoutMs, ?GrainCallConfig $config = null): ?Acknowledge
    {
        $init = new PubSubInitialize(['idle_timeout_ms' => $idleTimeoutMs]);
        $result = $this->cluster->request($topic, self::TOPIC_ACTOR_KIND, $init, $config);
        if ($result instanceof Acknowledge) {
            return $result;
        }
        return null;
    }

    public function publish(
        string $topic,
        Message $message,
        ?GrainCallConfig $config = null,
    ): ?PublishResponse {
        return $this->publishBatch($topic, new PubSubBatch([$message]), $config);
    }

    public function publishBatch(
        string $topic,
        PubSubBatch $batch,
        ?GrainCallConfig $config = null,
    ): ?PublishResponse {
        $transport = $batch->toTransport();
        $result = $this->cluster->request($topic, self::TOPIC_ACTOR_KIND, $transport, $config);
        if ($result instanceof PublishResponse) {
            return $result;
        }
        return null;
    }
}
