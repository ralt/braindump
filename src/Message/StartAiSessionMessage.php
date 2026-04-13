<?php

namespace App\Message;

use Symfony\Component\Uid\Uuid;

final readonly class StartAiSessionMessage
{
    public function __construct(
        public Uuid $sessionId,
        public Uuid $recordingId,
        public Uuid $userId,
    ) {}
}
