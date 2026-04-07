<?php

namespace App\Command;

use App\Service\RiseUpGroupMembershipSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync:riseup-group-memberships',
    description: 'Synchronize Rise Up user group memberships for local learners.',
)]
class SyncRiseUpGroupMembershipsCommand extends Command
{
    public function __construct(private readonly RiseUpGroupMembershipSyncService $membershipSyncService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->membershipSyncService->syncAll();
        } catch (\Throwable $throwable) {
            $io->error($throwable->getMessage());

            return Command::FAILURE;
        }

        $io->success('Rise Up group memberships synchronized.');
        $io->definitionList(
            ['Learners processed' => (string) $result['learners']],
            ['Membership links' => (string) $result['links']],
            ['Learners skipped' => (string) $result['skipped']],
        );

        return Command::SUCCESS;
    }
}

