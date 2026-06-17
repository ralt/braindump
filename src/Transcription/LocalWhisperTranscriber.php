<?php

namespace App\Transcription;

use Symfony\Component\Process\Process;

/**
 * On-device transcription via whisper.cpp — no API key, nothing leaves the machine.
 *
 * whisper.cpp wants 16 kHz mono PCM WAV, but the browser records WebM/Opus, so we
 * transcode with ffmpeg first. The model file (ggml-*.bin) is fetched with
 * `php bin/console app:whisper:download`.
 */
final class LocalWhisperTranscriber implements TranscriberInterface
{
    public function __construct(
        private string $whisperBinary,
        private string $whisperModel,
        private string $ffmpegBinary,
    ) {}

    public function transcribe(string $audioPath): string
    {
        if (!is_file($this->whisperModel)) {
            throw new \RuntimeException(sprintf(
                'Whisper model not found at "%s". Run "php bin/console app:whisper:download" to fetch one, '
                . 'or set TRANSCRIBER=openai with an OPENAI_API_KEY to use the hosted Whisper API instead.',
                $this->whisperModel,
            ));
        }

        $wavPath = tempnam(sys_get_temp_dir(), 'whisper_');
        try {
            $this->run([
                $this->ffmpegBinary, '-nostdin', '-y',
                '-i', $audioPath,
                '-ar', '16000', '-ac', '1', '-c:a', 'pcm_s16le', '-f', 'wav',
                $wavPath,
            ], 300, 'ffmpeg audio conversion');

            $whisper = $this->run([
                $this->whisperBinary,
                '-m', $this->whisperModel,
                '-f', $wavPath,
                '-nt', // no timestamps — emit just the text
                '-np', // no progress prints
            ], 1800, 'whisper.cpp transcription');

            return trim($whisper->getOutput());
        } finally {
            @unlink($wavPath);
        }
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command, int $timeout, string $label): Process
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            $detail = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            throw new \RuntimeException(sprintf('%s failed: %s', $label, $detail));
        }

        return $process;
    }
}
