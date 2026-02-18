<?php

declare(strict_types=1);

namespace Test\Grain;

use PHPUnit\Framework\TestCase;
use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Message\ActorInterface;
use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\ClusterIdentity;
use Phluxor\Cluster\Grain\ClusterInit;
use Phluxor\Cluster\Grain\GrainBase;
use Phluxor\Cluster\ProtoBuf\GrainRequest;

final class GrainContextTest extends TestCase
{
    public function testClusterInitIsValueObject(): void
    {
        $cluster = $this->createMock(Cluster::class);
        $identity = new ClusterIdentity('user-1', 'UserGrain');

        $init = new ClusterInit($cluster, $identity);

        self::assertSame($cluster, $init->cluster);
        self::assertSame($identity, $init->identity);
    }

    public function testGrainBaseReceivesClusterInit(): void
    {
        $grain = new ContextTestGrain();
        $context = $this->createMock(ContextInterface::class);

        $cluster = $this->createMock(Cluster::class);
        $identity = new ClusterIdentity('user-1', 'UserGrain');
        $init = new ClusterInit($cluster, $identity);

        $context->method('message')->willReturn($init);

        $grain->receive($context);

        self::assertSame($cluster, $grain->getCluster());
        self::assertSame($identity, $grain->getIdentity());
    }

    public function testGrainBaseClusterIdentityAvailableDuringGrainRequest(): void
    {
        $grain = new ContextTestGrain();
        $context = $this->createMock(ContextInterface::class);

        // First: send ClusterInit
        $cluster = $this->createMock(Cluster::class);
        $identity = new ClusterIdentity('user-1', 'UserGrain');
        $init = new ClusterInit($cluster, $identity);
        $context->method('message')->willReturn($init);
        $grain->receive($context);

        // Second: send GrainRequest
        $context2 = $this->createMock(ContextInterface::class);
        $request = new GrainRequest();
        $request->setMethodIndex(1);
        $request->setMessageData('test');
        $request->setMessageTypeName('Test');
        $context2->method('message')->willReturn($request);

        $grain->receive($context2);

        self::assertTrue($grain->grainRequestReceived);
        self::assertSame('user-1', $grain->identityDuringRequest);
    }

    public function testGrainBaseWithoutClusterInitReturnsNull(): void
    {
        $grain = new ContextTestGrain();

        self::assertNull($grain->getCluster());
        self::assertNull($grain->getIdentity());
    }
}

/**
 * @internal
 */
final class ContextTestGrain extends GrainBase
{
    public bool $grainRequestReceived = false;
    public ?string $identityDuringRequest = null;

    protected function onGrainRequest(GrainRequest $request, ContextInterface $context): void
    {
        $this->grainRequestReceived = true;
        $this->identityDuringRequest = $this->getIdentity()?->identity();
    }

    public function getCluster(): ?Cluster
    {
        return $this->cluster();
    }

    public function getIdentity(): ?ClusterIdentity
    {
        return $this->identity();
    }
}
