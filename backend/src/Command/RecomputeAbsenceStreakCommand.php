<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Learner;
use App\Service\AbsenceStreakService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:absences:recompute-streak',
    description: "Recompute one learner's consecutive unjustified masterclass absence streak (roadmap 3.4).",
)]
class RecomputeAbsenceStreakCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AbsenceStreakService $absenceStreakService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('learnerId', InputArgument::REQUIRED, 'Learner id')
            ->addOption(
                'clear-reset',
                null,
                InputOption::VALUE_NONE,
                "Clear absence_counter_reset_at first, so the learner's full absence history counts again "
                . '(otherwise recompute() only re-evaluates the already-tracked window and may be a no-op).',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $learnerId = (int) $input->getArgument('learnerId');

        $learner = $this->entityManager->getRepository(Learner::class)->find($learnerId);

        if (!$learner instanceof Learner) {
            $io->error(sprintf('Learner #%d not found.', $learnerId));

            return Command::FAILURE;
        }

        if ($input->getOption('clear-reset')) {
            $learner->setAbsenceCounterResetAt(null);
        }

        $this->absenceStreakService->recompute($learner);
        $this->entityManager->flush();

        $io->success(sprintf(
            'Learner #%d (%s %s): %d consecutive unjustified masterclass absence(s), alert %s.',
            $learnerId,
            $learner->getFirstName(),
            $learner->getLastName(),
            $learner->getConsecutiveUnjustifiedMasterclassAbsences(),
            $learner->getDisciplinaryAlertSentAt() !== null ? 'triggered' : 'not triggered',
        ));

        return Command::SUCCESS;
    }
}
