<?php

declare(strict_types=1);

namespace Phluxor\Cluster\PubSub;

use Google\Protobuf\Internal\Message;
use Phluxor\ActorSystem\AutoRespondInterface;
use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Message\MessageBatchInterface;
use Phluxor\Cluster\ProtoBuf\PubSubAutoRespondBatchTransport;
use Phluxor\Cluster\ProtoBuf\PubSubBatchTransport;
use Phluxor\Cluster\ProtoBuf\PubSubEnvelope;
use Phluxor\Cluster\ProtoBuf\PublishResponse;
use Phluxor\Cluster\ProtoBuf\PublishStatus;

final readonly class PubSubAutoRespondBatch implements MessageBatchInterface, AutoRespondInterface
{
    /**
     * @param list<Message> $envelopes
     */
    public function __construct(
        private array $envelopes,
    ) {
    }

    /**
     * @return list<Message>
     */
    public function getMessages(): array
    {
        return $this->envelopes;
    }

    public function getAutoResponse(ContextInterface $context): PublishResponse
    {
        return new PublishResponse(['status' => PublishStatus::Ok]);
    }

    public function toTransport(): PubSubAutoRespondBatchTransport
    {
        $batch = new PubSubBatch($this->envelopes);
        $batchTransport = $batch->toTransport();

        $transport = new PubSubAutoRespondBatchTransport();
        /** @var array<string> $typeNames */
        $typeNames = iterator_to_array($batchTransport->getTypeNames());
        /** @var array<PubSubEnvelope> $envelopes */
        $envelopes = iterator_to_array($batchTransport->getEnvelopes());
        $transport->setTypeNames($typeNames);
        $transport->setEnvelopes($envelopes);

        return $transport;
    }

    public static function fromTransport(PubSubAutoRespondBatchTransport $transport): self
    {
        $batchTransport = new PubSubBatchTransport();
        /** @var array<string> $typeNames */
        $typeNames = iterator_to_array($transport->getTypeNames());
        /** @var array<PubSubEnvelope> $envelopes */
        $envelopes = iterator_to_array($transport->getEnvelopes());
        $batchTransport->setTypeNames($typeNames);
        $batchTransport->setEnvelopes($envelopes);

        $batch = PubSubBatch::fromTransport($batchTransport);

        return new self($batch->getEnvelopes());
    }
}
