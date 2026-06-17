<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

/**
 * Diagnostic: attempt a real send using this container's MAILER_DSN and report the result.
 * Used to determine whether the CI task container can actually reach the SMTP relay
 * (the task's own notify() failures are swallowed and the run log truncates them).
 */
#[AsCommand(
    name: 'app:mail-test',
    description: 'Send a test email with the current MAILER_DSN and print whether it succeeded',
)]
class MailTestCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dsn = getenv('MAILER_DSN') ?: (string) ($_ENV['MAILER_DSN'] ?? $_SERVER['MAILER_DSN'] ?? '');
        $to = getenv('CI_NOTIFICATION_EMAIL') ?: (string) ($_ENV['CI_NOTIFICATION_EMAIL'] ?? '');
        $to = $to !== '' ? $to : 'florian@platform.sh';
        $domain = getenv('CI_EMAIL_DOMAIN') ?: (string) ($_ENV['CI_EMAIL_DOMAIN'] ?? '');
        $domain = $domain !== '' ? $domain : 'braindump.pltfrm.sh';

        $io->writeln(sprintf('[mail-test] dsn=%s to=%s from=noreply@%s', $dsn !== '' ? $dsn : '(empty)', $to, $domain));

        if ($dsn === '') {
            $io->error('[mail-test] MAILER_DSN is empty — nothing to test.');
            return Command::FAILURE;
        }

        try {
            $mailer = new Mailer(Transport::fromDsn($dsn));
            $mailer->send(
                (new Email())
                    ->from('noreply@' . $domain)
                    ->to($to)
                    ->subject('[Braindump CI] mail-test probe')
                    ->text('Sent by app:mail-test from this container.'),
            );
            $io->success('[mail-test] send OK -> ' . $to);
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('[mail-test] send FAILED: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
