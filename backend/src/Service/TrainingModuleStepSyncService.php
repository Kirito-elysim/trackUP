<?php

namespace App\Service;

use App\Entity\Training;
use App\Entity\TrainingModule;
use App\Entity\TrainingStep;
use App\Integration\RiseUp\RiseUpApiClient;
use Doctrine\ORM\EntityManagerInterface;

class TrainingModuleStepSyncService
{
    use RiseUpCollectionSyncTrait;

    public function __construct(
        private readonly RiseUpApiClient $riseUpApiClient,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{
     *     modules: array{fetched:int,created:int,updated:int,skipped:int},
     *     steps: array{fetched:int,created:int,updated:int,skipped:int}
     * }
     */
    public function sync(int $pageSize = 500, int $flushEvery = 200): array
    {
        $moduleResult = $this->syncModules($pageSize, $flushEvery);
        $stepResult = $this->syncSteps($pageSize, $flushEvery);

        return [
            'modules' => $moduleResult,
            'steps' => $stepResult,
        ];
    }

    /**
     * @return array{fetched:int,created:int,updated:int,skipped:int}
     */
    private function syncModules(int $pageSize, int $flushEvery): array
    {
        $rows = $this->riseUpApiClient->getCollection('/v3/modules', [], $pageSize);
        $trainingsByExternalId = $this->reloadTrainings();

        $processRow = function (array $row) use (&$trainingsByExternalId): string {
            $trainingExternalId = $this->requireInt($row, 'idtraining');
            $training = $trainingsByExternalId[$trainingExternalId] ?? null;

            if (!$training instanceof Training) {
                return 'skipped';
            }

            $externalId = $this->requireInt($row, 'id');
            $module = $this->entityManager->getRepository(TrainingModule::class)->findOneBy(['externalId' => $externalId]);
            $outcome = 'updated';

            if (!$module instanceof TrainingModule) {
                $module = (new TrainingModule())->setExternalId($externalId);
                $outcome = 'created';
            }

            $this->hydrateModule($module, $training, $row);
            $this->entityManager->persist($module);

            return $outcome;
        };

        $afterClear = function () use (&$trainingsByExternalId): void {
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
     * @return array{fetched:int,created:int,updated:int,skipped:int}
     */
    private function syncSteps(int $pageSize, int $flushEvery): array
    {
        $rows = $this->riseUpApiClient->getCollection('/v3/steps', [], $pageSize);
        $modulesByExternalId = $this->reloadModules();

        $processRow = function (array $row) use (&$modulesByExternalId): string {
            $moduleExternalId = $this->requireInt($row, 'idmodule');
            $module = $modulesByExternalId[$moduleExternalId] ?? null;

            if (!$module instanceof TrainingModule) {
                return 'skipped';
            }

            $externalId = $this->requireInt($row, 'id');
            $step = $this->entityManager->getRepository(TrainingStep::class)->findOneBy(['externalId' => $externalId]);
            $outcome = 'updated';

            if (!$step instanceof TrainingStep) {
                $step = (new TrainingStep())->setExternalId($externalId);
                $outcome = 'created';
            }

            $this->hydrateStep($step, $module, $row);
            $this->entityManager->persist($step);

            return $outcome;
        };

        $afterClear = function () use (&$modulesByExternalId): void {
            $modulesByExternalId = $this->reloadModules();
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
    private function hydrateModule(TrainingModule $module, Training $training, array $row): void
    {
        $module
            ->setTraining($training)
            ->setTitle($this->stringOrNull($row['title'] ?? null) ?? sprintf('Module %d', $module->getExternalId()))
            ->setDescription($this->stringOrNull($row['description'] ?? null))
            ->setEduDuration($this->intOrNull($row['eduduration'] ?? null))
            ->setDuration($this->intOrNull($row['duration'] ?? null))
            ->setType($this->stringOrNull($row['type'] ?? null))
            ->setReference($this->stringOrNull($row['reference'] ?? null))
            ->setPosition($this->intOrNull($row['position'] ?? null))
            ->setLanguage($this->stringOrNull($row['language'] ?? null))
            ->setRiseUpCreatedAt($this->dateTimeOrNull($row['creationdate'] ?? null))
            ->setRiseUpUpdatedAt($this->dateTimeOrNull($row['modificationdate'] ?? null))
            ->setSyncedAt(new \DateTimeImmutable());
    }

    /**
     * @param array<mixed> $row
     */
    private function hydrateStep(TrainingStep $step, TrainingModule $module, array $row): void
    {
        $step
            ->setModule($module)
            ->setTitle($this->stringOrNull($row['title'] ?? null) ?? sprintf('Step %d', $step->getExternalId()))
            ->setDescription($this->stringOrNull($row['description'] ?? null))
            ->setType($this->stringOrNull($row['type'] ?? null))
            ->setPosition($this->intOrNull($row['position'] ?? null))
            ->setContent($this->normalizeContent($row['content'] ?? null))
            ->setReference($this->stringOrNull($row['reference'] ?? null))
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
            throw new \RuntimeException(sprintf('Rise Up module/step payload is missing required field "%s".', $key));
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

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeContent(mixed $value): ?string
    {
        if (is_string($value)) {
            $normalized = trim($value);

            return $normalized === '' ? null : $normalized;
        }

        if ($value === null) {
            return null;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : null;
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

    /**
     * @return array<int, TrainingModule>
     */
    private function reloadModules(): array
    {
        $items = [];

        foreach ($this->entityManager->getRepository(TrainingModule::class)->findAll() as $module) {
            $items[$module->getExternalId()] = $module;
        }

        return $items;
    }
}
