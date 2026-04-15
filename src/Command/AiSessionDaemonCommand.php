<?php

namespace App\Command;

use App\Entity\AiSession;
use App\Entity\Recording;
use App\Entity\User;
use App\Enum\AiSessionStatus;
use App\Service\ApiKeyEncryptorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:ai-session-daemon', description: 'AI session daemon — manages all sessions concurrently via Mercure')]
final class AiSessionDaemonCommand extends Command
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

    /** @var array<string, array{process: resource, ptyMaster: resource, stderr: resource, topic: string, callbackIds: list<string>, tmpDir: string}> */
    private array $sessions = [];

    private ?string $sseCallbackId = null;
    /** @var resource|null */
    private $sseStream = null;

    private string $publishJwt = '';
    private string $publishHost = '';
    private int $publishPort = 80;
    private string $publishTransport = 'tcp';
    private string $publishPath = '';

    /** @var resource|null Persistent connection to Mercure for publishing */
    private $publishStream = null;
    private ?string $publishReadCbId = null;

    public function __construct(
        private EntityManagerInterface $em,
        private ApiKeyEncryptorInterface $encryptor,
        private LoggerInterface $logger,
        private string $mercureUrl,
        private string $mercureJwtSecret,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initPublisher();
        $this->connectToMercure();

        $shutdown = function () {
            error_log('[AiSessionDaemon] Shutting down...');
            foreach (array_keys($this->sessions) as $sessionId) {
                $this->cleanupSession($sessionId);
            }
            $this->closeSseConnection();
            $this->closePublishConnection();
            EventLoop::getDriver()->stop();
        };

        if (\function_exists('pcntl_signal')) {
            pcntl_signal(\SIGTERM, $shutdown);
            pcntl_signal(\SIGINT, $shutdown);
        }

        error_log('[AiSessionDaemon] Daemon started, entering event loop');
        EventLoop::run();

        return Command::SUCCESS;
    }

    private function closeSseConnection(): void
    {
        if ($this->sseCallbackId) {
            EventLoop::cancel($this->sseCallbackId);
            $this->sseCallbackId = null;
        }
        if ($this->sseStream && \is_resource($this->sseStream)) {
            @fclose($this->sseStream);
            $this->sseStream = null;
        }
    }

    /**
     * Subscribe to Mercure SSE via raw TCP socket + manual HTTP.
     *
     * HACK: This manual socket handling exists because the daemon runs as a
     * standalone CLI process (not inside FrankenPHP). PHP's fopen() HTTP
     * wrapper buffers SSE internally and never yields events. Once we migrate
     * to FrankenPHP worker mode, this entire method can be replaced with
     * the native Mercure subscriber integration.
     */
    private function connectToMercure(): void
    {
        // Clean up any existing connection first
        $this->closeSseConnection();

        $config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($this->mercureJwtSecret)
        );
        $jwt = $config->builder()
            ->withClaim('mercure', ['subscribe' => ['*']])
            ->getToken($config->signer(), $config->signingKey())
            ->toString();

        $parsed = parse_url($this->mercureUrl);
        $host = $parsed['host'] ?? 'localhost';
        $port = $parsed['port'] ?? (($parsed['scheme'] ?? 'http') === 'https' ? 443 : 80);
        $path = ($parsed['path'] ?? '') . '?' . http_build_query(['topic' => 'ai-session-daemon']);
        $transport = (($parsed['scheme'] ?? 'http') === 'https') ? 'ssl' : 'tcp';

        $stream = @stream_socket_client(
            $transport . '://' . $host . ':' . $port,
            $errno,
            $errstr,
            10
        );

        if ($stream === false) {
            error_log(sprintf('[AiSessionDaemon] Failed to connect to Mercure at %s:%d: %s', $host, $port, $errstr));
            EventLoop::delay(5.0, fn () => $this->connectToMercure());

            return;
        }

        // Enable TCP keepalive so the OS detects dead connections
        $socket = socket_import_stream($stream);
        if ($socket !== false) {
            socket_set_option($socket, \SOL_SOCKET, \SO_KEEPALIVE, 1);
            // Linux: start probing after 30s idle, every 10s, give up after 3 failures (~60s total)
            if (\defined('SOL_TCP')) {
                @socket_set_option($socket, \SOL_TCP, 4, 30);  // TCP_KEEPIDLE
                @socket_set_option($socket, \SOL_TCP, 5, 10);  // TCP_KEEPINTVL
                @socket_set_option($socket, \SOL_TCP, 6, 3);   // TCP_KEEPCNT
            }
        }

        $request = sprintf(
            "GET %s HTTP/1.1\r\nHost: %s\r\nAccept: text/event-stream\r\nAuthorization: Bearer %s\r\nCache-Control: no-cache\r\n\r\n",
            $path,
            $host,
            $jwt
        );
        fwrite($stream, $request);
        stream_set_blocking($stream, false);

        $this->sseStream = $stream;

        error_log('[AiSessionDaemon] Connected to Mercure SSE at ' . $host . ':' . $port);

        $buffer = '';
        $headersRead = false;

        $this->sseCallbackId = EventLoop::onReadable($stream, function (string $callbackId, $resource) use (&$buffer, &$headersRead) {
            $data = @fread($resource, 8192);
            if ($data === false || $data === '') {
                if (feof($resource)) {
                    error_log('[AiSessionDaemon] Mercure SSE connection lost (EOF), reconnecting in 2s...');
                    $this->closeSseConnection();
                    EventLoop::delay(2.0, fn () => $this->connectToMercure());
                }

                return;
            }

            $buffer .= $data;

            // Skip HTTP response headers
            if (!$headersRead) {
                $headerEnd = strpos($buffer, "\r\n\r\n");
                if ($headerEnd === false) {
                    return; // Haven't received all headers yet
                }
                $headers = substr($buffer, 0, $headerEnd);
                $buffer = substr($buffer, $headerEnd + 4);
                $headersRead = true;
                error_log('[AiSessionDaemon] SSE headers received: ' . explode("\r\n", $headers)[0]);
            }

            // Parse SSE events (double newline delimited)
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                $this->handleSseEvent($event);
            }
        });
    }

    private function handleSseEvent(string $raw): void
    {
        $dataLines = [];
        foreach (explode("\n", $raw) as $line) {
            if (str_starts_with($line, 'data:')) {
                $dataLines[] = ltrim(substr($line, 5));
            }
        }

        $data = trim(implode("\n", $dataLines));
        if ($data === '') {
            return;
        }

        $msg = json_decode($data, true);
        if (!\is_array($msg) || !isset($msg['type'])) {
            error_log('[AiSessionDaemon] Invalid message: ' . $data);

            return;
        }

        error_log(sprintf('[AiSessionDaemon] Received message type=%s', $msg['type']));

        try {
            match ($msg['type']) {
                'start' => $this->handleStart($msg),
                'input' => $this->handleInput($msg),
                'close' => $this->handleClose($msg),
                default => error_log('[AiSessionDaemon] Unknown type: ' . $msg['type']),
            };
        } catch (\Throwable $e) {
            error_log(sprintf('[AiSessionDaemon] Error handling %s: %s', $msg['type'], $e->getMessage()));
        }
    }

    private function handleStart(array $msg): void
    {
        $sessionId = $msg['sessionId'] ?? null;
        $recordingId = $msg['recordingId'] ?? null;
        $userId = $msg['userId'] ?? null;

        if (!$sessionId || !$recordingId || !$userId) {
            error_log('[AiSessionDaemon] start: missing required fields');

            return;
        }

        $this->ensureDbConnection();

        $session = $this->em->find(AiSession::class, $sessionId);
        $recording = $this->em->find(Recording::class, $recordingId);
        $user = $this->em->find(User::class, $userId);

        if ($session === null || $recording === null || $user === null) {
            $this->logger->error('[AiSessionDaemon] Entity not found', [
                'session' => $sessionId,
                'recording' => $recordingId,
                'user' => $userId,
            ]);
            $this->em->clear();

            return;
        }

        $topic = 'ai-session/' . $session->getId();

        $tmpDir = sys_get_temp_dir() . '/ai-sessions/' . $sessionId;
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $transcriptionPath = $tmpDir . '/transcription.md';
        file_put_contents($transcriptionPath, $recording->getTranscription() ?? '');

        $provider = $user->getAiProvider() ?? 'anthropic';
        $providerEnvVar = self::PROVIDER_ENV_MAP[$provider] ?? 'ANTHROPIC_API_KEY';
        $apiKey = '';

        if ($user->getEncryptedAiApiKey()) {
            try {
                $apiKey = $this->encryptor->decrypt($user->getEncryptedAiApiKey());
            } catch (\Throwable) {
                $this->publishOutput($topic, "\r\nError: Could not decrypt API key\r\n");
                $session->setStatus(AiSessionStatus::Closed);
                $session->setClosedAt(new \DateTimeImmutable());
                $this->em->flush();
                $this->em->clear();

                return;
            }
        }

        $piBin = trim(shell_exec('which pi') ?? '');
        if ($piBin === '') {
            $this->publishOutput($topic, "\r\nError: pi binary not found in PATH\r\n");
            $session->setStatus(AiSessionStatus::Closed);
            $session->setClosedAt(new \DateTimeImmutable());
            $this->em->flush();
            $this->em->clear();

            return;
        }

        // Per-user persistent directory for agent state across sessions
        $userDir = '/app/.pi/' . $user->getId();
        if (!is_dir($userDir)) {
            mkdir($userDir, 0755, true);
        }
        $piAgentDir = $userDir . '/agent';
        if (!is_dir($piAgentDir)) {
            mkdir($piAgentDir, 0755, true);
        }

        // Build per-session environment (NOT global putenv — multiple sessions share process)
        $env = getenv();
        $env[$providerEnvVar] = $apiKey;
        $env['TMPDIR'] = $tmpDir;
        $env['PI_CODING_AGENT_DIR'] = $piAgentDir;

        $sessionDir = $tmpDir . '/sessions';
        if (!is_dir($sessionDir)) {
            mkdir($sessionDir, 0755, true);
        }

        $bwrapBin = trim(shell_exec('which bwrap 2>/dev/null') ?? '');

        $initialPrompt = sprintf(
            'Read the transcription in %s and summarize what it contains, then wait for my instructions.',
            $transcriptionPath
        );

        if ($bwrapBin !== '') {
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
            $cmd = sprintf(
                '%s --verbose --session-dir %s %s',
                escapeshellarg($piBin),
                escapeshellarg($sessionDir),
                escapeshellarg($initialPrompt)
            );
        }

        error_log(sprintf(
            '[AiSessionDaemon] Starting pi process session=%s user=%s sandboxed=%s',
            $sessionId,
            $user->getEmail(),
            $bwrapBin !== '' ? 'yes' : 'no',
        ));

        $this->publishOutput($topic, "Launching pi.dev session...\r\n");

        $descriptors = [
            0 => ['pty'],
            1 => ['pty'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $tmpDir, $env);

        if (!\is_resource($process)) {
            error_log('[AiSessionDaemon] proc_open failed for session: ' . $sessionId);
            $this->publishOutput($topic, "\r\nError: Could not start pi process\r\n");
            $session->setStatus(AiSessionStatus::Closed);
            $session->setClosedAt(new \DateTimeImmutable());
            $this->em->flush();
            $this->em->clear();

            return;
        }

        $ptyMaster = $pipes[0];
        $stderr = $pipes[2];
        stream_set_blocking($ptyMaster, false);
        stream_set_blocking($stderr, false);

        $session->setStatus(AiSessionStatus::Running);
        $this->em->flush();
        $this->em->clear();

        $this->publishOutput($topic, "AI session started. Reading transcription...\r\n");

        $callbackIds = [];

        // Kill the session after 1 hour
        $callbackIds[] = EventLoop::delay(3600, function () use ($process, $sessionId) {
            $this->publishOutput($this->sessions[$sessionId]['topic'] ?? '', "\r\nSession timed out after 1 hour.\r\n");
            proc_terminate($process);
            $this->cleanupSession($sessionId);
        });

        $callbackIds[] = EventLoop::onReadable($ptyMaster, function (string $id, $stream) use ($topic, $sessionId) {
            $data = @fread($stream, 4096);
            if ($data !== false && $data !== '') {
                error_log(sprintf('[AiSessionDaemon] pty read session=%s bytes=%d', $sessionId, \strlen($data)));
                $this->publishOutput($topic, $data);
            }
            if ($data === false || feof($stream)) {
                error_log(sprintf('[AiSessionDaemon] pty EOF session=%s', $sessionId));
                $this->cleanupSession($sessionId);
            }
        });

        $callbackIds[] = EventLoop::onReadable($stderr, function (string $id, $stream) use ($topic) {
            $data = @fread($stream, 4096);
            if ($data !== false && $data !== '') {
                $this->publishOutput($topic, $data);
            }
        });

        $callbackIds[] = EventLoop::repeat(0.2, function (string $id) use ($process, $ptyMaster, $stderr, $topic, $sessionId) {
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
                '[AiSessionDaemon] pi process exited session=%s exitcode=%d signaled=%s termsig=%d',
                $sessionId,
                $status['exitcode'],
                $status['signaled'] ? 'true' : 'false',
                $status['termsig'],
            ));

            $this->cleanupSession($sessionId);
        });

        $this->sessions[$sessionId] = [
            'process' => $process,
            'ptyMaster' => $ptyMaster,
            'stderr' => $stderr,
            'topic' => $topic,
            'callbackIds' => $callbackIds,
            'tmpDir' => $tmpDir,
        ];
    }

    private function handleInput(array $msg): void
    {
        $sessionId = $msg['sessionId'] ?? null;
        $inputData = $msg['input'] ?? '';

        if (!$sessionId || !isset($this->sessions[$sessionId])) {
            error_log('[AiSessionDaemon] input: session not found: ' . ($sessionId ?? 'null') . ' (active sessions: ' . implode(', ', array_keys($this->sessions)) . ')');

            return;
        }

        $written = @fwrite($this->sessions[$sessionId]['ptyMaster'], $inputData);
        if ($written === false) {
            error_log(sprintf('[AiSessionDaemon] input: fwrite failed for session=%s', $sessionId));
        }
    }

    private function handleClose(array $msg): void
    {
        $sessionId = $msg['sessionId'] ?? null;

        if (!$sessionId || !isset($this->sessions[$sessionId])) {
            error_log('[AiSessionDaemon] close: session not found: ' . ($sessionId ?? 'null'));

            return;
        }

        $session = $this->sessions[$sessionId];

        // Send Ctrl+D to signal EOF
        @fwrite($session['ptyMaster'], "\x04");

        // Give the process a moment then terminate
        proc_terminate($session['process']);
        $this->cleanupSession($sessionId);
    }

    private function cleanupSession(string $sessionId): void
    {
        if (!isset($this->sessions[$sessionId])) {
            return;
        }

        $session = $this->sessions[$sessionId];

        // Cancel all event loop callbacks
        foreach ($session['callbackIds'] as $id) {
            EventLoop::cancel($id);
        }

        // Close streams
        @fclose($session['ptyMaster']);
        @fclose($session['stderr']);
        @proc_close($session['process']);

        // Cleanup temp dir
        $this->cleanupDir($session['tmpDir']);

        // Update DB
        $this->ensureDbConnection();
        $aiSession = $this->em->find(AiSession::class, $sessionId);
        if ($aiSession !== null) {
            $aiSession->setStatus(AiSessionStatus::Closed);
            $aiSession->setClosedAt(new \DateTimeImmutable());
            $this->em->flush();
        }
        $this->em->clear();

        // Publish end message
        $this->publishOutput($session['topic'], "\r\nSession ended.\r\n");

        unset($this->sessions[$sessionId]);
    }

    /**
     * Build the publish JWT and parse the Mercure URL once at startup.
     */
    private function initPublisher(): void
    {
        $parsed = parse_url($this->mercureUrl);
        $this->publishHost = $parsed['host'] ?? 'localhost';
        $this->publishPort = $parsed['port'] ?? (($parsed['scheme'] ?? 'http') === 'https' ? 443 : 80);
        $this->publishPath = $parsed['path'] ?? '/.well-known/mercure';
        $this->publishTransport = (($parsed['scheme'] ?? 'http') === 'https') ? 'ssl' : 'tcp';

        $config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($this->mercureJwtSecret)
        );
        $this->publishJwt = $config->builder()
            ->withClaim('mercure', ['publish' => ['*']])
            ->getToken($config->signer(), $config->signingKey())
            ->toString();
    }

    /**
     * Get or create a persistent TCP connection to Mercure for publishing.
     * Uses HTTP/1.1 keep-alive so all publishes share one connection.
     *
     * HACK: Raw socket because Symfony HttpClient (curl_multi) is never polled
     * inside a Revolt event loop, so requests silently stall. See "Current
     * Limitations" in docs/architecture.html. FrankenPHP will fix this.
     *
     * @return resource|null
     */
    private function getPublishConnection()
    {
        if ($this->publishStream !== null && \is_resource($this->publishStream) && !feof($this->publishStream)) {
            return $this->publishStream;
        }

        // Clean up old connection
        if ($this->publishReadCbId !== null) {
            EventLoop::cancel($this->publishReadCbId);
            $this->publishReadCbId = null;
        }
        if ($this->publishStream !== null && \is_resource($this->publishStream)) {
            @fclose($this->publishStream);
        }

        $stream = @stream_socket_client(
            $this->publishTransport . '://' . $this->publishHost . ':' . $this->publishPort,
            $errno,
            $errstr,
            5
        );

        if ($stream === false) {
            error_log(sprintf('[AiSessionDaemon] publish connect failed: %s', $errstr));
            $this->publishStream = null;

            return null;
        }

        stream_set_blocking($stream, false);
        $this->publishStream = $stream;

        // Drain responses in the background so the connection stays healthy
        $this->publishReadCbId = EventLoop::onReadable($stream, function (string $cbId, $resource) {
            $data = @fread($resource, 8192);
            if ($data === false || ($data === '' && feof($resource))) {
                // Connection dropped — will reconnect on next publish
                EventLoop::cancel($cbId);
                $this->publishReadCbId = null;
                @fclose($resource);
                $this->publishStream = null;
                error_log('[AiSessionDaemon] publish connection lost, will reconnect');
            }
        });

        error_log('[AiSessionDaemon] publish connection established');

        return $stream;
    }

    /**
     * Non-blocking fire-and-forget publish to Mercure over a persistent connection.
     */
    private function publishOutput(string $topic, string $data): void
    {
        if ($topic === '') {
            return;
        }

        $conn = $this->getPublishConnection();
        if ($conn === null) {
            return;
        }

        $body = http_build_query([
            'topic' => $topic,
            'data' => json_encode(['output' => $data]),
        ]);

        $request = sprintf(
            "POST %s HTTP/1.1\r\nHost: %s\r\nAuthorization: Bearer %s\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: %d\r\n\r\n%s",
            $this->publishPath,
            $this->publishHost,
            $this->publishJwt,
            \strlen($body),
            $body
        );

        $written = @fwrite($conn, $request);
        if ($written === false) {
            error_log('[AiSessionDaemon] publish write failed, closing connection');
            $this->closePublishConnection();
        }
    }

    private function closePublishConnection(): void
    {
        if ($this->publishReadCbId !== null) {
            EventLoop::cancel($this->publishReadCbId);
            $this->publishReadCbId = null;
        }
        if ($this->publishStream !== null && \is_resource($this->publishStream)) {
            @fclose($this->publishStream);
            $this->publishStream = null;
        }
    }

    private function ensureDbConnection(): void
    {
        $connection = $this->em->getConnection();
        try {
            $connection->executeQuery('SELECT 1');
        } catch (\Throwable) {
            $connection->close();
            $connection->connect();
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
