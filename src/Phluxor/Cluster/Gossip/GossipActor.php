<?php

declare(strict_types=1);

namespace Phluxor\Cluster\Gossip;

use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Message\ActorInterface;
use Phluxor\ActorSystem\Message\Started;
use Phluxor\Cluster\ProtoBuf\GossipRequest;
use Phluxor\Cluster\ProtoBuf\GossipResponse;

final class GossipActor implements ActorInterface
{
    public function __construct(
        private readonly GossipRequestHandler $gossiper
    ) {
    }

    public function receive(ContextInterface $context): void
    {
        $message = $context->message();

        if ($message instanceof Started) {
            return;
        }

        if ($message instanceof GossipRequest) {
            $this->onGossipRequest($message, $context);
        }
    }

    private function onGossipRequest(GossipRequest $request, ContextInterface $context): void
    {
        $response = $this->gossiper->handleGossipRequest($request);
        $context->respond($response);
    }
}
