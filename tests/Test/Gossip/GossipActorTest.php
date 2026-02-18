<?php

declare(strict_types=1);

namespace Test\Gossip;

use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Message\Started;
use Phluxor\Cluster\Gossip\GossipActor;
use Phluxor\Cluster\Gossip\GossipRequestHandler;
use Phluxor\Cluster\ProtoBuf\GossipKeyValue;
use Phluxor\Cluster\ProtoBuf\GossipMemberState;
use Phluxor\Cluster\ProtoBuf\GossipRequest;
use Phluxor\Cluster\ProtoBuf\GossipResponse;
use Phluxor\Cluster\ProtoBuf\GossipState;
use PHPUnit\Framework\TestCase;

final class GossipActorTest extends TestCase
{
    public function testHandlesStartedMessage(): void
    {
        $gossiper = $this->createMock(GossipRequestHandler::class);
        $gossiper->expects(self::never())->method('handleGossipRequest');

        $context = $this->createMock(ContextInterface::class);
        $context->method('message')->willReturn(new Started());

        $actor = new GossipActor($gossiper);
        $actor->receive($context);
    }

    public function testHandlesGossipRequest(): void
    {
        $request = new GossipRequest();
        $request->setMemberId('remote-member');
        $request->setState(new GossipState());

        $expectedResponse = new GossipResponse();
        $expectedResponse->setState(new GossipState());

        $gossiper = $this->createMock(GossipRequestHandler::class);
        $gossiper->expects(self::once())
            ->method('handleGossipRequest')
            ->with($request)
            ->willReturn($expectedResponse);

        $context = $this->createMock(ContextInterface::class);
        $context->method('message')->willReturn($request);
        $context->expects(self::once())->method('respond')->with($expectedResponse);

        $actor = new GossipActor($gossiper);
        $actor->receive($context);
    }

    public function testIgnoresUnknownMessages(): void
    {
        $gossiper = $this->createMock(GossipRequestHandler::class);
        $gossiper->expects(self::never())->method('handleGossipRequest');

        $context = $this->createMock(ContextInterface::class);
        $context->method('message')->willReturn('unknown-message');
        $context->expects(self::never())->method('respond');

        $actor = new GossipActor($gossiper);
        $actor->receive($context);
    }
}
