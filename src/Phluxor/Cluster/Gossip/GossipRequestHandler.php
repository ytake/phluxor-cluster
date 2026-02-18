<?php

declare(strict_types=1);

namespace Phluxor\Cluster\Gossip;

use Phluxor\Cluster\ProtoBuf\GossipRequest;
use Phluxor\Cluster\ProtoBuf\GossipResponse;

interface GossipRequestHandler
{
    public function handleGossipRequest(GossipRequest $request): GossipResponse;
}
