#!/usr/bin/env bash
# クラスタ統合テスト実行スクリプト
#
# 使い方:
#   ./scripts/run-integration-tests.sh
#
# 前提:
#   - Docker と Docker Compose が利用可能であること
#   - compose.cluster.yaml がプロジェクトルートにあること

set -euo pipefail

COMPOSE_FILE="compose.cluster.yaml"
PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

cd "$PROJECT_ROOT"

cleanup() {
    echo "[teardown] Stopping cluster containers..."
    docker compose -f "$COMPOSE_FILE" down --timeout 10 2>/dev/null || true
}
trap cleanup EXIT

# 1. コンテナ起動
echo "[start] Building and starting cluster containers..."
docker compose -f "$COMPOSE_FILE" up -d --build

# 2. 依存関係インストール (初回)
echo "[setup] Installing composer dependencies..."
docker compose -f "$COMPOSE_FILE" exec -T node1 composer install --no-interaction --quiet

# 3. node1 でプローブノード起動
echo "[start] Starting probe node on node1 (port 50052, probe 7330)..."
docker compose -f "$COMPOSE_FILE" exec -d node1 php tests/Support/cluster_test_node.php \
    --host node1 \
    --port 50052 \
    --auto-manage-port 6330 \
    --seeds node1:6330,node2:6331 \
    --probe-port 7330 \
    --peers http://node2:7331

# 4. node2 でプローブノード起動
echo "[start] Starting probe node on node2 (port 50053, probe 7331)..."
docker compose -f "$COMPOSE_FILE" exec -d node2 php tests/Support/cluster_test_node.php \
    --host node2 \
    --port 50053 \
    --auto-manage-port 6331 \
    --seeds node1:6330,node2:6331 \
    --probe-port 7331 \
    --peers http://node1:7330

# 5. プローブが ready になるまで待機 (最大 60 秒)
echo "[wait] Waiting for probe endpoints to become ready..."
TIMEOUT=60
ELAPSED=0

wait_probe() {
    local url="$1"
    while [ $ELAPSED -lt $TIMEOUT ]; do
        if curl -sf "${url}/ready" > /dev/null 2>&1; then
            echo "  [ok] ${url} is ready"
            return 0
        fi
        sleep 2
        ELAPSED=$((ELAPSED + 2))
    done
    echo "  [error] Timed out waiting for ${url}"
    return 1
}

wait_probe "http://localhost:7330"
ELAPSED=0
wait_probe "http://localhost:7331"

# 6. PHPUnit を node1 内で実行（ユニット＋統合テストを一括）
echo "[test] Running all tests inside node1..."
docker compose -f "$COMPOSE_FILE" exec -T \
    -e CLUSTER_PROBE_NODE1=http://localhost:7330 \
    -e CLUSTER_PROBE_NODE2=http://node2:7331 \
    node1 \
    php vendor/bin/phpunit --colors=always

echo "[done] Integration tests completed."
