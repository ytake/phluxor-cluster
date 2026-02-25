<?php

declare(strict_types=1);

namespace Test\Integration;

/**
 * クラスタ PubSub のメッセージ配送 E2E テスト。
 *
 * 前提: compose.cluster.yaml で node1/node2 が起動済みであること。
 * 実行: ./scripts/run-integration-tests.sh
 *
 * @group integration
 */
final class PubSubDeliveryE2ETest extends ClusterIntegrationTestCase
{
    public function testMessagePublishedOnNode1DeliveredToNode1Subscriber(): void
    {
        $this->waitUntilClusterReady(timeoutSeconds: 30);

        $topic = 'test-local-' . uniqid();

        // node1 でトピックを購読
        $subResult = $this->node1Post('/pubsub/subscribe', ['topic' => $topic]);
        self::assertTrue($subResult['ok'] ?? false, 'subscribe should succeed');

        // Swoole の TopicActor が起動するまで少し待つ
        usleep(500_000);

        // node1 からメッセージを発行
        $pubResult = $this->node1Post('/pubsub/publish', ['topic' => $topic]);
        self::assertTrue($pubResult['ok'] ?? false, 'publish should succeed');

        // メッセージが届くまで最大 10 秒待機
        $this->waitUntil(
            fn() => ($this->node1Get("/pubsub/received/{$topic}")['count'] ?? 0) >= 1,
            timeoutSeconds: 10,
            failMessage: 'node1 subscriber did not receive published message',
        );

        $count = $this->node1Get("/pubsub/received/{$topic}")['count'] ?? 0;
        self::assertGreaterThanOrEqual(1, $count, 'node1 subscriber should have received at least 1 message');
    }

    public function testMessagePublishedOnNode2DeliveredToNode1Subscriber(): void
    {
        $this->waitUntilClusterReady(timeoutSeconds: 30);

        $topic = 'test-cross-' . uniqid();

        // node1 でトピックを購読
        $subResult = $this->node1Post('/pubsub/subscribe', ['topic' => $topic]);
        self::assertTrue($subResult['ok'] ?? false, 'subscribe on node1 should succeed');

        usleep(500_000);

        // node2 からメッセージを発行
        $pubResult = $this->node2Post('/pubsub/publish', ['topic' => $topic]);
        self::assertTrue($pubResult['ok'] ?? false, 'publish from node2 should succeed');

        // node1 のサブスクライバーにメッセージが届くまで待機
        $this->waitUntil(
            fn() => ($this->node1Get("/pubsub/received/{$topic}")['count'] ?? 0) >= 1,
            timeoutSeconds: 15,
            failMessage: 'node1 subscriber did not receive cross-node message from node2',
        );

        $count = $this->node1Get("/pubsub/received/{$topic}")['count'] ?? 0;
        self::assertGreaterThanOrEqual(1, $count, 'node1 should receive message published on node2');
    }

    public function testMultipleMessagesDelivered(): void
    {
        $this->waitUntilClusterReady(timeoutSeconds: 30);

        $topic      = 'test-multi-' . uniqid();
        $publishCount = 3;

        $subResult = $this->node1Post('/pubsub/subscribe', ['topic' => $topic]);
        self::assertTrue($subResult['ok'] ?? false, 'subscribe should succeed');

        usleep(500_000);

        for ($i = 0; $i < $publishCount; $i++) {
            $pubResult = $this->node1Post('/pubsub/publish', ['topic' => $topic]);
            self::assertTrue($pubResult['ok'] ?? false, "publish #{$i} should succeed");
            usleep(100_000);
        }

        $this->waitUntil(
            fn() => ($this->node1Get("/pubsub/received/{$topic}")['count'] ?? 0) >= $publishCount,
            timeoutSeconds: 15,
            failMessage: "Expected {$publishCount} messages to be received",
        );

        $count = $this->node1Get("/pubsub/received/{$topic}")['count'] ?? 0;
        self::assertGreaterThanOrEqual(
            $publishCount,
            $count,
            "Should receive at least {$publishCount} messages",
        );
    }
}
