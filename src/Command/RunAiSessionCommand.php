<?php

namespace App\Command;

use App\Entity\AiSession;
use App\Entity\Recording;
use App\Entity\User;
use App\Enum\AiSessionStatus;
use App\Service\ApiKeyEncryptorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[AsCommand(name: 'app:run-ai-session', description: 'Run a single AI session (launched by the messenger handler)')]
final class RunAiSessionCommand extends Command
{
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
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('sessionId', InputArgument::REQUIRED)
            ->addArgument('recordingId', InputArgument::REQUIRED)
            ->addArgument('userId', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $session = $this->em->find(AiSession::class, $input->getArgument('sessionId'));
        $recording = $this->em->find(Recording::class, $input->getArgument('recordingId'));
        $user = $this->em->find(User::class, $input->getArgument('userId'));

        if ($session === null || $recording === null || $user === null) {
            $this->logger->error('[AiSession] Entity not found', [
                'session' => $input->getArgument('sessionId'),
                'recording' => $input->getArgument('recordingId'),
                'user' => $input->getArgument('userId'),
            ]);
            return Command::FAILURE;
        }

        $topic = 'ai-session/' . $session->getId();

        $tmpDir = sys_get_temp_dir() . '/ai-sessions/' . $session->getId();
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $transcriptionPath = $tmpDir . '/transcription.md';
        file_put_contents($transcriptionPath, $recording->getTranscription() ?? '');

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
                return Command::FAILURE;
            }
        }

        $piBin = trim(shell_exec('which pi') ?? '');
        if ($piBin === '') {
            $this->publishOutput($topic, "\r\nError: pi binary not found in PATH\r\n");
            $session->setStatus(AiSessionStatus::Closed);
            $session->setClosedAt(new \DateTimeImmutable());
            $this->em->flush();
            return Command::FAILURE;
        }

        // Per-user persistent directory for agent state across sessions
        // /app/.pi/ is a read-write mount on the ai-session worker
        $userDir = '/app/.pi/' . $user->getId();
        if (!is_dir($userDir)) {
            mkdir($userDir, 0755, true);
        }
        $piAgentDir = $userDir . '/agent';
        if (!is_dir($piAgentDir)) {
            mkdir($piAgentDir, 0755, true);
        }

        $descriptors = [
            0 => ['pty'],
            1 => ['pty'],
            2 => ['pipe', 'w'],
        ];

        $sessionDir = $tmpDir . '/sessions';
        if (!is_dir($sessionDir)) {
            mkdir($sessionDir, 0755, true);
        }

        $bwrapBin = trim(shell_exec('which bwrap 2>/dev/null') ?? '');

        $initialPrompt = sprintf(
            'Read the transcription in %s and summarize what it contains, then wait for my instructions.',
            $transcriptionPath
        );

        putenv($envVar . '=' . $apiKey);
        putenv('TMPDIR=' . $tmpDir);
        putenv('PI_CODING_AGENT_DIR=' . $piAgentDir);

        if ($bwrapBin !== '') {
            // Sandboxed: /app/.pi/{userId}/ → /app/.pi/ so pi sees ~/.pi/ as
            // its own dir with no other users visible. /tmp hidden to isolate
            // other sessions. Everything else read-only.
            $cmd = sprintf(
                '%s'
                . ' --ro-bind / /'
                . ' --dev /dev'
                . ' --tmpfs /tmp'
                . ' --bind %s %s'
                . ' --bind %s /app/.pi'
                . ' --unshare-pid'
                . ' --die-with-parent'
                . ' -- %s --verbose --session-dir %s %s',
                escapeshellarg($bwrapBin),
                escapeshellarg($tmpDir),
                escapeshellarg($tmpDir),
                escapeshellarg($userDir),
                escapeshellarg($piBin),
                escapeshellarg($sessionDir),
                escapeshellarg($initialPrompt)
            );
        } else {
            // Unsandboxed fallback (local dev)
            $cmd = sprintf(
                '%s --verbose --session-dir %s %s',
                escapeshellarg($piBin),
                escapeshellarg($sessionDir),
                escapeshellarg($initialPrompt)
            );
        }

        error_log(sprintf(
            '[AiSession] Starting pi process session=%s user=%s sandboxed=%s cmd=%s',
            $session->getId(),
            $user->getEmail(),
            $bwrapBin !== '' ? 'yes' : 'no',
            $cmd,
        ));

        $this->publishOutput($topic, "Launching pi.dev session...\r\n");

        $process = proc_open($cmd, $descriptors, $pipes, $tmpDir);

        if (!is_resource($process)) {
            error_log('[AiSession] proc_open failed for cmd: ' . $cmd);
            $this->publishOutput($topic, "\r\nError: Could not start pi process\r\n");
            $session->setStatus(AiSessionStatus::Closed);
            $session->setClosedAt(new \DateTimeImmutable());
            $this->em->flush();
            return Command::FAILURE;
        }

        $ptyMaster = $pipes[0];
        $stderr = $pipes[2];
        stream_set_blocking($ptyMaster, false);
        stream_set_blocking($stderr, false);

        $session->setStatus(AiSessionStatus::Running);
        $this->em->flush();

        $this->publishOutput($topic, "AI session started. Reading transcription...\r\n");

        $callbackIds = [];

        // Kill the session after 1 hour
        $callbackIds[] = EventLoop::delay(3600, function () use ($process, $topic, &$callbackIds) {
            $this->publishOutput($topic, "\r\nSession timed out after 1 hour.\r\n");
            proc_terminate($process);
            $this->cancelAll($callbackIds);
        });

        $callbackIds[] = EventLoop::onReadable($ptyMaster, function (string $id, $stream) use ($topic, &$callbackIds) {
            $data = @fread($stream, 4096);
            if ($data !== false && $data !== '') {
                $this->publishOutput($topic, $data);
            }
            if ($data === false || feof($stream)) {
                $this->cancelAll($callbackIds);
            }
        });

        $callbackIds[] = EventLoop::onReadable($stderr, function (string $id, $stream) use ($topic) {
            $data = @fread($stream, 4096);
            if ($data !== false && $data !== '') {
                $this->publishOutput($topic, $data);
            }
        });

        // User input via PostgreSQL LISTEN/NOTIFY (works across containers)
        $dbUrl = getenv('DATABASE_URL');
        $parsed = parse_url($dbUrl);
        $pgConn = pg_connect(sprintf(
            'host=%s port=%d dbname=%s user=%s password=%s',
            $parsed['host'],
            $parsed['port'] ?? 5432,
            ltrim($parsed['path'], '/'),
            $parsed['user'],
            $parsed['pass'] ?? '',
        ));
        $channel = 'ai_input_' . str_replace('-', '', (string) $session->getId());
        pg_query($pgConn, 'LISTEN ' . $channel);
        $pgSocket = pg_socket($pgConn);
        stream_set_blocking($pgSocket, false);

        $callbackIds[] = EventLoop::onReadable($pgSocket, function () use ($pgConn, $ptyMaster, $process, &$callbackIds) {
            pg_consume_input($pgConn);
            while (($notify = pg_get_notify($pgConn)) !== false) {
                $input = $notify['payload'];
                if ($input === "\x04") {
                    proc_terminate($process);
                    $this->cancelAll($callbackIds);
                    return;
                }
                @fwrite($ptyMaster, $input);
            }
        });

        $callbackIds[] = EventLoop::repeat(0.2, function (string $id) use ($process, $ptyMaster, $stderr, $topic, &$callbackIds) {
            $status = proc_get_status($process);
            if ($status['running']) {
                return;
            }

            $remaining = @stream_get_contents($ptyMaster);
            if ($remaining) {
                $this->publishOutput($topic, $remaining);
            }
            $remaining = @stream_get_contents($stderr);
            if ($remaining) {
                $this->publishOutput($topic, $remaining);
            }

            error_log(sprintf(
                '[AiSession] pi process exited exitcode=%d signaled=%s termsig=%d',
                $status['exitcode'],
                $status['signaled'] ? 'true' : 'false',
                $status['termsig'],
            ));

            $this->cancelAll($callbackIds);
        });

        EventLoop::run();

        putenv($envVar);
        putenv('TMPDIR');
        putenv('PI_CODING_AGENT_DIR');
        @fclose($ptyMaster);
        @fclose($stderr);
        proc_close($process);
        pg_close($pgConn);

        $this->cleanupDir($tmpDir);

        $session->setStatus(AiSessionStatus::Closed);
        $session->setClosedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->publishOutput($topic, "\r\nSession ended.\r\n");

        return Command::SUCCESS;
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
        try {
            $this->hub->publish(new Update($topic, json_encode(['output' => $data])));
        } catch (\Throwable $e) {
            $this->logger->error('[AiSession] Mercure publish error: ' . $e->getMessage());
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
