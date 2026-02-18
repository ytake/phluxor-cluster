<?php

declare(strict_types=1);

namespace Phluxor\Cluster\Grain;

use Google\Protobuf\Internal\Message;
use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Message\ActorInterface;
use Phluxor\ActorSystem\Message\Started;
use Phluxor\ActorSystem\Message\Stopping;
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

        if ($message instanceof Started) {
            $this->onStarted($context);
            return;
        }

        if ($message instanceof Stopping) {
            $this->onStopping($context);
            return;
        }

        if ($message instanceof GrainRequest) {
            $this->onGrainRequest($message, $context);
            return;
        }

        if (is_object($message)) {
            $this->onMessage($message, $context);
        }
    }

    abstract protected function onGrainRequest(GrainRequest $request, ContextInterface $context): void;

    /**
     * アクター開始時に呼ばれるライフサイクルフック。
     * サブクラスでオーバーライドして初期化処理を行う。
     *
     * **重要な制約**: Phluxor のアクターシステムでは `Started` メッセージが
     * `ClusterInit` より先に配信される。そのため、このフック内では
     * `cluster()` および `identity()` は null を返す可能性がある。
     * クラスタへのアクセスが必要な初期化は `onGrainRequest()` の初回呼び出し時か、
     * または `ClusterInit` 受信後に行うこと。
     */
    protected function onStarted(ContextInterface $context): void
    {
    }

    /**
     * アクター停止前に呼ばれるライフサイクルフック。
     * サブクラスでオーバーライドしてクリーンアップ処理を行う。
     */
    protected function onStopping(ContextInterface $context): void
    {
    }

    protected function onMessage(object $message, ContextInterface $context): void
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
