<?php

declare(strict_types=1);

namespace Phluxor\Cluster\PubSub;

use Swoole\Atomic;

final class ProduceProcessInfo
{
    private Atomic $finished;
    private Atomic $cancelled;
    private ?\Throwable $error = null;

    public function __construct()
    {
        $this->finished = new Atomic(0);
        $this->cancelled = new Atomic(0);
    }

    public function complete(): void
    {
        $this->finished->set(1);
    }

    public function fail(\Throwable $error): void
    {
        $this->error = $error;
        $this->finished->set(1);
    }

    public function cancel(): void
    {
        $this->cancelled->set(1);
        $this->finished->set(1);
    }

    public function isFinished(): bool
    {
        return $this->finished->get() === 1;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled->get() === 1;
    }

    public function error(): ?\Throwable
    {
        return $this->error;
    }
}
