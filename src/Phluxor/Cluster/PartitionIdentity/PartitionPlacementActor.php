<?php

declare(strict_types=1);

namespace Phluxor\Cluster\PartitionIdentity;

use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Message\ActorInterface;
use Phluxor\ActorSystem\Message\Started;
use Phluxor\ActorSystem\Message\Stopping;
use Phluxor\ActorSystem\ProtoBuf\Pid;
use Phluxor\ActorSystem\ProtoBuf\Terminated;
use Phluxor\ActorSystem\Ref;
use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\ClusterIdentity;
use Phluxor\Cluster\ClusterTopologyEvent;
use Phluxor\Cluster\Grain\ClusterInit;
use Phluxor\Cluster\Hashing\RendezvousHashSelector;
use Phluxor\Cluster\ProtoBuf\ActivationRequest;
use Phluxor\Cluster\ProtoBuf\ActivationResponse;
use Phluxor\Cluster\ProtoBuf\ActivationTerminated;
use Phluxor\Cluster\ProtoBuf\ClusterIdentity as ProtoBufClusterIdentity;

final class PartitionPlacementActor implements ActorInterface
{
    /** @var string partition-activator アクター名（PartitionManager と共有）*/
    public const string PARTITION_ACTIVATOR_ACTOR_NAME = 'partition-activator';

    /**
     * 処理済み request_id の最大保持数。
     * これを超えた場合は挿入順の古い半分を破棄し、新しい半分（末尾 5000 件）を残す。
     * PHP の配列は挿入順を保持するため、array_slice に負のオフセットを渡すことで
     * 末尾から N 件を O(n) コストで取得する。上限到達時のみ発生するため許容範囲とする。
     */
    private const int PROCESSED_IDS_MAX_SIZE = 10000;

    /** @var array<string, Ref> key="kind/identity" => spawned actor Ref */
    private array $actors = [];

    /**
     * 処理済みの request_id セット。同一IDによる二重アクティベーションを防ぐ。
     * メモリリーク防止のため PROCESSED_IDS_MAX_SIZE を超えたら古いエントリを削除する。
     * @var array<string, true>
     */
    private array $processedRequestIds = [];

    private int $currentTopologyHash = 0;

    public function __construct(
        private readonly Cluster $cluster
    ) {
    }

    public function receive(ContextInterface $context): void
    {
        $message = $context->message();

        if ($message instanceof Started) {
            return;
        }

        if ($message instanceof ActivationRequest) {
            $this->onActivationRequest($message, $context);
        } elseif ($message instanceof ClusterTopologyEvent) {
            $this->onClusterTopology($message, $context);
        } elseif ($message instanceof ActivationTerminated) {
            $this->onActivationTerminated($message);
        } elseif ($message instanceof Terminated) {
            $this->onTerminated($message);
        } elseif ($message instanceof Stopping) {
            $this->onStopping($context);
        }
    }

