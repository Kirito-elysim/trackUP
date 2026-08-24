<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\AbsenceDetectionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:absences:detect',
    description: 'Detect classroom sessions past their end date without a signature and create pending absences.',
)]
class DetectAbsencesCommand extends Command
{
    public function __construct(private readonly AbsenceDetectionService $absenceDetectionService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $detected = $this->absenceDetectionService->detect();
        } catch (\Throwable $throwable) {
            $io->error($throwable->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Absence detection completed: %d new absence(s) created.', $detected));

        return Command::SUCCESS;
    }
}
