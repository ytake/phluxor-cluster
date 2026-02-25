<?php

declare(strict_types=1);

namespace Test\PubSub;

use PHPUnit\Framework\TestCase;
use Phluxor\ActorSystem\ProtoBuf\Pid;
use Phluxor\Cluster\ProtoBuf\ClusterIdentity;
use Phluxor\Cluster\ProtoBuf\SubscriberIdentity;
use Phluxor\Cluster\PubSub\SubscriberIdentityStruct;

final class SubscriberIdentityStructTest extends TestCase
{
    public function testFromPidSubscriberIdentity(): void
    {
        $pid = new Pid(['address' => 'localhost:8080', 'id' => 'actor-1']);
        $subscriberIdentity = new SubscriberIdentity();
        $subscriberIdentity->setPid($pid);

        $struct = SubscriberIdentityStruct::fromSubscriberIdentity($subscriberIdentity);

        self::assertTrue($struct->isPid());
        self::assertFalse($struct->isClusterIdentity());
    }

    public function testFromClusterIdentitySubscriberIdentity(): void
    {
        $ci = new ClusterIdentity(['identity' => 'user-123', 'kind' => 'UserGrain']);
        $subscriberIdentity = new SubscriberIdentity();
        $subscriberIdentity->setClusterIdentity($ci);

        $struct = SubscriberIdentityStruct::fromSubscriberIdentity($subscriberIdentity);

        self::assertFalse($struct->isPid());
        self::assertTrue($struct->isClusterIdentity());
    }

    public function testToSubscriberIdentityWithPid(): void
    {
        $pid = new Pid(['address' => 'node-1:8080', 'id' => 'actor-42', 'request_id' => 5]);
        $subscriberIdentity = new SubscriberIdentity();
        $subscriberIdentity->setPid($pid);

        $struct = SubscriberIdentityStruct::fromSubscriberIdentity($subscriberIdentity);
        $restored = $struct->toSubscriberIdentity();

        self::assertTrue($restored->hasPid());
        self::assertSame('node-1:8080', $restored->getPid()->getAddress());
        self::assertSame('actor-42', $restored->getPid()->getId());
        self::assertSame(5, $restored->getPid()->getRequestId());
    }

    public function testToSubscriberIdentityWithClusterIdentity(): void
    {
        $ci = new ClusterIdentity(['identity' => 'order-456', 'kind' => 'OrderGrain']);
        $subscriberIdentity = new SubscriberIdentity();
        $subscriberIdentity->setClusterIdentity($ci);

        $struct = SubscriberIdentityStruct::fromSubscriberIdentity($subscriberIdentity);
        $restored = $struct->toSubscriberIdentity();

        self::assertTrue($restored->hasClusterIdentity());
        self::assertSame('order-456', $restored->getClusterIdentity()->getIdentity());
        self::assertSame('OrderGrain', $restored->getClusterIdentity()->getKind());
    }

    public function testToKeyWithPid(): void
    {
        $pid = new Pid(['address' => 'localhost:8080', 'id' => 'actor-1']);
        $subscriberIdentity = new SubscriberIdentity();
        $subscriberIdentity->setPid($pid);

        $struct = SubscriberIdentityStruct::fromSubscriberIdentity($subscriberIdentity);

        self::assertSame('pid:localhost:8080/actor-1', $struct->toKey());
    }

    public function testToKeyWithClusterIdentity(): void
    {
        $ci = new ClusterIdentity(['identity' => 'user-123', 'kind' => 'UserGrain']);
        $subscriberIdentity = new SubscriberIdentity();
        $subscriberIdentity->setClusterIdentity($ci);

        $struct = SubscriberIdentityStruct::fromSubscriberIdentity($subscriberIdentity);

        self::assertSame('ci:user-123/UserGrain', $struct->toKey());
    }

    public function testEqualsSamePid(): void
    {
        $pid1 = new Pid(['address' => 'localhost:8080', 'id' => 'actor-1']);
        $si1 = new SubscriberIdentity();
        $si1->setPid($pid1);

        $pid2 = new Pid(['address' => 'localhost:8080', 'id' => 'actor-1']);
        $si2 = new SubscriberIdentity();
        $si2->setPid($pid2);

        $struct1 = SubscriberIdentityStruct::fromSubscriberIdentity($si1);
        $struct2 = SubscriberIdentityStruct::fromSubscriberIdentity($si2);

        self::assertTrue($struct1->equals($struct2));
    }

    public function testEqualsDifferentPid(): void
    {
        $si1 = new SubscriberIdentity();
        $si1->setPid(new Pid(['address' => 'localhost:8080', 'id' => 'actor-1']));

        $si2 = new SubscriberIdentity();
        $si2->setPid(new Pid(['address' => 'localhost:8080', 'id' => 'actor-2']));

        $struct1 = SubscriberIdentityStruct::fromSubscriberIdentity($si1);
        $struct2 = SubscriberIdentityStruct::fromSubscriberIdentity($si2);

        self::assertFalse($struct1->equals($struct2));
    }

    public function testEqualsSameClusterIdentity(): void
    {
        $si1 = new SubscriberIdentity();
        $si1->setClusterIdentity(new ClusterIdentity(['identity' => 'user-1', 'kind' => 'UserGrain']));

        $si2 = new SubscriberIdentity();
        $si2->setClusterIdentity(new ClusterIdentity(['identity' => 'user-1', 'kind' => 'UserGrain']));

        $struct1 = SubscriberIdentityStruct::fromSubscriberIdentity($si1);
        $struct2 = SubscriberIdentityStruct::fromSubscriberIdentity($si2);

        self::assertTrue($struct1->equals($struct2));
    }

    public function testEqualsPidVsClusterIdentity(): void
    {
        $si1 = new SubscriberIdentity();
        $si1->setPid(new Pid(['address' => 'localhost:8080', 'id' => 'actor-1']));

        $si2 = new SubscriberIdentity();
        $si2->setClusterIdentity(new ClusterIdentity(['identity' => 'user-1', 'kind' => 'UserGrain']));

        $struct1 = SubscriberIdentityStruct::fromSubscriberIdentity($si1);
        $struct2 = SubscriberIdentityStruct::fromSubscriberIdentity($si2);

        self::assertFalse($struct1->equals($struct2));
    }
}
