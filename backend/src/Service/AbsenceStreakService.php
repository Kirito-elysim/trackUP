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

    // Décale en masse la date de départ du suivi (bannière "Le suivi des relances disciplinaires
    // ne compte que les absences dont la session a lieu à partir du..." sur le tableau de bord) —
    // évite de devoir le faire un par un ou par requête SQL manuelle. Contrairement à resetCounter()
    // qui remet le compteur à 0 (correct uniquement pour une date de reset = maintenant, où rien ne peut encore
    // s'être passé après), la nouvelle date pouvant être dans le passé, on recalcule via recompute()
    // pour compter correctement les absences déjà survenues entre cette date et maintenant.
    //
    // Cible normalement les apprenants déjà suivis (absenceCounterResetAt non nul). Si personne n'est
    // encore suivi (typiquement : tout premier réglage, avant qu'aucun reset individuel n'ait jamais
    // été fait), cible tous les apprenants à la place — sinon l'action ne ferait rien du tout, ce qui
    // serait surprenant pour un admin qui vient d'importer des données et veut fixer une date de
    // départ avant même qu'une alerte n'ait pu se déclencher.
    public function bulkShiftTrackingDate(\DateTimeImmutable $resetAt): int
    {
        $repository = $this->entityManager->getRepository(Learner::class);

        /** @var Learner[] $learners */
        $learners = $repository->createQueryBuilder('l')
            ->where('l.absenceCounterResetAt IS NOT NULL')
            ->getQuery()
            ->getResult();

        if ($learners === []) {
            /** @var Learner[] $learners */
            $learners = $repository->createQueryBuilder('l')->getQuery()->getResult();
        }

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
}
