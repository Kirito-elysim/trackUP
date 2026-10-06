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
// reset, seules les absences dont la session (s.startAt) est postérieure à cette date comptent dans
// la série courante, même si l'historique réel contient d'autres absences non justifiées plus
// anciennes. Volontairement basé sur la date de la session et non sur detectedAt (le moment où
// `app:absences:detect` a tourné) : une détection en masse sur un historique donnerait le même
// detectedAt à des absences dont la session réelle date de plusieurs mois, rendant le filtre inopérant.
class AbsenceStreakService
{
    private const ALERT_THRESHOLD = 3;
    private const TRACKING_DATE_SETTING = 'absences.streak_tracking_date';

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

        // A learner without their own reset date (typically created by a later RiseUp sync) follows
        // the global tracking date, otherwise their whole imported history would count.
        $resetAt = $learner->getAbsenceCounterResetAt() ?? $this->getTrackingDate();
        if ($resetAt !== null) {
            // Filtre sur la date réelle de la session (s.startAt), pas sur detectedAt : detectedAt
            // est le moment où `app:absences:detect` a tourné, pas la date de la session manquée. Une
            // détection en masse sur un historique (import initial, rattrapage) donne le même
            // detectedAt à des dizaines d'absences dont la session réelle peut dater de plusieurs
            // mois — filtrer sur detectedAt ne les exclurait donc jamais après un reset, faussant le
            // compteur de séries consécutives.
            $qb->andWhere('s.startAt > :resetAt')->setParameter('resetAt', $resetAt);
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
            if ($this->absenceNotificationService->sendDisciplinaryAlert($learner, $count)) {
                $learner->setDisciplinaryAlertSentAt(new \DateTimeImmutable());
            }
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

    // Décale en masse la date de départ du suivi (bannière "Le suivi des relances disciplinaires
    // ne compte que les absences dont la session a lieu à partir du..." sur le tableau de bord) —
    // évite de devoir le faire un par un ou par requête SQL manuelle. Contrairement à resetCounter()
    // qui remet le compteur à 0 (correct uniquement pour une date de reset = maintenant, où rien ne peut encore
    // s'être passé après), la nouvelle date pouvant être dans le passé, on recalcule via recompute()
    // pour compter correctement les absences déjà survenues entre cette date et maintenant.
    //
    // Cible tous les apprenants, et mémorise la date comme réglage global : les apprenants créés
    // ensuite par la synchro (sans date propre) la suivent aussi. Ne cibler que les apprenants déjà
    // suivis laissait de côté tous ceux arrivés après le premier réglage, avec tout leur historique.
    public function bulkShiftTrackingDate(\DateTimeImmutable $resetAt): int
    {
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO app_settings (name, value) VALUES (:name, :value) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['name' => self::TRACKING_DATE_SETTING, 'value' => $resetAt->format('Y-m-d H:i:s')],
        );

        /** @var Learner[] $learners */
        $learners = $this->entityManager->getRepository(Learner::class)->findAll();

        foreach ($learners as $learner) {
            $learner->setAbsenceCounterResetAt($resetAt);
        }
        $this->entityManager->flush();

        foreach ($learners as $learner) {
            $this->recompute($learner);
        }
        $this->entityManager->flush();

        return count($learners);
    }

    public function getTrackingDate(): ?\DateTimeImmutable
    {
        $value = $this->entityManager->getConnection()->fetchOne(
            'SELECT value FROM app_settings WHERE name = ?',
            [self::TRACKING_DATE_SETTING],
        );

        return is_string($value) && $value !== '' ? new \DateTimeImmutable($value) : null;
    }
}
