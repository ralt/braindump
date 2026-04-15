<?php

namespace App\Command;

use Platformsh\Client\Connection\Connector;
use Platformsh\Client\PlatformClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ci-run',
    description: 'Run CI: branch from main, update deps, run tests, merge on success',
)]
class CiRunCommand extends Command
{
    public function __construct(
        private LoggerInterface $logger,
        private string $upsunApiToken,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $projectId = $_ENV['PLATFORM_PROJECT'] ?? '';

        if ($this->upsunApiToken === '' || $projectId === '') {
            $io->error('UPSUN_API_TOKEN and PLATFORM_PROJECT must be set.');
            return Command::FAILURE;
        }

        $branchName = 'ci-test-' . (new \DateTimeImmutable())->format('Y-m-d-His');

        $this->logger->info('Starting CI run', ['branch' => $branchName]);
        $io->info('Starting CI run on branch ' . $branchName);

        $connector = new Connector([
            'api_url' => 'https://api.upsun.com',
            'token_url' => 'https://auth.api.platform.sh/oauth2/token',
        ]);
        $connector->setApiToken($this->upsunApiToken, 'exchange');
        $client = new PlatformClient($connector);

        $project = $client->getProject($projectId);
        if ($project === false) {
            $io->error('Could not find Upsun project ' . $projectId);
            return Command::FAILURE;
        }

        try {
            $mainEnv = $project->getEnvironment('main');
            if ($mainEnv === false) {
                $io->error('Could not find main environment');
                return Command::FAILURE;
            }

            $io->info('Creating branch...');
            $activity = $mainEnv->branch($branchName, $branchName);
            $activity->wait(null, null, 5);
            $activity->refresh();
            if (!\in_array($activity->result, ['success', 'warning'], true)) {
                $io->error(sprintf('Branch creation failed (result: %s)', $activity->result));
                $io->section('Activity log');
                $io->text($activity->log);
                return Command::FAILURE;
            }
            $io->info('Branch created and deployed');

            $ciEnv = $project->getEnvironment($branchName);
            if ($ciEnv === false) {
                $io->error('Could not find CI environment after creation');
                return Command::FAILURE;
            }

            $io->info('Running source operation to update dependencies...');
            $opResult = $ciEnv->runSourceOperation('update-dependencies');
            foreach ($opResult->getActivities() as $sourceActivity) {
                $sourceActivity->wait(null, null, 5);
                $sourceActivity->refresh();
                if (!\in_array($sourceActivity->result, ['success', 'warning'], true)) {
                    $io->error(sprintf('Source operation failed (result: %s)', $sourceActivity->result));
                    $io->section('Activity log');
                    $io->text($sourceActivity->log);
                    return Command::FAILURE;
                }
            }
            $io->info('Source operation completed');

            // Wait for the deploy activity (post_deploy runs phpunit)
            sleep(5);
            $ciEnv->refresh();
            $deployActivity = null;
            foreach ($ciEnv->getActivities() as $act) {
                if (!$act->isComplete()) {
                    $deployActivity = $act;
                    break;
                }
            }

            if ($deployActivity !== null) {
                $io->info('Waiting for deploy (tests run in post_deploy)...');
                $deployActivity->wait(null, null, 5);
                $deployActivity->refresh();

                $result = $deployActivity->result;

                if ($result === 'success' || $result === 'warning') {
                    if ($result === 'warning') {
                        $io->warning('Deploy completed with warnings:');
                        $io->text($deployActivity->log);
                    }

                    $io->success('CI passed, merging to main');
                    $ciEnv->refresh();
                    $mergeActivity = $ciEnv->merge();
                    $mergeActivity->wait(null, null, 5);

                    $ciEnv->refresh();
                    if ($ciEnv->isActive()) {
                        $deactivateActivity = $ciEnv->deactivate();
                        $deactivateActivity->wait(null, null, 5);
                    }
                    $ciEnv->refresh();
                    $ciEnv->delete();
                    $io->success('Merged and cleaned up');
                    return Command::SUCCESS;
                } else {
                    $io->error(sprintf('CI failed (result: %s)', $result));
                    $io->section('Activity log');
                    $io->text($deployActivity->log);
                    $this->logger->error('CI failed', ['branch' => $branchName, 'result' => $result]);
                    return Command::FAILURE;
                }
            } else {
                $io->error('No deploy activity found after source operation');
                return Command::FAILURE;
            }
        } catch (\Throwable $e) {
            $io->error('CI run failed: ' . $e->getMessage());
            $this->logger->error('CI run failed', ['branch' => $branchName, 'error' => $e->getMessage()]);

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

            return Command::FAILURE;
        }
    }
}
