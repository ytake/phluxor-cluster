<?php

declare(strict_types=1);

namespace Phluxor\Cluster;

use Phluxor\ActorSystem;
use Phluxor\ActorSystem\Message\ReceiveFunction;
use Phluxor\ActorSystem\Props;
use Phluxor\ActorSystem\ProtoBuf\Pid;
use Phluxor\ActorSystem\Ref;
use Phluxor\Cluster\Gossip\ConsensusCheck;
use Phluxor\Cluster\Gossip\GossipKeys;
use Phluxor\Cluster\Gossip\Gossiper;
use Phluxor\Cluster\ProtoBuf\SubscribeRequest;
use Phluxor\Cluster\ProtoBuf\SubscribeResponse;
use Phluxor\Cluster\ProtoBuf\SubscriberIdentity;
use Phluxor\Cluster\ProtoBuf\UnsubscribeRequest;
use Phluxor\Cluster\ProtoBuf\UnsubscribeResponse;
use Phluxor\Cluster\PubSub\BatchingProducer;
use Phluxor\Cluster\PubSub\BatchingProducerConfig;
use Phluxor\Cluster\PubSub\EmptyKeyValueStore;
use Phluxor\Cluster\PubSub\Publisher;
use Phluxor\Cluster\PubSub\PubSubExtension;
use Phluxor\Cluster\PubSub\TopicActor;
use Phluxor\EventStream\Subscription;

class Cluster
{
    private BlockList $blockList;

    private PidCache $pidCache;

    private ?MemberList $memberList = null;

    private ?ClusterContextInterface $clusterContext = null;

    private ?Gossiper $gossiper = null;

    private ?Subscription $topologySub = null;

    public function __construct(
        private readonly ActorSystem $actorSystem,
        private readonly ClusterConfig $config
    ) {
        $this->blockList = new BlockList();
        $this->pidCache = new PidCache();
    }

    public function actorSystem(): ActorSystem
    {
        return $this->actorSystem;
    }

    public function config(): ClusterConfig
    {
        return $this->config;
    }

    public function blockList(): BlockList
    {
        return $this->blockList;
    }

    public function pidCache(): PidCache
    {
        return $this->pidCache;
    }

    public function memberList(): ?MemberList
    {
        return $this->memberList;
    }

    public function gossiper(): ?Gossiper
    {
        return $this->gossiper;
    }

    public function startMember(): void
    {
        $this->actorSystem->getProcessRegistry()->setAddress($this->config->address());
        $this->actorSystem->extensions()->set(new ClusterExtension($this));

        self::ensureTopicKindRegistered($this->config->kindRegistry());

        $pubSub = new PubSubExtension($this, $this->config->pubSubSubscriberTimeoutSeconds());
        $this->actorSystem->extensions()->set($pubSub);
        $pubSub->start();

        $this->memberList = new MemberList($this->blockList);
        $this->clusterContext = new DefaultClusterContext($this);

        $this->subscribeToTopologyEvents();

        $this->gossiper = new Gossiper($this);
        $this->gossiper->start();

        $kinds = $this->config->kindRegistry()->allKindNames();
        $this->config->identityLookup()->setup($this, $kinds, false);
        $this->config->clusterProvider()->startMember($this);
    }

    public function startClient(): void
    {
        $this->actorSystem->getProcessRegistry()->setAddress($this->config->address());
        $this->actorSystem->extensions()->set(new ClusterExtension($this));

        self::ensureTopicKindRegistered($this->config->kindRegistry());

        $this->memberList = new MemberList($this->blockList);
        $this->clusterContext = new DefaultClusterContext($this);

        $this->subscribeToTopologyEvents();

        $this->gossiper = new Gossiper($this);
        $this->gossiper->start();

        $this->config->identityLookup()->setup($this, [], true);
        $this->config->clusterProvider()->startClient($this);
    }

    public function shutdown(bool $graceful = true): void
    {
        if ($graceful && $this->gossiper !== null) {
            $this->gossiper->setState(GossipKeys::GRACEFULLY_LEFT, '', 'Empty');
        }

        if ($graceful) {
            $this->config->clusterProvider()->shutdown(true);
            $this->config->identityLookup()->shutdown();
        }

        $this->gossiper?->stop();
        $this->gossiper = null;

        if ($this->topologySub !== null) {
            $this->actorSystem->getEventStream()?->unsubscribe($this->topologySub);
            $this->topologySub = null;
        }

        $this->clusterContext = null;
    }

    public function get(string $identity, string $kind): ?Ref
    {
        return $this->config->identityLookup()->get(
            new ClusterIdentity($identity, $kind)
        );
    }

    public function request(string $identity, string $kind, mixed $message, ?GrainCallConfig $config = null): mixed
    {
        return $this->clusterContext?->request($identity, $kind, $message, $config);
    }

    /**
     * コンセンサスチェックを登録する。
     * クラスタ全メンバーが特定のゴシップキーに対して同じ値を持つかを
     * 定期的に検証するためのコールバックを設定する。
     */
    public function registerConsensusCheck(ConsensusCheck $check): void
    {
        $this->gossiper?->registerConsensusCheck($check);
    }

