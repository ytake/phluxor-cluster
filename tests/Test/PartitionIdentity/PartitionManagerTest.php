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
use Phluxor\Cluster\KindRegistry;
use Phluxor\Cluster\Member;
use Phluxor\Cluster\PartitionIdentity\PartitionManager;
use Phluxor\Cluster\ProtoBuf\ActivationTerminated;
use Phluxor\EventStream\EventStream;
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
            identityLookup: $this->createMock(\Phluxor\Cluster\IdentityLookupInterface::class),
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
}
