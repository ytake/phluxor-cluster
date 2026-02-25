<?php

declare(strict_types=1);

namespace Phluxor\Cluster\PubSub;

use Phluxor\ActorSystem\ProtoBuf\Pid;
use Phluxor\Cluster\ProtoBuf\ClusterIdentity;
use Phluxor\Cluster\ProtoBuf\SubscriberIdentity;

final readonly class SubscriberIdentityStruct
{
    private function __construct(
        private bool $isPid,
        private string $address,
        private string $id,
        private int $requestId,
        private string $clusterIdentity,
        private string $kind,
    ) {
    }

    public static function fromSubscriberIdentity(SubscriberIdentity $subscriberIdentity): self
    {
        if ($subscriberIdentity->hasPid()) {
            $pid = $subscriberIdentity->getPid();
            assert($pid !== null);
            return new self(
                isPid: true,
                address: $pid->getAddress(),
                id: $pid->getId(),
                requestId: $pid->getRequestId(),
                clusterIdentity: '',
                kind: '',
            );
        }

        $ci = $subscriberIdentity->getClusterIdentity();
        assert($ci !== null);
        return new self(
            isPid: false,
            address: '',
            id: '',
            requestId: 0,
            clusterIdentity: $ci->getIdentity(),
            kind: $ci->getKind(),
        );
    }

    public function toSubscriberIdentity(): SubscriberIdentity
    {
        $identity = new SubscriberIdentity();
        if ($this->isPid) {
            $pid = new Pid([
                'address' => $this->address,
                'id' => $this->id,
                'request_id' => $this->requestId,
            ]);
            $identity->setPid($pid);
        } else {
            $ci = new ClusterIdentity([
                'identity' => $this->clusterIdentity,
                'kind' => $this->kind,
            ]);
            $identity->setClusterIdentity($ci);
        }
        return $identity;
    }

    public function isPid(): bool
    {
        return $this->isPid;
    }

    public function isClusterIdentity(): bool
    {
        return !$this->isPid;
    }

    public function toKey(): string
    {
        if ($this->isPid) {
            return "pid:{$this->address}/{$this->id}";
        }
        return "ci:{$this->clusterIdentity}/{$this->kind}";
    }

    public function equals(self $other): bool
    {
        return $this->isPid === $other->isPid
            && $this->address === $other->address
            && $this->id === $other->id
            && $this->requestId === $other->requestId
            && $this->clusterIdentity === $other->clusterIdentity
            && $this->kind === $other->kind;
    }
}
