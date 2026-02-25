<?php

declare(strict_types=1);

namespace Test\PartitionIdentity;

use Phluxor\ActorSystem;
use Phluxor\ActorSystem\Future;
use Phluxor\ActorSystem\FutureResult;
use Phluxor\ActorSystem\ProtoBuf\Pid;
use Phluxor\ActorSystem\Ref;
use Phluxor\ActorSystem\RootContext;
use Phluxor\ActorSystem\SpawnResult;
use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\ClusterConfig;
use Phluxor\Cluster\ClusterIdentity;
use Phluxor\Cluster\ClusterProviderInterface;
use Phluxor\Cluster\ClusterTopologyEvent;
use Phluxor\Cluster\IdentityLookupInterface;
use Phluxor\Cluster\KindRegistry;
use Phluxor\Cluster\Member;
use Phluxor\Cluster\PartitionIdentity\PartitionManager;
use Phluxor\Cluster\ProtoBuf\ActivationTerminated;
use Phluxor\EventStream\EventStream;
use Phluxor\EventStream\Subscription;
use PHPUnit\Framework\TestCase;

final class PartitionManagerTest extends TestCase
{
    public function testRemovePidSendsActivationTerminatedToOwnerNode(): void
    {
        $pid = new Pid();
        $pid->setAddress('127.0.0.1:8080');
        $pid->setId('test-actor-1');
        $ref = new Ref($pid);

        $clusterIdentity = new ClusterIdentity('user-1', 'UserGrain');

        $rootContext = $this->createMock(RootContext::class);
        $rootContext->expects(self::once())
            ->method('send')
            ->with(
                self::callback(function (Ref $target): bool {
                    return $target->protobufPid()->getId() === 'partition-activator';
                }),
                self::callback(function (ActivationTerminated $msg) use ($pid): bool {
                    $ci = $msg->getClusterIdentity();
                    return $ci !== null
                        && $ci->getIdentity() === 'user-1'
                        && $ci->getKind() === 'UserGrain'
                        && $msg->getPid() !== null
                        && $msg->getPid()->getId() === $pid->getId()
                        && $msg->getPid()->getAddress() === $pid->getAddress();
                })
            );

        $placementPid = new Pid();
        $placementPid->setAddress('127.0.0.1:8080');
        $placementPid->setId('partition-activator');
        $placementRef = new Ref($placementPid);
        $spawnResult = new SpawnResult($placementRef, null);

        $rootContext->method('spawnNamed')->willReturn($spawnResult);

        $eventStream = $this->createMock(EventStream::class);

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

        $manager = new PartitionManager($cluster);
        $manager->start();

        $manager->removePid($clusterIdentity, $ref);
    }

    public function testRemovePidDoesNothingBeforeStart(): void
    {
        $cluster = $this->createMock(Cluster::class);
        $manager = new PartitionManager($cluster);

        $pid = new Pid();
        $pid->setAddress('127.0.0.1:8080');
        $pid->setId('test-actor');
        $ref = new Ref($pid);

        $clusterIdentity = new ClusterIdentity('user-1', 'UserGrain');

        // Should not throw
        $manager->removePid($clusterIdentity, $ref);
        self::assertTrue(true);
    }