    /**
     * IDで指定されたコンセンサスチェックを削除する。
     */
    public function removeConsensusCheck(string $id): void
    {
        $this->gossiper?->removeConsensusCheck($id);
    }

    private const string TOPIC_ACTOR_KIND = 'prototopic';

    public function subscribeByPid(
        string $topic,
        Pid $pid,
        ?GrainCallConfig $config = null
    ): ?SubscribeResponse {
        $identity = new SubscriberIdentity();
        $identity->setPid($pid);
        $request = new SubscribeRequest();
        $request->setSubscriber($identity);

        $res = $this->request($topic, self::TOPIC_ACTOR_KIND, $request, $config);
        if ($res instanceof SubscribeResponse) {
            return $res;
        }
        return null;
    }

    public function subscribeByClusterIdentity(
        string $topic,
        ClusterIdentity $clusterIdentity,
        ?GrainCallConfig $config = null
    ): ?SubscribeResponse {
        $ci = new ProtoBuf\ClusterIdentity([
            'identity' => $clusterIdentity->identity(),
            'kind' => $clusterIdentity->kind(),
        ]);
        $identity = new SubscriberIdentity();
        $identity->setClusterIdentity($ci);
        $request = new SubscribeRequest();
        $request->setSubscriber($identity);

        $res = $this->request($topic, self::TOPIC_ACTOR_KIND, $request, $config);
        if ($res instanceof SubscribeResponse) {
            return $res;
        }
        return null;
    }

    public function subscribeWithReceive(
        string $topic,
        ReceiveFunction $receive,
        ?GrainCallConfig $config = null
    ): ?SubscribeResponse {
        $props = Props::fromFunction($receive);
        $ref = $this->actorSystem->root()->spawn($props);
        if ($ref === null) {
            return null;
        }
        return $this->subscribeByPid($topic, $ref->protobufPid(), $config);
    }

    public function unsubscribeByPid(
        string $topic,
        Pid $pid,
        ?GrainCallConfig $config = null
    ): ?UnsubscribeResponse {
        $identity = new SubscriberIdentity();
        $identity->setPid($pid);
        $request = new UnsubscribeRequest();
        $request->setSubscriber($identity);

        $res = $this->request($topic, self::TOPIC_ACTOR_KIND, $request, $config);
        if ($res instanceof UnsubscribeResponse) {
            return $res;
        }
        return null;
    }

    public function unsubscribeByClusterIdentity(
        string $topic,
        ClusterIdentity $clusterIdentity,
        ?GrainCallConfig $config = null
    ): ?UnsubscribeResponse {
        $ci = new ProtoBuf\ClusterIdentity([
            'identity' => $clusterIdentity->identity(),
            'kind' => $clusterIdentity->kind(),
        ]);
        $identity = new SubscriberIdentity();
        $identity->setClusterIdentity($ci);
        $request = new UnsubscribeRequest();
        $request->setSubscriber($identity);

        $res = $this->request($topic, self::TOPIC_ACTOR_KIND, $request, $config);
        if ($res instanceof UnsubscribeResponse) {
            return $res;
        }
        return null;
    }

    public function unsubscribeByIdentityAndKind(
        string $topic,
        string $identity,
        string $kind,
        ?GrainCallConfig $config = null
    ): ?UnsubscribeResponse {
        return $this->unsubscribeByClusterIdentity(
            $topic,
            new ClusterIdentity($identity, $kind),
            $config
        );
    }

    public function publisher(): Publisher
    {
        return new Publisher($this);
    }

    public function batchingProducer(
        string $topic,
        ?BatchingProducerConfig $config = null
    ): BatchingProducer {
        return new BatchingProducer(
            $this->publisher(),
            $topic,
            $config ?? new BatchingProducerConfig()
        );
    }

    public static function ensureTopicKindRegistered(KindRegistry $registry): void
    {
        $topicKind = self::TOPIC_ACTOR_KIND;
        if ($registry->has($topicKind)) {
            return;
        }
        $props = Props::fromProducer(
            fn() => new TopicActor(new EmptyKeyValueStore())
        );
        $registry->register(new ActivatedKind($topicKind, $props));
    }

    private function subscribeToTopologyEvents(): void
    {
        $this->topologySub = $this->actorSystem->getEventStream()?->subscribe(
            function (mixed $event): void {
                if ($event instanceof ClusterTopologyEvent) {
                    $this->onClusterTopology($event);
                }
            }
        );
    }

    private function onClusterTopology(ClusterTopologyEvent $event): void
    {
        // MemberList から責務を引き受け、Cluster がトポロジー変更の副作用を一元管理する。
        // left メンバーを BlockList に追加し、PidCache からエントリを削除する。
        foreach ($event->left() as $member) {
            if (!$this->blockList->isBlocked($member->id())) {
                $this->blockList->block($member->id());
            }
            $this->pidCache->removeByMember($member->address());
        }
    }
}
