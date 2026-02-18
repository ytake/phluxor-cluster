<?php

declare(strict_types=1);

namespace Phluxor\Cluster\Automanaged;

use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\ClusterProviderInterface;
use Phluxor\Cluster\Member;
use Phluxor\Cluster\MemberSet;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Timer;

final class AutomanagedProvider implements ClusterProviderInterface
{
    private ?int $pollingTimerId = null;

    private ?Server $httpServer = null;

    private bool $shutdown = false;

    private ?Cluster $cluster = null;

    private ?NodeModel $selfNode = null;

    public function __construct(
        private readonly AutomanagedConfig $config
    ) {
    }

    public function startMember(Cluster $cluster): void
    {
        $this->init($cluster);
        $this->startHealthEndpoint();
        $this->startPollingLoop();
    }

    public function startClient(Cluster $cluster): void
    {
        $this->init($cluster);
        $this->startPollingLoop();
    }

    public function shutdown(bool $graceful = true): void
    {
        $this->shutdown = true;

        if ($this->pollingTimerId !== null) {
            Timer::clear($this->pollingTimerId);
            $this->pollingTimerId = null;
        }

        if ($this->httpServer !== null) {
            $this->httpServer->shutdown();
            $this->httpServer = null;
        }
    }

    private function init(Cluster $cluster): void
    {
        $this->cluster = $cluster;
        $this->shutdown = false;

        $config = $cluster->config();
        $this->selfNode = new NodeModel(
            id: $cluster->actorSystem()->getProcessRegistry()->getAddress(),
            address: $config->host(),
            port: $config->port(),
            autoManagePort: $this->config->autoManagePort(),
            kinds: $config->kindRegistry()->allKindNames(),
            clusterName: $config->name(),
        );
    }

    private function startHealthEndpoint(): void
    {
        $selfNode = $this->selfNode;
        $port = $this->config->autoManagePort();

        Coroutine::create(function () use ($selfNode, $port): void {
            $server = new Server('0.0.0.0', $port, false);
            $this->httpServer = $server;

            $server->handle('/_health', function (Request $request, Response $response) use ($selfNode): void {
                $response->header('Content-Type', 'application/json');
                $response->end($selfNode?->toJson() ?? '{}');
            });

            $server->start();
        });
    }

    private function startPollingLoop(): void
    {
        $timerId = Timer::tick(
            $this->config->refreshIntervalMs(),
            fn() => $this->pollNodes()
        );
        if ($timerId !== false) {
            $this->pollingTimerId = $timerId;
        }
    }

    private function pollNodes(): void
    {
        if ($this->shutdown || $this->cluster === null) {
            return;
        }

        $nodes = $this->checkNodes();

        $members = [];
        foreach ($nodes as $node) {
            $members[] = new Member(
                host: $node->address,
                port: $node->port,
                id: $node->id,
                kinds: $node->kinds,
            );
        }

        if ($this->selfNode !== null) {
            $selfExists = false;
            foreach ($members as $member) {
                if ($member->id() === $this->selfNode->id) {
                    $selfExists = true;
                    break;
                }
            }
            if (!$selfExists) {
                $members[] = new Member(
                    host: $this->selfNode->address,
                    port: $this->selfNode->port,
                    id: $this->selfNode->id,
                    kinds: $this->selfNode->kinds,
                );
            }
        }

        $memberSet = new MemberSet(...$members);
        $event = $this->cluster->memberList()?->updateClusterTopology($memberSet);
        if ($event !== null) {
            $this->cluster->actorSystem()->getEventStream()?->publish($event);
        }
    }

    /**
     * @return list<NodeModel>
     */
    private function checkNodes(): array
    {
        $nodes = [];
        $timeoutSec = $this->config->httpTimeoutMs() / 1000;

        foreach ($this->config->seeds() as $seed) {
            $parts = explode(':', $seed);
            if (count($parts) !== 2) {
                continue;
            }

            $host = $parts[0];
            $port = (int) $parts[1];

            try {
                $client = new Coroutine\Http\Client($host, $port);
                $client->set(['timeout' => $timeoutSec]);
                $client->get('/_health');

                if ($client->statusCode === 200 && $client->body !== '') {
                    $node = NodeModel::fromJson($client->body);
                    if ($node !== null && $node->clusterName === $this->cluster?->config()->name()) {
                        $nodes[] = $node;
                    }
                }

                $client->close();
            } catch (\Throwable) {
                continue;
            }
        }

        return $nodes;
    }
}
