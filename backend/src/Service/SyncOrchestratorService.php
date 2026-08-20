<?php
declare(strict_types=1);

namespace App\Service;

use App\Command\SyncClassroomSessionsCommand;
use App\Command\SyncLearnersCommand;
use App\Command\SyncLearnerStepStatesCommand;
use App\Command\SyncLearningPathsCommand;
use App\Command\SyncRiseUpGroupMembershipsCommand;
use App\Command\SyncRiseUpGroupsCommand;
use App\Command\SyncTrainingModulesStepsCommand;
use App\Command\SyncTrainingRegistrationsCommand;
use App\Command\SyncTrainingsCommand;
use App\Entity\Role;
use App\Entity\SyncRun;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

// Point d'entrée unique pour exécuter les 9 syncs Rise Up dans l'ordre, que ce soit depuis la
// planification (Symfony Scheduler), la commande app:sync:all ou le bouton "Tout synchroniser" —
// garantit que les 3 chemins produisent exactement le même log en base et le même email de résumé.
// Une étape en échec n'interrompt pas les suivantes : on préfère 8 jeux de données à jour et 1 en
// erreur plutôt que de tout figer à cause d'un seul souci Rise Up.
class SyncOrchestratorService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $fromAddress,
        private readonly SyncLearnersCommand $syncLearnersCommand,
        private readonly SyncTrainingsCommand $syncTrainingsCommand,
        private readonly SyncRiseUpGroupsCommand $syncRiseUpGroupsCommand,
        private readonly SyncRiseUpGroupMembershipsCommand $syncRiseUpGroupMembershipsCommand,
        private readonly SyncTrainingModulesStepsCommand $syncTrainingModulesStepsCommand,
        private readonly SyncLearningPathsCommand $syncLearningPathsCommand,
        private readonly SyncTrainingRegistrationsCommand $syncTrainingRegistrationsCommand,
        private readonly SyncLearnerStepStatesCommand $syncLearnerStepStatesCommand,
        private readonly SyncClassroomSessionsCommand $syncClassroomSessionsCommand,
    ) {
    }

    public function runAll(string $triggerType, ?User $triggeredBy = null): SyncRun
    {
        // Chacune des 9 commandes tourne normalement dans son propre process (128M dédiés) ; ici
        // elles s'enchaînent dans le même process, donc la mémoire cumule malgré les clear()
        // périodiques internes à chaque service. Un run groupé est un job de fond ponctuel
        // (planifié ou déclenché à la main), pas une requête web : la marge est sans risque.
        ini_set('memory_limit', '1024M');

        $run = new SyncRun($triggerType, $triggeredBy);
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $runId = $run->getId();

        $steps = [
            ['command' => $this->syncLearnersCommand, 'label' => 'Apprenants'],
            ['command' => $this->syncTrainingsCommand, 'label' => 'Formations'],
            ['command' => $this->syncRiseUpGroupsCommand, 'label' => 'Groupes & classes'],
            ['command' => $this->syncRiseUpGroupMembershipsCommand, 'label' => 'Appartenances groupes'],
            ['command' => $this->syncTrainingModulesStepsCommand, 'label' => 'Modules & étapes'],
            ['command' => $this->syncLearningPathsCommand, 'label' => 'Parcours'],
            ['command' => $this->syncTrainingRegistrationsCommand, 'label' => 'Inscriptions formation'],
            ['command' => $this->syncLearnerStepStatesCommand, 'label' => 'Progression apprenants'],
            ['command' => $this->syncClassroomSessionsCommand, 'label' => 'Sessions & signatures'],
        ];

        $successCount = 0;
        $failureCount = 0;

        foreach ($steps as $index => $step) {
            /** @var Command $command */
            $command = $step['command'];

            // Écrit "étape en cours" avant de lancer la commande, pour que le polling front affiche
            // une progression en temps réel au lieu de rester figé pendant les dizaines de secondes
            // que peut prendre une étape (sessions notamment).
            $this->markCurrentStep($runId, $index + 1, $step['label']);

            $output = new BufferedOutput();
            $startedAt = microtime(true);
            $status = 'failed';

            try {
                $exitCode = $command->run(new ArrayInput([]), $output);
                $status = $exitCode === Command::SUCCESS ? 'success' : 'failed';
            } catch (\Throwable $throwable) {
                $output->write("\n" . $throwable->getMessage());
                $this->logger->error('Sync step crashed during grouped run.', [
                    'command' => $command->getName(),
                    'exception' => $throwable,
                ]);
            }

            $status === 'success' ? $successCount++ : $failureCount++;

            // Écrit le résultat de cette étape immédiatement (au lieu d'accumuler en mémoire pour
            // tout flusher à la fin) pour que la liste des étapes terminées se remplisse en direct
            // pendant le polling, pas seulement une fois les 9 syncs terminées.
            $this->appendCompletedStep($runId, [
                'command' => (string) $command->getName(),
                'label' => $step['label'],
                'status' => $status,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
                'output' => mb_substr(trim($output->fetch()), 0, 4000),
            ]);
        }

        $this->entityManager->clear();
        /** @var SyncRun $run */
        $run = $this->entityManager->getRepository(SyncRun::class)->find($runId);

        $run->setStatus(match (true) {
            $failureCount === 0 => SyncRun::STATUS_SUCCESS,
            $successCount === 0 => SyncRun::STATUS_FAILED,
            default => SyncRun::STATUS_PARTIAL,
        });
        $run->setCurrentStep(null, null);
        $run->setFinishedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->sendSummaryEmail($run);

        return $run;
    }

    private function markCurrentStep(int $runId, int $index, string $label): void
    {
        $this->entityManager->clear();
        $run = $this->entityManager->getRepository(SyncRun::class)->find($runId);
        $run?->setCurrentStep($index, $label);
        $this->entityManager->flush();
    }

    /**
     * @param array{command: string, label: string, status: string, durationMs: int, output: string} $completedStep
     */
    private function appendCompletedStep(int $runId, array $completedStep): void
    {
        $this->entityManager->clear();
        $run = $this->entityManager->getRepository(SyncRun::class)->find($runId);
        $run?->addStep($completedStep);
        $this->entityManager->flush();
    }

    private function sendSummaryEmail(SyncRun $run): void
    {
        $adminRole = $this->entityManager->getRepository(Role::class)->findOneBy(['code' => 'ADMIN']);
        $recipients = $adminRole?->getUsers()->toArray() ?? [];

        if ($recipients === []) {
            $this->logger->warning('No ADMIN user found to receive the sync summary email.');

            return;
        }

        [$subject, $icon] = match ($run->getStatus()) {
            SyncRun::STATUS_SUCCESS => ['Synchronisation Rise Up réussie', '✅'],
            SyncRun::STATUS_PARTIAL => ['Synchronisation Rise Up partiellement réussie', '⚠️'],
            default => ['Échec de la synchronisation Rise Up', '❌'],
        };

        $triggerLabel = $run->getTriggerType() === SyncRun::TRIGGER_SCHEDULED
            ? 'planifiée (cron quotidien 2h00)'
            : sprintf('manuelle, déclenchée par %s', $run->getTriggeredBy()?->getEmail() ?? 'un administrateur');

        $durationSeconds = $run->getFinishedAt() !== null
            ? $run->getFinishedAt()->getTimestamp() - $run->getStartedAt()->getTimestamp()
            : null;

        $lines = [];
        foreach ($run->getSteps() as $step) {
            $lines[] = sprintf(
                '%s %s (%s) — %d ms',
                $step['status'] === 'success' ? '✅' : '❌',
                $step['label'],
                $step['command'],
                $step['durationMs'],
            );
        }

        $text = sprintf(
            "%s %s\n\nSynchronisation %s.\nDurée totale : %s.\n\n%s\n",
            $icon,
            $subject,
            $triggerLabel,
            $durationSeconds !== null ? $durationSeconds . 's' : 'inconnue',
            implode("\n", $lines),
        );

        $html = sprintf(
            '<p><strong>%s %s</strong></p><p>Synchronisation %s.<br>Durée totale : %s.</p><ul>%s</ul>',
            $icon,
            htmlspecialchars($subject),
            htmlspecialchars($triggerLabel),
            $durationSeconds !== null ? $durationSeconds . 's' : 'inconnue',
            implode('', array_map(
                static fn (string $line): string => '<li>' . htmlspecialchars($line) . '</li>',
                $lines,
            )),
        );

        foreach ($recipients as $recipient) {
            /** @var User $recipient */
            $email = (new Email())
                ->from($this->fromAddress)
                ->to($recipient->getEmail())
                ->subject('TrackUp - ' . $subject)
                ->text($text)
                ->html($html);

            try {
                $this->mailer->send($email);
            } catch (TransportExceptionInterface $exception) {
                $this->logger->error('Failed to send sync summary email.', [
                    'recipient' => $recipient->getEmail(),
                    'exception' => $exception,
                ]);
            }
        }
    }
}
