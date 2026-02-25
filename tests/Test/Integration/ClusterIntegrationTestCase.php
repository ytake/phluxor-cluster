<?php

declare(strict_types=1);

namespace Test\Integration;

use PHPUnit\Framework\TestCase;

/**
 * マルチノード統合テストの基底クラス。
 *
 * 起動方法: scripts/run-integration-tests.sh
 *
 * プローブ URL はデフォルトで node1=localhost:7330 / node2=node2:7331 を使用する。
 * 実行環境に合わせて環境変数でオーバーライドできる:
 *   CLUSTER_PROBE_NODE1=http://localhost:7330
 *   CLUSTER_PROBE_NODE2=http://localhost:7331
 */
abstract class ClusterIntegrationTestCase extends TestCase
{
    private const int DEFAULT_WAIT_SECONDS = 30;

    private string $node1BaseUrl;

    private string $node2BaseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->node1BaseUrl = getenv('CLUSTER_PROBE_NODE1') ?: 'http://localhost:7330';
        $this->node2BaseUrl = getenv('CLUSTER_PROBE_NODE2') ?: 'http://node2:7331';

        if (!$this->isProbeReachable($this->node1BaseUrl) || !$this->isProbeReachable($this->node2BaseUrl)) {
            $this->markTestSkipped(
                'Cluster probe not reachable. '
                . "Start nodes with: ./scripts/run-integration-tests.sh\n"
                . "  NODE1: {$this->node1BaseUrl}/ready\n"
                . "  NODE2: {$this->node2BaseUrl}/ready",
            );
        }
    }

    /**
     * 両ノードが 2 メンバー以上になるまで待機する。
     */
    protected function waitUntilClusterReady(int $timeoutSeconds = self::DEFAULT_WAIT_SECONDS): void
    {
        $this->waitUntil(
            fn() => ($this->probeGet($this->node1BaseUrl, '/ready')['ready'] ?? false) === true,
            $timeoutSeconds,
            'Cluster not ready within timeout',
        );
    }

    /**
     * $condition が true を返すまでポーリングする。
     * timeout を超えたらテスト失敗。
     */
    protected function waitUntil(
        callable $condition,
        int $timeoutSeconds = 10,
        string $failMessage = 'Condition not met within timeout'
    ): void {
        $deadline = time() + $timeoutSeconds;
        while (time() < $deadline) {
            if ($condition()) {
                return;
            }
            usleep(500_000); // 0.5 秒間隔でポーリング
        }
        $this->fail("{$failMessage} ({$timeoutSeconds}s)");
    }

    /**
     * Node1 プローブに GET リクエストを送る。
     *
     * @return array<string, mixed>
     */
    protected function node1Get(string $path): array
    {
        return $this->probeGet($this->node1BaseUrl, $path);
    }

    /**
     * Node2 プローブに GET リクエストを送る。
     *
     * @return array<string, mixed>
     */
    protected function node2Get(string $path): array
    {
        return $this->probeGet($this->node2BaseUrl, $path);
    }

    /**
     * Node1 プローブに POST リクエストを送る。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function node1Post(string $path, array $data): array
    {
        return $this->probePost($this->node1BaseUrl, $path, $data);
    }

    /**
     * Node2 プローブに POST リクエストを送る。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function node2Post(string $path, array $data): array
    {
        return $this->probePost($this->node2BaseUrl, $path, $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function probeGet(string $baseUrl, string $path): array
    {
        $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5]]);
        $body    = @file_get_contents($baseUrl . $path, false, $context);
        if ($body === false) {
            return [];
        }
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($body, true);
        return $decoded ?? [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function probePost(string $baseUrl, string $path, array $data): array
    {
        $payload = (string) json_encode($data);
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nContent-Length: " . strlen($payload),
                'content' => $payload,
                'timeout' => 5,
            ],
        ]);
        $body = @file_get_contents($baseUrl . $path, false, $context);
        if ($body === false) {
            return [];
        }
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($body, true);
        return $decoded ?? [];
    }

    private function isProbeReachable(string $baseUrl): bool
    {
        $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 2]]);
        return @file_get_contents($baseUrl . '/ready', false, $context) !== false;
    }
}
