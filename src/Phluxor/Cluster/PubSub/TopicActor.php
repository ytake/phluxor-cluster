<?php

declare(strict_types=1);

namespace Phluxor\Cluster\PubSub;

use DateInterval;
use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Message\ReceiveTimeout;
use Phluxor\ActorSystem\ProtoBuf\Pid;
use Phluxor\ActorSystem\Ref;
use Phluxor\Cluster\ClusterTopologyEvent;
use Phluxor\Cluster\Grain\ClusterInit;
use Phluxor\Cluster\Grain\GrainBase;
use Phluxor\Cluster\ProtoBuf\DeliverBatchRequestTransport;
use Phluxor\Cluster\ProtoBuf\GrainRequest;
use Phluxor\Cluster\ProtoBuf\NotifyAboutFailingSubscribersRequest;
use Phluxor\Cluster\ProtoBuf\NotifyAboutFailingSubscribersResponse;
use Phluxor\Cluster\ProtoBuf\PubSubBatchTransport;
use Phluxor\Cluster\ProtoBuf\PubSubInitialize;
use Phluxor\Cluster\ProtoBuf\Acknowledge;
use Phluxor\Cluster\ProtoBuf\PublishResponse;
use Phluxor\Cluster\ProtoBuf\PublishStatus;
use Phluxor\Cluster\ProtoBuf\SubscribeRequest;
use Phluxor\Cluster\ProtoBuf\SubscribeResponse;
use Phluxor\Cluster\ProtoBuf\SubscriberIdentity;
use Phluxor\Cluster\ProtoBuf\Subscribers;
use Phluxor\Cluster\ProtoBuf\UnsubscribeRequest;
use Phluxor\Cluster\ProtoBuf\UnsubscribeResponse;
use Phluxor\EventStream\Subscription;

class TopicActor extends GrainBase
{
    private const string DELIVERY_ACTOR_NAME = '$pubsub-delivery';

    private string $topic = '';

    /** @var array<string, SubscriberIdentity> */
    private array $subscribers = [];

    private ?Subscription $topologySubscription = null;

    public function __construct(
        private readonly KeyValueStoreInterface $subscriptionStore,
    ) {
    }

    public function receive(ContextInterface $context): void
    {
        $message = $context->message();

        if ($message instanceof ClusterInit) {
            parent::receive($context);
            $this->onClusterInit($context);
            return;
        }

        if ($message instanceof ReceiveTimeout) {
            $context->poison($context->self());
            return;
        }

        parent::receive($context);
    }

    protected function onGrainRequest(GrainRequest $request, ContextInterface $context): void
    {
        $this->respondError($context, 'TopicActor does not support GrainRequest');
    }

    protected function onStopping(ContextInterface $context): void
    {
        $cluster = $this->cluster();
        if ($cluster !== null && $this->topologySubscription !== null) {
            $cluster->actorSystem()->getEventStream()?->unsubscribe($this->topologySubscription);
            $this->topologySubscription = null;
        }
    }

    protected function onMessage(object $message, ContextInterface $context): void
    {
        match (true) {
            $message instanceof SubscribeRequest => $this->onSubscribe($message, $context),
            $message instanceof UnsubscribeRequest => $this->onUnsubscribe($message, $context),
            $message instanceof PubSubBatchTransport => $this->onPubSubBatch($message, $context),
            $message instanceof NotifyAboutFailingSubscribersRequest => $this->onNotifyAboutFailingSubscribers(
                $message,
                $context
            ),
            $message instanceof PubSubInitialize => $this->onInitialize($message, $context),
            $message instanceof ClusterTopologyEvent => $this->onClusterTopologyChanged($message),
            default => null,
        };
    }

    private function onClusterInit(ContextInterface $context): void
    {
        $identity = $this->identity();
        if ($identity !== null) {
            $this->topic = $identity->identity();
        }

        $cluster = $this->cluster();
        $actorSystem = $cluster?->actorSystem();
        $eventStream = $actorSystem?->getEventStream();
        if ($eventStream !== null) {
            $self = $context->self();
            if ($self !== null && $actorSystem !== null) {
                $this->topologySubscription = $eventStream->subscribe(
                    function (mixed $event) use ($actorSystem, $self): void {
                        if ($event instanceof ClusterTopologyEvent) {
                            $self->sendUserMessage($actorSystem, $event);
                        }
                    }
                );
            }
        }

        $this->loadSubscriptions();
    }

    private function onSubscribe(SubscribeRequest $request, ContextInterface $context): void
    {
        $subscriberIdentity = $request->getSubscriber();
        if ($subscriberIdentity !== null) {
            $struct = SubscriberIdentityStruct::fromSubscriberIdentity($subscriberIdentity);
            $this->subscribers[$struct->toKey()] = $subscriberIdentity;
            $this->saveSubscriptions();
        }
        $context->respond(new SubscribeResponse());
    }

