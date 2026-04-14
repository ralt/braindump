<?php

namespace App\MessageHandler;

use App\Entity\AiSession;
use App\Message\StartAiSessionMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AiSessionHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {}

    public function __invoke(StartAiSessionMessage $message): void
    {
        $session = $this->em->find(AiSession::class, $message->sessionId);
        if ($session === null) {
            return;
        }

        $cmd = sprintf(
            'nohup %s %s/bin/console app:run-ai-session %s %s %s > /dev/null 2>&1 &',
            \PHP_BINARY,
            escapeshellarg($this->projectDir),
            escapeshellarg((string) $message->sessionId),
            escapeshellarg((string) $message->recordingId),
            escapeshellarg((string) $message->userId),
        );

        $this->logger->info('[AiSession] Launching session process', [
            'session' => (string) $message->sessionId,
            'cmd' => $cmd,
        ]);

        exec($cmd);
    }
}
