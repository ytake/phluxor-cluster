<?php

declare(strict_types=1);

namespace Test\PubSub;

use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Message\ReceiveFunction;
use Phluxor\ActorSystem\Props;
use Phluxor\Cluster\ActivatedKind;
use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\KindRegistry;
use Phluxor\Cluster\PubSub\TopicActor;
use PHPUnit\Framework\TestCase;

final class ClusterPubSubIntegrationTest extends TestCase
{
    private const string TOPIC_ACTOR_KIND = 'prototopic';

    public function testEnsureTopicKindRegisteredAddsKindWhenMissing(): void
    {
        $registry = new KindRegistry();
        self::assertFalse($registry->has(self::TOPIC_ACTOR_KIND));

        Cluster::ensureTopicKindRegistered($registry);

        self::assertTrue($registry->has(self::TOPIC_ACTOR_KIND));
        $kind = $registry->find(self::TOPIC_ACTOR_KIND);
        self::assertNotNull($kind);
        self::assertSame(self::TOPIC_ACTOR_KIND, $kind->kind());
    }

    public function testEnsureTopicKindRegisteredDoesNotOverrideExisting(): void
    {
        $customProps = Props::fromFunction(
            new ReceiveFunction(
                static function (ContextInterface $context): void {
                }
            )
        );
        $customKind = new ActivatedKind(self::TOPIC_ACTOR_KIND, $customProps);

        $registry = new KindRegistry($customKind);
        self::assertTrue($registry->has(self::TOPIC_ACTOR_KIND));

        Cluster::ensureTopicKindRegistered($registry);

        $found = $registry->find(self::TOPIC_ACTOR_KIND);
        self::assertSame($customKind, $found);
    }

    public function testEnsureTopicKindRegisteredPreservesOtherKinds(): void
    {
        $otherProps = Props::fromFunction(
            new ReceiveFunction(
                static function (ContextInterface $context): void {
                }
            )
        );
        $otherKind = new ActivatedKind('UserGrain', $otherProps);

        $registry = new KindRegistry($otherKind);

        Cluster::ensureTopicKindRegistered($registry);

        self::assertTrue($registry->has('UserGrain'));
        self::assertTrue($registry->has(self::TOPIC_ACTOR_KIND));
        self::assertSame($otherKind, $registry->find('UserGrain'));
    }
}
