<?php

namespace App\Command;

use App\Service\RiseUpActivityLogImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:riseup-activity-logs',
    description: 'Import a Rise Up activity journal file (XLSX or CSV) into the local TrackUp database.',
)]
class ImportRiseUpActivityLogsCommand extends Command
{
    public function __construct(private readonly RiseUpActivityLogImportService $importService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Path to the Rise Up activity journal file.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');

        try {
            $result = $this->importService->import($file);
        } catch (\Throwable $throwable) {
            $io->error($throwable->getMessage());

            return Command::FAILURE;
        }

        $io->success('Rise Up activity logs imported.');
        $io->definitionList(
            ['Parsed' => (string) $result['parsed']],
            ['Imported' => (string) $result['imported']],
            ['Skipped (duplicates/invalid rows)' => (string) $result['skipped']],
        );

        return Command::SUCCESS;
    }
}
