<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Absence;
use App\Entity\ClassroomSessionRegistration;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

// "Session passée sans émargement" (roadmap 3.2, étape 1) : une inscription dont la session est
// terminée, sans aucune signature (classroom_session_signatures.has_signed = true) et sans absence
// déjà détectée pour cette inscription. Une seule période signée sur la session suffit à ne pas
// déclencher d'absence — décision validée avec l'utilisateur pour les sessions à périodes multiples
// (matin/après-midi).
class AbsenceDetectionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AbsenceNotificationService $absenceNotificationService,
        private readonly AbsenceStreakService $absenceStreakService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function detect(): int
    {
        $qb = $this->entityManager->createQueryBuilder();
        $registrations = $qb->select('r')
            ->from(ClassroomSessionRegistration::class, 'r')
            ->join('r.session', 's')
            ->where('s.endAt IS NOT NULL')
            ->andWhere('s.endAt < :now')
            ->andWhere($qb->expr()->not($qb->expr()->exists(
                'SELECT 1 FROM App\Entity\Absence a WHERE a.registration = r'
            )))
            ->andWhere($qb->expr()->not($qb->expr()->exists(
                'SELECT 1 FROM App\Entity\ClassroomSessionSignature sig WHERE sig.registration = r AND sig.hasSigned = true'
            )))
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();

        $detected = 0;
        $affectedLearners = [];

        /** @var ClassroomSessionRegistration $registration */
        foreach ($registrations as $registration) {
            $type = $registration->getSession()->getSessionType() === 'virtual'
                ? Absence::TYPE_MASTERCLASS
                : Absence::TYPE_PRESENTIEL;

            $absence = new Absence($registration, $type);
            $this->entityManager->persist($absence);
            $this->absenceNotificationService->notify($absence);
            ++$detected;

            if ($type === Absence::TYPE_MASTERCLASS) {
                $affectedLearners[$registration->getLearner()->getId()] = $registration->getLearner();
            }
        }

        if ($detected > 0) {
            // Flush avant de recalculer les séries : AbsenceStreakService interroge les absences en
            // base via DQL, donc les nouvelles entités doivent déjà être persistées pour être comptées.
            $this->entityManager->flush();

            foreach ($affectedLearners as $learner) {
                $this->absenceStreakService->recompute($learner);
            }

            $this->entityManager->flush();
        }

        $this->logger->info('Absence detection completed.', ['detected' => $detected]);

        return $detected;
    }
}
