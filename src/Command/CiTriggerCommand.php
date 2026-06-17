<?php

namespace App\Command;

use Platformsh\Client\Connection\Connector;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ci-trigger',
    description: 'Trigger the on-demand "ci" task container to run app:ci-run off production resources',
)]
class CiTriggerCommand extends Command
{
    public function __construct(
        private LoggerInterface $logger,
        private string $upsunApiToken,
        private string $ciNotificationEmail,
        private string $ciEmailDomain,
        private string $openAiApiKey,
        private string $appSecret,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $projectId = $_ENV['PLATFORM_PROJECT'] ?? '';
        // The cron runs on production (main); trigger the task on the same environment.
        $environment = $_ENV['PLATFORM_BRANCH'] ?? $_ENV['PLATFORM_ENVIRONMENT'] ?? 'main';

        if ($this->upsunApiToken === '' || $projectId === '') {
            $io->error('UPSUN_API_TOKEN and PLATFORM_PROJECT must be set.');
            return Command::FAILURE;
        }

        $connector = new Connector([
            'api_url' => 'https://api.upsun.com',
            'token_url' => 'https://auth.api.platform.sh/oauth2/token',
        ]);
        $connector->setApiToken($this->upsunApiToken, 'exchange');

        $url = sprintf(
            'https://api.upsun.com/projects/%s/environments/%s/tasks/ci/run',
            rawurlencode($projectId),
            rawurlencode($environment),
        );

        // The task has no `env: view` authorization, so it can't read the project's
        // variables ambiently. Forward exactly what app:ci-run needs into this run's
        // payload; Upsun exposes each as a plain environment variable in the container.
        // (PLATFORM_PROJECT and other PLATFORM_* vars are injected automatically;
        // DATABASE_URL/MAILER_DSN are built from relationships in .environment.)
        $variables = [
            'UPSUN_API_TOKEN' => $this->upsunApiToken,
            'CI_NOTIFICATION_EMAIL' => $this->ciNotificationEmail,
            'CI_EMAIL_DOMAIN' => $this->ciEmailDomain,
            'OPENAI_API_KEY' => $this->openAiApiKey,
            'APP_SECRET' => $this->appSecret,
        ];

        try {
            $connector->getClient()->post($url, ['json' => ['variables' => $variables]]);
        } catch (\Throwable $e) {
            $io->error('Failed to trigger CI task: ' . $e->getMessage());
            $this->logger->error('Failed to trigger CI task', [
                'environment' => $environment,
                'error' => $e->getMessage(),
            ]);
            return Command::FAILURE;
        }

        $this->logger->info('CI task triggered', ['environment' => $environment]);
        $io->success(sprintf('CI task triggered on environment %s', $environment));

        return Command::SUCCESS;
    }
}
