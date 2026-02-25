<?php

declare(strict_types=1);

namespace Test\PubSub;

use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Exception\FutureTimeoutException;
use Phluxor\ActorSystem\Future;
use Phluxor\ActorSystem\FutureResult;
use Phluxor\ActorSystem\Message\ActorInterface;
use Phluxor\ActorSystem\Message\Started;
use Phluxor\ActorSystem\ProtoBuf\Pid;
use Phluxor\ActorSystem\Ref;
use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\ClusterExtension;
use Phluxor\Cluster\ProtoBuf\DeliverBatchRequestTransport;
use Phluxor\Cluster\ProtoBuf\DeliveryStatus;
use Phluxor\Cluster\ProtoBuf\NotifyAboutFailingSubscribersRequest;
use Phluxor\Cluster\ProtoBuf\PubSubBatchTransport;
use Phluxor\Cluster\ProtoBuf\PubSubEnvelope;
use Phluxor\Cluster\ProtoBuf\PublishResponse;
use Phluxor\Cluster\ProtoBuf\PublishStatus;
use Phluxor\Cluster\ProtoBuf\SubscribeRequest;
use Phluxor\Cluster\ProtoBuf\SubscriberIdentity;
use Phluxor\Cluster\ProtoBuf\Subscribers;
use Phluxor\Cluster\PubSub\PubSubAutoRespondBatch;
use Phluxor\Cluster\PubSub\PubSubMemberDeliveryActor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PubSubMemberDeliveryActorTest extends TestCase
{
    private const int DEFAULT_TIMEOUT = 5;

    private function createDeliveryActor(): PubSubMemberDeliveryActor
    {
        return new PubSubMemberDeliveryActor(self::DEFAULT_TIMEOUT);
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

    /**
     * @return Cluster&MockObject
     */
    private function createClusterMock(): Cluster
    {
        return $this->createMock(Cluster::class);
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

    private function createDeliverBatchRequest(
        array $subscriberIdentities,
        string $topic = 'test-topic'
    ): DeliverBatchRequestTransport {
        $subscribers = new Subscribers();
        $subscribers->setSubscribers($subscriberIdentities);

        $message = new SubscribeRequest();
        $batch = new PubSubBatchTransport();
        $batch->setTypeNames([SubscribeRequest::class]);
        $batch->setEnvelopes([new PubSubEnvelope([
            'type_id' => 0,
            'message_data' => $message->serializeToString(),
            'serializer_id' => 0,
        ])]);

        $transport = new DeliverBatchRequestTransport();
        $transport->setSubscribers($subscribers);
        $transport->setBatch($batch);
        $transport->setTopic($topic);

        return $transport;
    }

    private function createSuccessFutureResult(): FutureResult
    {
        return new FutureResult(new PublishResponse(['status' => PublishStatus::Ok]));
    }

    private function createTimeoutFutureResult(): FutureResult
    {
        return new FutureResult(null, new FutureTimeoutException('timeout'));
    }

    public function testImplementsActorInterface(): void
    {
        $actor = $this->createDeliveryActor();
        self::assertInstanceOf(ActorInterface::class, $actor);
    }

    public function testIgnoresNonDeliverBatchMessages(): void
    {
        $actor = $this->createDeliveryActor();
        $context = $this->createContext(new Started());
        $context->expects($this->never())->method('requestFuture');

        $actor->receive($context);
    }

    public function testDeliversToPidSubscriber(): void
    {
        $actor = $this->createDeliveryActor();

        $sub = $this->createPidSubscriberIdentity('127.0.0.1:9000', 'actor/1');
        $request = $this->createDeliverBatchRequest([$sub]);

        $future = $this->createMock(Future::class);
        $future->method('result')->willReturn($this->createSuccessFutureResult());

        $context = $this->createContext($request);
        $context->expects($this->once())
            ->method('requestFuture')
            ->with(
                $this->callback(fn(Ref $ref) => $ref->protobufPid()->getAddress() === '127.0.0.1:9000'
                    && $ref->protobufPid()->getId() === 'actor/1'),
                $this->isInstanceOf(PubSubAutoRespondBatch::class),
                self::DEFAULT_TIMEOUT
            )
            ->willReturn($future);

        $actor->receive($context);
    }

    public function testDeliversToClusterIdentitySubscriber(): void
    {
        $actor = $this->createDeliveryActor();

        $sub = $this->createClusterIdentitySubscriberIdentity('user-1', 'UserGrain');
        $request = $this->createDeliverBatchRequest([$sub]);

        $resolvedRef = new Ref(new Pid(['address' => '192.168.1.1:8080', 'id' => 'UserGrain/user-1']));

        $cluster = $this->createClusterMock();
        $cluster->method('get')->with('user-1', 'UserGrain')->willReturn($resolvedRef);

        $clusterExtension = new ClusterExtension($cluster);

        $future = $this->createMock(Future::class);
        $future->method('result')->willReturn($this->createSuccessFutureResult());

        $context = $this->createContext($request);
        $context->method('get')
            ->with(ClusterExtension::clusterExtensionId())
            ->willReturn($clusterExtension);
        $context->expects($this->once())
            ->method('requestFuture')
            ->with(
                $resolvedRef,
                $this->isInstanceOf(PubSubAutoRespondBatch::class),
                self::DEFAULT_TIMEOUT
            )
            ->willReturn($future);

        $actor->receive($context);
    }

    public function testSuccessfulDeliveryDoesNotNotifyTopicActor(): void
    {
        $actor = $this->createDeliveryActor();

        $sub = $this->createPidSubscriberIdentity('127.0.0.1:9000', 'actor/1');
        $request = $this->createDeliverBatchRequest([$sub]);

        $future = $this->createMock(Future::class);
        $future->method('result')->willReturn($this->createSuccessFutureResult());

        $cluster = $this->createClusterMock();
        $cluster->expects($this->never())->method('request');

        $clusterExtension = new ClusterExtension($cluster);

        $context = $this->createContext($request);
        $context->method('requestFuture')->willReturn($future);
        $context->method('get')
            ->with(ClusterExtension::clusterExtensionId())
            ->willReturn($clusterExtension);

        $actor->receive($context);
    }

    public function testTimeoutDeliveryNotifiesTopicActor(): void
    {
        $actor = $this->createDeliveryActor();

        $sub = $this->createPidSubscriberIdentity('127.0.0.1:9000', 'actor/1');
        $request = $this->createDeliverBatchRequest([$sub], 'failed-topic');

        $future = $this->createMock(Future::class);
        $future->method('result')->willReturn($this->createTimeoutFutureResult());

        $cluster = $this->createClusterMock();
        $cluster->expects($this->once())
            ->method('request')
            ->with(
                'failed-topic',
                'prototopic',
                $this->callback(function (mixed $msg): bool {
                    if (!$msg instanceof NotifyAboutFailingSubscribersRequest) {
                        return false;
                    }
                    $deliveries = $msg->getInvalidDeliveries();
                    return count($deliveries) === 1
                        && $deliveries[0]->getStatus() === DeliveryStatus::Timeout;
                })
            );

        $clusterExtension = new ClusterExtension($cluster);

        $context = $this->createContext($request);
        $context->method('requestFuture')->willReturn($future);
        $context->method('get')
            ->with(ClusterExtension::clusterExtensionId())
            ->willReturn($clusterExtension);

        $actor->receive($context);
    }

    public function testMultipleSubscribersPartialFailure(): void
    {
        $actor = $this->createDeliveryActor();

        $sub1 = $this->createPidSubscriberIdentity('127.0.0.1:9000', 'actor/1');
        $sub2 = $this->createPidSubscriberIdentity('127.0.0.1:9001', 'actor/2');
        $request = $this->createDeliverBatchRequest([$sub1, $sub2], 'partial-topic');

        $successFuture = $this->createMock(Future::class);
        $successFuture->method('result')->willReturn($this->createSuccessFutureResult());

        $failFuture = $this->createMock(Future::class);
        $failFuture->method('result')->willReturn($this->createTimeoutFutureResult());

        $cluster = $this->createClusterMock();
        $cluster->expects($this->once())
            ->method('request')
            ->with(
                'partial-topic',
                'prototopic',
                $this->callback(function (mixed $msg): bool {
                    return $msg instanceof NotifyAboutFailingSubscribersRequest
                        && count($msg->getInvalidDeliveries()) === 1;
                })
            );

        $clusterExtension = new ClusterExtension($cluster);

        $callCount = 0;
        $context = $this->createContext($request);
        $context->method('requestFuture')
            ->willReturnCallback(function () use (&$callCount, $successFuture, $failFuture) {
                $callCount++;
                return $callCount === 1 ? $successFuture : $failFuture;
            });
        $context->method('get')
            ->with(ClusterExtension::clusterExtensionId())
            ->willReturn($clusterExtension);

        $actor->receive($context);
    }

    public function testNoSubscribersDoesNothing(): void
    {
        $actor = $this->createDeliveryActor();

        $request = $this->createDeliverBatchRequest([]);

        $context = $this->createContext($request);
        $context->expects($this->never())->method('requestFuture');

        $actor->receive($context);
    }
}
