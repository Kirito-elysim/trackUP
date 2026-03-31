<?php

namespace App\Command;

use App\Service\TrainingSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync:trainings',
    description: 'Synchronize trainings from Rise Up into the local TrackUp database.',
)]
class SyncTrainingsCommand extends Command
{
    public function __construct(private readonly TrainingSyncService $trainingSyncService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('page-size', null, InputOption::VALUE_REQUIRED, 'Rise Up page size (max 500).', '500')
            ->addOption('flush-every', null, InputOption::VALUE_REQUIRED, 'Flush frequency for local persistence.', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->trainingSyncService->sync(
                (int) $input->getOption('page-size'),
                (int) $input->getOption('flush-every'),
            );
        } catch (\Throwable $throwable) {
            $io->error($throwable->getMessage());

            return Command::FAILURE;
        }

        $io->success('Training sync completed.');
        $io->definitionList(
            ['Fetched' => (string) $result['fetched']],
            ['Created' => (string) $result['created']],
            ['Updated' => (string) $result['updated']],
        );

        return Command::SUCCESS;
    }
}
