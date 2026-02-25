<?php

declare(strict_types=1);

namespace Test\PartitionIdentity;

use Phluxor\ActorSystem;
use Phluxor\ActorSystem\ProtoBuf\Pid;
use Phluxor\ActorSystem\Ref;
use Phluxor\ActorSystem\RootContext;
use Phluxor\ActorSystem\SpawnResult;
use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\ClusterConfig;
use Phluxor\Cluster\ClusterIdentity;
use Phluxor\Cluster\ClusterProviderInterface;
use Phluxor\Cluster\IdentityLookupInterface;
use Phluxor\Cluster\KindRegistry;
use Phluxor\Cluster\PartitionIdentity\PartitionIdentityLookup;
use Phluxor\EventStream\EventStream;
use Phluxor\EventStream\Subscription;
use PHPUnit\Framework\TestCase;

final class PartitionIdentityLookupTest extends TestCase
{
    public function testImplementsIdentityLookupInterface(): void
    {
        $lookup = new PartitionIdentityLookup();
        self::assertInstanceOf(IdentityLookupInterface::class, $lookup);
    }

    public function testGetReturnsNullBeforeSetup(): void
    {
        $lookup = new PartitionIdentityLookup();
        $identity = new ClusterIdentity('user-1', 'UserGrain');
        self::assertNull($lookup->get($identity));
    }

    public function testRemovePidBeforeSetupDoesNotThrow(): void
    {
        $lookup = new PartitionIdentityLookup();
        $identity = new ClusterIdentity('user-1', 'UserGrain');
        $ref = $this->createMock(Ref::class);

        $lookup->removePid($identity, $ref);
        self::assertTrue(true);
    }

    public function testShutdownBeforeSetupDoesNotThrow(): void
    {
        $lookup = new PartitionIdentityLookup();
        $lookup->shutdown();
        self::assertTrue(true);
    }

    public function testSetupAsClientDoesNotSpawnPlacementActor(): void
    {
        $rootContext = $this->createMock(RootContext::class);
        // クライアントモードでは spawnNamed が呼ばれてはいけない
        $rootContext->expects(self::never())
            ->method('spawnNamed');

        $subscription = $this->createMock(Subscription::class);
        $eventStream = $this->createMock(EventStream::class);
        $eventStream->expects(self::once())
            ->method('subscribe')
            ->willReturn($subscription);

        $actorSystem = $this->createMock(ActorSystem::class);
        $actorSystem->method('root')->willReturn($rootContext);
        $actorSystem->method('getEventStream')->willReturn($eventStream);

        $provider = $this->createMock(ClusterProviderInterface::class);
        $config = new ClusterConfig(
            name: 'test-cluster',
            host: '127.0.0.1',
            port: 8080,
            clusterProvider: $provider,
            identityLookup: $this->createMock(IdentityLookupInterface::class),
            kindRegistry: new KindRegistry(),
            requestTimeoutSeconds: 5,
        );

        $cluster = $this->createMock(Cluster::class);
        $cluster->method('actorSystem')->willReturn($actorSystem);
        $cluster->method('config')->willReturn($config);

        $lookup = new PartitionIdentityLookup();
        // isClient=true: PlacementActorをスポーンしない
        $lookup->setup($cluster, [], true);

        // トポロジー未受信時は解決先が無いため null を返す
        $identity = new ClusterIdentity('user-1', 'UserGrain');
        self::assertNull($lookup->get($identity));
    }

    public function testSetupAsMemberSpawnsPlacementActor(): void
    {
        $placementPid = new Pid();
        $placementPid->setAddress('127.0.0.1:8080');
        $placementPid->setId('partition-activator');
        $placementRef = new Ref($placementPid);
        $spawnResult = new SpawnResult($placementRef, null);

        $rootContext = $this->createMock(RootContext::class);
        // メンバーモードでは spawnNamed が一度呼ばれる
        $rootContext->expects(self::once())
            ->method('spawnNamed')
            ->willReturn($spawnResult);

        $subscription = $this->createMock(Subscription::class);
        $eventStream = $this->createMock(EventStream::class);
        // PartitionManager::start() が EventStream::subscribe() を呼ぶため、
        // Subscription を返すよう設定する（T-3: モック戻り値漏れの修正）
        $eventStream->method('subscribe')->willReturn($subscription);

        $actorSystem = $this->createMock(ActorSystem::class);
        $actorSystem->method('root')->willReturn($rootContext);
        $actorSystem->method('getEventStream')->willReturn($eventStream);

        $provider = $this->createMock(ClusterProviderInterface::class);
        $config = new ClusterConfig(
            name: 'test-cluster',
            host: '127.0.0.1',
            port: 8080,
            clusterProvider: $provider,
            identityLookup: $this->createMock(IdentityLookupInterface::class),
            kindRegistry: new KindRegistry(),
            requestTimeoutSeconds: 5,
        );

        $cluster = $this->createMock(Cluster::class);
        $cluster->method('actorSystem')->willReturn($actorSystem);
        $cluster->method('config')->willReturn($config);

        $lookup = new PartitionIdentityLookup();
        // isClient=false: PlacementActorをスポーンする
        $lookup->setup($cluster, ['UserGrain'], false);
    }
}
