<?php

declare(strict_types=1);

namespace Phluxor\Cluster;

use Phluxor\Value\ContextExtensionId;
use Phluxor\Value\ExtensionInterface;

final class ClusterExtension implements ExtensionInterface
{
    private static ?ContextExtensionId $extensionIdInstance = null;

    public function __construct(
        private readonly Cluster $cluster
    ) {
    }

    public function cluster(): Cluster
    {
        return $this->cluster;
    }

    public function extensionID(): ContextExtensionId
    {
        return self::clusterExtensionId();
    }

    public static function clusterExtensionId(): ContextExtensionId
    {
        if (self::$extensionIdInstance === null) {
            self::$extensionIdInstance = new ContextExtensionId();
        }

        return self::$extensionIdInstance;
    }
}
