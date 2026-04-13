<?php

namespace App\MessageHandler;

use App\Message\RunCiMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Process\Process;

#[AsMessageHandler]
final class RunCiHandler
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function __invoke(RunCiMessage $message): void
    {
        $branchName = 'ci-test-' . $message->triggeredAt->format('Y-m-d-His');

        $this->logger->info('Starting CI run', ['branch' => $branchName]);

        try {
            // Create a new environment from main
            $this->run(['upsun', 'branch', $branchName, '--force', '--no-wait'], 120);
            $this->logger->info('Branch created, waiting for deployment...');

            // Wait for deployment to finish
            $this->run(['upsun', 'activity:wait', '-e', $branchName], 600);
            $this->logger->info('Deployment complete, running tests...');

            // Run tests on the new environment
            $result = $this->run(
                ['upsun', 'ssh', '-e', $branchName, '--', 'php', 'bin/phpunit', '--colors=never'],
                300,
                allowFailure: true
            );

            if ($result->isSuccessful()) {
                $this->logger->info('CI passed, merging to main');
                $this->run(['upsun', 'merge', $branchName], 120);
                $this->run(['upsun', 'environment:delete', $branchName, '--yes', '--no-wait'], 60);
                $this->logger->info('Merged and cleaned up');
            } else {
                $this->logger->error('CI failed, keeping environment for debugging', [
                    'branch' => $branchName,
                    'output' => $result->getOutput(),
                    'error' => $result->getErrorOutput(),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('CI run failed with exception', [
                'branch' => $branchName,
                'error' => $e->getMessage(),
            ]);

            // Attempt cleanup on infrastructure failure
            try {
                $this->run(['upsun', 'environment:delete', $branchName, '--yes', '--no-wait'], 60, allowFailure: true);
            } catch (\Throwable) {
                // ignore cleanup failures
            }
        }
    }

    private function run(array $command, int $timeout, bool $allowFailure = false): Process
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();

        if (!$allowFailure && !$process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                'Command "%s" failed (exit %d): %s',
                implode(' ', $command),
                $process->getExitCode(),
                $process->getErrorOutput()
            ));
        }

        return $process;
    }
}