    private function onUnsubscribe(UnsubscribeRequest $request, ContextInterface $context): void
    {
        $subscriberIdentity = $request->getSubscriber();
        if ($subscriberIdentity !== null) {
            $struct = SubscriberIdentityStruct::fromSubscriberIdentity($subscriberIdentity);
            unset($this->subscribers[$struct->toKey()]);
            $this->saveSubscriptions();
        }
        $context->respond(new UnsubscribeResponse());
    }

    private function onPubSubBatch(PubSubBatchTransport $batch, ContextInterface $context): void
    {
        /** @var array<string, Subscribers> $subscribersByAddress */
        $subscribersByAddress = [];

        $selfAddress = $context->self()?->protobufPid()->getAddress() ?? '';

        foreach ($this->subscribers as $subscriberIdentity) {
            if ($subscriberIdentity->hasPid()) {
                $pid = $subscriberIdentity->getPid();
                assert($pid !== null);
                $address = $pid->getAddress();
            } else {
                $address = $selfAddress;
            }

            if (!isset($subscribersByAddress[$address])) {
                $subscribersByAddress[$address] = new Subscribers();
            }

            /** @var list<SubscriberIdentity> $current */
            $current = [];
            foreach ($subscribersByAddress[$address]->getSubscribers() as $existing) {
                /** @var SubscriberIdentity $existing */
                $current[] = $existing;
            }
            $current[] = $subscriberIdentity;
            $subscribersByAddress[$address]->setSubscribers($current);
        }

        foreach ($subscribersByAddress as $address => $subscribers) {
            $ref = new Ref(new Pid([
                'address' => $address,
                'id' => self::DELIVERY_ACTOR_NAME,
            ]));

            $transport = new DeliverBatchRequestTransport();
            $transport->setSubscribers($subscribers);
            $transport->setBatch($batch);
            $transport->setTopic($this->topic);

            $context->send($ref, $transport);
        }

        $context->respond(new PublishResponse(['status' => PublishStatus::Ok]));
    }

    private function onClusterTopologyChanged(ClusterTopologyEvent $event): void
    {
        $leftAddresses = [];
        foreach ($event->left() as $member) {
            $leftAddresses[$member->address()] = true;
        }

        foreach ($this->subscribers as $key => $subscriberIdentity) {
            if ($subscriberIdentity->hasPid()) {
                $pid = $subscriberIdentity->getPid();
                assert($pid !== null);
                $pidAddress = $pid->getAddress();
                if (isset($leftAddresses[$pidAddress])) {
                    unset($this->subscribers[$key]);
                }
            }
        }

        $this->saveSubscriptions();
    }

    private function onNotifyAboutFailingSubscribers(
        NotifyAboutFailingSubscribersRequest $request,
        ContextInterface $context,
    ): void {
        foreach ($request->getInvalidDeliveries() as $report) {
            /** @var \Phluxor\Cluster\ProtoBuf\SubscriberDeliveryReport $report */
            $subscriberIdentity = $report->getSubscriber();
            if ($subscriberIdentity !== null) {
                $struct = SubscriberIdentityStruct::fromSubscriberIdentity($subscriberIdentity);
                unset($this->subscribers[$struct->toKey()]);
            }
        }

        $this->saveSubscriptions();
        $context->respond(new NotifyAboutFailingSubscribersResponse());
    }

    private function onInitialize(PubSubInitialize $init, ContextInterface $context): void
    {
        $timeoutMs = (int) $init->getIdleTimeoutMs();
        if ($timeoutMs > 0) {
            $seconds = intdiv($timeoutMs, 1000);
            $context->setReceiveTimeout(new DateInterval("PT{$seconds}S"));
        }
        $context->respond(new Acknowledge());
    }

    private function loadSubscriptions(): void
    {
        $subscribers = $this->subscriptionStore->get($this->topic);
        if ($subscribers === null) {
            return;
        }
        foreach ($subscribers->getSubscribers() as $subscriberIdentity) {
            /** @var SubscriberIdentity $subscriberIdentity */
            $struct = SubscriberIdentityStruct::fromSubscriberIdentity($subscriberIdentity);
            $this->subscribers[$struct->toKey()] = $subscriberIdentity;
        }
    }

    private function saveSubscriptions(): void
    {
        $subscribers = new Subscribers();
        $identities = array_values($this->subscribers);
        $subscribers->setSubscribers($identities);
        $this->subscriptionStore->set($this->topic, $subscribers);
    }
}
