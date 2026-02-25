<?php

declare(strict_types=1);

namespace Test\PubSub;

use DateInterval;
use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Message\ReceiveTimeout;
use Phluxor\ActorSystem\ProtoBuf\Pid;
use Phluxor\ActorSystem\Ref;
use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\ClusterIdentity;
use Phluxor\Cluster\ClusterTopologyEvent;
use Phluxor\Cluster\Grain\ClusterInit;
use Phluxor\Cluster\Member;
use Phluxor\Cluster\ProtoBuf\DeliverBatchRequestTransport;
use Phluxor\Cluster\ProtoBuf\DeliveryStatus;
use Phluxor\Cluster\ProtoBuf\NotifyAboutFailingSubscribersRequest;
use Phluxor\Cluster\ProtoBuf\NotifyAboutFailingSubscribersResponse;
use Phluxor\Cluster\ProtoBuf\PubSubBatchTransport;
use Phluxor\Cluster\ProtoBuf\PubSubEnvelope;
use Phluxor\Cluster\ProtoBuf\PubSubInitialize;
use Phluxor\Cluster\ProtoBuf\SubscribeRequest;
use Phluxor\Cluster\ProtoBuf\SubscribeResponse;
use Phluxor\Cluster\ProtoBuf\SubscriberDeliveryReport;
use Phluxor\Cluster\ProtoBuf\SubscriberIdentity;
use Phluxor\Cluster\ProtoBuf\Subscribers;
use Phluxor\Cluster\ProtoBuf\UnsubscribeRequest;
use Phluxor\Cluster\ProtoBuf\Acknowledge;
use Phluxor\Cluster\ProtoBuf\PublishResponse;
use Phluxor\Cluster\ProtoBuf\UnsubscribeResponse;
use Phluxor\Cluster\PubSub\InMemoryKeyValueStore;
use Phluxor\Cluster\PubSub\TopicActor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class TopicActorTest extends TestCase
{
    private InMemoryKeyValueStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryKeyValueStore();
    }

    private function createTopicActor(): TopicActor
    {
        return new TopicActor($this->store);
    }

    /**
     * @return ContextInterface&MockObject
     */
    private function createContext(mixed $message): ContextInterface
    {
        $context = $this->createMock(ContextInterface::class);
        $context->method('message')->willReturn($message);
        return $context;
    }

    private function initializeActor(TopicActor $actor, string $topic = 'test-topic'): void
    {
        $cluster = $this->createMock(Cluster::class);
        $identity = new ClusterIdentity($topic, 'prototopic');
        $clusterInit = new ClusterInit($cluster, $identity);

        $context = $this->createContext($clusterInit);
        $actor->receive($context);
    }

    private function createPidSubscriberIdentity(string $address, string $id): SubscriberIdentity
    {
        $identity = new SubscriberIdentity();
        $identity->setPid(new Pid(['address' => $address, 'id' => $id]));
        return $identity;
    }

    private function createClusterIdentitySubscriberIdentity(
        string $identityName,
        string $kind
    ): SubscriberIdentity {
        $ci = new \Phluxor\Cluster\ProtoBuf\ClusterIdentity([
            'identity' => $identityName,
            'kind' => $kind,
        ]);
        $identity = new SubscriberIdentity();
        $identity->setClusterIdentity($ci);
        return $identity;
    }

    private function createSelfRef(string $address = '127.0.0.1:8080', string $id = 'topic-actor/1'): Ref
    {
        return new Ref(new Pid(['address' => $address, 'id' => $id]));
    }

    // --- Subscribe Tests ---

    public function testSubscribeAddsPidSubscriber(): void
    {
        $actor = $this->createTopicActor();
        $this->initializeActor($actor, 'my-topic');

        $subscriberIdentity = $this->createPidSubscriberIdentity('127.0.0.1:9000', 'actor/1');
        $request = new SubscribeRequest(['subscriber' => $subscriberIdentity]);

        $context = $this->createContext($request);
        $context->expects($this->once())
            ->method('respond')
            ->with($this->isInstanceOf(SubscribeResponse::class));

        $actor->receive($context);

        $stored = $this->store->get('my-topic');
        self::assertNotNull($stored);
        self::assertCount(1, $stored->getSubscribers());
    }

    public function testSubscribeAddsClusterIdentitySubscriber(): void
    {
        $actor = $this->createTopicActor();
        $this->initializeActor($actor, 'my-topic');

        $subscriberIdentity = $this->createClusterIdentitySubscriberIdentity('user-1', 'UserGrain');
        $request = new SubscribeRequest(['subscriber' => $subscriberIdentity]);

        $context = $this->createContext($request);
        $context->expects($this->once())
            ->method('respond')
            ->with($this->isInstanceOf(SubscribeResponse::class));

        $actor->receive($context);

        $stored = $this->store->get('my-topic');
        self::assertNotNull($stored);
        self::assertCount(1, $stored->getSubscribers());
    }

    public function testDuplicateSubscribeIsIdempotent(): void
    {
        $actor = $this->createTopicActor();
        $this->initializeActor($actor, 'my-topic');

        $subscriberIdentity = $this->createPidSubscriberIdentity('127.0.0.1:9000', 'actor/1');
        $request = new SubscribeRequest(['subscriber' => $subscriberIdentity]);

        $context1 = $this->createContext($request);
        $context1->method('respond');
        $actor->receive($context1);

        $context2 = $this->createContext($request);
        $context2->method('respond');
        $actor->receive($context2);

        $stored = $this->store->get('my-topic');
        self::assertNotNull($stored);
        self::assertCount(1, $stored->getSubscribers());
    }

    // --- Unsubscribe Tests ---

    public function testUnsubscribeRemovesSubscriber(): void
    {
        $actor = $this->createTopicActor();
        $this->initializeActor($actor, 'my-topic');

        $subscriberIdentity = $this->createPidSubscriberIdentity('127.0.0.1:9000', 'actor/1');

        // Subscribe first
        $subRequest = new SubscribeRequest(['subscriber' => $subscriberIdentity]);
        $subContext = $this->createContext($subRequest);
        $subContext->method('respond');
        $actor->receive($subContext);

        // Unsubscribe
        $unsubRequest = new UnsubscribeRequest(['subscriber' => $subscriberIdentity]);
        $unsubContext = $this->createContext($unsubRequest);
        $unsubContext->expects($this->once())
            ->method('respond')
            ->with($this->isInstanceOf(UnsubscribeResponse::class));
        $actor->receive($unsubContext);

        $stored = $this->store->get('my-topic');
        self::assertNotNull($stored);
        self::assertCount(0, $stored->getSubscribers());
    }

    public function testUnsubscribeNonExistentSubscriberDoesNotError(): void
    {
        $actor = $this->createTopicActor();
        $this->initializeActor($actor, 'my-topic');

        $subscriberIdentity = $this->createPidSubscriberIdentity('127.0.0.1:9000', 'actor/1');
        $request = new UnsubscribeRequest(['subscriber' => $subscriberIdentity]);

        $context = $this->createContext($request);
        $context->expects($this->once())
            ->method('respond')
            ->with($this->isInstanceOf(UnsubscribeResponse::class));

        $actor->receive($context);
    }

    // --- Load Subscriptions Test ---

    public function testLoadSubscriptionsOnInit(): void
    {
        $subscriberIdentity = $this->createPidSubscriberIdentity('127.0.0.1:9000', 'actor/1');
        $subscribers = new Subscribers();
        $subscribers->setSubscribers([$subscriberIdentity]);
        $this->store->set('my-topic', $subscribers);

        $actor = $this->createTopicActor();
        $this->initializeActor($actor, 'my-topic');

        // Subscribe another to verify loaded subscribers are preserved
        $newSubscriber = $this->createPidSubscriberIdentity('127.0.0.1:9001', 'actor/2');
        $request = new SubscribeRequest(['subscriber' => $newSubscriber]);
        $context = $this->createContext($request);
        $context->method('respond');
        $actor->receive($context);

        $stored = $this->store->get('my-topic');
        self::assertNotNull($stored);
        self::assertCount(2, $stored->getSubscribers());
    }

    // --- PubSubBatch Delivery Tests ---

    public function testPubSubBatchSendsToDeliveryActorGroupedByAddress(): void
    {
        $actor = $this->createTopicActor();
        $this->initializeActor($actor, 'my-topic');

        // Add subscribers on two different addresses
        $sub1 = $this->createPidSubscriberIdentity('192.168.1.1:8080', 'actor/1');
        $sub2 = $this->createPidSubscriberIdentity('192.168.1.2:8080', 'actor/2');

        $ctx1 = $this->createContext(new SubscribeRequest(['subscriber' => $sub1]));
        $ctx1->method('respond');
        $actor->receive($ctx1);

        $ctx2 = $this->createContext(new SubscribeRequest(['subscriber' => $sub2]));
        $ctx2->method('respond');
        $actor->receive($ctx2);

        // Send PubSubBatch
        $batch = new PubSubBatchTransport();
        $batch->setTypeNames(['SomeType']);
        $batch->setEnvelopes([new PubSubEnvelope([
            'type_id' => 0,
            'message_data' => 'test',
            'serializer_id' => 0,
        ])]);

        $self = $this->createSelfRef('127.0.0.1:8080');
        $context = $this->createContext($batch);
        $context->method('self')->willReturn($self);

        $sentMessages = [];
        $context->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (Ref $ref, mixed $message) use (&$sentMessages): void {
                $sentMessages[] = [
                    'address' => $ref->protobufPid()->getAddress(),
                    'id' => $ref->protobufPid()->getId(),
                    'message' => $message,
                ];
            });
        $context->expects($this->once())
            ->method('respond')
            ->with($this->isInstanceOf(PublishResponse::class));

        $actor->receive($context);

        self::assertCount(2, $sentMessages);

        $addresses = array_map(fn(array $m) => $m['address'], $sentMessages);
        sort($addresses);
        self::assertSame(['192.168.1.1:8080', '192.168.1.2:8080'], $addresses);

        foreach ($sentMessages as $sent) {
            self::assertSame('$pubsub-delivery', $sent['id']);
            self::assertInstanceOf(DeliverBatchRequestTransport::class, $sent['message']);
        }
    }

    public function testPubSubBatchClusterIdentityUsesLocalAddress(): void
    {
        $actor = $this->createTopicActor();
        $this->initializeActor($actor, 'my-topic');

        $sub = $this->createClusterIdentitySubscriberIdentity('user-1', 'UserGrain');
        $subCtx = $this->createContext(new SubscribeRequest(['subscriber' => $sub]));
        $subCtx->method('respond');
        $actor->receive($subCtx);

        $batch = new PubSubBatchTransport();
        $batch->setTypeNames(['SomeType']);
        $batch->setEnvelopes([new PubSubEnvelope([
            'type_id' => 0,
            'message_data' => 'test',
            'serializer_id' => 0,
        ])]);

        $self = $this->createSelfRef('127.0.0.1:8080');
        $context = $this->createContext($batch);
        $context->method('self')->willReturn($self);

        $context->expects($this->once())
            ->method('send')
            ->with(
                $this->callback(
                    fn(Ref $ref) => $ref->protobufPid()->getAddress() === '127.0.0.1:8080'
                        && $ref->protobufPid()->getId() === '$pubsub-delivery'
                ),
                $this->isInstanceOf(DeliverBatchRequestTransport::class)
            );
        $context->method('respond');

        $actor->receive($context);
    }

    public function testPubSubBatchSameAddressMergesSubscribers(): void
    {
        $actor = $this->createTopicActor();
        $this->initializeActor($actor, 'my-topic');

        $sub1 = $this->createPidSubscriberIdentity('192.168.1.1:8080', 'actor/1');
        $sub2 = $this->createPidSubscriberIdentity('192.168.1.1:8080', 'actor/2');

        $ctx1 = $this->createContext(new SubscribeRequest(['subscriber' => $sub1]));
        $ctx1->method('respond');
        $actor->receive($ctx1);

        $ctx2 = $this->createContext(new SubscribeRequest(['subscriber' => $sub2]));
        $ctx2->method('respond');
        $actor->receive($ctx2);

        $batch = new PubSubBatchTransport();
        $batch->setTypeNames(['SomeType']);
        $batch->setEnvelopes([new PubSubEnvelope([
            'type_id' => 0,
            'message_data' => 'test',
            'serializer_id' => 0,
        ])]);

        $self = $this->createSelfRef('127.0.0.1:8080');
        $context = $this->createContext($batch);
        $context->method('self')->willReturn($self);

        $sentMessage = null;
        $context->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (Ref $ref, mixed $message) use (&$sentMessage): void {
                $sentMessage = $message;
            });
        $context->method('respond');

        $actor->receive($context);

        self::assertInstanceOf(DeliverBatchRequestTransport::class, $sentMessage);
        self::assertCount(2, $sentMessage->getSubscribers()->getSubscribers());
    }

    // --- Cluster Topology Tests ---

    public function testClusterTopologyRemovesPidSubscribersOnLeftMembers(): void
    {
        $actor = $this->createTopicActor();
        $this->initializeActor($actor, 'my-topic');

        // Add PID subscriber at address that will leave
        $sub = $this->createPidSubscriberIdentity('192.168.1.1:8080', 'actor/1');
        $subCtx = $this->createContext(new SubscribeRequest(['subscriber' => $sub]));
        $subCtx->method('respond');
        $actor->receive($subCtx);

        // Simulate topology change: member at 192.168.1.1:8080 left
        $leftMember = new Member('192.168.1.1', 8080, 'member-1', ['kind1']);
        $topologyEvent = new ClusterTopologyEvent(
            topologyHash: 2,
            members: [],
            joined: [],
            left: [$leftMember],
            blocked: []
        );

        $context = $this->createContext($topologyEvent);
        $actor->receive($context);

        $stored = $this->store->get('my-topic');
        self::assertNotNull($stored);
        self::assertCount(0, $stored->getSubscribers());
    }

    public function testClusterTopologyKeepsClusterIdentitySubscribers(): void
    {
        $actor = $this->createTopicActor();
        $this->initializeActor($actor, 'my-topic');

        $sub = $this->createClusterIdentitySubscriberIdentity('user-1', 'UserGrain');
        $subCtx = $this->createContext(new SubscribeRequest(['subscriber' => $sub]));
        $subCtx->method('respond');
        $actor->receive($subCtx);

        // Simulate topology change: some member left
        $leftMember = new Member('192.168.1.1', 8080, 'member-1', ['kind1']);
        $topologyEvent = new ClusterTopologyEvent(
            topologyHash: 2,
            members: [],
            joined: [],
            left: [$leftMember],
            blocked: []
        );

        $context = $this->createContext($topologyEvent);
        $actor->receive($context);

        $stored = $this->store->get('my-topic');
        self::assertNotNull($stored);
        self::assertCount(1, $stored->getSubscribers());
    }

    public function testClusterTopologyKeepsPidSubscribersOnRemainingMembers(): void
    {
        $actor = $this->createTopicActor();
        $this->initializeActor($actor, 'my-topic');

        // Add two PID subscribers on different addresses
        $sub1 = $this->createPidSubscriberIdentity('192.168.1.1:8080', 'actor/1');
        $sub2 = $this->createPidSubscriberIdentity('192.168.1.2:8080', 'actor/2');

        $ctx1 = $this->createContext(new SubscribeRequest(['subscriber' => $sub1]));
        $ctx1->method('respond');
        $actor->receive($ctx1);

        $ctx2 = $this->createContext(new SubscribeRequest(['subscriber' => $sub2]));
        $ctx2->method('respond');
        $actor->receive($ctx2);

        // Only 192.168.1.1:8080 leaves
        $leftMember = new Member('192.168.1.1', 8080, 'member-1', ['kind1']);
        $topologyEvent = new ClusterTopologyEvent(
            topologyHash: 2,
            members: [],
            joined: [],
            left: [$leftMember],
            blocked: []
        );

        $context = $this->createContext($topologyEvent);
        $actor->receive($context);

        $stored = $this->store->get('my-topic');
        self::assertNotNull($stored);
        self::assertCount(1, $stored->getSubscribers());
    }

    // --- NotifyAboutFailingSubscribers Tests ---

    public function testNotifyAboutFailingSubscribersRemovesThem(): void
    {
        $actor = $this->createTopicActor();
        $this->initializeActor($actor, 'my-topic');

        $sub = $this->createPidSubscriberIdentity('127.0.0.1:9000', 'actor/1');
        $subCtx = $this->createContext(new SubscribeRequest(['subscriber' => $sub]));
        $subCtx->method('respond');
        $actor->receive($subCtx);

        $report = new SubscriberDeliveryReport([
            'subscriber' => $sub,
            'status' => DeliveryStatus::SubscriberNoLongerReachable,
        ]);
        $request = new NotifyAboutFailingSubscribersRequest([
            'invalid_deliveries' => [$report],
        ]);

        $context = $this->createContext($request);
        $context->expects($this->once())
            ->method('respond')
            ->with($this->isInstanceOf(NotifyAboutFailingSubscribersResponse::class));

        $actor->receive($context);

        $stored = $this->store->get('my-topic');
        self::assertNotNull($stored);
        self::assertCount(0, $stored->getSubscribers());
    }

    // --- PubSubInitialize Tests ---

    public function testInitializeSetsReceiveTimeout(): void
    {
        $actor = $this->createTopicActor();
        $this->initializeActor($actor);

        $init = new PubSubInitialize(['idle_timeout_ms' => 5000]);

        $context = $this->createContext($init);
        $context->expects($this->once())
            ->method('setReceiveTimeout')
            ->with($this->callback(function (DateInterval $interval): bool {
                return $interval->s === 5;
            }));
        $context->expects($this->once())
            ->method('respond')
            ->with($this->isInstanceOf(Acknowledge::class));

        $actor->receive($context);
    }

    // --- ReceiveTimeout Tests ---

    public function testReceiveTimeoutPoisonsSelf(): void
    {
        $actor = $this->createTopicActor();
        $this->initializeActor($actor);

        $self = $this->createSelfRef();
        $context = $this->createContext(new ReceiveTimeout());
        $context->method('self')->willReturn($self);
        $context->expects($this->once())
            ->method('poison')
            ->with($self);

        $actor->receive($context);
    }
}
