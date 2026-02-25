<?php

/**
 * Grain 定義ファイルのサンプル
 *
 * Usage:
 *   vendor/bin/phluxor-grain-gen \
 *     --definition=examples/grain_definition.example.php \
 *     --output=src/Generated/
 *
 * このファイルは複数の Grain を一度に定義できる。
 * 単一 Grain の場合は配列を1つ返すだけでよい。
 *
 * Generated files (per grain):
 *   src/Generated/{Kind}Actor.php  — abstract class（サーバー側: 継承して実装）
 *   src/Generated/{Kind}Client.php — final class  （クライアント側: そのまま使用）
 */

declare(strict_types=1);

return [
    // --- Grain 1 ---
    [
        // 生成クラスの namespace
        'namespace'       => 'YourApp\\Grain',

        // ProtoBuf メッセージクラスの namespace
        'proto_namespace' => 'YourApp\\Protobuf',

        // Grain の種別名（KindRegistry に登録する文字列と一致させる）
        'kind'            => 'HelloGrain',

        // RPC メソッド定義（.proto の rpc 定義順に並べる）
        'methods'         => [
            [
                'name'     => 'sayHello',
                'request'  => 'HelloRequest',
                'response' => 'HelloResponse',
            ],
            [
                'name'     => 'sayGoodbye',
                'request'  => 'GoodbyeRequest',
                'response' => 'GoodbyeResponse',
            ],
        ],
    ],

    // --- Grain 2 ---
    [
        'namespace'       => 'YourApp\\Grain',
        'proto_namespace' => 'YourApp\\Protobuf',
        'kind'            => 'CounterGrain',
        'methods'         => [
            [
                'name'     => 'increment',
                'request'  => 'IncrementRequest',
                'response' => 'CounterResponse',
            ],
            [
                'name'     => 'getCount',
                'request'  => 'GetCountRequest',
                'response' => 'CounterResponse',
            ],
        ],
    ],
];
