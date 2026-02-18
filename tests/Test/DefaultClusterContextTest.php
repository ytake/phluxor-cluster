<?php

declare(strict_types=1);

namespace Test;

use Phluxor\ActorSystem;
use Phluxor\ActorSystem\Exception\FutureTimeoutException;
use Phluxor\ActorSystem\Future;
use Phluxor\ActorSystem\FutureResult;
use Phluxor\ActorSystem\ProtoBuf\Pid;
use Phluxor\ActorSystem\Ref;
use Phluxor\ActorSystem\RootContext;
use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\ClusterConfig;
use Phluxor\Cluster\ClusterContextInterface;
use Phluxor\Cluster\ClusterIdentity;
use Phluxor\Cluster\ClusterProviderInterface;
use Phluxor\Cluster\DefaultClusterContext;
use Phluxor\Cluster\GrainCallConfig;
use Phluxor\Cluster\IdentityLookupInterface;
use Phluxor\Cluster\KindRegistry;
use Phluxor\Cluster\PidCache;
use PHPUnit\Framework\TestCase;

final class DefaultClusterContextTest extends TestCase
{
    public function testImplementsClusterContextInterface(): void
    {
        $cluster = $this->createMock(Cluster::class);
        $ctx = new DefaultClusterContext($cluster);
        self::assertInstanceOf(ClusterContextInterface::class, $ctx);
    }

    public function testRequestReturnsNullWhenPidNotResolved(): void
    {
        $pidCache = new PidCache();

        $identityLookup = $this->createMock(IdentityLookupInterface::class);
        $identityLookup->method('get')->willReturn(null);

        $config = $this->createClusterConfig($identityLookup);
        $cluster = $this->createMock(Cluster::class);
        $cluster->method('pidCache')->willReturn($pidCache);
        $cluster->method('config')->willReturn($config);

        $ctx = new DefaultClusterContext($cluster);
        $result = $ctx->request('user-1', 'UserGrain', 'hello', new GrainCallConfig(retryCount: 1));

        self::assertNull($result);
    }

    public function testRequestReturnsCachedPidResult(): void
    {
        $pid = new Pid();
        $pid->setAddress('localhost:8080');
        $pid->setId('test-actor');
        $ref = new Ref($pid);

        $pidCache = new PidCache();
        $pidCache->set(new ClusterIdentity('user-1', 'UserGrain'), $ref);

        $future = $this->createMock(Future::class);
        $future->method('result')->willReturn(new FutureResult('response-data'));

        $rootContext = $this->createMock(RootContext::class);
        $rootContext->method('requestFuture')->willReturn($future);

        $actorSystem = $this->createMock(ActorSystem::class);
        $actorSystem->method('root')->willReturn($rootContext);

        $identityLookup = $this->createMock(IdentityLookupInterface::class);
        $config = $this->createClusterConfig($identityLookup);

        $cluster = $this->createMock(Cluster::class);
        $cluster->method('pidCache')->willReturn($pidCache);
        $cluster->method('config')->willReturn($config);
        $cluster->method('actorSystem')->willReturn($actorSystem);

        $ctx = new DefaultClusterContext($cluster);
        $result = $ctx->request('user-1', 'UserGrain', 'hello');

        self::assertSame('response-data', $result);
    }

    public function testRequestFallsBackToIdentityLookup(): void
    {
        $pid = new Pid();
        $pid->setAddress('localhost:8080');
        $pid->setId('test-actor');
        $ref = new Ref($pid);

        $pidCache = new PidCache();

        $identityLookup = $this->createMock(IdentityLookupInterface::class);
        $identityLookup->method('get')->willReturn($ref);

        $future = $this->createMock(Future::class);
        $future->method('result')->willReturn(new FutureResult('lookup-response'));

        $rootContext = $this->createMock(RootContext::class);
        $rootContext->method('requestFuture')->willReturn($future);

        $actorSystem = $this->createMock(ActorSystem::class);
        $actorSystem->method('root')->willReturn($rootContext);

        $config = $this->createClusterConfig($identityLookup);

        $cluster = $this->createMock(Cluster::class);
        $cluster->method('pidCache')->willReturn($pidCache);
        $cluster->method('config')->willReturn($config);
        $cluster->method('actorSystem')->willReturn($actorSystem);

        $ctx = new DefaultClusterContext($cluster);
        $result = $ctx->request('user-1', 'UserGrain', 'hello');

        self::assertSame('lookup-response', $result);

        $cached = $pidCache->get(new ClusterIdentity('user-1', 'UserGrain'));
        self::assertNotNull($cached);
    }

    public function testRequestRetriesOnTimeout(): void
    {
        $pid = new Pid();
        $pid->setAddress('localhost:8080');
        $pid->setId('test-actor');
        $ref = new Ref($pid);

        $pidCache = new PidCache();
        $pidCache->set(new ClusterIdentity('user-1', 'UserGrain'), $ref);

        $identityLookup = $this->createMock(IdentityLookupInterface::class);
        $identityLookup->method('get')->willReturn($ref);
        $identityLookup->expects(self::exactly(2))->method('removePid');

        $future = $this->createMock(Future::class);
        $future->method('result')->willReturn(
            new FutureResult(null, new FutureTimeoutException('timeout'))
        );

        $rootContext = $this->createMock(RootContext::class);
        $rootContext->method('requestFuture')->willReturn($future);

        $actorSystem = $this->createMock(ActorSystem::class);
        $actorSystem->method('root')->willReturn($rootContext);

        $config = $this->createClusterConfig($identityLookup);

        $cluster = $this->createMock(Cluster::class);
        $cluster->method('pidCache')->willReturn($pidCache);
        $cluster->method('config')->willReturn($config);
        $cluster->method('actorSystem')->willReturn($actorSystem);

        $ctx = new DefaultClusterContext($cluster);
        $result = $ctx->request('user-1', 'UserGrain', 'hello', new GrainCallConfig(retryCount: 2));

        self::assertNull($result);
    }

    private function createClusterConfig(IdentityLookupInterface $identityLookup): ClusterConfig
    {
        $provider = $this->createMock(ClusterProviderInterface::class);
        return new ClusterConfig(
            name: 'test-cluster',
            host: 'localhost',
            port: 8080,
            clusterProvider: $provider,
            identityLookup: $identityLookup,
            kindRegistry: new KindRegistry(),
            requestTimeoutSeconds: 5
        );
    }
}
