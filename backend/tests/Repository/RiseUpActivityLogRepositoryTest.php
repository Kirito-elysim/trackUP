<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\RiseUpActivityLogFilters;
use App\Repository\RiseUpActivityLogRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\TestCase;

final class RiseUpActivityLogRepositoryTest extends TestCase
{
    public function testLearnerNameLikeSqlBuildsTheExpectedFragmentForAnyAlias(): void
    {
        $repository = $this->makeRepository();

        $this->assertSame(
            "LOWER(TRIM(CONCAT(COALESCE(l2.first_name, ''), ' ', COALESCE(l2.last_name, '')))) LIKE :learnerQuery",
            $repository->learnerNameLikeSql('l2'),
        );
        $this->assertSame(
            "LOWER(TRIM(CONCAT(COALESCE(lqe.first_name, ''), ' ', COALESCE(lqe.last_name, '')))) LIKE :learnerQuery",
            $repository->learnerNameLikeSql('lqe'),
        );
    }

    public function testBuildFilterSqlWithNoFiltersProducesAnEmptyWhereClause(): void
    {
        $repository = $this->makeRepository();

        $result = $repository->buildFilterSql(new RiseUpActivityLogFilters());

        $this->assertSame('', $result['where']);
        $this->assertSame([], $result['params']);
        $this->assertSame([], $result['types']);
    }

    public function testBuildFilterSqlWithNonEmailLearnerQueryUsesAWildcardedLikeParameter(): void
    {
        $repository = $this->makeRepository();

        $result = $repository->buildFilterSql(new RiseUpActivityLogFilters(learnerQuery: 'Jane Doe'));

        $this->assertStringContainsString('ral.learner_email', $result['where']);
        $this->assertStringContainsString($repository->learnerNameLikeSql('lq'), $result['where']);
        $this->assertSame('%jane doe%', $result['params']['learnerQuery']);
        $this->assertSame(ParameterType::STRING, $result['types']['learnerQuery']);
    }

    public function testBuildFilterSqlWithAnEmailLearnerQueryUsesAnExactMatchParameter(): void
    {
        $repository = $this->makeRepository();

        $result = $repository->buildFilterSql(new RiseUpActivityLogFilters(learnerQuery: 'Jane@Example.com'));

        // No wildcards, and no name-matching fragment — an email goes through exact comparison only.
        $this->assertSame('jane@example.com', $result['params']['learnerQuery']);
        $this->assertStringNotContainsString('LIKE :learnerQuery', $result['where']);
    }

    public function testBuildFilterSqlBindsGroupExternalIdAsAnInteger(): void
    {
        $repository = $this->makeRepository();

        $result = $repository->buildFilterSql(new RiseUpActivityLogFilters(groupExternalId: 42));

        $this->assertStringContainsString('riseup_learner_groups', $result['where']);
        $this->assertSame(42, $result['params']['groupExternalId']);
        $this->assertSame(ParameterType::INTEGER, $result['types']['groupExternalId']);
    }

    public function testBuildFilterSqlFormatsDateBoundsAsDatetimeStrings(): void
    {
        $repository = $this->makeRepository();

        $result = $repository->buildFilterSql(new RiseUpActivityLogFilters(
            dateFrom: new \DateTimeImmutable('2026-01-01 00:00:00'),
            dateTo: new \DateTimeImmutable('2026-01-31 23:59:59'),
        ));

        $this->assertStringContainsString('ral.login_at >= :dateFrom', $result['where']);
        $this->assertStringContainsString('ral.login_at <= :dateTo', $result['where']);
        $this->assertSame('2026-01-01 00:00:00', $result['params']['dateFrom']);
        $this->assertSame('2026-01-31 23:59:59', $result['params']['dateTo']);
    }

    public function testBuildFilterSqlCombinesMultipleConditionsWithAnd(): void
    {
        $repository = $this->makeRepository();

        $result = $repository->buildFilterSql(new RiseUpActivityLogFilters(
            groupExternalId: 1,
            trainingExternalId: 2,
        ));

        $this->assertStringStartsWith('WHERE', $result['where']);
        $this->assertMatchesRegularExpression('/\)\s*AND\s*\(/', $result['where']);
        $this->assertStringContainsString('riseup_learner_groups', $result['where']);
        $this->assertStringContainsString('training_registrations', $result['where']);
        $this->assertCount(2, $result['params']);
    }

    private function makeRepository(): RiseUpActivityLogRepository
    {
        return new RiseUpActivityLogRepository($this->createStub(Connection::class));
    }
}
