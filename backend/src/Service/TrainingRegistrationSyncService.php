<?php

namespace App\Service;

use App\Entity\Learner;
use App\Entity\Training;
use App\Entity\TrainingRegistration;
use App\Integration\RiseUp\RiseUpApiClient;
use Doctrine\ORM\EntityManagerInterface;

class TrainingRegistrationSyncService
{
    use RiseUpCollectionSyncTrait;

    public function __construct(
        private readonly RiseUpApiClient $riseUpApiClient,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{fetched:int,created:int,updated:int,skipped:int}
     */
    public function sync(int $pageSize = 500, int $flushEvery = 200): array
    {
        $rows = $this->riseUpApiClient->getCollection('/v3/courseregistrations', [], $pageSize);

        $learnersByExternalId = $this->reloadLearners();
        $trainingsByExternalId = $this->reloadTrainings();

        $processRow = function (array $row) use (&$learnersByExternalId, &$trainingsByExternalId): string {
            $learnerExternalId = $this->requireInt($row, 'iduser');
            $trainingExternalId = $this->requireInt($row, 'idtraining');

            $learner = $learnersByExternalId[$learnerExternalId] ?? null;
            $training = $trainingsByExternalId[$trainingExternalId] ?? null;

            if (!$learner instanceof Learner || !$training instanceof Training) {
                return 'skipped';
            }

            $externalId = $this->requireInt($row, 'id');
            $registration = $this->entityManager->getRepository(TrainingRegistration::class)->findOneBy(['externalId' => $externalId]);
            $outcome = 'updated';

            if (!$registration instanceof TrainingRegistration) {
                $registration = (new TrainingRegistration())->setExternalId($externalId);
                $outcome = 'created';
            }

            $this->hydrateRegistration($registration, $learner, $training, $row);
            $this->entityManager->persist($registration);

            return $outcome;
        };

        $afterClear = function () use (&$learnersByExternalId, &$trainingsByExternalId): void {
            $learnersByExternalId = $this->reloadLearners();
            $trainingsByExternalId = $this->reloadTrainings();
        };

        /** @var array{fetched:int,created?:int,updated?:int,skipped?:int} $result */
        $result = $this->runCollectionSync($this->entityManager, $rows, $processRow, $flushEvery, $afterClear);

        return [
            'fetched' => $result['fetched'],
            'created' => $result['created'] ?? 0,
            'updated' => $result['updated'] ?? 0,
            'skipped' => $result['skipped'] ?? 0,
        ];
    }

    /**
     * @param array<mixed> $row
     */
    private function hydrateRegistration(TrainingRegistration $registration, Learner $learner, Training $training, array $row): void
    {
        $registration
            ->setLearner($learner)
            ->setTraining($training)
            ->setCompanyExternalId($this->intOrNull($row['idcompany'] ?? null))
            ->setValidatorExternalId($this->intOrNull($row['iduservalidator'] ?? null))
            ->setRegisteredByExternalId($this->intOrNull($row['iduserregister'] ?? null))
            ->setState($this->stringOrNull($row['state'] ?? null))
            ->setTotalTime($this->intOrNull($row['totaltime'] ?? null))
            ->setProgress($this->floatOrNull($row['progress'] ?? null))
            ->setScore($this->floatOrNull($row['score'] ?? null))
            ->setForceFinished($this->boolOrNull($row['forcefinished'] ?? null))
            ->setReference($this->stringOrNull($row['reference'] ?? null))
            ->setSubscribedAt($this->dateTimeOrNull($row['subscribedate'] ?? null))
            ->setTrainingEndAt($this->dateTimeOrNull($row['trainingenddate'] ?? null))
            ->setRiseUpCreatedAt($this->dateTimeOrNull($row['creationdate'] ?? null))
            ->setRiseUpUpdatedAt($this->dateTimeOrNull($row['modificationdate'] ?? null))
            ->setCoursePeriodExternalId($this->intOrNull($row['idcourseperiod'] ?? null))
            ->setSyncedAt(new \DateTimeImmutable());
    }

    /**
     * @param array<mixed> $row
     */
    private function requireInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (!is_int($value) && !is_string($value)) {
            throw new \RuntimeException(sprintf('Rise Up registration payload is missing required field "%s".', $key));
        }

        return (int) $value;
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

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
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

    /**
     * @return array<int, Learner>
     */
    private function reloadLearners(): array
    {
        $items = [];

        foreach ($this->entityManager->getRepository(Learner::class)->findAll() as $learner) {
            $items[$learner->getExternalId()] = $learner;
        }

        return $items;
    }

    /**
     * @return array<int, Training>
     */
    private function reloadTrainings(): array
    {
        $items = [];

        foreach ($this->entityManager->getRepository(Training::class)->findAll() as $training) {
            $items[$training->getExternalId()] = $training;
        }

        return $items;
    }
}
