<?php

declare(strict_types=1);

namespace Test\PubSub;

use Phluxor\ActorSystem;
use Phluxor\ActorSystem\Message\ReceiveFunction;
use Phluxor\ActorSystem\ProtoBuf\Pid;
use Phluxor\ActorSystem\Ref;
use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\ClusterConfig;
use Phluxor\Cluster\ClusterIdentity;
use Phluxor\Cluster\ClusterProviderInterface;
use Phluxor\Cluster\GrainCallConfig;
use Phluxor\Cluster\IdentityLookupInterface;
use Phluxor\Cluster\KindRegistry;
use Phluxor\Cluster\ProtoBuf\SubscribeRequest;
use Phluxor\Cluster\ProtoBuf\SubscribeResponse;
use Phluxor\Cluster\ProtoBuf\UnsubscribeRequest;
use Phluxor\Cluster\ProtoBuf\UnsubscribeResponse;
use Phluxor\Cluster\PubSub\BatchingProducer;
use Phluxor\Cluster\PubSub\BatchingProducerConfig;
use PHPUnit\Framework\TestCase;

final class ClusterPubSubMethodsTest extends TestCase
{
    private const string TOPIC_ACTOR_KIND = 'prototopic';

    /**
     * @return Cluster&\PHPUnit\Framework\MockObject\MockObject
     */
    private function createPartialCluster(?ActorSystem $actorSystem = null): Cluster
    {
        $actorSystem ??= $this->createMock(ActorSystem::class);
        $config = new ClusterConfig(
            'test-cluster',
            'localhost',
            50052,
            $this->createMock(ClusterProviderInterface::class),
            $this->createMock(IdentityLookupInterface::class),
            new KindRegistry()
        );

        return $this->getMockBuilder(Cluster::class)
            ->setConstructorArgs([$actorSystem, $config])
            ->onlyMethods(['request'])
            ->getMock();
    }

    public function testSubscribeByPid(): void
    {
        $pid = new Pid(['address' => '127.0.0.1:9000', 'id' => 'actor/1']);
        $expectedResponse = new SubscribeResponse();

        $cluster = $this->createPartialCluster();
        $cluster->expects($this->once())
            ->method('request')
            ->with(
                'test-topic',
                self::TOPIC_ACTOR_KIND,
                $this->callback(function (mixed $msg) use ($pid): bool {
                    return $msg instanceof SubscribeRequest
                        && $msg->getSubscriber()?->getPid()?->getAddress() === '127.0.0.1:9000'
                        && $msg->getSubscriber()?->getPid()?->getId() === 'actor/1';
                }),
                null
            )
            ->willReturn($expectedResponse);

        $result = $cluster->subscribeByPid('test-topic', $pid);
        self::assertInstanceOf(SubscribeResponse::class, $result);
        self::assertSame($expectedResponse, $result);
    }

    public function testSubscribeByPidWithConfig(): void
    {
        $pid = new Pid(['address' => '127.0.0.1:9000', 'id' => 'actor/1']);
        $grainConfig = new GrainCallConfig(retryCount: 5, requestTimeoutSeconds: 10);
        $expectedResponse = new SubscribeResponse();

        $cluster = $this->createPartialCluster();
        $cluster->expects($this->once())
            ->method('request')
            ->with(
                'test-topic',
                self::TOPIC_ACTOR_KIND,
                $this->isInstanceOf(SubscribeRequest::class),
                $grainConfig
            )
            ->willReturn($expectedResponse);

        $result = $cluster->subscribeByPid('test-topic', $pid, $grainConfig);
        self::assertSame($expectedResponse, $result);
    }

    public function testSubscribeByPidReturnsNullOnUnexpectedResponse(): void
    {
        $pid = new Pid(['address' => '127.0.0.1:9000', 'id' => 'actor/1']);

        $cluster = $this->createPartialCluster();
        $cluster->method('request')->willReturn(null);

        $result = $cluster->subscribeByPid('test-topic', $pid);
        self::assertNull($result);
    }

    public function testSubscribeByClusterIdentity(): void
    {
        $clusterIdentity = new ClusterIdentity('user-1', 'UserGrain');
        $expectedResponse = new SubscribeResponse();

        $cluster = $this->createPartialCluster();
        $cluster->expects($this->once())
            ->method('request')
            ->with(
                'events-topic',
                self::TOPIC_ACTOR_KIND,
                $this->callback(function (mixed $msg): bool {
                    return $msg instanceof SubscribeRequest
                        && $msg->getSubscriber()?->getClusterIdentity()?->getIdentity() === 'user-1'
                        && $msg->getSubscriber()?->getClusterIdentity()?->getKind() === 'UserGrain';
                }),
                null
            )
            ->willReturn($expectedResponse);

        $result = $cluster->subscribeByClusterIdentity('events-topic', $clusterIdentity);
        self::assertInstanceOf(SubscribeResponse::class, $result);
    }

