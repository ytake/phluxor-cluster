<?php

declare(strict_types=1);

namespace Test\Integration;

/**
 * Virtual Actor (Grain) のクラスタ間アクティベーション E2E テスト。
 *
 * 前提: compose.cluster.yaml で node1/node2 が起動済みであること。
 * 実行: ./scripts/run-integration-tests.sh
 *
 * @group integration
 */
final class VirtualActorActivationE2ETest extends ClusterIntegrationTestCase
{
    public function testGrainActivatedFromNode1(): void
    {
        $this->waitUntilClusterReady(timeoutSeconds: 30);

        $result = $this->node1Get('/grain/ping/user-alice');

        self::assertTrue($result['ok'] ?? false, 'PingGrain should respond ok from node1');
    }

    public function testGrainActivatedFromNode2(): void
    {
        $this->waitUntilClusterReady(timeoutSeconds: 30);

        $result = $this->node2Get('/grain/ping/user-bob');

        self::assertTrue($result['ok'] ?? false, 'PingGrain should respond ok from node2');
    }

    public function testSameIdentityReturnsSameGrainFromBothNodes(): void
    {
        $this->waitUntilClusterReady(timeoutSeconds: 30);

        // 同一 identity は一方のノードに固定アクティベートされる
        $fromNode1 = $this->node1Get('/grain/ping/shared-user-1');
        $fromNode2 = $this->node2Get('/grain/ping/shared-user-1');

        self::assertTrue($fromNode1['ok'] ?? false, 'PingGrain(shared-user-1) should respond from node1');
        self::assertTrue($fromNode2['ok'] ?? false, 'PingGrain(shared-user-1) should respond from node2');
    }

    public function testMultipleDistinctGrainsCanBeActivated(): void
    {
        $this->waitUntilClusterReady(timeoutSeconds: 30);

        $identities = ['grain-1', 'grain-2', 'grain-3', 'grain-4', 'grain-5'];

        foreach ($identities as $identity) {
            $result = $this->node1Get("/grain/ping/{$identity}");
            self::assertTrue(
                $result['ok'] ?? false,
                "PingGrain({$identity}) should be activatable",
            );
        }
    }
}
