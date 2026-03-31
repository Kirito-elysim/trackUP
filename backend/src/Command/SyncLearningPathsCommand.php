<?php

namespace App\Command;

use App\Service\LearningPathSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync:learningpaths',
    description: 'Synchronize learning paths and their registrations from Rise Up into the local TrackUp database.',
)]
class SyncLearningPathsCommand extends Command
{
    public function __construct(private readonly LearningPathSyncService $learningPathSyncService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('page-size', null, InputOption::VALUE_REQUIRED, 'Rise Up page size (max 500).', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->learningPathSyncService->sync((int) $input->getOption('page-size'));
        } catch (\Throwable $throwable) {
            $io->error($throwable->getMessage());

            return Command::FAILURE;
        }

        $io->success('Learning path sync completed.');
        $io->definitionList(
            ['Learning paths fetched' => (string) $result['learningPathsFetched']],
            ['Learning paths created' => (string) $result['learningPathsCreated']],
            ['Learning paths updated' => (string) $result['learningPathsUpdated']],
            ['Training links created' => (string) $result['trainingLinksCreated']],
            ['Training links skipped' => (string) $result['trainingLinksSkipped']],
            ['Registrations fetched' => (string) $result['registrationsFetched']],
            ['Registrations created' => (string) $result['registrationsCreated']],
            ['Registrations updated' => (string) $result['registrationsUpdated']],
            ['Registrations skipped' => (string) $result['registrationsSkipped']],
        );

        return Command::SUCCESS;
    }
}
