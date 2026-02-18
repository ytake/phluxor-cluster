# AGENTS.md

Phluxor Cluster のプロジェクト構造と各ディレクトリの役割を記載する。

## プロジェクト概要

PHP アクターシステム [Phluxor](https://github.com/ytake/phluxor) に Cluster / Virtual Actor 機能を提供するライブラリ。
[Proto.Cluster](https://asynkron.se/docs/protoactor/cluster/) に着想を得ており、WebSocket 上で Protocol Buffers によるノード間通信を行い、Swoole コルーチンによる非同期 I/O を前提とする。

- PHP 8.4+
- Swoole 拡張必須
- Apache-2.0 ライセンス

## ディレクトリツリー

```
phluxor-cluster/
├── protobuf/                          # ProtoBuf 定義（ソースオブトゥルース）
├── src/Phluxor/Cluster/
│   ├── Automanaged/                   # 自己管理型クラスタプロバイダ
│   ├── Exception/                     # 例外クラス
│   ├── Gossip/                        # Gossip プロトコル実装
│   ├── Grain/                         # Virtual Actor（Grain）層
│   ├── Hashing/                       # メンバー選択アルゴリズム
│   ├── Metadata/                      # ProtoBuf 自動生成メタデータ（編集不可）
│   ├── PartitionIdentity/             # パーティションベース ID ルックアップ
│   └── ProtoBuf/                      # ProtoBuf 自動生成メッセージクラス（編集不可）
├── tests/Test/                        # PHPUnit テストスイート（src 構成を反映）
├── examples/                          # クラスタノード起動サンプル
└── docs/                              # ドキュメント
```

## 各ディレクトリの役割

| ディレクトリ | 役割 |
|-------------|------|
| `protobuf/` | ProtoBuf 定義ファイル。`cluster.proto` にクラスタメッセージ型（ClusterIdentity, Activation, Grain, Gossip 関連）を定義 |
| `src/.../Cluster/`（ルート） | クラスタの中核。`Cluster` オーケストレータ、設定、メンバー管理、PID キャッシュ、トポロジーイベント、各種インターフェース（`ClusterProviderInterface`, `IdentityLookupInterface`, `MemberStrategyInterface`, `ClusterContextInterface`） |
| `Grain/` | Virtual Actor（Grain）層。抽象基底クラス `GrainBase`、RPC クライアント `GrainClient`、レスポンスデコーダ、初期化メッセージ |
| `Gossip/` | Gossip プロトコル。`Gossiper` エンジン、`GossipActor`、状態差分計算、ハートビート公開、コンセンサス検証 |
| `PartitionIdentity/` | Consistent Hashing によるアイデンティティルックアップ。`PartitionIdentityLookup`（`IdentityLookupInterface` 実装）、パーティション管理、配置アクター |
| `Automanaged/` | 外部設定不要の自己管理型プロバイダ。HTTP ヘルスエンドポイントとシードノードへの定期ポーリングでノード探索 |
| `Hashing/` | メンバー選択アルゴリズム。Rendezvous Hashing とラウンドロビン |
| `Exception/` | 例外クラス（アクティベーション失敗、RPC 失敗、デコード失敗、Identity 未発見） |
| `ProtoBuf/`, `Metadata/` | `cluster.proto` からの自動生成コード。手動編集不可、静的解析・リント対象外 |
| `examples/` | クラスタノード起動スクリプト（CLI 引数でホスト・ポート・シード等を指定） |

## アーキテクチャ概要

### リクエスト/レスポンスフロー

```
Client
  │
  ▼
Cluster::request(identity, kind, message)
  │
  ▼
DefaultClusterContext
  ├── PidCache（キャッシュヒット） ──→ Ref
  └── IdentityLookupInterface::get() ──→ Ref
        │
        ▼
  ActorSystem::requestFuture(ref, message, timeout)
        │
        ▼
  GrainBase::receive()
  └── onGrainRequest() ──→ GrainResponse / GrainErrorResponse
```

### トポロジー管理

```
ClusterProviderInterface（AutomanagedProvider）
  │  定期ポーリング / ヘルスチェック
  ▼
MemberList ──→ ClusterTopologyEvent
  │
  ▼
Gossiper ──→ GossipActor
  │  タイマーベースのピア間状態交換
  ▼
ConsensusCheck（トポロジーコンセンサス検証）
```

### Identity ルックアップ

```
IdentityLookupInterface
  └── PartitionIdentityLookup
        └── PartitionManager
              └── PartitionPlacementActor（Grain のアクティベーション・配置）
                    └── Consistent Hashing（RendezvousHashSelector）
```

## コマンド

| コマンド | 説明 |
|----------|------|
| `composer tests` | PHPUnit テスト実行 |
| `composer cs` | コードスタイルチェック（PSR-12 + Slevomat、ProtoBuf 生成コード除外） |
| `composer cs-fix` | コードスタイル自動修正 |

## 依存関係

| パッケージ | 用途 |
|-----------|------|
| `phluxor/phluxor` | コア アクターシステム |
| `phluxor/phluxor-websocket` | WebSocket トランスポート |
| `google/protobuf` | Protocol Buffers シリアライゼーション |
| `ext-swoole` | 非同期 I/O・コルーチン |

## コード品質

- PHPStan レベル max（`treatPhpDocTypesAsCertain: false`）
- PSR-12 + Slevomat Coding Standard
- ProtoBuf 自動生成ファイル（`ProtoBuf/`, `Metadata/`）は解析・リント対象外
- 1 公開型 = 1 ファイルの原則
