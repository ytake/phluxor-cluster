<?php

declare(strict_types=1);

namespace Phluxor\Cluster\PubSub;

use Phluxor\Cluster\ProtoBuf\DeliverBatchRequestTransport;
use Phluxor\Cluster\ProtoBuf\Subscribers;

final readonly class DeliverBatchRequest
{
    public function __construct(
        private Subscribers $subscribers,
        private PubSubBatch $pubSubBatch,
        private string $topic,
    ) {
    }

    public function getSubscribers(): Subscribers
    {
        return $this->subscribers;
    }

    public function getPubSubBatch(): PubSubBatch
    {
        return $this->pubSubBatch;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function toTransport(): DeliverBatchRequestTransport
    {
        $transport = new DeliverBatchRequestTransport();
        $transport->setSubscribers($this->subscribers);
        $transport->setBatch($this->pubSubBatch->toTransport());
        $transport->setTopic($this->topic);
        return $transport;
    }

    public static function fromTransport(DeliverBatchRequestTransport $transport): self
    {
        $subscribers = $transport->getSubscribers();
        $batch = $transport->getBatch();
        if ($subscribers === null || $batch === null) {
            throw new \InvalidArgumentException('DeliverBatchRequestTransport is missing required fields');
        }
        return new self(
            $subscribers,
            PubSubBatch::fromTransport($batch),
            $transport->getTopic(),
        );
    }
}
