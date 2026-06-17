<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:whisper:download',
    description: 'Download a local whisper.cpp model (ggml) so transcription works without any API key',
)]
class DownloadWhisperModelCommand extends Command
{
    /**
     * A few of the commonly used ggml models, smallest → largest. The English-only ".en"
     * variants are smaller and faster when you only need English.
     */
    private const KNOWN_MODELS = [
        'tiny.en', 'tiny', 'base.en', 'base', 'small.en', 'small', 'medium.en', 'medium', 'large-v3',
    ];

    private const BASE_URL = 'https://huggingface.co/ggerganov/whisper.cpp/resolve/main';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $whisperModelDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('model', InputArgument::OPTIONAL, 'Model to download (e.g. base.en, small, medium)', 'base.en')
            ->setHelp("Downloads a whisper.cpp ggml model into the model directory.\n\nExamples:\n  <info>php bin/console app:whisper:download</info>           # base.en (good default)\n  <info>php bin/console app:whisper:download small</info>     # more accurate, slower\n  <info>php bin/console app:whisper:download tiny.en</info>   # fastest, least accurate");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $model = (string) $input->getArgument('model');

        if (!\in_array($model, self::KNOWN_MODELS, true)) {
            $io->warning(sprintf('"%s" is not in the known list (%s). Trying anyway.', $model, implode(', ', self::KNOWN_MODELS)));
        }

        $filename = sprintf('ggml-%s.bin', $model);
        $url = self::BASE_URL . '/' . $filename;
        $target = rtrim($this->whisperModelDir, '/') . '/' . $filename;

        if (!is_dir($this->whisperModelDir) && !@mkdir($this->whisperModelDir, 0775, true) && !is_dir($this->whisperModelDir)) {
            $io->error(sprintf('Could not create model directory "%s".', $this->whisperModelDir));
            return Command::FAILURE;
        }

        if (is_file($target)) {
            $io->success(sprintf('Model already present: %s', $target));
            return Command::SUCCESS;
        }

        $io->info(sprintf('Downloading %s …', $url));

        $tmp = $target . '.part';
        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 0]);
            if ($response->getStatusCode() !== 200) {
                $io->error(sprintf('Download failed (HTTP %d). Is "%s" a valid model name?', $response->getStatusCode(), $model));
                return Command::FAILURE;
            }

            $handle = fopen($tmp, 'wb');
            if ($handle === false) {
                $io->error(sprintf('Could not open "%s" for writing.', $tmp));
                return Command::FAILURE;
            }

            $total = (int) ($response->getHeaders()['content-length'][0] ?? 0);
            $io->progressStart($total);
            $downloaded = 0;
            foreach ($this->httpClient->stream($response) as $chunk) {
                $content = $chunk->getContent();
                fwrite($handle, $content);
                $downloaded += \strlen($content);
                $io->progressAdvance(\strlen($content));
            }
            fclose($handle);
            $io->progressFinish();

            rename($tmp, $target);
        } catch (\Throwable $e) {
            @unlink($tmp);
            $io->error('Download failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf('Saved %s (%.1f MB). Set WHISPER_MODEL to this path if it is not the default.', $target, filesize($target) / 1_048_576));

        return Command::SUCCESS;
    }
}
