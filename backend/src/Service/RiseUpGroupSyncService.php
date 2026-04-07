<?php

namespace App\Service;

use App\Entity\RiseUpGroup;
use App\Entity\RiseUpGroupLearningPath;
use App\Integration\RiseUp\RiseUpApiClient;
use Doctrine\ORM\EntityManagerInterface;

class RiseUpGroupSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RiseUpApiClient $riseUpApiClient,
    ) {
    }

    /**
     * @return array{groups:int,groupLearningPaths:int}
     */
    public function sync(): array
    {
        $syncedAt = new \DateTimeImmutable();
        $payload = $this->riseUpApiClient->get('/v3/groups');

        if (!array_is_list($payload)) {
            throw new \RuntimeException('Rise Up /v3/groups did not return a list payload.');
        }

        $groupsSynced = 0;
        $learningPathsSynced = 0;

        /** @var array<int, array<mixed>> $payload */
        foreach ($payload as $row) {
            if (!is_array($row)) {
                continue;
            }

            $externalId = $this->positiveIntOrNull($row['id'] ?? null);
            $name = is_string($row['name'] ?? null) ? trim((string) $row['name']) : '';
            if ($externalId === null || $name === '') {
                continue;
            }

            $group = $this->entityManager->getRepository(RiseUpGroup::class)->findOneBy(['externalId' => $externalId])
                ?? (new RiseUpGroup())->setExternalId($externalId);

            $group
                ->setName($name)
                ->setReference(is_string($row['reference'] ?? null) ? (string) $row['reference'] : null)
                ->setHidden((bool) ($row['hidden'] ?? false))
                ->setCommunity((bool) ($row['community'] ?? false))
                ->setRiseUpCreatedAt($this->parseRiseUpDateTime($row['creationdate'] ?? null))
                ->setRiseUpUpdatedAt($this->parseRiseUpDateTime($row['modificationdate'] ?? null))
                ->setSyncedAt($syncedAt);

            $this->entityManager->persist($group);
            ++$groupsSynced;

            $this->syncGroupLearningPaths($group, $row['trainingpaths'] ?? null, $syncedAt, $learningPathsSynced);
        }

        $this->entityManager->flush();

        return [
            'groups' => $groupsSynced,
            'groupLearningPaths' => $learningPathsSynced,
        ];
    }

    private function syncGroupLearningPaths(RiseUpGroup $group, mixed $trainingPathsValue, \DateTimeImmutable $syncedAt, int &$counter): void
    {
        // Only delete existing links when the group already exists (has a DB identifier).
        if ($group->getId() !== null) {
            $this->entityManager->createQueryBuilder()
                ->delete(RiseUpGroupLearningPath::class, 'glp')
                ->where('glp.group = :group')
                ->setParameter('group', $group)
                ->getQuery()
                ->execute();
        }

        $externalIds = $this->extractExternalIds($trainingPathsValue);
        foreach ($externalIds as $learningPathExternalId) {
            $link = (new RiseUpGroupLearningPath())
                ->setGroup($group)
                ->setLearningPathExternalId($learningPathExternalId)
                ->setSyncedAt($syncedAt);

            $this->entityManager->persist($link);
            ++$counter;
        }
    }

    /**
     * @return int[]
     */
    private function extractExternalIds(mixed $value): array
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
                $id = $this->positiveIntOrNull($item['id'] ?? null) ?? $this->positiveIntOrNull($item['trainingpathid'] ?? null);
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

    private function parseRiseUpDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', trim($value));

        return $date instanceof \DateTimeImmutable ? $date : null;
    }
}
