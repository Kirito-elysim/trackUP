<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Absence;
use App\Entity\Learner;
use Doctrine\ORM\EntityManagerInterface;

// Roadmap 3.4 : compte les absences masterclass non justifiées consécutives d'un apprenant, en
// tenant compte des absences encore "en_attente" (pas seulement les "non_justifiee" définitives) —
// décision explicite de l'utilisateur. Recalculé (pas incrémenté à la main) à chaque événement
// pertinent (nouvelle absence détectée, changement de statut, expiration automatique) : cela gère
// naturellement le cas où une absence déjà comptée est ensuite justifiée, sans logique d'annulation
// séparée à maintenir.
//
// absenceCounterResetAt sert de point de départ pour une réinitialisation manuelle admin : après un
// reset, seules les absences détectées après cette date comptent dans la série courante, même si
// l'historique réel contient d'autres absences non justifiées plus anciennes.
class AbsenceStreakService
{
    private const ALERT_THRESHOLD = 3;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AbsenceNotificationService $absenceNotificationService,
    ) {
    }

    public function recompute(Learner $learner): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('a')
            ->from(Absence::class, 'a')
            ->join('a.registration', 'r')
            ->join('r.session', 's')
            ->where('r.learner = :learner')
            ->andWhere('a.type = :type')
            ->orderBy('s.startAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setParameter('learner', $learner)
            ->setParameter('type', Absence::TYPE_MASTERCLASS);

        $resetAt = $learner->getAbsenceCounterResetAt();
        if ($resetAt !== null) {
            $qb->andWhere('a.detectedAt > :resetAt')->setParameter('resetAt', $resetAt);
        }

        /** @var Absence[] $absences */
        $absences = $qb->getQuery()->getResult();

        $count = 0;
        foreach ($absences as $absence) {
            if (!in_array($absence->getStatus(), [Absence::STATUS_EN_ATTENTE, Absence::STATUS_NON_JUSTIFIEE], true)) {
                break;
            }

            ++$count;
        }

        $learner->setConsecutiveUnjustifiedMasterclassAbsences($count);

        if ($count >= self::ALERT_THRESHOLD && $learner->getDisciplinaryAlertSentAt() === null) {
            $this->absenceNotificationService->sendDisciplinaryAlert($learner, $count);
            $learner->setDisciplinaryAlertSentAt(new \DateTimeImmutable());
        } elseif ($count < self::ALERT_THRESHOLD) {
            $learner->setDisciplinaryAlertSentAt(null);
        }
    }

    public function resetCounter(Learner $learner): void
    {
        $learner->setAbsenceCounterResetAt(new \DateTimeImmutable());
        $learner->setConsecutiveUnjustifiedMasterclassAbsences(0);
        $learner->setDisciplinaryAlertSentAt(null);
    }
}
