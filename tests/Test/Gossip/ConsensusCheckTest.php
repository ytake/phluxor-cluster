<?php

declare(strict_types=1);

namespace Test\Gossip;

use Phluxor\Cluster\Gossip\ConsensusCheck;
use Phluxor\Cluster\ProtoBuf\GossipKeyValue;
use PHPUnit\Framework\TestCase;

final class ConsensusCheckTest extends TestCase
{
    public function testIdAccessor(): void
    {
        $check = new ConsensusCheck(
            'topology-check',
            ['topology'],
            fn(array $values) => true
        );

        self::assertSame('topology-check', $check->id());
    }

    public function testAffectedKeysAccessor(): void
    {
        $check = new ConsensusCheck(
            'check-1',
            ['topology', 'heartbeat'],
            fn(array $values) => true
        );

        self::assertSame(['topology', 'heartbeat'], $check->affectedKeys());
    }

    public function testHasConsensusReturnsTrueWhenAllValuesMatch(): void
    {
        $check = new ConsensusCheck(
            'topology-check',
            ['topology'],
            function (array $values): bool {
                $hashes = [];
                foreach ($values as $kv) {
                    $hashes[] = $kv->getValue();
                }
                return count(array_unique($hashes)) === 1;
            }
        );

        $kv1 = new GossipKeyValue();
        $kv1->setValue('hash-abc');
        $kv2 = new GossipKeyValue();
        $kv2->setValue('hash-abc');

        self::assertTrue($check->hasConsensus([
            'member-1' => $kv1,
            'member-2' => $kv2,
        ]));
    }

    public function testHasConsensusReturnsFalseWhenValuesDiffer(): void
    {
        $check = new ConsensusCheck(
            'topology-check',
            ['topology'],
            function (array $values): bool {
                $hashes = [];
                foreach ($values as $kv) {
                    $hashes[] = $kv->getValue();
                }
                return count(array_unique($hashes)) === 1;
            }
        );

        $kv1 = new GossipKeyValue();
        $kv1->setValue('hash-abc');
        $kv2 = new GossipKeyValue();
        $kv2->setValue('hash-xyz');

        self::assertFalse($check->hasConsensus([
            'member-1' => $kv1,
            'member-2' => $kv2,
        ]));
    }

    public function testHasConsensusWithEmptyValues(): void
    {
        $invoked = false;
        $check = new ConsensusCheck(
            'check-1',
            ['key'],
            function (array $values) use (&$invoked): bool {
                $invoked = true;
                return $values === [];
            }
        );

        self::assertTrue($check->hasConsensus([]));
        self::assertTrue($invoked);
    }
}
