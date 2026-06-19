<?php

namespace App\Command;

use App\Repository\RecordingRepository;
use App\Search\SearchProviderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:search:reindex',
    description: 'Re-index every recording into the configured search provider',
)]
final class ReindexSearchCommand extends Command
{
    public function __construct(
        private readonly RecordingRepository $recordings,
        private readonly SearchProviderInterface $searchProvider,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $recordings = $this->recordings->findAll();
        $total = \count($recordings);

        if ($total === 0) {
            $io->success('No recordings to index.');

            return Command::SUCCESS;
        }

        $io->progressStart($total);
        foreach ($recordings as $recording) {
            $this->searchProvider->index($recording);
            $io->progressAdvance();
        }
        $io->progressFinish();

        $io->success(sprintf('Re-indexed %d recording%s.', $total, $total === 1 ? '' : 's'));

        return Command::SUCCESS;
    }
}
