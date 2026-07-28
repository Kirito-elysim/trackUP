<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Learner;
use App\Entity\RiseUpGroup;
use App\Entity\RiseUpLearnerGroup;
use App\Integration\RiseUp\RiseUpApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class RiseUpGroupMembershipSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RiseUpApiClient $riseUpApiClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{learners:int,links:int,skipped:int}
     */
    public function syncAll(): array
    {
        $syncedAt = new \DateTimeImmutable();
        $learners = $this->entityManager->getRepository(Learner::class)->findAll();

        $processed = 0;
        $links = 0;
        $skipped = 0;

        foreach ($learners as $learner) {
            if (!$learner instanceof Learner) {
                continue;
            }

            $externalId = $learner->getExternalId();

            try {
                $payload = $this->riseUpApiClient->get(sprintf('/v3/users/%d', $externalId));
            } catch (\Throwable $exception) {
                ++$skipped;
                $this->logger->warning('Skipped Rise Up group membership sync for a learner: API call failed.', [
                    'learnerId' => $learner->getId(),
                    'learnerExternalId' => $externalId,
                    'exception' => $exception->getMessage(),
                ]);
                continue;
            }

            // RiseUpApiClient::request() decodes the response body regardless of HTTP
            // status, so a 404 (e.g. user deleted/deactivated in Rise Up) doesn't throw —
            // it comes back as {"error": "...", "error_description": "..."}. Treat that as
            // a skip instead of silently wiping the learner's existing group links.
            if (isset($payload['error'])) {
                ++$skipped;
                $this->logger->warning('Skipped Rise Up group membership sync for a learner: API returned an error payload.', [
                    'learnerId' => $learner->getId(),
                    'learnerExternalId' => $externalId,
                    'error' => $payload['error'],
                    'errorDescription' => $payload['error_description'] ?? null,
                ]);
                continue;
            }

            $groupExternalIds = $this->extractGroupExternalIds($payload['groups'] ?? null);
            $linksCreatedForLearner = 0;

            try {
                // Delete + insert happen in one transaction so a crash mid-sync never
                // leaves a learner with their old groups wiped but no new ones linked.
                $this->entityManager->wrapInTransaction(
                    function () use ($learner, $groupExternalIds, $syncedAt, &$linksCreatedForLearner): void {
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
                            ++$linksCreatedForLearner;
                        }
                    }
                );
            } catch (\Throwable $exception) {
                ++$skipped;
                $this->logger->error('Skipped Rise Up group membership sync for a learner: transaction failed, changes rolled back.', [
                    'learnerId' => $learner->getId(),
                    'learnerExternalId' => $externalId,
                    'exception' => $exception->getMessage(),
                ]);
                continue;
            }

            $links += $linksCreatedForLearner;
            ++$processed;
        }

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
