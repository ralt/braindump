<?php

namespace App\Command;

use Platformsh\Client\Connection\Connector;
use Platformsh\Client\PlatformClient;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[AsCommand(
    name: 'app:ci-run',
    description: 'Run CI: branch from main, update deps, run tests, auto-merge security fixes or notify',
)]
class CiRunCommand extends Command
{
    public function __construct(
        private LoggerInterface $logger,
        private MailerInterface $mailer,
        private PlatformInterface $platform,
        private string $upsunApiToken,
        private string $ciNotificationEmail,
        private string $ciEmailDomain,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Suppress deprecation warnings from platformsh/client (PHP 8.4 implicit nullable params)
        $previousLevel = error_reporting(error_reporting() & ~\E_DEPRECATED);

        try {
            return $this->doExecute($input, $output);
        } finally {
            error_reporting($previousLevel);
        }
    }

    private function doExecute(InputInterface $input, OutputInterface $output): int
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

            // 1. Create branch
            $io->info('Creating branch...');
            $activity = $mainEnv->branch($branchName, $branchName);
            $activity->wait(null, null, 5);
            $activity->refresh();
            if (!\in_array($activity->result, ['success', 'warning'], true)) {
                $io->error(sprintf('Branch creation failed (result: %s)', $activity->result));
                $log = $this->getActivityLog($activity, $connector);
                $analysis = $this->analyzeFailure($log);
                $this->notify(
                    'CI failed: branch creation',
                    sprintf("Branch: %s\nResult: %s\n\nActivity log:\n%s%s", $branchName, $activity->result, $log, $analysis !== '' ? "\n\nAI Analysis:\n" . $analysis : ''),
                );
                return Command::FAILURE;
            }
            $io->info('Branch created and deployed');

            $ciEnv = $project->getEnvironment($branchName);
            if ($ciEnv === false) {
                $io->error('Could not find CI environment after creation');
                return Command::FAILURE;
            }

            // 2. Run source operation
            $io->info('Running source operation to update dependencies...');
            $opResult = $ciEnv->runSourceOperation('update-dependencies');
            $sourceLog = '';
            foreach ($opResult->getActivities() as $sourceActivity) {
                $sourceActivity->wait(null, null, 5);
                $sourceActivity->refresh();
                $activityLog = $this->getActivityLog($sourceActivity, $connector);
                $sourceLog .= $activityLog;
                if (!\in_array($sourceActivity->result, ['success', 'warning'], true)) {
                    $io->error(sprintf('Source operation failed (result: %s)', $sourceActivity->result));
                    $analysis = $this->analyzeFailure($activityLog);
                    $this->notify(
                        'CI failed: source operation',
                        sprintf("Branch: %s\nResult: %s\n\nActivity log:\n%s%s", $branchName, $sourceActivity->result, $activityLog, $analysis !== '' ? "\n\nAI Analysis:\n" . $analysis : ''),
                    );
                    return Command::FAILURE;
                }
            }
            $io->info('Source operation completed');

            $hasSecurityFix = str_contains($sourceLog, 'security vulnerability advisories found')
                && !str_contains($sourceLog, 'No security vulnerability advisories found');

            // 3. Wait for deploy (post_deploy runs phpunit)
            sleep(5);
            $ciEnv->refresh();
            $deployActivity = null;
            foreach ($ciEnv->getActivities() as $act) {
                if (!$act->isComplete()) {
                    $deployActivity = $act;
                    break;
                }
            }

            if ($deployActivity === null) {
                $io->error('No deploy activity found after source operation');
                return Command::FAILURE;
            }

            $io->info('Waiting for deploy (tests run in post_deploy)...');
            $deployActivity->wait(null, null, 5);
            $deployActivity->refresh();

            $result = $deployActivity->result;

