<?php

declare(strict_types=1);

namespace Phluxor\Cluster\PubSub;

use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Exception\FutureTimeoutException;
use Phluxor\ActorSystem\Message\ActorInterface;
use Phluxor\ActorSystem\Ref;
use Phluxor\Cluster\ClusterExtension;
use Phluxor\Cluster\ProtoBuf\DeliverBatchRequestTransport;
use Phluxor\Cluster\ProtoBuf\DeliveryStatus;
use Phluxor\Cluster\ProtoBuf\NotifyAboutFailingSubscribersRequest;
use Phluxor\Cluster\ProtoBuf\SubscriberDeliveryReport;
use Phluxor\Cluster\ProtoBuf\SubscriberIdentity;

final class PubSubMemberDeliveryActor implements ActorInterface
{
    private const string TOPIC_ACTOR_KIND = 'prototopic';

    public function __construct(
        private readonly int $subscriberTimeout,
    ) {
    }

    public function receive(ContextInterface $context): void
    {
        $message = $context->message();
        if (!$message instanceof DeliverBatchRequestTransport) {
            return;
        }
        $this->deliverBatch($message, $context);
    }

    private function deliverBatch(DeliverBatchRequestTransport $request, ContextInterface $context): void
    {
        $subscribers = $request->getSubscribers()?->getSubscribers();
        if ($subscribers === null || count($subscribers) === 0) {
            return;
        }

        $batchTransport = $request->getBatch();
        if ($batchTransport === null) {
            return;
        }
        $batch = PubSubBatch::fromTransport($batchTransport);
        $autoRespondBatch = new PubSubAutoRespondBatch($batch->getEnvelopes());

        /** @var array<int, array{future: \Phluxor\ActorSystem\Future, subscriber: SubscriberIdentity}> $deliveries */
        $deliveries = [];

        foreach ($subscribers as $subscriber) {
            /** @var SubscriberIdentity $subscriber */
            $ref = $this->resolveSubscriber($subscriber, $context);
            if ($ref === null) {
                continue;
            }
            $future = $context->requestFuture($ref, $autoRespondBatch, $this->subscriberTimeout);
            $deliveries[] = ['future' => $future, 'subscriber' => $subscriber];
        }

        /** @var SubscriberDeliveryReport[] $invalidDeliveries */
        $invalidDeliveries = [];

        foreach ($deliveries as $delivery) {
            $result = $delivery['future']->result();
            if ($result->error() instanceof FutureTimeoutException) {
                $report = new SubscriberDeliveryReport();
                $report->setSubscriber($delivery['subscriber']);
                $report->setStatus(DeliveryStatus::Timeout);
                $invalidDeliveries[] = $report;
            }
        }

        if (count($invalidDeliveries) > 0) {
            $notify = new NotifyAboutFailingSubscribersRequest();
            $notify->setInvalidDeliveries($invalidDeliveries);

            /** @var ClusterExtension $ext */
            $ext = $context->get(ClusterExtension::clusterExtensionId());
            $ext->cluster()->request(
                $request->getTopic(),
                self::TOPIC_ACTOR_KIND,
                $notify,
            );
        }
    }

    private function resolveSubscriber(SubscriberIdentity $subscriber, ContextInterface $context): ?Ref
    {
        if ($subscriber->getPid() !== null) {
            return new Ref($subscriber->getPid());
        }
        if ($subscriber->getClusterIdentity() !== null) {
            $ci = $subscriber->getClusterIdentity();
            /** @var ClusterExtension $ext */
            $ext = $context->get(ClusterExtension::clusterExtensionId());
            return $ext->cluster()->get($ci->getIdentity(), $ci->getKind());
        }
        return null;
    }
}
