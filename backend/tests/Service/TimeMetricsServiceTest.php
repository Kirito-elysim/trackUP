<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TimeMetricsService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class TimeMetricsServiceTest extends TestCase
{
    public function testGetTimeMetricsByLearnerConvertsEverythingToSecondsAndSumsTotals(): void
    {
        $connection = $this->createStub(Connection::class);

        // Call order inside getTimeMetricsByLearner(): elearning, session, expected-elearning, then learnerIds.
        $connection->method('fetchAllAssociative')->willReturnOnConsecutiveCalls(
            [ // getElearningTimeByLearner: module_time_seconds
                ['learner_id' => 1, 'module_time_seconds' => 600],
            ],
            [ // getSessionTimeByLearner: masterclass_time_minutes / expected_time_minutes
                ['learner_id' => 1, 'masterclass_time_minutes' => 15, 'expected_time_minutes' => 30],
            ],
            [ // getExpectedElearningTimeByLearner: expected_elearning_time_minutes
                ['learner_id' => 1, 'expected_elearning_time_minutes' => 20],
            ],
        );
        $connection->method('fetchFirstColumn')->willReturn([1, 2]);

        $service = new TimeMetricsService($connection);
        $result = $service->getTimeMetricsByLearner(learningPathId: 42);

        $this->assertSame([
            'learner_id' => 1,
            'module_time_seconds' => 600,
            'expected_time_seconds' => 1800, // 30 min * 60
            'expected_elearning_time_seconds' => 1200, // 20 min * 60
            'total_time_seconds' => 1500, // 600s module + (15 min * 60)s session
            'session_time_seconds' => 900, // 15 min * 60
        ], $result[1]);
    }

    public function testGetTimeMetricsByLearnerDefaultsToZeroWhenLearnerHasNoActivity(): void
    {
        $connection = $this->createStub(Connection::class);

        $connection->method('fetchAllAssociative')->willReturnOnConsecutiveCalls([], [], []);
        $connection->method('fetchFirstColumn')->willReturn([2]);

        $service = new TimeMetricsService($connection);
        $result = $service->getTimeMetricsByLearner(learningPathId: 42);

        $this->assertSame([
            'learner_id' => 2,
            'module_time_seconds' => 0,
            'expected_time_seconds' => 0,
            'expected_elearning_time_seconds' => 0,
            'total_time_seconds' => 0,
            'session_time_seconds' => 0,
        ], $result[2]);
    }

    public function testGetTimeMetricsByLearnerReturnsEmptyArrayWhenNoRegistrations(): void
    {
        $connection = $this->createStub(Connection::class);

        $connection->method('fetchAllAssociative')->willReturnOnConsecutiveCalls([], [], []);
        $connection->method('fetchFirstColumn')->willReturn([]);

        $service = new TimeMetricsService($connection);

        $this->assertSame([], $service->getTimeMetricsByLearner(learningPathId: 42));
    }
}
