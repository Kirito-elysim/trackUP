<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\RiseUpCollectionSyncTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class RiseUpCollectionSyncTraitTest extends TestCase
{
    public function testCountsAreAggregatedByProcessRowOutcome(): void
    {
        $harness = new class {
            use RiseUpCollectionSyncTrait;

            /**
             * @param array<int, mixed> $rows
             * @return array<string, int>
             */
            public function run(EntityManagerInterface $em, array $rows, callable $processRow, int $flushEvery, ?callable $afterClear = null): array
            {
                return $this->runCollectionSync($em, $rows, $processRow, $flushEvery, $afterClear);
            }
        };

        $em = $this->createStub(EntityManagerInterface::class);

        $rows = [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]];
        $result = $harness->run($em, $rows, static function (array $row): string {
            return $row['id'] <= 2 ? 'created' : 'updated';
        }, flushEvery: 100);

        $this->assertSame(['fetched' => 4, 'created' => 2, 'updated' => 2], $result);
    }

    public function testNonArrayRowsAreSkippedButStillCountedInFetched(): void
    {
        $harness = new class {
            use RiseUpCollectionSyncTrait;

            /**
             * @param array<int, mixed> $rows
             * @return array<string, int>
             */
            public function run(EntityManagerInterface $em, array $rows, callable $processRow, int $flushEvery): array
            {
                return $this->runCollectionSync($em, $rows, $processRow, $flushEvery);
            }
        };

        $em = $this->createStub(EntityManagerInterface::class);

        $rows = [['id' => 1], 'not-a-row', null, ['id' => 2]];
        $result = $harness->run($em, $rows, static fn (array $row): string => 'created', flushEvery: 100);

        $this->assertSame(4, $result['fetched']);
        $this->assertSame(2, $result['created']);
    }

    public function testFlushesAndClearsEveryFlushEveryRowsPlusOnceAtTheEnd(): void
    {
        $harness = new class {
            use RiseUpCollectionSyncTrait;

            /**
             * @param array<int, mixed> $rows
             * @return array<string, int>
             */
            public function run(EntityManagerInterface $em, array $rows, callable $processRow, int $flushEvery): array
            {
                return $this->runCollectionSync($em, $rows, $processRow, $flushEvery);
            }
        };

        $em = $this->createMock(EntityManagerInterface::class);
        // 10 rows, flushEvery=3 -> periodic flush/clear at rows 3, 6, 9, plus one final flush/clear = 4 total.
        $em->expects($this->exactly(4))->method('flush');
        $em->expects($this->exactly(4))->method('clear');

        $rows = array_fill(0, 10, ['id' => 1]);
        $harness->run($em, $rows, static fn (array $row): string => 'created', flushEvery: 3);
    }

    public function testAfterClearRunsOnEveryPeriodicClearButNotOnTheFinalOne(): void
    {
        $harness = new class {
            use RiseUpCollectionSyncTrait;

            /**
             * @param array<int, mixed> $rows
             * @return array<string, int>
             */
            public function run(EntityManagerInterface $em, array $rows, callable $processRow, int $flushEvery, callable $afterClear): array
            {
                return $this->runCollectionSync($em, $rows, $processRow, $flushEvery, $afterClear);
            }
        };

        $em = $this->createStub(EntityManagerInterface::class);
        $afterClearCalls = 0;

        // 7 rows, flushEvery=3 -> periodic clears at rows 3 and 6 call afterClear
        // (2 times); the unconditional flush/clear after row 7 (loop end) must not
        // invoke it again since row 7 isn't itself a multiple of flushEvery.
        $rows = array_fill(0, 7, ['id' => 1]);
        $harness->run($em, $rows, static fn (array $row): string => 'created', flushEvery: 3, afterClear: function () use (&$afterClearCalls): void {
            ++$afterClearCalls;
        });

        $this->assertSame(2, $afterClearCalls);
    }

    public function testProcessRowClosureSeesReloadedMapsAfterClear(): void
    {
        // Simulates the real usage pattern: a lookup map captured by reference in
        // processRow, reassigned by afterClear once EntityManager::clear() would
        // have detached the objects it held.
        $harness = new class {
            use RiseUpCollectionSyncTrait;

            /**
             * @param array<int, mixed> $rows
             * @return array<string, int>
             */
            public function run(EntityManagerInterface $em, array $rows, int $flushEvery): array
            {
                $map = ['gen' => 0];
                $reloadCount = 0;

                $processRow = function (array $row) use (&$map): string {
                    return $map['gen'] === 0 ? 'created' : 'updated';
                };

                $afterClear = function () use (&$map, &$reloadCount): void {
                    ++$reloadCount;
                    $map = ['gen' => $reloadCount];
                };

                return $this->runCollectionSync($em, $rows, $processRow, $flushEvery, $afterClear);
            }
        };

        $em = $this->createStub(EntityManagerInterface::class);

        // First 3 rows use the initial map (gen 0 -> 'created'); afterClear then
        // bumps gen to 1, so the remaining rows must see 'updated'.
        $rows = array_fill(0, 6, ['id' => 1]);
        $result = $harness->run($em, $rows, flushEvery: 3);

        $this->assertSame(3, $result['created']);
        $this->assertSame(3, $result['updated']);
    }
}
