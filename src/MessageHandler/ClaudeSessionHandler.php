<?php

namespace App\MessageHandler;

use App\Entity\ClaudeSession;
use App\Entity\Recording;
use App\Entity\User;
use App\Enum\ClaudeSessionStatus;
use App\Message\StartClaudeSessionMessage;
use App\Service\ApiKeyEncryptorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ClaudeSessionHandler implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private HubInterface $hub,
        private ApiKeyEncryptorInterface $encryptor,
    ) {
        $this->logger = new NullLogger();
    }

    public function __invoke(StartClaudeSessionMessage $message): void
    {
        $session = $this->em->find(ClaudeSession::class, $message->sessionId);
        $recording = $this->em->find(Recording::class, $message->recordingId);
        $user = $this->em->find(User::class, $message->userId);

        if ($session === null || $recording === null || $user === null) {
            return;
        }

        $topic = 'claude-session/' . $session->getId();

        // Create temp directory for the session
        $tmpDir = sys_get_temp_dir() . '/claude-sessions/' . $session->getId();
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        // Write transcription for Claude to read
        $transcriptionPath = $tmpDir . '/transcription.md';
        file_put_contents($transcriptionPath, $recording->getTranscription() ?? '');

        // Create FIFO for user input
        $fifoPath = $tmpDir . '/input.fifo';
        if (!file_exists($fifoPath)) {
            posix_mkfifo($fifoPath, 0600);
        }

        // Decrypt API key
        $apiKey = '';
        if ($user->getEncryptedAnthropicApiKey()) {
            try {
                $apiKey = $this->encryptor->decrypt($user->getEncryptedAnthropicApiKey());
            } catch (\Throwable) {
                $this->publishOutput($topic, "\r\nError: Could not decrypt API key\r\n");
                $session->setStatus(ClaudeSessionStatus::Closed);
                $session->setClosedAt(new \DateTimeImmutable());
                $this->em->flush();
                return;
            }
        }

        // Start Claude process
        $claudeBin = trim(shell_exec('which claude') ?? '');
        if ($claudeBin === '') {
            $this->publishOutput($topic, "\r\nError: claude binary not found in PATH\r\n");
            $session->setStatus(ClaudeSessionStatus::Closed);
            $session->setClosedAt(new \DateTimeImmutable());
            $this->em->flush();
            return;
        }

        // Set env vars for the child process, then pass null to proc_open
        // to inherit the full parent environment (passing an array replaces it entirely,
        // and $_SERVER/$_ENV in Symfony contain non-string values that break proc_open).
        putenv('ANTHROPIC_API_KEY=' . $apiKey);
        putenv('TMPDIR=' . $tmpDir);

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $initialPrompt = sprintf(
            'Read the transcription in %s and summarize what it contains, then wait for my instructions.',
            $transcriptionPath
        );

        $cmd = sprintf(
            '%s --verbose %s',
            escapeshellarg($claudeBin),
            escapeshellarg($initialPrompt)
        );

        $this->logger->info('Starting Claude process', [
            'session' => (string) $session->getId(),
            'recording' => (string) $recording->getId(),
            'user' => $user->getEmail(),
            'cmd' => $cmd,
        ]);

        $process = proc_open($cmd, $descriptors, $pipes, $tmpDir);

        if (!is_resource($process)) {
            $this->publishOutput($topic, "\r\nError: Could not start Claude process\r\n");
            $session->setStatus(ClaudeSessionStatus::Closed);
            $session->setClosedAt(new \DateTimeImmutable());
            $this->em->flush();
            return;
        }

        // Set stdout and stderr to non-blocking
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $session->setStatus(ClaudeSessionStatus::Running);
        $this->em->flush();

        $this->publishOutput($topic, "Claude session started. Reading transcription...\r\n");

        // Main loop: read stdout/stderr and forward to Mercure, read FIFO for input
        $fifo = null;
        $running = true;

        while ($running) {
            // Read stdout
            $output = fread($pipes[1], 4096);
            if ($output !== false && $output !== '') {
                $this->publishOutput($topic, $output);
            }

            // Read stderr
            $stderr = fread($pipes[2], 4096);
            if ($stderr !== false && $stderr !== '') {
                $this->publishOutput($topic, $stderr);
            }

            // Check if process is still running
            $status = proc_get_status($process);
            if (!$status['running']) {
                // Read any remaining output
                $remaining = stream_get_contents($pipes[1]);
                if ($remaining) {
                    $this->publishOutput($topic, $remaining);
                }
                $remaining = stream_get_contents($pipes[2]);
                if ($remaining) {
                    $this->publishOutput($topic, $remaining);
                }
                $running = false;
                break;
            }

            // Try to read from FIFO (non-blocking)
            if ($fifo === null && file_exists($fifoPath)) {
                $fifo = @fopen($fifoPath, 'r');
                if ($fifo) {
                    stream_set_blocking($fifo, false);
                }
            }

            if ($fifo) {
                $input = fread($fifo, 4096);
                if ($input !== false && $input !== '') {
                    if ($input === "\x04") {
                        // EOT - close signal
                        proc_terminate($process);
                        $running = false;
                        break;
                    }
                    fwrite($pipes[0], $input);
                    fflush($pipes[0]);
                }

                if (feof($fifo)) {
                    fclose($fifo);
                    $fifo = null;
                    // Re-create FIFO for next write
                    if (file_exists($fifoPath)) {
                        unlink($fifoPath);
                    }
                    posix_mkfifo($fifoPath, 0600);
                }
            }

            usleep(50000); // 50ms
        }

        // Cleanup
        putenv('ANTHROPIC_API_KEY');
        putenv('TMPDIR');
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($fifo) {
            fclose($fifo);
        }

        // Clean up temp directory
        $this->cleanupDir($tmpDir);

        $session->setStatus(ClaudeSessionStatus::Closed);
        $session->setClosedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->publishOutput($topic, "\r\nSession ended.\r\n");
    }

    private function publishOutput(string $topic, string $data): void
    {
        try {
            $this->hub->publish(new Update(
                $topic,
                json_encode(['output' => $data]),
            ));
        } catch (\Throwable) {
            // Mercure may not be available
        }
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
