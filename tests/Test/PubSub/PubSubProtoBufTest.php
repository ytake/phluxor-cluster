<?php

declare(strict_types=1);

namespace Test\PubSub;

use PHPUnit\Framework\TestCase;
use Phluxor\ActorSystem\ProtoBuf\Pid;
use Phluxor\Cluster\ProtoBuf\Acknowledge;
use Phluxor\Cluster\ProtoBuf\ClusterIdentity;
use Phluxor\Cluster\ProtoBuf\DeliverBatchRequestTransport;
use Phluxor\Cluster\ProtoBuf\DeliveryStatus;
use Phluxor\Cluster\ProtoBuf\NotifyAboutFailingSubscribersRequest;
use Phluxor\Cluster\ProtoBuf\NotifyAboutFailingSubscribersResponse;
use Phluxor\Cluster\ProtoBuf\PubSubAutoRespondBatchTransport;
use Phluxor\Cluster\ProtoBuf\PubSubBatchTransport;
use Phluxor\Cluster\ProtoBuf\PubSubEnvelope;
use Phluxor\Cluster\ProtoBuf\PubSubInitialize;
use Phluxor\Cluster\ProtoBuf\PublishResponse;
use Phluxor\Cluster\ProtoBuf\PublishStatus;
use Phluxor\Cluster\ProtoBuf\SubscribeRequest;
use Phluxor\Cluster\ProtoBuf\SubscribeResponse;
use Phluxor\Cluster\ProtoBuf\SubscriberDeliveryReport;
use Phluxor\Cluster\ProtoBuf\SubscriberIdentity;
use Phluxor\Cluster\ProtoBuf\Subscribers;
use Phluxor\Cluster\ProtoBuf\UnsubscribeRequest;
use Phluxor\Cluster\ProtoBuf\UnsubscribeResponse;

final class PubSubProtoBufTest extends TestCase
{
    public function testSubscriberIdentityWithPid(): void
    {
        $pid = new Pid([
            'address' => 'localhost:8080',
            'id' => 'actor-1',
        ]);

        $identity = new SubscriberIdentity();
        $identity->setPid($pid);

        $data = $identity->serializeToString();
        $decoded = new SubscriberIdentity();
        $decoded->mergeFromString($data);

        self::assertTrue($decoded->hasPid());
        self::assertFalse($decoded->hasClusterIdentity());
        self::assertSame('localhost:8080', $decoded->getPid()->getAddress());
        self::assertSame('actor-1', $decoded->getPid()->getId());
        self::assertSame('pid', $decoded->getIdentity());
    }

    public function testSubscriberIdentityWithClusterIdentity(): void
    {
        $clusterIdentity = new ClusterIdentity([
            'identity' => 'user-123',
            'kind' => 'UserGrain',
        ]);

        $identity = new SubscriberIdentity();
        $identity->setClusterIdentity($clusterIdentity);

        $data = $identity->serializeToString();
        $decoded = new SubscriberIdentity();
        $decoded->mergeFromString($data);

        self::assertFalse($decoded->hasPid());
        self::assertTrue($decoded->hasClusterIdentity());
        self::assertSame('user-123', $decoded->getClusterIdentity()->getIdentity());
        self::assertSame('UserGrain', $decoded->getClusterIdentity()->getKind());
        self::assertSame('cluster_identity', $decoded->getIdentity());
    }

    public function testSubscribersRoundTrip(): void
    {
        $sub1 = new SubscriberIdentity();
        $sub1->setPid(new Pid(['address' => 'node-1:8080', 'id' => 'actor-1']));

        $sub2 = new SubscriberIdentity();
        $sub2->setClusterIdentity(new ClusterIdentity([
            'identity' => 'order-456',
            'kind' => 'OrderGrain',
        ]));

        $subscribers = new Subscribers();
        $subscribers->setSubscribers([$sub1, $sub2]);

        $data = $subscribers->serializeToString();
        $decoded = new Subscribers();
        $decoded->mergeFromString($data);

        self::assertCount(2, $decoded->getSubscribers());
        self::assertTrue($decoded->getSubscribers()[0]->hasPid());
        self::assertTrue($decoded->getSubscribers()[1]->hasClusterIdentity());
    }

    public function testSubscribeRequestResponse(): void
    {
        $request = new SubscribeRequest();
        $request->setSubscriber(
            (new SubscriberIdentity())->setPid(
                new Pid(['address' => 'localhost:8080', 'id' => 'sub-1'])
            )
        );

        $data = $request->serializeToString();
        $decoded = new SubscribeRequest();
        $decoded->mergeFromString($data);

        self::assertSame('localhost:8080', $decoded->getSubscriber()->getPid()->getAddress());

        $response = new SubscribeResponse();
        $responseData = $response->serializeToString();
        $decodedResponse = new SubscribeResponse();
        $decodedResponse->mergeFromString($responseData);
        self::assertInstanceOf(SubscribeResponse::class, $decodedResponse);
    }

