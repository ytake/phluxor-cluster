<?php

declare(strict_types=1);

namespace Phluxor\Cluster\Hashing;

use Phluxor\Cluster\Member;

final class RendezvousHashSelector
{
    /**
     * @param string $key
     * @param list<Member> $members
     * @return string|null
     */
    public function getPartition(string $key, array $members): ?string
    {
        if ($members === []) {
            return null;
        }

        $maxHash = 0;
        $selected = null;

        foreach ($members as $member) {
            $address = $member->address();
            $hash = $this->fnv32($key . $address);

            if ($selected === null || $hash > $maxHash) {
                $maxHash = $hash;
                $selected = $address;
            }
        }

        return $selected;
    }

    /**
     * @param string $senderAddress
     * @param list<Member> $members
     * @return string|null
     */
    public function getActivator(string $senderAddress, array $members): ?string
    {
        if ($members === []) {
            return null;
        }

        foreach ($members as $member) {
            if ($member->address() === $senderAddress) {
                return $senderAddress;
            }
        }

        return $members[0]->address();
    }

    private function fnv32(string $key): int
    {
        $hash = 0x811c9dc5;
        $prime32 = 0x01000193;
        $len = strlen($key);
        for ($i = 0; $i < $len; $i++) {
            $hash ^= ord($key[$i]);
            $hash = ($hash * $prime32) & 0xffffffff;
        }
        return $hash;
    }
}
