<?php

declare(strict_types=1);

namespace Phluxor\Cluster\Grain;

use Google\Protobuf\Internal\Message;
use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Message\ActorInterface;
use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\ClusterIdentity;
use Phluxor\Cluster\ProtoBuf\GrainErrorResponse;
use Phluxor\Cluster\ProtoBuf\GrainRequest;
use Phluxor\Cluster\ProtoBuf\GrainResponse;

abstract class GrainBase implements ActorInterface
{
    private ?Cluster $grainCluster = null;

    private ?ClusterIdentity $grainIdentity = null;

    public function receive(ContextInterface $context): void
    {
        $message = $context->message();

        if ($message instanceof ClusterInit) {
            $this->grainCluster = $message->cluster;
            $this->grainIdentity = $message->identity;
            return;
        }

        if ($message instanceof GrainRequest) {
            $this->onGrainRequest($message, $context);
            return;
        }

        $this->onMessage($message, $context);
    }

    abstract protected function onGrainRequest(GrainRequest $request, ContextInterface $context): void;

    protected function onMessage(mixed $message, ContextInterface $context): void
    {
    }

    protected function cluster(): ?Cluster
    {
        return $this->grainCluster;
    }

    protected function identity(): ?ClusterIdentity
    {
        return $this->grainIdentity;
    }

    protected function respondGrain(ContextInterface $context, Message $response): void
    {
        $grainResponse = new GrainResponse();
        $grainResponse->setMessageData($response->serializeToString());
        $grainResponse->setMessageTypeName($response::class);
        $context->respond($grainResponse);
    }

    protected function respondError(ContextInterface $context, string $error, int $code = 0): void
    {
        $grainErrorResponse = new GrainErrorResponse();
        $grainErrorResponse->setError($error);
        $grainErrorResponse->setCode($code);
        $context->respond($grainErrorResponse);
    }
}
