<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\AbsenceExpiryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:absences:expire',
    description: 'Mark absences as non justifiée when their justification deadline has passed without a submission.',
)]
class ExpireAbsenceJustificationsCommand extends Command
{
    public function __construct(private readonly AbsenceExpiryService $absenceExpiryService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $expired = $this->absenceExpiryService->expireOverdue();
        } catch (\Throwable $throwable) {
            $io->error($throwable->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Absence justification expiry completed: %d absence(s) marked as non justifiée.', $expired));

        return Command::SUCCESS;
    }
}
