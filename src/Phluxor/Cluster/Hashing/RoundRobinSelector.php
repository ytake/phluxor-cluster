<?php

declare(strict_types=1);

namespace Phluxor\Cluster\Hashing;

use Phluxor\Cluster\Member;
use Swoole\Atomic;

final class RoundRobinSelector
{
    private Atomic $counter;

    public function __construct()
    {
        $this->counter = new Atomic(0);
    }

    /**
     * @param list<Member> $members
     */
    public function getByRoundRobin(array $members): ?string
    {
        if ($members === []) {
            return null;
        }

        $count = count($members);
        if ($count === 1) {
            return $members[0]->address();
        }

        $val = $this->counter->add(1);
        $index = $val % $count;

        return $members[$index]->address();
    }
}
