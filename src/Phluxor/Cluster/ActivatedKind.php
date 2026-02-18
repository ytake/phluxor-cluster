<?php

declare(strict_types=1);

namespace Phluxor\Cluster;

use Phluxor\ActorSystem\Props;

final readonly class ActivatedKind
{
    public function __construct(
        private string $kind,
        private Props $props
    ) {
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function props(): Props
    {
        return $this->props;
    }
}
