<?php

declare(strict_types=1);

namespace Test\Integration;

/**
 * Gossip によるメンバーシップ伝播の E2E テスト。
 *
 * 前提: compose.cluster.yaml で node1/node2 が起動済みであること。
 * 実行: ./scripts/run-integration-tests.sh
 *
 * @group integration
 */
final class GossipPropagationE2ETest extends ClusterIntegrationTestCase
{
    public function testBothNodesReachTwoMemberConsensus(): void
    {
        $this->waitUntilClusterReady(timeoutSeconds: 30);

        $node1Members = $this->node1Get('/cluster/members');
        $node2Members = $this->node2Get('/cluster/members');

        self::assertGreaterThanOrEqual(2, count($node1Members), 'node1 should see at least 2 members');
        self::assertGreaterThanOrEqual(2, count($node2Members), 'node2 should see at least 2 members');
    }

    public function testNode1SeesNode2InMemberList(): void
    {
        $this->waitUntilClusterReady(timeoutSeconds: 30);

        $members = $this->node1Get('/cluster/members');

        $ports = array_column($members, 'port');
        self::assertContains(50053, $ports, 'node1 should see node2 (port 50053) in member list');
    }

    public function testNode2SeesNode1InMemberList(): void
    {
        $this->waitUntilClusterReady(timeoutSeconds: 30);

        $members = $this->node2Get('/cluster/members');

        $ports = array_column($members, 'port');
        self::assertContains(50052, $ports, 'node2 should see node1 (port 50052) in member list');
    }

    public function testBothNodesAdvertisePingGrainKind(): void
    {
        $this->waitUntilClusterReady(timeoutSeconds: 30);

        $node1Members = $this->node1Get('/cluster/members');
        $node2Members = $this->node2Get('/cluster/members');

        $allMembers = array_merge($node1Members, $node2Members);
        $allKinds   = array_merge(...array_column($allMembers, 'kinds'));

        self::assertContains('PingGrain', $allKinds, 'At least one node should advertise PingGrain kind');
    }
}
