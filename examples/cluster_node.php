<?php

/**
 * Phluxor Cluster node bootstrap script.
 *
 * Usage:
 *   php examples/cluster_node.php --host 0.0.0.0 --port 50052 --auto-manage-port 6330 --seeds node1:6330,node2:6331
 *
 * Docker Compose example (node1):
 *   docker compose -f compose.cluster.yaml exec node1 php examples/cluster_node.php \
 *     --host 0.0.0.0 --port 50052 --auto-manage-port 6330 --seeds node1:6330,node2:6331
 *
 * Docker Compose example (node2):
 *   docker compose -f compose.cluster.yaml exec node2 php examples/cluster_node.php \
 *     --host 0.0.0.0 --port 50053 --auto-manage-port 6331 --seeds node1:6330,node2:6331
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Phluxor\ActorSystem;
use Phluxor\Cluster\Automanaged\AutomanagedConfig;
use Phluxor\Cluster\Automanaged\AutomanagedProvider;
use Phluxor\Cluster\Cluster;
use Phluxor\Cluster\ClusterConfig;
use Phluxor\Cluster\KindRegistry;
use Phluxor\Cluster\PartitionIdentity\PartitionIdentityLookup;

$opts = getopt('', ['host:', 'port:', 'auto-manage-port:', 'seeds:', 'name:']);
$host = $opts['host'] ?? '0.0.0.0';
$port = (int) ($opts['port'] ?? 50052);
$autoManagePort = (int) ($opts['auto-manage-port'] ?? 6330);
$seeds = isset($opts['seeds']) ? explode(',', $opts['seeds']) : ['127.0.0.1:6330'];
$clusterName = $opts['name'] ?? 'phluxor-cluster';

\Swoole\Coroutine\run(function () use ($host, $port, $autoManagePort, $seeds, $clusterName): void {
    $system = ActorSystem::create();

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
        kindRegistry: new KindRegistry(),
    );

    $cluster = new Cluster($system, $config);
    $cluster->startMember();

    echo "Cluster node started at {$host}:{$port} (auto-manage: {$autoManagePort})\n";
    echo "Seeds: " . implode(', ', $seeds) . "\n";

    while (true) {
        \Swoole\Coroutine::sleep(1);
    }
});
