#!/usr/bin/env php
<?php

/**
 * クラスタ統合テスト用プローブノード
 *
 * クラスタノードと HTTP プローブサーバーを同一プロセスで起動する。
 * PHPUnit 統合テストはプローブ経由でクラスタ状態を観測・操作する。
 *
 * Usage:
 *   php tests/Support/cluster_test_node.php \
 *     --host 0.0.0.0 --port 50052 --auto-manage-port 6330 \
 *     --seeds node1:6330,node2:6331 --probe-port 7330 \
 *     --peers http://node2:7331
 *
 * Probe endpoints:
 *   GET  /ready                    クラスタが 2 ノード以上になったら ready:true
 *   GET  /cluster/members          現在のメンバー一覧 (JSON array)
 *   GET  /grain/ping/{identity}    PingGrain を呼び出し ok:true/false を返す
 *   POST /pubsub/subscribe         {"topic":"..."} でトピックを購読
 *   POST /pubsub/publish           {"topic":"..."} で MemberHeartbeat を発行
 *   POST /pubsub/publish?relay=1   リレー受信時のみの発行（再リレーしない）
 *   GET  /pubsub/received/{topic}  受信メッセージ数 {"count":N}
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Phluxor\ActorSystem;
use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Message\ReceiveFunction;
use Phluxor\ActorSystem\Props;
use Phluxor\Cluster\ActivatedKind;
use Phluxor\Cluster\Automanaged\AutomanagedConfig;
use Phluxor\Cluster\Automanaged\AutomanagedProvider;
use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\ClusterConfig;
use Phluxor\Cluster\Grain\GrainBase;
use Phluxor\Cluster\KindRegistry;
use Phluxor\Cluster\PartitionIdentity\PartitionIdentityLookup;
use Phluxor\Cluster\ProtoBuf\GrainRequest;
use Phluxor\Cluster\ProtoBuf\GrainResponse;
use Phluxor\Cluster\ProtoBuf\MemberHeartbeat;
use Phluxor\Cluster\PubSub\PubSubAutoRespondBatch;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * テスト用 Grain: methodIndex=0 → MemberHeartbeat を返す
 *
 * @internal
 */
final class PingGrain extends GrainBase
{
    protected function onGrainRequest(GrainRequest $request, ContextInterface $context): void
    {
        $this->respondGrain($context, new MemberHeartbeat());
    }
}

// --- CLI 引数 ---

$opts = getopt('', ['host:', 'port:', 'auto-manage-port:', 'seeds:', 'name:', 'probe-port:', 'peers:']);
$host           = (string) ($opts['host'] ?? '0.0.0.0');
$port           = (int)    ($opts['port'] ?? 50052);
$autoManagePort = (int)    ($opts['auto-manage-port'] ?? 6330);
$seeds          = isset($opts['seeds']) ? explode(',', (string) $opts['seeds']) : ['127.0.0.1:6330'];
$clusterName    = (string) ($opts['name'] ?? 'phluxor-cluster');
$probePort      = (int)    ($opts['probe-port'] ?? ($autoManagePort + 1000));

// ピアプローブ URL リスト（カンマ区切り）
// クロスノード PubSub リレーに使用する
/** @var list<string> $peerProbeUrls */
$peerProbeUrls  = isset($opts['peers']) && $opts['peers'] !== ''
    ? array_filter(array_map('trim', explode(',', (string) $opts['peers'])))
    : [];

// --- Swoole コルーチンランタイム ---

