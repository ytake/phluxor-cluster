<?php

declare(strict_types=1);

namespace Phluxor\Cluster\Grain;

use Google\Protobuf\Internal\Message;
use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\Exception\GrainCallException;
use Phluxor\Cluster\GrainCallConfig;
use Phluxor\Cluster\ProtoBuf\GrainErrorResponse;
use Phluxor\Cluster\ProtoBuf\GrainRequest;
use Phluxor\Cluster\ProtoBuf\GrainResponse;

abstract class GrainClient
{
    public function __construct(
        protected readonly Cluster $cluster,
        protected readonly string $identity,
        protected readonly string $kind,
    ) {
    }

    /**
     * @throws GrainCallException
     */
    protected function callGrain(int $methodIndex, Message $request): ?GrainResponse
    {
        $grainRequest = new GrainRequest();
        $grainRequest->setMethodIndex($methodIndex);
        $grainRequest->setMessageData($request->serializeToString());
        $grainRequest->setMessageTypeName($request::class);

        $response = $this->cluster->request(
            $this->identity,
            $this->kind,
            $grainRequest,
            $this->callGrainConfig()
        );

        if ($response instanceof GrainResponse) {
            return $response;
        }

        if ($response instanceof GrainErrorResponse) {
            throw new GrainCallException(
                grainError: $response->getError(),
                code: $response->getCode()
            );
        }

        return null;
    }

    protected function callGrainConfig(): ?GrainCallConfig
    {
        return null;
    }
}
