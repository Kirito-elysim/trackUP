<?php

namespace App\Command;

use App\Service\RiseUpGroupSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync:riseup-groups',
    description: 'Synchronize Rise Up groups (classes) and group learning paths into the local database.',
)]
class SyncRiseUpGroupsCommand extends Command
{
    public function __construct(private readonly RiseUpGroupSyncService $groupSyncService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->groupSyncService->sync();
        } catch (\Throwable $throwable) {
            $io->error($throwable->getMessage());

            return Command::FAILURE;
        }

        $io->success('Rise Up groups synchronized.');
        $io->definitionList(
            ['Groups' => (string) $result['groups']],
            ['Group learning paths' => (string) $result['groupLearningPaths']],
        );

        return Command::SUCCESS;
    }
}

