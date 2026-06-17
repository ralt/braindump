<?php

namespace App\Transcription;

use Symfony\AI\Platform\Message\Content\Audio;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Hosted transcription via the OpenAI Whisper API. Opt-in (TRANSCRIBER=openai); faster
 * and more accurate than the local model, but requires an OPENAI_API_KEY and sends audio
 * to OpenAI.
 */
final class OpenAiTranscriber implements TranscriberInterface
{
    public function __construct(
        private PlatformInterface $platform,
    ) {}

    public function transcribe(string $audioPath): string
    {
        $audio = Audio::fromFile($audioPath);

        return $this->platform->invoke('whisper-1', $audio)->asText();
    }
}
