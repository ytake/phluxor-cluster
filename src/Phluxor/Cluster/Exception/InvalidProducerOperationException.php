<?php

declare(strict_types=1);

namespace Phluxor\Cluster\Exception;

use RuntimeException;

final class InvalidProducerOperationException extends RuntimeException
{
    public function __construct(
        private readonly string $topic,
        int $code = 0,
    ) {
        parent::__construct(
            sprintf('Producer for topic %s is stopped', $this->topic),
            $code
        );
    }

    public function topic(): string
    {
        return $this->topic;
    }
}
