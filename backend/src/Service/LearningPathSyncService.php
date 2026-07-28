<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Learner;
use App\Entity\LearningPath;
use App\Entity\LearningPathRegistration;
use App\Entity\LearningPathTraining;
use App\Entity\Training;
use App\Integration\RiseUp\RiseUpApiClient;
use Doctrine\ORM\EntityManagerInterface;

class LearningPathSyncService
{
    public function __construct(
        private readonly RiseUpApiClient $riseUpApiClient,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{learningPathsFetched:int,learningPathsCreated:int,learningPathsUpdated:int,trainingLinksCreated:int,trainingLinksSkipped:int,registrationsFetched:int,registrationsCreated:int,registrationsUpdated:int,registrationsSkipped:int}
     */
    public function sync(int $pageSize = 500): array
    {
        $learningPathRows = $this->riseUpApiClient->getCollection('/v3/learningpaths', [], $pageSize);
        $trainingByExternalId = $this->trainingMap();
        $existingLearningPaths = $this->learningPathMap();
        $seenLearningPathIds = [];
        $learningPathsCreated = 0;
        $learningPathsUpdated = 0;
        $trainingLinksCreated = 0;
        $trainingLinksSkipped = 0;

        foreach ($learningPathRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $externalId = $this->requireInt($row, 'id');
            $learningPath = $existingLearningPaths[$externalId] ?? null;

            if (!$learningPath instanceof LearningPath) {
                $learningPath = (new LearningPath())->setExternalId($externalId);
                ++$learningPathsCreated;
            } else {
                ++$learningPathsUpdated;
            }

            $this->hydrateLearningPath($learningPath, $row);
            $this->entityManager->persist($learningPath);
            $this->entityManager->flush();

            foreach ($this->entityManager->getRepository(LearningPathTraining::class)->findBy(['learningPath' => $learningPath]) as $link) {
                $this->entityManager->remove($link);
            }
            $this->entityManager->flush();

            $seenLearningPathIds[] = $externalId;

            foreach ($row['trainings'] ?? [] as $item) {
                if (!is_array($item) || !isset($item['id'])) {
                    continue;
                }

                $training = $trainingByExternalId[(int) $item['id']] ?? null;

                if (!$training instanceof Training) {
                    ++$trainingLinksSkipped;
                    continue;
                }

                $link = (new LearningPathTraining())
                    ->setLearningPath($learningPath)
                    ->setTraining($training)
                    ->setPosition($this->intOrNull($item['position'] ?? null))
                    ->setIsRequired(true)
                    ->setUpdatedAt(new \DateTimeImmutable());

                $this->entityManager->persist($link);
                ++$trainingLinksCreated;
            }

            $this->entityManager->flush();
        }

        foreach ($existingLearningPaths as $externalId => $learningPath) {
            if (in_array($externalId, $seenLearningPathIds, true)) {
                continue;
            }

            $this->entityManager->remove($learningPath);
        }
        $this->entityManager->flush();

        $registrationRows = $this->riseUpApiClient->getCollection('/v3/learningpathregistrations', [], $pageSize);
        $learningPathsByExternalId = $this->learningPathMap();
        $learnersByExternalId = $this->learnerMap();
        $existingRegistrations = $this->learningPathRegistrationMap();
        $seenRegistrationIds = [];
        $registrationsCreated = 0;
        $registrationsUpdated = 0;
        $registrationsSkipped = 0;

        foreach ($registrationRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $externalId = $this->requireInt($row, 'id');
            $learningPath = $learningPathsByExternalId[$this->requireInt($row, 'idpath')] ?? null;
            $learner = $learnersByExternalId[$this->requireInt($row, 'iduser')] ?? null;

            if (!$learningPath instanceof LearningPath || !$learner instanceof Learner) {
                ++$registrationsSkipped;
                continue;
            }

            $registration = $existingRegistrations[$externalId] ?? null;

            if (!$registration instanceof LearningPathRegistration) {
                $registration = (new LearningPathRegistration())->setExternalId($externalId);
                ++$registrationsCreated;
            } else {
                ++$registrationsUpdated;
            }

            $registration
                ->setLearningPath($learningPath)
                ->setLearner($learner)
                ->setReference($this->stringOrNull($row['reference'] ?? null))
                ->setScore($this->floatOrNull($row['score'] ?? null))
                ->setProgress($this->floatOrNull($row['progress'] ?? null))
                ->setSubscribedAt($this->dateTimeOrNull($row['subscribedate'] ?? null))
                ->setRiseUpCreatedAt($this->dateTimeOrNull($row['creationdate'] ?? ($row['created'] ?? null)))
                ->setRiseUpUpdatedAt($this->dateTimeOrNull($row['modificationdate'] ?? null))
                ->setSyncedAt(new \DateTimeImmutable());

            $this->entityManager->persist($registration);
            $seenRegistrationIds[] = $externalId;
        }

        foreach ($existingRegistrations as $externalId => $registration) {
            if (in_array($externalId, $seenRegistrationIds, true)) {
                continue;
            }

            $this->entityManager->remove($registration);
        }

        $this->entityManager->flush();

        return [
            'learningPathsFetched' => count($learningPathRows),
            'learningPathsCreated' => $learningPathsCreated,
            'learningPathsUpdated' => $learningPathsUpdated,
            'trainingLinksCreated' => $trainingLinksCreated,
            'trainingLinksSkipped' => $trainingLinksSkipped,
            'registrationsFetched' => count($registrationRows),
            'registrationsCreated' => $registrationsCreated,
            'registrationsUpdated' => $registrationsUpdated,
            'registrationsSkipped' => $registrationsSkipped,
        ];
    }

    /**
     * @param array<mixed> $row
     */
    private function hydrateLearningPath(LearningPath $learningPath, array $row): void
    {
        $learningPath
            ->setTitle($this->stringOrNull($row['title'] ?? null) ?? sprintf('Learning path %d', $learningPath->getExternalId()))
            ->setReference($this->stringOrNull($row['pathref'] ?? null))
            ->setLanguage($this->stringOrNull($row['language'] ?? null))
            ->setDescription($this->stringOrNull($row['description'] ?? null))
            ->setSequential($this->boolOrNull($row['sequential'] ?? null))
            ->setImageUrl($this->stringOrNull($row['img'] ?? null))
            ->setRiseUpCreatedAt($this->dateTimeOrNull($row['creationdate'] ?? null))
            ->setRiseUpUpdatedAt($this->dateTimeOrNull($row['modificationdate'] ?? null))
            ->setSyncedAt(new \DateTimeImmutable());
    }

    /**
     * @return array<int, Training>
     */
    private function trainingMap(): array
    {
        $items = [];

        foreach ($this->entityManager->getRepository(Training::class)->findAll() as $training) {
            $items[$training->getExternalId()] = $training;
        }

        return $items;
    }

    /**
     * @return array<int, Learner>
     */
    private function learnerMap(): array
    {
        $items = [];

        foreach ($this->entityManager->getRepository(Learner::class)->findAll() as $learner) {
            $items[$learner->getExternalId()] = $learner;
        }

        return $items;
    }

    /**
     * @return array<int, LearningPath>
     */
    private function learningPathMap(): array
    {
        $items = [];

        foreach ($this->entityManager->getRepository(LearningPath::class)->findAll() as $learningPath) {
            $items[$learningPath->getExternalId()] = $learningPath;
        }

        return $items;
    }

    /**
     * @return array<int, LearningPathRegistration>
     */
    private function learningPathRegistrationMap(): array
    {
        $items = [];

        foreach ($this->entityManager->getRepository(LearningPathRegistration::class)->findAll() as $registration) {
            $items[$registration->getExternalId()] = $registration;
        }

        return $items;
    }

    /**
     * @param array<mixed> $row
     */
    private function requireInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (!is_int($value) && !is_string($value)) {
            throw new \RuntimeException(sprintf('Rise Up learning path payload is missing required field "%s".', $key));
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

    private function floatOrNull(mixed $value): ?float
    {
        if (!is_int($value) && !is_string($value) && !is_float($value)) {
            return null;
        }

        return (float) $value;
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
