<?php

namespace App\MessageHandler;

use App\Message\RunCiMessage;
use Platformsh\Client\Connection\Connector;
use Platformsh\Client\PlatformClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Process\Process;

#[AsMessageHandler]
final class RunCiHandler
{
    public function __construct(
        private LoggerInterface $logger,
        private string $upsunApiToken,
        private string $upsunProjectId,
    ) {}

    public function __invoke(RunCiMessage $message): void
    {
        $branchName = 'ci-test-' . $message->triggeredAt->format('Y-m-d-His');

        $this->logger->info('Starting CI run', ['branch' => $branchName]);

        $connector = new Connector([
            'api_url' => 'https://api.upsun.com',
            'token_url' => 'https://auth.api.platform.sh/oauth2/token',
        ]);
        $connector->setApiToken($this->upsunApiToken, 'exchange');
        $client = new PlatformClient($connector);

        $project = $client->getProject($this->upsunProjectId);
        if ($project === false) {
            $this->logger->error('Could not find Upsun project', ['id' => $this->upsunProjectId]);
            return;
        }

        try {
            // Create a new environment from main
            $mainEnv = $project->getEnvironment('main');
            if ($mainEnv === false) {
                $this->logger->error('Could not find main environment');
                return;
            }

            $this->logger->info('Creating branch...', ['branch' => $branchName]);
            $activity = $mainEnv->branch($branchName, $branchName);
            $activity->wait(null, null, 5);
            $this->logger->info('Branch created and deployed');

            // Get SSH URL for the new environment
            $ciEnv = $project->getEnvironment($branchName);
            if ($ciEnv === false) {
                $this->logger->error('Could not find CI environment after creation');
                return;
            }

            $sshUrl = $ciEnv->getSshUrl('app');
            $this->logger->info('Running tests via SSH', ['ssh' => $sshUrl]);

            // Run tests via SSH
            $process = new Process(['ssh', '-o', 'StrictHostKeyChecking=no', $sshUrl, 'php', 'bin/phpunit', '--colors=never']);
            $process->setTimeout(300);
            $process->run();

            if ($process->isSuccessful()) {
                $this->logger->info('CI passed, merging to main');
                $mergeActivity = $ciEnv->merge();
                $mergeActivity->wait(null, null, 5);

                // Deactivate and delete
                $ciEnv->refresh();
                if ($ciEnv->isActive()) {
                    $deactivateActivity = $ciEnv->deactivate();
                    $deactivateActivity->wait(null, null, 5);
                }
                $ciEnv->refresh();
                $ciEnv->delete();
                $this->logger->info('Merged and cleaned up');
            } else {
                $this->logger->error('CI failed, keeping environment for debugging', [
                    'branch' => $branchName,
                    'output' => $process->getOutput(),
                    'error' => $process->getErrorOutput(),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('CI run failed with exception', [
                'branch' => $branchName,
                'error' => $e->getMessage(),
            ]);

            // Attempt cleanup
            try {
                $ciEnv = $project->getEnvironment($branchName);
                if ($ciEnv !== false) {
                    if ($ciEnv->isActive()) {
                        $ciEnv->deactivate()->wait(null, null, 5);
                        $ciEnv->refresh();
                    }
                    $ciEnv->delete();
                }
            } catch (\Throwable) {
                // ignore cleanup failures
            }
        }
    }
}