    public function testUnsubscribeRequestResponse(): void
    {
        $request = new UnsubscribeRequest();
        $request->setSubscriber(
            (new SubscriberIdentity())->setClusterIdentity(
                new ClusterIdentity(['identity' => 'user-1', 'kind' => 'UserGrain'])
            )
        );

        $data = $request->serializeToString();
        $decoded = new UnsubscribeRequest();
        $decoded->mergeFromString($data);

        self::assertSame('user-1', $decoded->getSubscriber()->getClusterIdentity()->getIdentity());

        $response = new UnsubscribeResponse();
        $responseData = $response->serializeToString();
        $decodedResponse = new UnsubscribeResponse();
        $decodedResponse->mergeFromString($responseData);
        self::assertInstanceOf(UnsubscribeResponse::class, $decodedResponse);
    }

    public function testPubSubEnvelopeRoundTrip(): void
    {
        $envelope = new PubSubEnvelope([
            'type_id' => 42,
            'message_data' => 'hello-world-binary',
            'serializer_id' => 1,
        ]);

        $data = $envelope->serializeToString();
        $decoded = new PubSubEnvelope();
        $decoded->mergeFromString($data);

        self::assertSame(42, $decoded->getTypeId());
        self::assertSame('hello-world-binary', $decoded->getMessageData());
        self::assertSame(1, $decoded->getSerializerId());
    }

    public function testPubSubBatchTransportRoundTrip(): void
    {
        $env1 = new PubSubEnvelope(['type_id' => 0, 'message_data' => 'data-1', 'serializer_id' => 0]);
        $env2 = new PubSubEnvelope(['type_id' => 1, 'message_data' => 'data-2', 'serializer_id' => 0]);

        $batch = new PubSubBatchTransport();
        $batch->setTypeNames(['TypeA', 'TypeB']);
        $batch->setEnvelopes([$env1, $env2]);

        $data = $batch->serializeToString();
        $decoded = new PubSubBatchTransport();
        $decoded->mergeFromString($data);

        self::assertCount(2, $decoded->getTypeNames());
        self::assertSame('TypeA', $decoded->getTypeNames()[0]);
        self::assertSame('TypeB', $decoded->getTypeNames()[1]);
        self::assertCount(2, $decoded->getEnvelopes());
        self::assertSame('data-1', $decoded->getEnvelopes()[0]->getMessageData());
        self::assertSame('data-2', $decoded->getEnvelopes()[1]->getMessageData());
    }

    public function testDeliverBatchRequestTransportRoundTrip(): void
    {
        $sub = new SubscriberIdentity();
        $sub->setPid(new Pid(['address' => 'node-1:8080', 'id' => 'actor-1']));

        $subscribers = new Subscribers();
        $subscribers->setSubscribers([$sub]);

        $envelope = new PubSubEnvelope(['type_id' => 0, 'message_data' => 'payload', 'serializer_id' => 0]);
        $batch = new PubSubBatchTransport();
        $batch->setTypeNames(['MyMessage']);
        $batch->setEnvelopes([$envelope]);

        $request = new DeliverBatchRequestTransport();
        $request->setSubscribers($subscribers);
        $request->setBatch($batch);
        $request->setTopic('test-topic');

        $data = $request->serializeToString();
        $decoded = new DeliverBatchRequestTransport();
        $decoded->mergeFromString($data);

        self::assertSame('test-topic', $decoded->getTopic());
        self::assertCount(1, $decoded->getSubscribers()->getSubscribers());
        self::assertCount(1, $decoded->getBatch()->getEnvelopes());
        self::assertSame('payload', $decoded->getBatch()->getEnvelopes()[0]->getMessageData());
    }

    public function testPubSubAutoRespondBatchTransportRoundTrip(): void
    {
        $env = new PubSubEnvelope(['type_id' => 5, 'message_data' => 'auto-data', 'serializer_id' => 2]);

        $autoRespond = new PubSubAutoRespondBatchTransport();
        $autoRespond->setTypeNames(['AutoType']);
        $autoRespond->setEnvelopes([$env]);

        $data = $autoRespond->serializeToString();
        $decoded = new PubSubAutoRespondBatchTransport();
        $decoded->mergeFromString($data);

        self::assertCount(1, $decoded->getTypeNames());
        self::assertSame('AutoType', $decoded->getTypeNames()[0]);
        self::assertSame(5, $decoded->getEnvelopes()[0]->getTypeId());
        self::assertSame('auto-data', $decoded->getEnvelopes()[0]->getMessageData());
        self::assertSame(2, $decoded->getEnvelopes()[0]->getSerializerId());
    }

