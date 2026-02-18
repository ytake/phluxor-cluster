<?php

declare(strict_types=1);

namespace Test;

use PHPUnit\Framework\TestCase;
use Phluxor\Cluster\BlockList;

final class BlockListTest extends TestCase
{
    public function testInitiallyEmpty(): void
    {
        $blockList = new BlockList();

        self::assertSame(0, $blockList->count());
        self::assertFalse($blockList->isBlocked('node-1'));
    }

    public function testBlockSingleMember(): void
    {
        $blockList = new BlockList();

        $blockList->block('node-1');

        self::assertTrue($blockList->isBlocked('node-1'));
        self::assertSame(1, $blockList->count());
    }

    public function testBlockMultipleMembers(): void
    {
        $blockList = new BlockList();

        $blockList->block('node-1', 'node-2', 'node-3');

        self::assertTrue($blockList->isBlocked('node-1'));
        self::assertTrue($blockList->isBlocked('node-2'));
        self::assertTrue($blockList->isBlocked('node-3'));
        self::assertSame(3, $blockList->count());
    }

    public function testBlockDuplicateIsIdempotent(): void
    {
        $blockList = new BlockList();

        $blockList->block('node-1');
        $blockList->block('node-1');

        self::assertTrue($blockList->isBlocked('node-1'));
        self::assertSame(1, $blockList->count());
    }

    public function testBlockedMembers(): void
    {
        $blockList = new BlockList();

        $blockList->block('node-1', 'node-2');

        self::assertSame(['node-1', 'node-2'], $blockList->blockedMembers());
    }

    public function testIsBlockedReturnsFalseForUnknown(): void
    {
        $blockList = new BlockList();

        $blockList->block('node-1');

        self::assertFalse($blockList->isBlocked('node-999'));
    }
}
