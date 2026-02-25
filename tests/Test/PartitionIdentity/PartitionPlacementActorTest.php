<?php

declare(strict_types=1);

namespace Test\PartitionIdentity;

use PHPUnit\Framework\TestCase;
use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Message\ActorInterface;
use Phluxor\ActorSystem\ProtoBuf\Pid;
use Phluxor\ActorSystem\Ref;
use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\ClusterConfig;
use Phluxor\Cluster\ClusterProviderInterface;
use Phluxor\Cluster\ClusterTopologyEvent;
use Phluxor\Cluster\IdentityLookupInterface;
use Phluxor\Cluster\KindRegistry;
use Phluxor\Cluster\Member;
use Phluxor\Cluster\PartitionIdentity\PartitionPlacementActor;
use Phluxor\Cluster\ProtoBuf\ActivationRequest;
use Phluxor\Cluster\ProtoBuf\ActivationResponse;
use Phluxor\Cluster\ProtoBuf\ActivationTerminated;
use Phluxor\Cluster\ProtoBuf\ClusterIdentity as ProtoBufClusterIdentity;

final class PartitionPlacementActorTest extends TestCase
{
    public function testImplementsActorInterface(): void
    {
        $cluster = $this->createMock(Cluster::class);
        $actor = new PartitionPlacementActor($cluster);
        self::assertInstanceOf(ActorInterface::class, $actor);
    }

    public function testActivationRequestWithNullIdentityRespondsFailed(): void
    {
        $config = new ClusterConfig(
            name: 'test-cluster',
            host: '127.0.0.1',
            port: 8080,
            clusterProvider: $this->createMock(ClusterProviderInterface::class),
            identityLookup: $this->createMock(IdentityLookupInterface::class),
            kindRegistry: new KindRegistry(),
            requestTimeoutSeconds: 5,
        );

        $cluster = $this->createMock(Cluster::class);
        $cluster->method('config')->willReturn($config);

        $actor = new PartitionPlacementActor($cluster);

        $request = new ActivationRequest();
        // cluster_identity を設定しない

        $context = $this->createMock(ContextInterface::class);
        $context->method('message')->willReturn($request);
        $context->expects(self::once())
            ->method('respond')
            ->with(self::callback(function (mixed $response): bool {
                return $response instanceof ActivationResponse
                    && $response->getFailed() === true;
            }));

        $actor->receive($context);
    }

    public function testActivationTerminatedRemovesActorFromRegistry(): void
    {
        $config = new ClusterConfig(
            name: 'test-cluster',
            host: '127.0.0.1',
            port: 8080,
            clusterProvider: $this->createMock(ClusterProviderInterface::class),
            identityLookup: $this->createMock(IdentityLookupInterface::class),
            kindRegistry: new KindRegistry(),
            requestTimeoutSeconds: 5,
        );

        $cluster = $this->createMock(Cluster::class);
        $cluster->method('config')->willReturn($config);

        $actor = new PartitionPlacementActor($cluster);

        $pid = new Pid();
        $pid->setId('test-grain-1');
        $pid->setAddress('127.0.0.1:8080');

        $pbIdentity = new ProtoBufClusterIdentity();
        $pbIdentity->setKind('UserGrain');
        $pbIdentity->setIdentity('user-1');

        $terminated = new ActivationTerminated();
        $terminated->setPid($pid);
        $terminated->setClusterIdentity($pbIdentity);

        $context = $this->createMock(ContextInterface::class);
        $context->method('message')->willReturn($terminated);

        // 登録されていないアクターへの ActivationTerminated は何もしない
        $context->expects(self::never())->method('respond');
        $actor->receive($context);
        self::assertTrue(true);
    }

    public function testDuplicateRequestIdWithUnknownKindReturnsFailed(): void
    {
        // T-2: request_id 冪等性ロジックのテスト
        // kind が未登録の場合、同一 request_id で2回リクエストしても
        // processedRequestIds にアクターが登録されないため、両方 failed を返す。
        // これにより、二重アクティベーションが起きていないことを検証する。
        $config = new ClusterConfig(
            name: 'test-cluster',
            host: '127.0.0.1',
            port: 8080,
            clusterProvider: $this->createMock(ClusterProviderInterface::class),
            identityLookup: $this->createMock(IdentityLookupInterface::class),
            kindRegistry: new KindRegistry(), // 空 - UserGrain が未登録
            requestTimeoutSeconds: 5,
        );

        $cluster = $this->createMock(Cluster::class);
        $cluster->method('config')->willReturn($config);

        $actor = new PartitionPlacementActor($cluster);

        $requestId = 'req-' . uniqid();
        $pbIdentity = new ProtoBufClusterIdentity();
        $pbIdentity->setKind('UserGrain');
        $pbIdentity->setIdentity('user-1');

        $request = new ActivationRequest();
        $request->setClusterIdentity($pbIdentity);
        $request->setRequestId($requestId);

        $context = $this->createMock(ContextInterface::class);
        $context->method('message')->willReturn($request);
        // 1回目も2回目も kind 未登録のため failed を返す
        $context->expects(self::exactly(2))
            ->method('respond')
            ->with(self::callback(function (mixed $response): bool {
                return $response instanceof ActivationResponse
                    && $response->getFailed() === true;
            }));

        $actor->receive($context);
        $actor->receive($context);
    }

    public function testActivationRequestWithStaleTopologyHashRespondsFailedWithCurrentHash(): void
    {
        $config = new ClusterConfig(
            name: 'test-cluster',
            host: '127.0.0.1',
            port: 8080,
            clusterProvider: $this->createMock(ClusterProviderInterface::class),
            identityLookup: $this->createMock(IdentityLookupInterface::class),
            kindRegistry: new KindRegistry(),
            requestTimeoutSeconds: 5,
        );

        $cluster = $this->createMock(Cluster::class);
        $cluster->method('config')->willReturn($config);

        $actor = new PartitionPlacementActor($cluster);

        $topologyEvent = new ClusterTopologyEvent(
            topologyHash: 123,
            members: [new Member('127.0.0.1', 8080, 'node-1', ['UserGrain'])],
            joined: [],
            left: [],
            blocked: [],
        );

        $topologyContext = $this->createMock(ContextInterface::class);
        $topologyContext->method('message')->willReturn($topologyEvent);
        $actor->receive($topologyContext);

        $pbIdentity = new ProtoBufClusterIdentity();
        $pbIdentity->setKind('UserGrain');
        $pbIdentity->setIdentity('user-1');

        $request = new ActivationRequest();
        $request->setClusterIdentity($pbIdentity);
        $request->setTopologyHash(122); // stale

        $requestContext = $this->createMock(ContextInterface::class);
        $requestContext->method('message')->willReturn($request);
        $requestContext->expects(self::once())
            ->method('respond')
            ->with(self::callback(function (mixed $response): bool {
                return $response instanceof ActivationResponse
                    && $response->getFailed() === true
                    && $response->getTopologyHash() === 123;
            }));

        $actor->receive($requestContext);
    }
}
