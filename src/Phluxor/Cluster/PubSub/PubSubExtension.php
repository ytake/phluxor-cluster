<?php

declare(strict_types=1);

namespace Phluxor\Cluster\PubSub;

use Phluxor\ActorSystem\Props;
use Phluxor\Cluster\Cluster;
use Phluxor\Value\ContextExtensionId;
use Phluxor\Value\ExtensionInterface;

final class PubSubExtension implements ExtensionInterface
{
    private const string DELIVERY_ACTOR_NAME = '$pubsub-delivery';
    private const int DEFAULT_SUBSCRIBER_TIMEOUT = 5;

    private static ?ContextExtensionId $extensionIdInstance = null;

    public function __construct(
        private readonly Cluster $cluster,
        private readonly int $subscriberTimeout = self::DEFAULT_SUBSCRIBER_TIMEOUT,
    ) {
    }

    public function extensionID(): ContextExtensionId
    {
        return self::pubSubExtensionId();
    }

    public static function pubSubExtensionId(): ContextExtensionId
    {
        if (self::$extensionIdInstance === null) {
            self::$extensionIdInstance = new ContextExtensionId();
        }
        return self::$extensionIdInstance;
    }

    public function start(): void
    {
        $timeout = $this->subscriberTimeout;
        $props = Props::fromProducer(
            fn() => new PubSubMemberDeliveryActor($timeout)
        );
        $this->cluster->actorSystem()->root()->spawnNamed($props, self::DELIVERY_ACTOR_NAME);
    }

    public function publisher(): PublisherInterface
    {
        return new Publisher($this->cluster);
    }

    public function subscriberTimeout(): int
    {
        return $this->subscriberTimeout;
    }
}
