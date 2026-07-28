<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\ClassroomSessionSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync:sessions',
    description: 'Synchronize classroom sessions, registrations and signatures from Rise Up into the local TrackUp database.',
)]
class SyncClassroomSessionsCommand extends Command
{
    public function __construct(private readonly ClassroomSessionSyncService $classroomSessionSyncService)
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
            $result = $this->classroomSessionSyncService->sync(
                (int) $input->getOption('page-size'),
                (int) $input->getOption('flush-every'),
            );
        } catch (\Throwable $throwable) {
            $io->error($throwable->getMessage());

            return Command::FAILURE;
        }

        $io->success('Session and masterclass sync completed.');
        $io->definitionList(
            ['Sessions fetched' => (string) $result['sessions']['fetched']],
            ['Sessions created' => (string) $result['sessions']['created']],
            ['Sessions updated' => (string) $result['sessions']['updated']],
            ['Registrations fetched' => (string) $result['registrations']['fetched']],
            ['Registrations created' => (string) $result['registrations']['created']],
            ['Registrations updated' => (string) $result['registrations']['updated']],
            ['Registrations skipped' => (string) $result['registrations']['skipped']],
            ['Signature registrations processed' => (string) $result['signatures']['registrations_processed']],
            ['Signatures fetched' => (string) $result['signatures']['fetched']],
            ['Signatures created' => (string) $result['signatures']['created']],
            ['Signatures updated' => (string) $result['signatures']['updated']],
            ['Signatures removed' => (string) $result['signatures']['removed']],
        );

        return Command::SUCCESS;
    }
}
