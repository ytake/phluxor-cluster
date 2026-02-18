<?php

declare(strict_types=1);

namespace Test\Automanaged;

use PHPUnit\Framework\TestCase;
use Phluxor\Cluster\Automanaged\NodeModel;

final class NodeModelTest extends TestCase
{
    public function testConstructAndAccessors(): void
    {
        $node = new NodeModel(
            id: 'node-1',
            address: '127.0.0.1',
            port: 8080,
            autoManagePort: 6330,
            kinds: ['user', 'order'],
            clusterName: 'my-cluster'
        );

        self::assertSame('node-1', $node->id);
        self::assertSame('127.0.0.1', $node->address);
        self::assertSame(8080, $node->port);
        self::assertSame(6330, $node->autoManagePort);
        self::assertSame(['user', 'order'], $node->kinds);
        self::assertSame('my-cluster', $node->clusterName);
    }

    public function testToJsonAndFromJson(): void
    {
        $node = new NodeModel(
            id: 'node-1',
            address: '127.0.0.1',
            port: 8080,
            autoManagePort: 6330,
            kinds: ['user', 'order'],
            clusterName: 'my-cluster'
        );

        $json = $node->toJson();
        $decoded = NodeModel::fromJson($json);

        self::assertNotNull($decoded);
        self::assertSame('node-1', $decoded->id);
        self::assertSame('127.0.0.1', $decoded->address);
        self::assertSame(8080, $decoded->port);
        self::assertSame(6330, $decoded->autoManagePort);
        self::assertSame(['user', 'order'], $decoded->kinds);
        self::assertSame('my-cluster', $decoded->clusterName);
    }

    public function testFromJsonReturnsNullForInvalidJson(): void
    {
        self::assertNull(NodeModel::fromJson('not valid json'));
    }

    public function testFromJsonReturnsNullForMissingFields(): void
    {
        self::assertNull(NodeModel::fromJson('{"id":"node-1"}'));
    }

    public function testToJsonProducesValidJsonString(): void
    {
        $node = new NodeModel(
            id: 'node-1',
            address: '127.0.0.1',
            port: 8080,
            autoManagePort: 6330,
            kinds: [],
            clusterName: 'test'
        );

        $json = $node->toJson();
        $data = json_decode($json, true);

        self::assertIsArray($data);
        self::assertSame('node-1', $data['id']);
        self::assertSame('127.0.0.1', $data['address']);
        self::assertSame(8080, $data['port']);
        self::assertSame(6330, $data['autoManagePort']);
        self::assertSame([], $data['kinds']);
        self::assertSame('test', $data['clusterName']);
    }

    public function testEmptyKinds(): void
    {
        $node = new NodeModel(
            id: 'node-1',
            address: '127.0.0.1',
            port: 8080,
            autoManagePort: 6330,
            kinds: [],
            clusterName: 'test'
        );

        $json = $node->toJson();
        $decoded = NodeModel::fromJson($json);

        self::assertNotNull($decoded);
        self::assertSame([], $decoded->kinds);
    }
}
