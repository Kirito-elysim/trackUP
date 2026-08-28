<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Absence;
use App\Entity\AbsenceEvent;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

// Petite façade de persistance pour AbsenceEvent, réutilisée par les quelques call sites (service de
// notification, contrôleur admin, service d'expiration) qui journalisent une action sur une absence —
// évite de dupliquer "new AbsenceEvent(...); persist(...)" à chaque endroit.
class AbsenceEventLogger
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function log(Absence $absence, string $type, ?User $actor = null, array $metadata = []): void
    {
        $this->entityManager->persist(new AbsenceEvent($absence, $type, $actor, $metadata));
    }
}
