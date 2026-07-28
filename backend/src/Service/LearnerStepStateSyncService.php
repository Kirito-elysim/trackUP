<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Learner;
use App\Entity\LearnerStepState;
use App\Entity\TrainingStep;
use App\Integration\RiseUp\RiseUpApiClient;
use Doctrine\ORM\EntityManagerInterface;

class LearnerStepStateSyncService
{
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
        $rows = $this->riseUpApiClient->getCollection('/v3/userstepstates', [], $pageSize);
        $learnersByExternalId = $this->reloadLearners();
        $stepsByExternalId = $this->reloadSteps();

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $processed = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $learnerExternalId = $this->requireInt($row, 'iduser');
            $stepExternalId = $this->requireInt($row, 'idstep');

            $learner = $learnersByExternalId[$learnerExternalId] ?? null;
            $step = $stepsByExternalId[$stepExternalId] ?? null;

            if (!$learner instanceof Learner || !$step instanceof TrainingStep) {
                ++$skipped;

                continue;
            }

            $externalId = $this->requireInt($row, 'id');
            $stateEntity = $this->entityManager->getRepository(LearnerStepState::class)->findOneBy(['externalId' => $externalId]);

            if (!$stateEntity instanceof LearnerStepState) {
                $stateEntity = (new LearnerStepState())->setExternalId($externalId);
                ++$created;
            } else {
                ++$updated;
            }

            $this->hydrateState($stateEntity, $learner, $step, $row);
            $this->entityManager->persist($stateEntity);

            ++$processed;

            if ($processed % $flushEvery === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
                $learnersByExternalId = $this->reloadLearners();
                $stepsByExternalId = $this->reloadSteps();
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        return [
            'fetched' => count($rows),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param array<mixed> $row
     */
    private function hydrateState(LearnerStepState $stateEntity, Learner $learner, TrainingStep $step, array $row): void
    {
        $stateEntity
            ->setLearner($learner)
            ->setStep($step)
            ->setState($this->stringOrNull($row['state'] ?? null))
            ->setTimeSpent($this->intOrNull($row['timespent'] ?? null))
            ->setTotalTime($this->intOrNull($row['total_time'] ?? null))
            ->setScore($this->floatOrNull($row['score'] ?? null))
            ->setActivityAt($this->dateTimeOrNull($row['date'] ?? null))
            ->setSyncedAt(new \DateTimeImmutable());
    }

    /**
     * @param array<mixed> $row
     */
    private function requireInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (!is_int($value) && !is_string($value)) {
            throw new \RuntimeException(sprintf('Rise Up userstepstate payload is missing required field "%s".', $key));
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
     * @return array<int, TrainingStep>
     */
    private function reloadSteps(): array
    {
        $items = [];

        foreach ($this->entityManager->getRepository(TrainingStep::class)->findAll() as $step) {
            $items[$step->getExternalId()] = $step;
        }

        return $items;
    }
}
