<?php

namespace App\Transcription;

/**
 * Turns an audio file into a text transcription.
 *
 * Implementations are selected by the TRANSCRIBER env var via {@see TranscriberFactory}:
 * "local" (default, runs whisper.cpp on-device — no API key) or "openai" (Whisper API).
 */
interface TranscriberInterface
{
    /**
     * @param string $audioPath Absolute path to the recorded audio file.
     *
     * @throws \RuntimeException if transcription cannot be performed
     */
    public function transcribe(string $audioPath): string;
}