    public function testPubSubInitializeAndAcknowledge(): void
    {
        $init = new PubSubInitialize(['idle_timeout_ms' => 30000]);

        $data = $init->serializeToString();
        $decoded = new PubSubInitialize();
        $decoded->mergeFromString($data);

        self::assertSame(30000, $decoded->getIdleTimeoutMs());

        $ack = new Acknowledge();
        $ackData = $ack->serializeToString();
        $decodedAck = new Acknowledge();
        $decodedAck->mergeFromString($ackData);
        self::assertInstanceOf(Acknowledge::class, $decodedAck);
    }

    public function testPublishResponseWithStatus(): void
    {
        $response = new PublishResponse(['status' => PublishStatus::Ok]);

        $data = $response->serializeToString();
        $decoded = new PublishResponse();
        $decoded->mergeFromString($data);

        self::assertSame(PublishStatus::Ok, $decoded->getStatus());

        $failedResponse = new PublishResponse(['status' => PublishStatus::Failed]);
        $failedData = $failedResponse->serializeToString();
        $decodedFailed = new PublishResponse();
        $decodedFailed->mergeFromString($failedData);

        self::assertSame(PublishStatus::Failed, $decodedFailed->getStatus());
    }

    public function testDeliveryStatusEnum(): void
    {
        self::assertSame(0, DeliveryStatus::Delivered);
        self::assertSame(1, DeliveryStatus::SubscriberNoLongerReachable);
        self::assertSame(2, DeliveryStatus::Timeout);
        self::assertSame(127, DeliveryStatus::OtherError);

        self::assertSame('Delivered', DeliveryStatus::name(DeliveryStatus::Delivered));
        self::assertSame('OtherError', DeliveryStatus::name(DeliveryStatus::OtherError));
    }

    public function testSubscriberDeliveryReportRoundTrip(): void
    {
        $sub = new SubscriberIdentity();
        $sub->setPid(new Pid(['address' => 'node-1:8080', 'id' => 'actor-1']));

        $report = new SubscriberDeliveryReport();
        $report->setSubscriber($sub);
        $report->setStatus(DeliveryStatus::SubscriberNoLongerReachable);

        $data = $report->serializeToString();
        $decoded = new SubscriberDeliveryReport();
        $decoded->mergeFromString($data);

        self::assertSame(DeliveryStatus::SubscriberNoLongerReachable, $decoded->getStatus());
        self::assertSame('node-1:8080', $decoded->getSubscriber()->getPid()->getAddress());
    }

    public function testNotifyAboutFailingSubscribersRoundTrip(): void
    {
        $sub1 = new SubscriberIdentity();
        $sub1->setPid(new Pid(['address' => 'node-1:8080', 'id' => 'actor-1']));

        $sub2 = new SubscriberIdentity();
        $sub2->setClusterIdentity(new ClusterIdentity([
            'identity' => 'order-1',
            'kind' => 'OrderGrain',
        ]));

        $report1 = new SubscriberDeliveryReport();
        $report1->setSubscriber($sub1);
        $report1->setStatus(DeliveryStatus::Timeout);

        $report2 = new SubscriberDeliveryReport();
        $report2->setSubscriber($sub2);
        $report2->setStatus(DeliveryStatus::OtherError);

        $request = new NotifyAboutFailingSubscribersRequest();
        $request->setInvalidDeliveries([$report1, $report2]);

        $data = $request->serializeToString();
        $decoded = new NotifyAboutFailingSubscribersRequest();
        $decoded->mergeFromString($data);

        self::assertCount(2, $decoded->getInvalidDeliveries());
        self::assertSame(DeliveryStatus::Timeout, $decoded->getInvalidDeliveries()[0]->getStatus());
        self::assertSame(DeliveryStatus::OtherError, $decoded->getInvalidDeliveries()[1]->getStatus());
        self::assertTrue($decoded->getInvalidDeliveries()[0]->getSubscriber()->hasPid());
        self::assertTrue($decoded->getInvalidDeliveries()[1]->getSubscriber()->hasClusterIdentity());

        $response = new NotifyAboutFailingSubscribersResponse();
        $responseData = $response->serializeToString();
        $decodedResponse = new NotifyAboutFailingSubscribersResponse();
        $decodedResponse->mergeFromString($responseData);
        self::assertInstanceOf(NotifyAboutFailingSubscribersResponse::class, $decodedResponse);
    }
}