    private function onActivationRequest(ActivationRequest $msg, ContextInterface $context): void
    {
        $clusterIdentity = $msg->getClusterIdentity();
        if ($clusterIdentity === null) {
            $this->respondFailed($context);
            return;
        }

        $key = $clusterIdentity->getKind() . '/' . $clusterIdentity->getIdentity();

        // request_id による冪等性チェック: 同一IDが既に処理済みの場合はスキップ
        $requestId = $msg->getRequestId();
        if ($requestId !== '' && isset($this->processedRequestIds[$requestId])) {
            $existing = $this->actors[$key] ?? null;
            if ($existing instanceof Ref) {
                $response = new ActivationResponse();
                $response->setPid($existing->protobufPid());
                $response->setTopologyHash($this->currentTopologyHash);
                $context->respond($response);
            } else {
                $this->respondFailed($context);
            }
            return;
        }

        $existing = $this->actors[$key] ?? null;
        if ($existing instanceof Ref) {
            $response = new ActivationResponse();
            $response->setPid($existing->protobufPid());
            $response->setTopologyHash($this->currentTopologyHash);
            $context->respond($response);
            return;
        }

        $kind = $this->cluster->config()->kindRegistry()->find($clusterIdentity->getKind());
        if ($kind === null) {
            $this->respondFailed($context);
            return;
        }

        $ref = $context->spawnPrefix($kind->props(), $clusterIdentity->getIdentity());
        if (!$ref instanceof Ref) {
            $this->respondFailed($context);
            return;
        }

        $context->send(
            $ref,
            new ClusterInit(
                $this->cluster,
                new ClusterIdentity(
                    $clusterIdentity->getIdentity(),
                    $clusterIdentity->getKind()
                )
            )
        );

        $this->actors[$key] = $ref;

        // request_id を処理済みとしてマーク（冪等性保証）
        if ($requestId !== '') {
            $this->processedRequestIds[$requestId] = true;
            // メモリリーク防止: 上限を超えたら挿入順の古い半分を破棄し新しい半分を残す
            if (count($this->processedRequestIds) > self::PROCESSED_IDS_MAX_SIZE) {
                $this->processedRequestIds = array_slice(
                    $this->processedRequestIds,
                    -(int)(self::PROCESSED_IDS_MAX_SIZE / 2),
                    null,
                    true
                );
            }
        }

        $response = new ActivationResponse();
        $response->setPid($ref->protobufPid());
        $response->setTopologyHash($this->currentTopologyHash);
        $context->respond($response);
    }

    private function onClusterTopology(ClusterTopologyEvent $msg, ContextInterface $context): void
    {
        $rdv = new RendezvousHashSelector();
        $selfAddress = $this->cluster->config()->address();

        // トポロジーハッシュを更新
        $this->currentTopologyHash = $msg->topologyHash();

        foreach ($this->actors as $key => $ref) {
            $owner = $rdv->getPartition($key, $msg->members());
            if ($owner !== $selfAddress) {
                // オーナーが変わったアクターについて ActivationTerminated を
                // 新しいオーナーノードの partition-activator に送信し、
                // PidCache のクリアを促す
                if ($owner !== null) {
                    $parts = explode('/', $key, 2);
                    if (count($parts) === 2) {
                        [$kind, $identity] = $parts;

                        $pbIdentity = new ProtoBufClusterIdentity();
                        $pbIdentity->setKind($kind);
                        $pbIdentity->setIdentity($identity);

                        $terminated = new ActivationTerminated();
                        $terminated->setPid($ref->protobufPid());
                        $terminated->setClusterIdentity($pbIdentity);

                        $ownerPid = new Pid();
                        $ownerPid->setAddress($owner);
                        $ownerPid->setId(self::PARTITION_ACTIVATOR_ACTOR_NAME);
                        $ownerRef = new Ref($ownerPid);

                        $context->send($ownerRef, $terminated);
                    }
                }

                $context->poison($ref);
                unset($this->actors[$key]);
            }
        }
    }

    private function onActivationTerminated(ActivationTerminated $msg): void
    {
        $clusterIdentity = $msg->getClusterIdentity();
        if ($clusterIdentity === null) {
            return;
        }

        $key = $clusterIdentity->getKind() . '/' . $clusterIdentity->getIdentity();
        $existing = $this->actors[$key] ?? null;
        if (!$existing instanceof Ref) {
            return;
        }

        $pid = $msg->getPid();
        if ($pid === null || $existing->protobufPid()->getId() === $pid->getId()) {
            unset($this->actors[$key]);
        }
    }

    private function onTerminated(Terminated $msg): void
    {
        $who = $msg->getWho();
        if ($who === null) {
            return;
        }

        foreach ($this->actors as $key => $ref) {
            if ($ref->protobufPid()->getId() === $who->getId()) {
                unset($this->actors[$key]);
                return;
            }
        }
    }

    private function onStopping(ContextInterface $context): void
    {
        foreach ($this->actors as $ref) {
            $context->poison($ref);
        }
    }

    private function respondFailed(ContextInterface $context): void
    {
        $response = new ActivationResponse();
        $response->setFailed(true);
        $context->respond($response);
    }
}
