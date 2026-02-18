<?php

declare(strict_types=1);

namespace Phluxor\Cluster\Gossip;

final class GossipKeys
{
    public const string TOPOLOGY = 'topology';

    public const string HEARTBEAT = 'heartbeat';

    public const string GRACEFULLY_LEFT = 'left';

    /** インスタンス化防止 */
    private function __construct()
    {
    }
}
