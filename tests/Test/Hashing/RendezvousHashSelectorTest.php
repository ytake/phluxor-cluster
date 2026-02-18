<?php

declare(strict_types=1);

namespace Test\Hashing;

use PHPUnit\Framework\TestCase;
use Phluxor\Cluster\Hashing\RendezvousHashSelector;
use Phluxor\Cluster\Member;

final class RendezvousHashSelectorTest extends TestCase
{
    public function testDeterministicSelection(): void
    {
        $selector = new RendezvousHashSelector();
        $members = [
            new Member('host1', 50051, 'node-1', ['grain1']),
            new Member('host2', 50052, 'node-2', ['grain1']),
            new Member('host3', 50053, 'node-3', ['grain1']),
        ];
        $key = 'test-key';

        $first = $selector->getPartition($key, $members);

        for ($i = 0; $i < 10; $i++) {
            self::assertSame($first, $selector->getPartition($key, $members));
        }
    }

    public function testReturnsNullForEmptyMembers(): void
    {
        $selector = new RendezvousHashSelector();
        self::assertNull($selector->getPartition('key', []));
    }

    public function testDistribution(): void
    {
        $selector = new RendezvousHashSelector();
        $members = [
            new Member('host1', 50051, 'node-1', ['grain1']),
            new Member('host2', 50052, 'node-2', ['grain1']),
            new Member('host3', 50053, 'node-3', ['grain1']),
        ];

        /** @var array<string, int> $counts */
        $counts = [];
        foreach ($members as $member) {
            $counts[$member->address()] = 0;
        }

        for ($i = 0; $i < 100; $i++) {
            $key = 'key-' . $i;
            $node = $selector->getPartition($key, $members);
            self::assertNotNull($node);
            $counts[$node]++;
        }

        foreach ($counts as $address => $count) {
            self::assertGreaterThan(0, $count, "Node $address received no keys");
        }
    }

    public function testConsistencyOnMemberRemoval(): void
    {
        $selector = new RendezvousHashSelector();
        $m1 = new Member('host1', 50051, 'node-1', ['grain1']);
        $m2 = new Member('host2', 50052, 'node-2', ['grain1']);
        $m3 = new Member('host3', 50053, 'node-3', ['grain1']);
        $members = [$m1, $m2, $m3];

        /** @var array<string, string|null> $assignments */
        $assignments = [];
        for ($i = 0; $i < 100; $i++) {
            $key = 'key-' . $i;
            $assignments[$key] = $selector->getPartition($key, $members);
        }

        $newMembers = [$m1, $m2];
        $kept = 0;
        $totalToCheck = 0;

        foreach ($assignments as $key => $oldNode) {
            if ($oldNode !== $m3->address()) {
                $totalToCheck++;
                $newNode = $selector->getPartition($key, $newMembers);
                if ($newNode === $oldNode) {
                    $kept++;
                }
            }
        }

        if ($totalToCheck > 0) {
            self::assertGreaterThanOrEqual(
                (int) ($totalToCheck * 0.5),
                $kept,
                'At least 50% of keys on remaining nodes should stay'
            );
        }
    }

    public function testGetActivatorWithLocalAffinity(): void
    {
        $selector = new RendezvousHashSelector();
        $sender = 'host1:50051';
        $members = [
            new Member('host1', 50051, 'node-1', ['grain1']),
            new Member('host2', 50052, 'node-2', ['grain1']),
        ];

        self::assertSame($sender, $selector->getActivator($sender, $members));
    }

    public function testGetActivatorFallback(): void
    {
        $selector = new RendezvousHashSelector();
        $sender = 'hostX:9999';
        $members = [
            new Member('host1', 50051, 'node-1', ['grain1']),
            new Member('host2', 50052, 'node-2', ['grain1']),
        ];

        self::assertSame($members[0]->address(), $selector->getActivator($sender, $members));
    }
}