    public function testSubscribeWithReceive(): void
    {
        $spawnedPid = new Pid(['address' => '127.0.0.1:8080', 'id' => 'spawned/1']);
        $spawnedRef = new Ref($spawnedPid);

        $rootContext = $this->createMock(ActorSystem\RootContext::class);
        $rootContext->expects($this->once())
            ->method('spawn')
            ->with($this->isInstanceOf(ActorSystem\Props::class))
            ->willReturn($spawnedRef);

        $actorSystem = $this->createMock(ActorSystem::class);
        $actorSystem->method('root')->willReturn($rootContext);

        $expectedResponse = new SubscribeResponse();

        $cluster = $this->createPartialCluster($actorSystem);
        $cluster->expects($this->once())
            ->method('request')
            ->with(
                'test-topic',
                self::TOPIC_ACTOR_KIND,
                $this->callback(function (mixed $msg) use ($spawnedPid): bool {
                    return $msg instanceof SubscribeRequest
                        && $msg->getSubscriber()?->getPid()?->getAddress() === $spawnedPid->getAddress()
                        && $msg->getSubscriber()?->getPid()?->getId() === $spawnedPid->getId();
                }),
                null
            )
            ->willReturn($expectedResponse);

        $receive = new ReceiveFunction(
            static function (ActorSystem\Context\ContextInterface $context): void {
            }
        );
        $result = $cluster->subscribeWithReceive('test-topic', $receive);
        self::assertInstanceOf(SubscribeResponse::class, $result);
    }

    public function testUnsubscribeByPid(): void
    {
        $pid = new Pid(['address' => '127.0.0.1:9000', 'id' => 'actor/1']);
        $expectedResponse = new UnsubscribeResponse();

        $cluster = $this->createPartialCluster();
        $cluster->expects($this->once())
            ->method('request')
            ->with(
                'test-topic',
                self::TOPIC_ACTOR_KIND,
                $this->callback(function (mixed $msg): bool {
                    return $msg instanceof UnsubscribeRequest
                        && $msg->getSubscriber()?->getPid()?->getAddress() === '127.0.0.1:9000'
                        && $msg->getSubscriber()?->getPid()?->getId() === 'actor/1';
                }),
                null
            )
            ->willReturn($expectedResponse);

        $result = $cluster->unsubscribeByPid('test-topic', $pid);
        self::assertInstanceOf(UnsubscribeResponse::class, $result);
        self::assertSame($expectedResponse, $result);
    }

    public function testUnsubscribeByClusterIdentity(): void
    {
        $clusterIdentity = new ClusterIdentity('user-1', 'UserGrain');
        $expectedResponse = new UnsubscribeResponse();

        $cluster = $this->createPartialCluster();
        $cluster->expects($this->once())
            ->method('request')
            ->with(
                'events-topic',
                self::TOPIC_ACTOR_KIND,
                $this->callback(function (mixed $msg): bool {
                    return $msg instanceof UnsubscribeRequest
                        && $msg->getSubscriber()?->getClusterIdentity()?->getIdentity() === 'user-1'
                        && $msg->getSubscriber()?->getClusterIdentity()?->getKind() === 'UserGrain';
                }),
                null
            )
            ->willReturn($expectedResponse);

        $result = $cluster->unsubscribeByClusterIdentity('events-topic', $clusterIdentity);
        self::assertInstanceOf(UnsubscribeResponse::class, $result);
    }

    public function testUnsubscribeByIdentityAndKind(): void
    {
        $expectedResponse = new UnsubscribeResponse();

        $cluster = $this->createPartialCluster();
        $cluster->expects($this->once())
            ->method('request')
            ->with(
                'events-topic',
                self::TOPIC_ACTOR_KIND,
                $this->callback(function (mixed $msg): bool {
                    return $msg instanceof UnsubscribeRequest
                        && $msg->getSubscriber()?->getClusterIdentity()?->getIdentity() === 'order-42'
                        && $msg->getSubscriber()?->getClusterIdentity()?->getKind() === 'OrderGrain';
                }),
                null
            )
            ->willReturn($expectedResponse);

        $result = $cluster->unsubscribeByIdentityAndKind('events-topic', 'order-42', 'OrderGrain');
        self::assertInstanceOf(UnsubscribeResponse::class, $result);
    }

    public function testUnsubscribeByPidReturnsNullOnUnexpectedResponse(): void
    {
        $pid = new Pid(['address' => '127.0.0.1:9000', 'id' => 'actor/1']);

        $cluster = $this->createPartialCluster();
        $cluster->method('request')->willReturn(null);

        $result = $cluster->unsubscribeByPid('test-topic', $pid);
        self::assertNull($result);
    }

    public function testBatchingProducerReturnsInstance(): void
    {
        \Co\run(function () {
            $cluster = $this->createPartialCluster();
            $producer = $cluster->batchingProducer('test-topic');
            self::assertInstanceOf(BatchingProducer::class, $producer);
            $producer->dispose();
        });
    }

    public function testBatchingProducerWithCustomConfig(): void
    {
        \Co\run(function () {
            $config = new BatchingProducerConfig(batchSize: 500);
            $cluster = $this->createPartialCluster();
            $producer = $cluster->batchingProducer('test-topic', $config);
            self::assertInstanceOf(BatchingProducer::class, $producer);
            $producer->dispose();
        });
    }
}
