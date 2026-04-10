<?php

namespace App\Message;

use Symfony\Component\Uid\Uuid;

final readonly class TranscribeRecordingMessage
{
    public function __construct(
        public Uuid $recordingId,
    ) {}
}
