<?php

namespace App\Service;

use App\Entity\Training;
use App\Integration\RiseUp\RiseUpApiClient;
use Doctrine\ORM\EntityManagerInterface;

class TrainingSyncService
{
    public function __construct(
        private readonly RiseUpApiClient $riseUpApiClient,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{fetched:int,created:int,updated:int}
     */
    public function sync(int $pageSize = 500, int $flushEvery = 200): array
    {
        $rows = $this->riseUpApiClient->getCollection('/v3/courses', [], $pageSize);

        $created = 0;
        $updated = 0;
        $processed = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $externalId = $this->requireInt($row, 'id');
            $training = $this->entityManager->getRepository(Training::class)->findOneBy(['externalId' => $externalId]);

            if (!$training instanceof Training) {
                $training = (new Training())->setExternalId($externalId);
                ++$created;
            } else {
                ++$updated;
            }

            $this->hydrateTraining($training, $row);
            $this->entityManager->persist($training);

            ++$processed;

            if ($processed % $flushEvery === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        return [
            'fetched' => count($rows),
            'created' => $created,
            'updated' => $updated,
        ];
    }

    /**
     * @param array<mixed> $row
     */
    private function hydrateTraining(Training $training, array $row): void
    {
        $training
            ->setTitle($this->stringOrNull($row['title'] ?? null) ?? sprintf('Training %d', $training->getExternalId()))
            ->setReference($this->stringOrNull($row['reference'] ?? null))
            ->setDescription($this->stringOrNull($row['description'] ?? null))
            ->setLanguage($this->stringOrNull($row['language'] ?? null))
            ->setObjective($this->stringOrNull($row['objective'] ?? null))
            ->setEduDuration($this->intOrNull($row['eduduration'] ?? null))
            ->setState($this->stringOrNull($row['state'] ?? null))
            ->setExternalLink($this->stringOrNull($row['externallink'] ?? null))
            ->setType($this->stringOrNull($row['type'] ?? null))
            ->setSequential($this->boolOrNull($row['sequential'] ?? null))
            ->setRiseUpCreatedAt($this->dateTimeOrNull($row['creationdate'] ?? null))
            ->setRiseUpUpdatedAt($this->dateTimeOrNull($row['modificationdate'] ?? null))
            ->setSyncedAt(new \DateTimeImmutable());
    }

    /**
     * @param array<mixed> $row
     */
    private function requireInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (!is_int($value) && !is_string($value)) {
            throw new \RuntimeException(sprintf('Rise Up training payload is missing required field "%s".', $key));
        }

        return (int) $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value) && !is_float($value)) {
            return null;
        }

        return (int) $value;
    }

    private function boolOrNull(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        }

        return null;
    }

    private function dateTimeOrNull(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        return new \DateTimeImmutable($normalized);
    }
}
