<?php

namespace App\Message;

final readonly class StartAiSessionMessage
{
    public function __construct(
        public string $sessionId,
        public string $recordingId,
        public string $userId,
    ) {}
}
