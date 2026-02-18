<?php

declare(strict_types=1);

namespace Phluxor\Cluster\Exception;

use RuntimeException;

final class GrainCallException extends RuntimeException
{
    public function __construct(
        string $grainError,
        int $code = 0,
    ) {
        parent::__construct($grainError, $code);
    }
}