            if ($result !== 'success' && $result !== 'warning') {
                $io->error(sprintf('CI failed (result: %s)', $result));
                $deployLog = $this->getActivityLog($deployActivity, $connector);
                $analysis = $this->analyzeFailure($deployLog);
                $this->notify(
                    'CI failed: tests',
                    sprintf("Branch: %s\nResult: %s\n\nActivity log:\n%s%s", $branchName, $result, $deployLog, $analysis !== '' ? "\n\nAI Analysis:\n" . $analysis : ''),
                );
                $this->logger->error('CI failed', ['branch' => $branchName, 'result' => $result]);
                return Command::FAILURE;
            }

            if ($result === 'warning') {
                $io->warning('Deploy completed with warnings');
            }

            // 4. Tests passed — decide: auto-merge or notify
            if ($hasSecurityFix) {
                $io->info('Security advisory found — auto-merging');
                $this->mergeAndCleanup($ciEnv, $io);
                $this->notify(
                    'CI: security update auto-merged',
                    sprintf("Branch %s contained a security fix and was automatically merged to main.\n\nSource operation log:\n%s", $branchName, $sourceLog),
                );
                return Command::SUCCESS;
            }

            // No security fix — leave branch, send link to merge
            $mergeUrl = sprintf('https://console.upsun.com/projects/%s/environments/%s', $projectId, $branchName);
            $io->info('No security advisory — sending notification with merge link');
            $this->notify(
                'CI passed: dependency update ready to merge',
                sprintf(
                    "Branch %s has updated dependencies and tests pass.\n\nNo security advisories were found, so it was not auto-merged.\n\nReview and merge: %s\n\nSource operation log:\n%s",
                    $branchName,
                    $mergeUrl,
                    $sourceLog,
                ),
            );
            $io->success('Notification sent');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('CI run failed: ' . $e->getMessage());
            $this->logger->error('CI run failed', ['branch' => $branchName, 'error' => $e->getMessage()]);
            $this->notify(
                'CI failed: unexpected error',
                sprintf("Branch: %s\nError: %s", $branchName, $e->getMessage()),
            );

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

    private function mergeAndCleanup(mixed $ciEnv, SymfonyStyle $io): void
    {
        $ciEnv->refresh();
        $mergeActivity = $ciEnv->merge();
        $mergeActivity->wait(null, null, 5);

        $ciEnv->refresh();
        if ($ciEnv->isActive()) {
            $ciEnv->deactivate()->wait(null, null, 5);
        }
        $ciEnv->refresh();
        $ciEnv->delete();
        $io->success('Merged and cleaned up');
    }

    private function getActivityLog(object $activity, Connector $connector): string
    {
        try {
            if ($activity->hasLink('log')) {
                $url = $activity->getLink('log');
                $response = $connector->getClient()->get($url);
                return (string) $response->getBody();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch streaming log', ['error' => $e->getMessage()]);
        }

        return $activity->log;
    }

    private function analyzeFailure(string $activityLog): string
    {
        try {
            $log = mb_substr($activityLog, 0, 12000);

            $messages = new MessageBag(
                new SystemMessage('You are a DevOps assistant analyzing CI/CD activity logs from an Upsun (Platform.sh) deployment. Identify the root cause of the failure and suggest concrete fixes. Be concise — bullet points preferred.'),
                new UserMessage(new Text("This CI activity failed. Analyze the log and suggest how to fix it:\n\n" . $log)),
            );

            return $this->platform->invoke('gpt-4.1-mini', $messages)->asText();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get AI analysis of CI failure', ['error' => $e->getMessage()]);
            return '';
        }
    }

    private function notify(string $subject, string $body): void
    {
        if ($this->ciNotificationEmail === '') {
            return;
        }

        try {
            $from = $this->ciEmailDomain !== '' ? 'noreply@' . $this->ciEmailDomain : $this->ciNotificationEmail;
            $email = (new Email())
                ->from($from)
                ->to($this->ciNotificationEmail)
                ->subject('[Braindump CI] ' . $subject)
                ->text($body);

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send CI notification email', ['error' => $e->getMessage()]);
        }
    }
}
