<?php

declare(strict_types=1);

namespace Phluxor\Cluster\PubSub;

use Google\Protobuf\Internal\Message;
use Phluxor\Cluster\Exception\InvalidProducerOperationException;
use Phluxor\Cluster\Exception\ProducerQueueFullException;
use Swoole\Atomic;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

final class BatchingProducer
{
    private const int DEFAULT_CHANNEL_CAPACITY = 65536;

    private Channel $messageChannel;
    private Atomic $completed;
    private Channel $loopDone;

    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly string $topic,
        private readonly BatchingProducerConfig $config = new BatchingProducerConfig(),
    ) {
        $capacity = $this->config->maxQueueSize() > 0
            ? $this->config->maxQueueSize()
            : self::DEFAULT_CHANNEL_CAPACITY;
        $this->messageChannel = new Channel($capacity);
        $this->completed = new Atomic(0);
        $this->loopDone = new Channel(1);

        Coroutine::create(fn() => $this->publishLoop());
    }

    /**
     * @throws InvalidProducerOperationException
     * @throws ProducerQueueFullException
     */
    public function produce(Message $message): ProduceProcessInfo
    {
        if ($this->completed->get() === 1) {
            throw new InvalidProducerOperationException($this->topic);
        }

        $info = new ProduceProcessInfo();

        if ($this->config->maxQueueSize() > 0 && $this->messageChannel->isFull()) {
            throw new ProducerQueueFullException($this->topic);
        }

        $this->messageChannel->push(['message' => $message, 'info' => $info], 0.001);

        return $info;
    }

    public function dispose(): void
    {
        if ($this->completed->get() === 1) {
            return;
        }
        $this->completed->set(1);
        $this->messageChannel->close();
        $this->loopDone->pop(5.0);
    }

    private function publishLoop(): void
    {
        /** @var array{message: Message, info: ProduceProcessInfo}[] $batch */
        $batch = [];

        while (true) {
            if ($this->completed->get() === 1 && $this->messageChannel->isEmpty()) {
                $this->cancelBatch($batch);
                break;
            }

            $item = $this->messageChannel->pop(0.05);

            if ($item === false) {
                if ($this->completed->get() === 1) {
                    $this->cancelBatch($batch);
                    break;
                }
                if (count($batch) > 0) {
                    $this->publishBatch($batch);
                    $batch = [];
                }
                continue;
            }

            /** @var array{message: Message, info: ProduceProcessInfo} $item */
            $batch[] = $item;

            if (count($batch) >= $this->config->batchSize()) {
                $this->publishBatch($batch);
                $batch = [];
            }
        }

        $this->loopDone->push(true);
    }

    /**
     * @param array{message: Message, info: ProduceProcessInfo}[] $batch
     */
    private function publishBatch(array $batch): void
    {
        if (count($batch) === 0) {
            return;
        }

        $messages = array_map(fn(array $item) => $item['message'], $batch);
        $pubSubBatch = new PubSubBatch($messages);
        $retries = 0;

        while (true) {
            try {
                $this->publisher->publishBatch($this->topic, $pubSubBatch);
                $this->completeBatch($batch);
                return;
            } catch (\Throwable $e) {
                $decision = ($this->config->onPublishingError())($retries, $e, $pubSubBatch);

                switch ($decision->action()) {
                    case PublishingErrorAction::FailBatchAndStop:
                        $this->failBatch($batch, $e);
                        $this->completed->set(1);
                        $this->messageChannel->close();
                        return;

                    case PublishingErrorAction::FailBatchAndContinue:
                        $this->failBatch($batch, $e);
                        return;

                    case PublishingErrorAction::RetryBatch:
                        $retries++;
                        $batch = $this->removeCancelledFromBatch($batch);
                        if (count($batch) === 0) {
                            return;
                        }
                        $messages = array_map(fn(array $item) => $item['message'], $batch);
                        $pubSubBatch = new PubSubBatch($messages);
                        if ($decision->delaySeconds() > 0) {
                            Coroutine::sleep($decision->delaySeconds());
                        }
                        break;
                }
            }
        }
    }

    /**
     * @param array{message: Message, info: ProduceProcessInfo}[] $batch
     */
    private function completeBatch(array $batch): void
    {
        foreach ($batch as $item) {
            $item['info']->complete();
        }
    }

    /**
     * @param array{message: Message, info: ProduceProcessInfo}[] $batch
     */
    private function failBatch(array $batch, \Throwable $error): void
    {
        foreach ($batch as $item) {
            $item['info']->fail($error);
        }
    }

    /**
     * @param array{message: Message, info: ProduceProcessInfo}[] $batch
     */
    private function cancelBatch(array $batch): void
    {
        foreach ($batch as $item) {
            $item['info']->cancel();
        }
    }

    /**
     * @param array{message: Message, info: ProduceProcessInfo}[] $batch
     * @return array{message: Message, info: ProduceProcessInfo}[]
     */
    private function removeCancelledFromBatch(array $batch): array
    {
        return array_values(
            array_filter($batch, fn(array $item) => !$item['info']->isCancelled())
        );
    }
}
