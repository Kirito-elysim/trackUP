<?php

namespace App\Service;

use App\Entity\Learner;
use App\Entity\RiseUpGroup;
use App\Entity\RiseUpLearnerGroup;
use App\Integration\RiseUp\RiseUpApiClient;
use Doctrine\ORM\EntityManagerInterface;

class RiseUpGroupMembershipSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RiseUpApiClient $riseUpApiClient,
    ) {
    }

    /**
     * @return array{learners:int,links:int,skipped:int}
     */
    public function syncAll(int $flushEvery = 25): array
    {
        $syncedAt = new \DateTimeImmutable();
        $learners = $this->entityManager->getRepository(Learner::class)->findAll();

        $processed = 0;
        $links = 0;
        $skipped = 0;

        foreach ($learners as $index => $learner) {
            if (!$learner instanceof Learner) {
                continue;
            }

            $externalId = $learner->getExternalId();

            try {
                $payload = $this->riseUpApiClient->get(sprintf('/v3/users/%d', $externalId));
            } catch (\Throwable) {
                ++$skipped;
                continue;
            }

            $groupExternalIds = $this->extractGroupExternalIds($payload['groups'] ?? null);

            $this->entityManager->createQueryBuilder()
                ->delete(RiseUpLearnerGroup::class, 'lg')
                ->where('lg.learner = :learner')
                ->setParameter('learner', $learner)
                ->getQuery()
                ->execute();

            foreach ($groupExternalIds as $groupExternalId) {
                $group = $this->entityManager->getRepository(RiseUpGroup::class)->findOneBy(['externalId' => $groupExternalId]);
                if (!$group instanceof RiseUpGroup) {
                    // Group list can be out of sync; skip to keep membership sync robust.
                    continue;
                }

                $link = (new RiseUpLearnerGroup())
                    ->setLearner($learner)
                    ->setGroup($group)
                    ->setSyncedAt($syncedAt);

                $this->entityManager->persist($link);
                ++$links;
            }

            ++$processed;

            if (($index + 1) % $flushEvery === 0) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();

        return [
            'learners' => $processed,
            'links' => $links,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return int[]
     */
    private function extractGroupExternalIds(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $item) {
            $id = null;

            if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                $id = (int) $item;
            } elseif (is_array($item)) {
                $id = $this->positiveIntOrNull($item['id'] ?? null) ?? $this->positiveIntOrNull($item['groupid'] ?? null);
            }

            if ($id !== null && $id > 0) {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
