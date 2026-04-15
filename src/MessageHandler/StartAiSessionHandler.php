<?php

namespace App\MessageHandler;

use App\Entity\AiSession;
use App\Entity\Recording;
use App\Entity\User;
use App\Enum\AiSessionStatus;
use App\Message\StartAiSessionMessage;
use App\Service\ApiKeyEncryptorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class StartAiSessionHandler
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
        private string $mercureUrl,
        private string $mercureJwtSecret,
    ) {}

    public function __invoke(StartAiSessionMessage $message): void
    {
        $session = $this->em->find(AiSession::class, $message->sessionId);
        $recording = $this->em->find(Recording::class, $message->recordingId);
        $user = $this->em->find(User::class, $message->userId);

        if ($session === null || $recording === null || $user === null) {
            error_log('[AiSession] Entity not found: session=' . $message->sessionId);

            return;
        }

        $outputTopic = 'ai-session/' . $session->getId();
        $commandTopic = 'ai-session/' . $session->getId() . '/commands';

        // Decrypt API key
        $provider = $user->getAiProvider() ?? 'anthropic';
        $providerEnvVar = self::PROVIDER_ENV_MAP[$provider] ?? 'ANTHROPIC_API_KEY';
        $apiKey = '';

        if ($user->getEncryptedAiApiKey()) {
            try {
                $apiKey = $this->encryptor->decrypt($user->getEncryptedAiApiKey());
            } catch (\Throwable) {
                $this->publish($outputTopic, "\r\nError: Could not decrypt API key\r\n");
                $this->closeSession($session);

                return;
            }
        }

        $piBin = trim(shell_exec('which pi') ?? '');
        if ($piBin === '') {
            $this->publish($outputTopic, "\r\nError: pi binary not found in PATH\r\n");
            $this->closeSession($session);

            return;
        }

        // Set up directories
        $tmpDir = sys_get_temp_dir() . '/ai-sessions/' . $session->getId();
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $transcriptionPath = $tmpDir . '/transcription.md';
        file_put_contents($transcriptionPath, $recording->getTranscription() ?? '');

        $userDir = '/app/.pi/' . $user->getId();
        if (!is_dir($userDir)) {
            mkdir($userDir, 0755, true);
        }
        $piAgentDir = $userDir . '/agent';
        if (!is_dir($piAgentDir)) {
            mkdir($piAgentDir, 0755, true);
        }

        $sessionDir = $tmpDir . '/sessions';
        if (!is_dir($sessionDir)) {
            mkdir($sessionDir, 0755, true);
        }

        // Build environment
        $env = getenv();
        $env[$providerEnvVar] = $apiKey;
        $env['TMPDIR'] = $tmpDir;
        $env['PI_CODING_AGENT_DIR'] = $piAgentDir;

        // Build command
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

        $this->publish($outputTopic, "Launching pi.dev session...\r\n");

        $process = proc_open($cmd, [0 => ['pty'], 1 => ['pty'], 2 => ['pipe', 'w']], $pipes, $tmpDir, $env);

        if (!\is_resource($process)) {
            $this->publish($outputTopic, "\r\nError: Could not start pi process\r\n");
            $this->closeSession($session);

            return;
        }

        $ptyMaster = $pipes[0];
        $stderr = $pipes[2];
        stream_set_blocking($ptyMaster, false);
        stream_set_blocking($stderr, false);

        $session->setStatus(AiSessionStatus::Running);
        $this->em->flush();

        $this->publish($outputTopic, "AI session started. Reading transcription...\r\n");

        // Subscribe to per-session command topic for input/close
        $sseStream = $this->connectSse($commandTopic);

        $startTime = time();
        $sseBuffer = '';
        $sseHeadersRead = false;

        // Main loop — stream_select multiplexes PTY + SSE, no event loop needed
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                // Drain remaining output
                $remaining = @stream_get_contents($ptyMaster);
                if ($remaining) {
                    $this->publish($outputTopic, $remaining);
                }
                $remaining = @stream_get_contents($stderr);
                if ($remaining) {
                    $this->publish($outputTopic, $remaining);
                }

                break;
            }

            // 1 hour timeout
            if (time() - $startTime > 3600) {
                $this->publish($outputTopic, "\r\nSession timed out after 1 hour.\r\n");
                proc_terminate($process);

                break;
            }

            $read = [$ptyMaster, $stderr];
            if ($sseStream !== null && \is_resource($sseStream) && !feof($sseStream)) {
                $read[] = $sseStream;
            } elseif ($sseStream !== null) {
                // SSE connection dropped — reconnect
                @fclose($sseStream);
                $sseStream = $this->connectSse($commandTopic);
                $sseBuffer = '';
                $sseHeadersRead = false;
                if ($sseStream !== null) {
                    $read[] = $sseStream;
                }
            }

            $write = $except = [];
            $changed = @stream_select($read, $write, $except, 0, 200_000); // 200ms timeout

            if ($changed === false) {
                continue;
            }

            foreach ($read as $stream) {
                if ($stream === $ptyMaster) {
                    $data = @fread($ptyMaster, 4096);
                    if ($data !== false && $data !== '') {
                        $this->publish($outputTopic, $data);
                    }
                } elseif ($stream === $stderr) {
                    $data = @fread($stderr, 4096);
                    if ($data !== false && $data !== '') {
                        $this->publish($outputTopic, $data);
                    }
                } elseif ($stream === $sseStream) {
                    $data = @fread($sseStream, 8192);
                    if ($data === false || ($data === '' && feof($sseStream))) {
                        // Will reconnect on next iteration
                        continue;
                    }

                    $sseBuffer .= $data;

                    // Skip HTTP headers
                    if (!$sseHeadersRead) {
                        $headerEnd = strpos($sseBuffer, "\r\n\r\n");
                        if ($headerEnd === false) {
                            continue;
                        }
                        $sseBuffer = substr($sseBuffer, $headerEnd + 4);
                        $sseHeadersRead = true;
                    }

                    // Parse SSE events
                    while (($pos = strpos($sseBuffer, "\n\n")) !== false) {
                        $event = substr($sseBuffer, 0, $pos);
                        $sseBuffer = substr($sseBuffer, $pos + 2);

                        $msg = $this->parseSseEvent($event);
                        if ($msg === null) {
                            continue;
                        }

                        if (($msg['type'] ?? '') === 'input' && isset($msg['input'])) {
                            @fwrite($ptyMaster, $msg['input']);
                        } elseif (($msg['type'] ?? '') === 'close') {
                            @fwrite($ptyMaster, "\x04");
                            proc_terminate($process);

                            break 3; // Exit the while(true) loop
                        }
                    }
                }
            }
        }

        // Cleanup
        @fclose($ptyMaster);
        @fclose($stderr);
        @proc_close($process);
        if ($sseStream !== null && \is_resource($sseStream)) {
            @fclose($sseStream);
        }
        $this->cleanupDir($tmpDir);

        $this->publish($outputTopic, "\r\nSession ended.\r\n");
        $this->closeSession($session);
    }

    private function publish(string $topic, string $data): void
    {
        try {
            $this->hub->publish(new Update($topic, json_encode(['output' => $data])));
        } catch (\Throwable $e) {
            error_log('[AiSession] Mercure publish error: ' . $e->getMessage());
        }
    }

    private function closeSession(AiSession $session): void
    {
        $session->setStatus(AiSessionStatus::Closed);
        $session->setClosedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    /**
     * Open a raw SSE connection to Mercure for a given topic.
     *
     * HACK: PHP's fopen() HTTP wrapper buffers SSE internally.
     * FrankenPHP will replace this with native Mercure subscriptions.
     *
     * @return resource|null
     */
    private function connectSse(string $topic)
    {
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
        $path = ($parsed['path'] ?? '') . '?' . http_build_query(['topic' => $topic]);
        $transport = (($parsed['scheme'] ?? 'http') === 'https') ? 'ssl' : 'tcp';

        $stream = @stream_socket_client(
            $transport . '://' . $host . ':' . $port,
            $errno,
            $errstr,
            5
        );

        if ($stream === false) {
            error_log('[AiSession] SSE connect failed: ' . $errstr);

            return null;
        }

        $request = sprintf(
            "GET %s HTTP/1.1\r\nHost: %s\r\nAccept: text/event-stream\r\nAuthorization: Bearer %s\r\nCache-Control: no-cache\r\n\r\n",
            $path,
            $host,
            $jwt
        );
        fwrite($stream, $request);
        stream_set_blocking($stream, false);

        return $stream;
    }

    private function parseSseEvent(string $raw): ?array
    {
        $dataLines = [];
        foreach (explode("\n", $raw) as $line) {
            if (str_starts_with($line, 'data:')) {
                $dataLines[] = ltrim(substr($line, 5));
            }
        }

        $data = trim(implode("\n", $dataLines));
        if ($data === '') {
            return null;
        }

        $msg = json_decode($data, true);

        return \is_array($msg) ? $msg : null;
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
