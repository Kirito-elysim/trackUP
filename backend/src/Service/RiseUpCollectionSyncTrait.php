<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Shared "loop over a fetched Rise Up collection, upsert row by row, flush
 * and clear the EntityManager periodically" mechanic used by most sync
 * services. Each caller is still responsible for its own getCollection()
 * call and per-row logic (skip conditions, resolving foreign-key lookup
 * maps, find-or-create, hydration) — this only factors the counting and
 * the flush/clear/reload rhythm around that loop, which was duplicated
 * near-identically across services.
 *
 * Deliberately a trait rather than an abstract base class: some services
 * (TrainingModuleStepSyncService, ClassroomSessionSyncService) run this
 * mechanic more than once per sync() call for different entities, which a
 * single-inheritance "template method" base class doesn't accommodate
 * cleanly.
 */
trait RiseUpCollectionSyncTrait
{
    /**
     * @param array<int, mixed> $rows
     * @param callable(mixed $row): string $processRow Handles one row (skip
     *        checks, find-or-create by externalId, hydrate, persist) and
     *        returns an outcome label such as 'created', 'updated' or
     *        'skipped'. Rows that aren't arrays are skipped before this is
     *        even called and are not counted at all, matching the original
     *        per-service behavior.
     * @param (callable(): void)|null $afterClear Invoked right after each
     *        periodic clear() (and not after the final one) so the caller
     *        can reload any externalId => entity lookup maps it captured by
     *        reference into $processRow — those objects are detached once
     *        clear() runs and must not be reused.
     *
     * @return array<string, int> Counts keyed by whatever $processRow
     *         returns, plus 'fetched' (the total row count, including any
     *         non-array rows skipped before $processRow was called).
     */
    private function runCollectionSync(
        EntityManagerInterface $entityManager,
        array $rows,
        callable $processRow,
        int $flushEvery,
        ?callable $afterClear = null,
    ): array {
        $counts = [];
        $processed = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $outcome = $processRow($row);
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;

            ++$processed;

            if ($processed % $flushEvery === 0) {
                $entityManager->flush();
                $entityManager->clear();

                if ($afterClear !== null) {
                    $afterClear();
                }
            }
        }

        $entityManager->flush();
        $entityManager->clear();

        return ['fetched' => count($rows), ...$counts];
    }
}
