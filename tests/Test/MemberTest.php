<?php

declare(strict_types=1);

namespace Test;

use PHPUnit\Framework\TestCase;
use Phluxor\Cluster\Member;

final class MemberTest extends TestCase
{
    public function testAddress(): void
    {
        $member = new Member('localhost', 50052, 'node-1', ['UserGrain']);

        self::assertSame('localhost:50052', $member->address());
    }

    public function testHasKind(): void
    {
        $member = new Member('localhost', 50052, 'node-1', ['UserGrain', 'OrderGrain']);

        self::assertTrue($member->hasKind('UserGrain'));
        self::assertFalse($member->hasKind('UnknownGrain'));
    }
}
