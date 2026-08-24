<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Absence;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

// Roadmap 3.4 : deuxième déclencheur du statut "non justifiée", en plus du rejet explicite par un
// admin (Admin/AbsenceController) — expiration automatique du délai de dépôt de justificatif
// (14 jours, voir AbsenceNotificationService::JUSTIFICATION_TOKEN_TTL) sans qu'aucun document n'ait
// été déposé. Décision explicite de l'utilisateur : les deux déclencheurs comptent.
class AbsenceExpiryService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AbsenceNotificationService $absenceNotificationService,
        private readonly AbsenceStreakService $absenceStreakService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function expireOverdue(): int
    {
        $overdue = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Absence::class, 'a')
            ->where('a.status = :status')
            ->andWhere('a.justificationTokenExpiresAt IS NOT NULL')
            ->andWhere('a.justificationTokenExpiresAt < :now')
            ->setParameter('status', Absence::STATUS_EN_ATTENTE)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();

        $expired = 0;
        $affectedLearners = [];

        /** @var Absence $absence */
        foreach ($overdue as $absence) {
            $absence->setStatus(Absence::STATUS_NON_JUSTIFIEE);
            $absence->setValidation(new \DateTimeImmutable(), null);
            $this->absenceNotificationService->sendConfirmation($absence);
            ++$expired;

            if ($absence->getType() === Absence::TYPE_MASTERCLASS) {
                $learner = $absence->getRegistration()->getLearner();
                $affectedLearners[$learner->getId()] = $learner;
            }
        }

        if ($expired > 0) {
            $this->entityManager->flush();

            foreach ($affectedLearners as $learner) {
                $this->absenceStreakService->recompute($learner);
            }

            $this->entityManager->flush();
        }

        $this->logger->info('Absence justification expiry completed.', ['expired' => $expired]);

        return $expired;
    }
}
