<?php

namespace App\Message;

final readonly class RunCiMessage
{
    public function __construct(
        public \DateTimeImmutable $triggeredAt = new \DateTimeImmutable(),
    ) {}
}
