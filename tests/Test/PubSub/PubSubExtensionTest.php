<?php

declare(strict_types=1);

namespace Test\PubSub;

use Phluxor\ActorSystem;
use Phluxor\ActorSystem\Ref;
use Phluxor\ActorSystem\SpawnResult;
use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\PubSub\Publisher;
use Phluxor\Cluster\PubSub\PublisherInterface;
use Phluxor\Cluster\PubSub\PubSubExtension;
use Phluxor\Value\ExtensionInterface;
use PHPUnit\Framework\TestCase;

final class PubSubExtensionTest extends TestCase
{
    public function testImplementsExtensionInterface(): void
    {
        $cluster = $this->createMock(Cluster::class);
        $ext = new PubSubExtension($cluster);
        self::assertInstanceOf(ExtensionInterface::class, $ext);
    }

    public function testExtensionIdIsSingleton(): void
    {
        $cluster = $this->createMock(Cluster::class);
        $ext1 = new PubSubExtension($cluster);
        $ext2 = new PubSubExtension($cluster);
        self::assertSame(
            PubSubExtension::pubSubExtensionId()->value(),
            $ext1->extensionID()->value()
        );
        self::assertSame(
            $ext1->extensionID()->value(),
            $ext2->extensionID()->value()
        );
    }

    public function testPublisherReturnsPublisherInterface(): void
    {
        $cluster = $this->createMock(Cluster::class);
        $ext = new PubSubExtension($cluster);
        $publisher = $ext->publisher();
        self::assertInstanceOf(PublisherInterface::class, $publisher);
        self::assertInstanceOf(Publisher::class, $publisher);
    }

    public function testStartSpawnsDeliveryActor(): void
    {
        $ref = $this->createMock(Ref::class);
        $spawnResult = new SpawnResult($ref, null);

        $rootContext = $this->createMock(ActorSystem\RootContext::class);
        $rootContext->expects($this->once())
            ->method('spawnNamed')
            ->with(
                $this->isInstanceOf(ActorSystem\Props::class),
                '$pubsub-delivery'
            )
            ->willReturn($spawnResult);

        $actorSystem = $this->createMock(ActorSystem::class);
        $actorSystem->method('root')->willReturn($rootContext);

        $cluster = $this->createMock(Cluster::class);
        $cluster->method('actorSystem')->willReturn($actorSystem);

        $ext = new PubSubExtension($cluster);
        $ext->start();
    }

    public function testDefaultSubscriberTimeout(): void
    {
        $cluster = $this->createMock(Cluster::class);
        $ext = new PubSubExtension($cluster);
        self::assertSame(5, $ext->subscriberTimeout());
    }

    public function testCustomSubscriberTimeout(): void
    {
        $cluster = $this->createMock(Cluster::class);
        $ext = new PubSubExtension($cluster, subscriberTimeout: 10);
        self::assertSame(10, $ext->subscriberTimeout());
    }
}
