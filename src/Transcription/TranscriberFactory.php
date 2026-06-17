<?php

namespace App\Transcription;

use Symfony\AI\Platform\PlatformInterface;

/**
 * Picks the transcription backend from the TRANSCRIBER env var. Defaults to the local
 * whisper.cpp model so the app transcribes out of the box with no API key.
 */
class TranscriberFactory
{
    public function __construct(
        private string $transcriber,
        private string $whisperBinary,
        private string $whisperModel,
        private string $ffmpegBinary,
        private PlatformInterface $platform,
    ) {}

    public function create(): TranscriberInterface
    {
        return match ($this->transcriber) {
            'openai' => new OpenAiTranscriber($this->platform),
            default => new LocalWhisperTranscriber($this->whisperBinary, $this->whisperModel, $this->ffmpegBinary),
        };
    }
}
