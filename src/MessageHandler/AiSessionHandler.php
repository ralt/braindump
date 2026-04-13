<?php

namespace App\MessageHandler;

use App\Entity\AiSession;
use App\Entity\Recording;
use App\Entity\User;
use App\Enum\AiSessionStatus;
use App\Message\StartAiSessionMessage;
use App\Service\ApiKeyEncryptorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class AiSessionHandler implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const PROVIDER_ENV_MAP = [
        'anthropic' => 'ANTHROPIC_API_KEY',
        'openai' => 'OPENAI_API_KEY',
        'google' => 'GOOGLE_API_KEY',
        'groq' => 'GROQ_API_KEY',
        'mistral' => 'MISTRAL_API_KEY',
        'deepseek' => 'DEEPSEEK_API_KEY',
        'xai' => 'XAI_API_KEY',
        'openrouter' => 'OPENROUTER_API_KEY',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private HubInterface $hub,
        private ApiKeyEncryptorInterface $encryptor,
    ) {
        $this->logger = new NullLogger();
    }

    public function __invoke(StartAiSessionMessage $message): void
    {
        $session = $this->em->find(AiSession::class, $message->sessionId);
        $recording = $this->em->find(Recording::class, $message->recordingId);
        $user = $this->em->find(User::class, $message->userId);

        if ($session === null || $recording === null || $user === null) {
            return;
        }

        $topic = 'ai-session/' . $session->getId();

        // Create temp directory for the session
        $tmpDir = sys_get_temp_dir() . '/ai-sessions/' . $session->getId();
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        // Write transcription for the AI to read
        $transcriptionPath = $tmpDir . '/transcription.md';
        file_put_contents($transcriptionPath, $recording->getTranscription() ?? '');

        // Create FIFO for user input
        $fifoPath = $tmpDir . '/input.fifo';
        if (!file_exists($fifoPath)) {
            posix_mkfifo($fifoPath, 0600);
        }

        // Decrypt API key and determine provider env var
        $provider = $user->getAiProvider() ?? 'anthropic';
        $envVar = self::PROVIDER_ENV_MAP[$provider] ?? 'ANTHROPIC_API_KEY';
        $apiKey = '';

        if ($user->getEncryptedAiApiKey()) {
            try {
                $apiKey = $this->encryptor->decrypt($user->getEncryptedAiApiKey());
            } catch (\Throwable) {
                $this->publishOutput($topic, "\r\nError: Could not decrypt API key\r\n");
                $session->setStatus(AiSessionStatus::Closed);
                $session->setClosedAt(new \DateTimeImmutable());
                $this->em->flush();
                return;
            }
        }

        // Start pi process
        $piBin = trim(shell_exec('which pi') ?? '');
        if ($piBin === '') {
            $this->publishOutput($topic, "\r\nError: pi binary not found in PATH\r\n");
            $session->setStatus(AiSessionStatus::Closed);
            $session->setClosedAt(new \DateTimeImmutable());
            $this->em->flush();
            return;
        }

        // Set env vars for the child process, then pass null to proc_open
        // to inherit the full parent environment.
        putenv($envVar . '=' . $apiKey);
        putenv('TMPDIR=' . $tmpDir);

        // Use PTY so pi.dev sees a real terminal (enables colors, cursor, ANSI)
        $descriptors = [
            0 => ['pty'],     // stdin  — pi.dev sees a TTY
            1 => ['pty'],     // stdout — merged with stdin on the master side
            2 => ['pipe', 'w'], // stderr — kept separate for error visibility
        ];

        $initialPrompt = sprintf(
            'Read the transcription in %s and summarize what it contains, then wait for my instructions.',
            $transcriptionPath
        );

        $cmd = sprintf(
            '%s --verbose %s',
            escapeshellarg($piBin),
            escapeshellarg($initialPrompt)
        );

        $this->log('Starting pi process', [
            'session' => (string) $session->getId(),
            'recording' => (string) $recording->getId(),
            'user' => $user->getEmail(),
            'provider' => $provider,
            'piBin' => $piBin,
            'cmd' => $cmd,
            'tmpDir' => $tmpDir,
        ]);

        $this->publishOutput($topic, "Launching pi.dev session...\r\n");

        $process = proc_open($cmd, $descriptors, $pipes, $tmpDir);

        if (!is_resource($process)) {
            $this->log('Failed to start pi process');
            $this->publishOutput($topic, "\r\nError: Could not start pi process\r\n");
            $session->setStatus(AiSessionStatus::Closed);
            $session->setClosedAt(new \DateTimeImmutable());
            $this->em->flush();
            return;
        }

        // PTY: pipes[0] is the master end (read/write), pipes[2] is stderr
        $ptyMaster = $pipes[0];
        $stderr = $pipes[2];
        stream_set_blocking($ptyMaster, false);
        stream_set_blocking($stderr, false);

        $session->setStatus(AiSessionStatus::Running);
        $this->em->flush();

        $this->publishOutput($topic, "AI session started. Reading transcription...\r\n");

        // Use Revolt event loop with fibers for concurrent I/O
        $callbackIds = [];

        // Green thread 1: read PTY output → publish to Mercure
        $callbackIds[] = EventLoop::onReadable($ptyMaster, function (string $id, $stream) use ($topic, $process, &$callbackIds) {
            $data = @fread($stream, 4096);
            if ($data !== false && $data !== '') {
                $this->publishOutput($topic, $data);
            }
            if ($data === false || feof($stream)) {
                $this->cancelAll($callbackIds);
            }
        });

        // Green thread 2: read stderr → publish to Mercure
        $callbackIds[] = EventLoop::onReadable($stderr, function (string $id, $stream) use ($topic) {
            $data = @fread($stream, 4096);
            if ($data !== false && $data !== '') {
                $this->publishOutput($topic, $data);
            }
        });

        // Green thread 3: poll FIFO for user input → write to PTY stdin
        // FIFO needs periodic re-opening (writer closes after each message),
        // so we use a repeating timer instead of onReadable.
        $fifo = null;
        $callbackIds[] = EventLoop::repeat(0.05, function (string $id) use (&$fifo, $fifoPath, $ptyMaster, $process, &$callbackIds) {
            if ($fifo === null && file_exists($fifoPath)) {
                // Use 'r+' (read-write) to avoid blocking: 'r' on a FIFO
                // blocks until a writer opens the other end, which would
                // stall the entire event loop.
                $fifo = @fopen($fifoPath, 'r+');
                if ($fifo) {
                    stream_set_blocking($fifo, false);
                }
            }

            if ($fifo === null) {
                return;
            }

            $input = @fread($fifo, 4096);
            if ($input !== false && $input !== '') {
                if ($input === "\x04") {
                    proc_terminate($process);
                    $this->cancelAll($callbackIds);
                    return;
                }
                @fwrite($ptyMaster, $input);
            }

            if (feof($fifo)) {
                fclose($fifo);
                $fifo = null;
                if (file_exists($fifoPath)) {
                    unlink($fifoPath);
                }
                posix_mkfifo($fifoPath, 0600);
            }
        });

        // Green thread 4: check if process is still alive
        $callbackIds[] = EventLoop::repeat(0.2, function (string $id) use ($process, $ptyMaster, $stderr, $topic, &$callbackIds) {
            $status = proc_get_status($process);
            if ($status['running']) {
                return;
            }

            // Drain remaining output
            $remaining = @stream_get_contents($ptyMaster);
            if ($remaining) {
                $this->publishOutput($topic, $remaining);
            }
            $remaining = @stream_get_contents($stderr);
            if ($remaining) {
                $this->publishOutput($topic, $remaining);
            }

            $this->log('pi process exited', [
                'exitcode' => $status['exitcode'],
                'signaled' => $status['signaled'],
                'termsig' => $status['termsig'],
            ]);

            $this->cancelAll($callbackIds);
        });

        // Run the event loop — blocks until all callbacks are cancelled
        EventLoop::run();

        // Cleanup
        putenv($envVar);
        putenv('TMPDIR');
        @fclose($ptyMaster);
        @fclose($stderr);
        proc_close($process);

        if ($fifo) {
            @fclose($fifo);
        }

        $this->cleanupDir($tmpDir);

        $session->setStatus(AiSessionStatus::Closed);
        $session->setClosedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->publishOutput($topic, "\r\nSession ended.\r\n");
    }

    /**
     * @param list<string> $ids
     */
    private function cancelAll(array &$ids): void
    {
        foreach ($ids as $id) {
            EventLoop::cancel($id);
        }
        $ids = [];
    }

    private function publishOutput(string $topic, string $data): void
    {
        error_log('[AiSession] Publishing to topic=' . $topic . ' len=' . strlen($data));
        try {
            $id = $this->hub->publish(new Update(
                $topic,
                json_encode(['output' => $data]),
            ));
            error_log('[AiSession] Published OK id=' . $id);
        } catch (\Throwable $e) {
            error_log('[AiSession] Mercure publish error: ' . $e->getMessage());
        }
    }

    private function log(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
        error_log('[AiSession] ' . $message . ' ' . json_encode($context));
    }

    private function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($dir);
    }
}