    public function testClusterTopologyEventIsForwardedToPlacementActor(): void
    {
        $placementPid = new Pid();
        $placementPid->setAddress('127.0.0.1:8080');
        $placementPid->setId('partition-activator');
        $placementRef = new Ref($placementPid);
        $spawnResult = new SpawnResult($placementRef, null);

        $topologyHash = 12345678;
        $member = new Member('127.0.0.1', 8080, 'node-1', ['UserGrain']);
        $event = new ClusterTopologyEvent(
            topologyHash: $topologyHash,
            members: [$member],
            joined: [$member],
            left: [],
            blocked: [],
        );

        // ClusterTopologyEvent が EventStream 経由で来た時、
        // PartitionManager は PlacementActor に対して send() を呼ぶことを確認する（T-1修正）
        $rootContext = $this->createMock(RootContext::class);
        $rootContext->method('spawnNamed')->willReturn($spawnResult);
        $rootContext->expects(self::once())
            ->method('send')
            ->with(
                self::callback(fn(Ref $ref): bool => $ref->protobufPid()->getId() === 'partition-activator'),
                self::callback(fn(ClusterTopologyEvent $e): bool => $e->topologyHash() === $topologyHash)
            );

        $subscription = $this->createMock(Subscription::class);
        $eventStream = $this->createMock(EventStream::class);
        // subscribe のコールバックを保持して手動でトリガーできるようにする
        $capturedCallback = null;
        $eventStream->method('subscribe')
            ->willReturnCallback(function (callable $cb) use (&$capturedCallback, $subscription): Subscription {
                $capturedCallback = $cb;
                return $subscription;
            });

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

        $manager = new PartitionManager($cluster);
        $manager->start();

        // subscribe で登録されたコールバックを手動でトリガーし、
        // PlacementActor への send() が行われることを検証する
        self::assertNotNull($capturedCallback);
        ($capturedCallback)($event);
    }

    public function testStartAsClientDoesNotSpawnPlacementActorButCanRemovePid(): void
    {
        $rootContext = $this->createMock(RootContext::class);
        $rootContext->expects(self::never())
            ->method('spawnNamed');
        $rootContext->expects(self::once())
            ->method('send')
            ->with(
                self::callback(function (Ref $target): bool {
                    return $target->protobufPid()->getAddress() === '127.0.0.1:8080'
                        && $target->protobufPid()->getId() === 'partition-activator';
                }),
                self::callback(function (ActivationTerminated $msg): bool {
                    $ci = $msg->getClusterIdentity();
                    return $ci !== null
                        && $ci->getIdentity() === 'user-1'
                        && $ci->getKind() === 'UserGrain';
                })
            );

        $subscription = $this->createMock(Subscription::class);
        $eventStream = $this->createMock(EventStream::class);
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

        $pid = new Pid();
        $pid->setAddress('127.0.0.1:8080');
        $pid->setId('test-actor-1');
        $ref = new Ref($pid);
        $clusterIdentity = new ClusterIdentity('user-1', 'UserGrain');

        $manager = new PartitionManager($cluster);
        $manager->start(true);
        $manager->removePid($clusterIdentity, $ref);
    }

    public function testGetReturnsNullWhenActivationResponseTopologyHashMismatch(): void
    {
        $capturedCallback = null;
        $subscription = $this->createMock(Subscription::class);
        $eventStream = $this->createMock(EventStream::class);
        $eventStream->method('subscribe')
            ->willReturnCallback(function (callable $cb) use (&$capturedCallback, $subscription): Subscription {
                $capturedCallback = $cb;
                return $subscription;
            });

        $responsePid = new Pid();
        $responsePid->setAddress('127.0.0.1:8081');
        $responsePid->setId('user-1$01');

        $activationResponse = new \Phluxor\Cluster\ProtoBuf\ActivationResponse();
        $activationResponse->setPid($responsePid);
        $activationResponse->setTopologyHash(9999); // mismatch

        $future = $this->createMock(Future::class);
        $future->method('result')->willReturn(new FutureResult($activationResponse, null));

        $rootContext = $this->createMock(RootContext::class);
        $rootContext->expects(self::never())->method('spawnNamed');
        $rootContext->expects(self::once())
            ->method('requestFuture')
            ->with(
                self::callback(fn(Ref $target): bool => $target->protobufPid()->getAddress() === '127.0.0.1:8081'),
                self::callback(fn(mixed $msg): bool => $msg instanceof \Phluxor\Cluster\ProtoBuf\ActivationRequest
                    && $msg->getTopologyHash() === 1234),
                5
            )
            ->willReturn($future);

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

        $manager = new PartitionManager($cluster);
        $manager->start(true);

        self::assertNotNull($capturedCallback);
        ($capturedCallback)(new ClusterTopologyEvent(
            topologyHash: 1234,
            members: [new Member('127.0.0.1', 8081, 'node-1', ['UserGrain'])],
            joined: [],
            left: [],
            blocked: [],
        ));

        $result = $manager->get(new ClusterIdentity('user-1', 'UserGrain'));
        self::assertNull($result);
    }
}