\Swoole\Coroutine\run(
    function () use ($host, $port, $autoManagePort, $seeds, $clusterName, $probePort, $peerProbeUrls): void {
        $system = ActorSystem::create();

        $kindRegistry = new KindRegistry(
            new ActivatedKind('PingGrain', Props::fromProducer(fn() => new PingGrain())),
        );

        $provider = new AutomanagedProvider(
            new AutomanagedConfig(
                seeds: $seeds,
                autoManagePort: $autoManagePort,
            ),
        );

        $config = new ClusterConfig(
            name: $clusterName,
            host: $host,
            port: $port,
            clusterProvider: $provider,
            identityLookup: new PartitionIdentityLookup(),
            kindRegistry: $kindRegistry,
        );

        $cluster = new Cluster($system, $config);
        $cluster->startMember();

        echo "Cluster test node started: {$host}:{$port} (auto-manage: {$autoManagePort}, probe: {$probePort})\n";
        echo "Seeds: " . implode(', ', $seeds) . "\n";
        if ($peerProbeUrls !== []) {
            echo "Peers: " . implode(', ', $peerProbeUrls) . "\n";
        }

        // プローブが観測する受信メッセージカウンター (トピック => 件数)
        /** @var array<string, int> $received */
        $received = [];

        // プローブ HTTP サーバーをバックグラウンドコルーチンで起動
        Coroutine::create(function () use ($cluster, &$received, $probePort, $peerProbeUrls): void {
            $server = new Server('0.0.0.0', $probePort, false);

            $server->handle('/', function (Request $req, Response $res) use ($cluster, &$received, $peerProbeUrls): void {
                $uri    = $req->server['request_uri'] ?? '/';
                $method = strtoupper($req->server['request_method'] ?? 'GET');
                $query  = $req->server['query_string'] ?? '';
                parse_str($query, $queryParams);

                $res->header('Content-Type', 'application/json');

                // GET /ready
                if ($uri === '/ready' && $method === 'GET') {
                    $memberCount = count($cluster->memberList()?->members()->all() ?? []);
                    $res->end((string) json_encode(['ready' => $memberCount >= 2, 'memberCount' => $memberCount]));
                    return;
                }

                // GET /cluster/members
                if ($uri === '/cluster/members' && $method === 'GET') {
                    $members = $cluster->memberList()?->members()->all() ?? [];
                    $data = array_values(array_map(
                        fn($m) => [
                            'id'    => $m->id(),
                            'host'  => $m->host(),
                            'port'  => $m->port(),
                            'kinds' => $m->kinds(),
                        ],
                        $members,
                    ));
                    $res->end((string) json_encode($data));
                    return;
                }

                // GET /grain/ping/{identity}
                if (str_starts_with($uri, '/grain/ping/') && $method === 'GET') {
                    $identity = substr($uri, strlen('/grain/ping/'));
                    if ($identity === '') {
                        $res->status(400);
                        $res->end((string) json_encode(['ok' => false, 'error' => 'missing identity']));
                        return;
                    }

                    $grainRequest = new GrainRequest();
                    $grainRequest->setMethodIndex(0);
                    $grainRequest->setMessageData((new MemberHeartbeat())->serializeToString());
                    $grainRequest->setMessageTypeName(MemberHeartbeat::class);

                    $response = $cluster->request($identity, 'PingGrain', $grainRequest);
                    $res->end((string) json_encode(['ok' => $response instanceof GrainResponse]));
                    return;
                }

                // POST /pubsub/subscribe  {"topic":"..."}
                if ($uri === '/pubsub/subscribe' && $method === 'POST') {
                    /** @var array<string, string>|null $body */
                    $body  = json_decode($req->rawContent() ?? '', true);
                    $topic = (string) ($body['topic'] ?? '');

                    if ($topic === '') {
                        $res->status(400);
                        $res->end((string) json_encode(['ok' => false, 'error' => 'missing topic']));
                        return;
                    }

                    $received[$topic] ??= 0;

                    $cluster->subscribeWithReceive(
                        $topic,
                        new ReceiveFunction(
                            function (ContextInterface $context) use (&$received, $topic): void {
                                $msg = $context->message();
                                if ($msg instanceof PubSubAutoRespondBatch) {
                                    $received[$topic] += count($msg->getMessages());
                                }
                            },
                        ),
                    );

                    $res->end((string) json_encode(['ok' => true]));
                    return;
                }

                // POST /pubsub/publish  {"topic":"..."}
                // relay=1 クエリパラメータが付いている場合はピアへのリレーをスキップする
                if ($uri === '/pubsub/publish' && $method === 'POST') {
                    /** @var array<string, string>|null $body */
                    $body  = json_decode($req->rawContent() ?? '', true);
                    $topic = (string) ($body['topic'] ?? '');

                    if ($topic === '') {
                        $res->status(400);
                        $res->end((string) json_encode(['ok' => false, 'error' => 'missing topic']));
                        return;
                    }

                    // ローカル TopicActor に発行
                    $result = $cluster->publisher()->publish($topic, new MemberHeartbeat());

                    // リレー受信時（relay=1）はピアへの転送をスキップして無限ループを防ぐ
                    $isRelay = isset($queryParams['relay']) && $queryParams['relay'] === '1';
                    if (!$isRelay && $peerProbeUrls !== []) {
                        foreach ($peerProbeUrls as $peerUrl) {
                            $parsed = parse_url($peerUrl);
                            $peerHost = $parsed['host'] ?? '';
                            $peerPort = (int) ($parsed['port'] ?? 80);
                            if ($peerHost === '') {
                                continue;
                            }
                            // ピアプローブへの publish リレー（fire-and-forget コルーチン）
                            Coroutine::create(
                                function () use ($peerHost, $peerPort, $topic): void {
                                    $client = new Swoole\Coroutine\Http\Client($peerHost, $peerPort);
                                    $client->setMethod('POST');
                                    $client->setHeaders(['Content-Type' => 'application/json']);
                                    $client->setData((string) json_encode(['topic' => $topic]));
                                    $client->execute('/pubsub/publish?relay=1');
                                    $client->close();
                                }
                            );
                        }
                    }

                    $res->end((string) json_encode(['ok' => $result !== null]));
                    return;
                }

                // GET /pubsub/received/{topic}
                if (str_starts_with($uri, '/pubsub/received/') && $method === 'GET') {
                    $topic = substr($uri, strlen('/pubsub/received/'));
                    $count = $received[$topic] ?? 0;
                    $res->end((string) json_encode(['count' => $count]));
                    return;
                }

                $res->status(404);
                $res->end((string) json_encode(['error' => 'not found', 'path' => $uri]));
            });

            $server->start();
            echo "Probe server listening on :{$probePort}\n";
        });

        while (true) {
            \Swoole\Coroutine::sleep(1);
        }
    }
);
