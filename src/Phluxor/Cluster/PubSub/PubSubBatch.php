<?php

declare(strict_types=1);

namespace Phluxor\Cluster\PubSub;

use Google\Protobuf\Internal\Message;
use Phluxor\Cluster\ProtoBuf\PubSubBatchTransport;
use Phluxor\Cluster\ProtoBuf\PubSubEnvelope;

final readonly class PubSubBatch
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
    public function getEnvelopes(): array
    {
        return $this->envelopes;
    }

    public function toTransport(): PubSubBatchTransport
    {
        /** @var list<string> $typeNames */
        $typeNames = [];
        /** @var list<PubSubEnvelope> $pubSubEnvelopes */
        $pubSubEnvelopes = [];

        foreach ($this->envelopes as $message) {
            $typeName = $message::class;
            $typeIndex = array_search($typeName, $typeNames, true);
            if ($typeIndex === false) {
                $typeNames[] = $typeName;
                $typeIndex = count($typeNames) - 1;
            }

            $pubSubEnvelopes[] = new PubSubEnvelope([
                'type_id' => (int) $typeIndex,
                'message_data' => $message->serializeToString(),
                'serializer_id' => 0,
            ]);
        }

        $transport = new PubSubBatchTransport();
        $transport->setTypeNames($typeNames);
        $transport->setEnvelopes($pubSubEnvelopes);

        return $transport;
    }

    public static function fromTransport(PubSubBatchTransport $transport): self
    {
        /** @var list<Message> $messages */
        $messages = [];
        $typeNames = $transport->getTypeNames();

        foreach ($transport->getEnvelopes() as $envelope) {
            /** @var PubSubEnvelope $envelope */
            /** @var string $typeName */
            $typeName = $typeNames[$envelope->getTypeId()];
            if (!class_exists($typeName)) {
                throw new \RuntimeException("Unknown message type: {$typeName}");
            }
            if (!is_subclass_of($typeName, Message::class)) {
                throw new \RuntimeException(
                    "Message type must extend " . Message::class . ": {$typeName}"
                );
            }

            /** @var Message $message */
            $message = new $typeName();
            $message->mergeFromString($envelope->getMessageData());
            $messages[] = $message;
        }

        return new self($messages);
    }
}
