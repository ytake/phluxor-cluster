<?php

/**
 * Grain 定義ファイルのサンプル
 *
 * Usage:
 *   php tools/GrainGenerator.php \
 *     --definition=examples/grain_definition.example.php \
 *     --output=src/Generated/
 *
 * Generated files:
 *   src/Generated/HelloGrainActor.php  — abstract class (server-side, extend and implement)
 *   src/Generated/HelloGrainClient.php — final class (client-side, use directly)
 */

declare(strict_types=1);

return [
    // 生成クラスの namespace
    'namespace'       => 'YourApp\\Grain',

    // ProtoBuf メッセージクラスの namespace
    'proto_namespace' => 'YourApp\\Protobuf',

    // Grain の種別名（KindRegistry に登録する文字列）
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
];
