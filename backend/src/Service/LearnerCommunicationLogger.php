<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Learner;
use App\Entity\LearnerCommunication;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

// Même rôle que AbsenceEventLogger, mais pour les communications qui ne se rattachent pas à une
// absence précise (voir LearnerCommunication).
class LearnerCommunicationLogger
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function log(Learner $learner, string $type, ?User $actor = null, array $metadata = []): void
    {
        $this->entityManager->persist(new LearnerCommunication($learner, $type, $actor, $metadata));
    }
}
