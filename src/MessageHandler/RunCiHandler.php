<?php

namespace App\MessageHandler;

use App\Message\RunCiMessage;
use Platformsh\Client\Connection\Connector;
use Platformsh\Client\PlatformClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

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

            $ciEnv = $project->getEnvironment($branchName);
            if ($ciEnv === false) {
                $this->logger->error('Could not find CI environment after creation');
                return;
            }

            // Run source operation to update composer.lock
            $this->logger->info('Running source operation to update dependencies...');
            $result = $ciEnv->runSourceOperation('update-dependencies');
            foreach ($result->getActivities() as $sourceActivity) {
                $sourceActivity->wait(null, null, 5);
            }
            $this->logger->info('Source operation completed');

            // Set CI_RUN=1 env var — this triggers a rebuild that includes
            // dev dependencies and runs phpunit during the build phase
            $this->logger->info('Setting CI_RUN variable to trigger test build...');
            $ciEnv->setVariable('env:CI_RUN', '1');

            // Wait for the rebuild triggered by the variable change
            sleep(5); // Give Upsun a moment to start the activity
            $ciEnv->refresh();
            $buildActivity = null;
            foreach ($ciEnv->getActivities() as $act) {
                if (!$act->isComplete()) {
                    $buildActivity = $act;
                    break;
                }
            }

            if ($buildActivity !== null) {
                $this->logger->info('Waiting for CI build...', ['activity' => $buildActivity->id]);
                $buildActivity->wait(null, null, 5);
                $buildActivity->refresh();

                if ($buildActivity->result === 'success') {
                    $this->logger->info('CI passed, merging to main');
                    $ciEnv->refresh();
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
                    $this->logger->error('CI build failed', [
                        'branch' => $branchName,
                        'result' => $buildActivity->result,
                    ]);
                }
            } else {
                $this->logger->error('No rebuild activity found after setting CI_RUN');
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
