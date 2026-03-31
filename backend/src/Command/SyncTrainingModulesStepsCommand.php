<?php

namespace App\Command;

use App\Service\TrainingModuleStepSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync:modules-steps',
    description: 'Synchronize modules and steps from Rise Up into the local TrackUp database.',
)]
class SyncTrainingModulesStepsCommand extends Command
{
    public function __construct(private readonly TrainingModuleStepSyncService $trainingModuleStepSyncService)
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
            $result = $this->trainingModuleStepSyncService->sync(
                (int) $input->getOption('page-size'),
                (int) $input->getOption('flush-every'),
            );
        } catch (\Throwable $throwable) {
            $io->error($throwable->getMessage());

            return Command::FAILURE;
        }

        $io->success('Module and step sync completed.');
        $io->definitionList(
            ['Modules fetched' => (string) $result['modules']['fetched']],
            ['Modules created' => (string) $result['modules']['created']],
            ['Modules updated' => (string) $result['modules']['updated']],
            ['Modules skipped' => (string) $result['modules']['skipped']],
            ['Steps fetched' => (string) $result['steps']['fetched']],
            ['Steps created' => (string) $result['steps']['created']],
            ['Steps updated' => (string) $result['steps']['updated']],
            ['Steps skipped' => (string) $result['steps']['skipped']],
        );

        return Command::SUCCESS;
    }
}
