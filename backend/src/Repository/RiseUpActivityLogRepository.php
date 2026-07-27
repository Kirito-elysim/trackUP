<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Raw-SQL queries backing the Rise Up activity log listing/export screen.
 * Combines riseup_activity_logs (e-learning) with signed classroom sessions,
 * so it isn't scoped to a single Doctrine entity — a plain DBAL-backed class
 * (like TimeMetricsService) fits better here than a ServiceEntityRepository.
 */
final class RiseUpActivityLogRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return string SQL fragment matching a learner's full name (case-insensitive,
     *                 whitespace-trimmed) against the :learnerQuery bound parameter.
     */
    public function learnerNameLikeSql(string $alias): string
    {
        return sprintf(
            "LOWER(TRIM(CONCAT(COALESCE(%s.first_name, ''), ' ', COALESCE(%s.last_name, '')))) LIKE :learnerQuery",
            $alias,
            $alias,
        );
    }

    /**
     * @return array{
     *   where:string,
     *   params:array<string, mixed>,
     *   types:array<string, int>
     * }
     */
    public function buildFilterSql(RiseUpActivityLogFilters $filters): array
    {
        $conditions = [];
        $params = [];
        $types = [];

        if ($filters->learnerQuery !== null) {
            // Si la requête contient un @, c'est probablement un email exact
            $isEmail = str_contains($filters->learnerQuery, '@');

            if ($isEmail) {
                $conditions[] = <<<SQL
                    (
                        LOWER(COALESCE(ral.learner_email, '')) = :learnerQuery
                        OR EXISTS (
                            SELECT 1
                            FROM learners lq
                            WHERE lq.external_id = ral.learner_external_id
                              AND LOWER(COALESCE(lq.email, '')) = :learnerQuery
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM learners lqe
                            WHERE lqe.email = ral.learner_email
                              AND LOWER(COALESCE(lqe.email, '')) = :learnerQuery
                        )
                    )
                SQL;
                $params['learnerQuery'] = mb_strtolower($filters->learnerQuery);
                $types['learnerQuery'] = ParameterType::STRING;
            } else {
                $conditions[] = sprintf(
                    <<<SQL
                        (
                            LOWER(COALESCE(ral.learner_email, '')) LIKE :learnerQuery
                            OR EXISTS (
                                SELECT 1
                                FROM learners lq
                                WHERE lq.external_id = ral.learner_external_id
                                  AND (%s OR LOWER(COALESCE(lq.email, '')) LIKE :learnerQuery)
                            )
                            OR EXISTS (
                                SELECT 1
                                FROM learners lqe
                                WHERE lqe.email = ral.learner_email
                                  AND (%s OR LOWER(COALESCE(lqe.email, '')) LIKE :learnerQuery)
                            )
                        )
                    SQL,
                    $this->learnerNameLikeSql('lq'),
                    $this->learnerNameLikeSql('lqe'),
                );
                $params['learnerQuery'] = '%' . mb_strtolower($filters->learnerQuery) . '%';
                $types['learnerQuery'] = ParameterType::STRING;
            }
        }

        if ($filters->groupExternalId !== null) {
            $conditions[] = <<<SQL
                (
                    EXISTS (
                        SELECT 1
                        FROM learners lg_l
                        INNER JOIN riseup_learner_groups lg ON lg.learner_id = lg_l.id
                        INNER JOIN riseup_groups rg ON rg.id = lg.group_id
                        WHERE rg.external_id = :groupExternalId
                          AND lg_l.external_id = ral.learner_external_id
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM learners lg_le
                        INNER JOIN riseup_learner_groups lg2 ON lg2.learner_id = lg_le.id
                        INNER JOIN riseup_groups rg2 ON rg2.id = lg2.group_id
                        WHERE rg2.external_id = :groupExternalId
                          AND lg_le.email = ral.learner_email
                    )
                )
            SQL;
            $params['groupExternalId'] = $filters->groupExternalId;
            $types['groupExternalId'] = ParameterType::INTEGER;
        }

        if ($filters->learningPathId !== null) {
            $conditions[] = <<<SQL
                (
                    EXISTS (
                        SELECT 1
                        FROM trainings tp
                        INNER JOIN learning_path_trainings lpt ON lpt.training_id = tp.id
                        WHERE tp.external_id = ral.training_external_id
                          AND lpt.learning_path_id = :learningPathId
                    )
                    AND (
                        EXISTS (
                            SELECT 1
                            FROM learners ll
                            INNER JOIN learning_path_registrations lpr ON lpr.learner_id = ll.id
                            WHERE ll.external_id = ral.learner_external_id
                              AND lpr.learning_path_id = :learningPathId
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM learners lle
                            INNER JOIN learning_path_registrations lpr2 ON lpr2.learner_id = lle.id
                            WHERE lle.email = ral.learner_email
                              AND lpr2.learning_path_id = :learningPathId
                        )
                    )
                )
            SQL;
            $params['learningPathId'] = $filters->learningPathId;
            $types['learningPathId'] = ParameterType::INTEGER;
        }

        if ($filters->trainingExternalId !== null) {
            $conditions[] = <<<SQL
                (
                    ral.training_external_id = :trainingExternalId
                    AND (
                        EXISTS (
                            SELECT 1
                            FROM trainings tt
                            INNER JOIN training_registrations tr ON tr.training_id = tt.id AND tr.state = 'validated'
                            INNER JOIN learners lr ON lr.id = tr.learner_id
                            WHERE tt.external_id = :trainingExternalId
                              AND lr.external_id = ral.learner_external_id
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM trainings tt2
                            INNER JOIN training_registrations tr2 ON tr2.training_id = tt2.id AND tr2.state = 'validated'
                            INNER JOIN learners lr2 ON lr2.id = tr2.learner_id
                            WHERE tt2.external_id = :trainingExternalId
                              AND lr2.email = ral.learner_email
                        )
                    )
                )
            SQL;
            $params['trainingExternalId'] = $filters->trainingExternalId;
            $types['trainingExternalId'] = ParameterType::INTEGER;
        }

        if ($filters->dateFrom instanceof \DateTimeImmutable) {
            $conditions[] = 'ral.login_at >= :dateFrom';
            $params['dateFrom'] = $filters->dateFrom->format('Y-m-d H:i:s');
        }

        if ($filters->dateTo instanceof \DateTimeImmutable) {
            $conditions[] = 'ral.login_at <= :dateTo';
            $params['dateTo'] = $filters->dateTo->format('Y-m-d H:i:s');
        }

        return [
            'where' => $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions),
            'params' => $params,
            'types' => $types,
        ];
    }

    /**
     * @return array{logCount:int,uniqueLearnersCount:int,uniqueTrainingsCount:int,totalDurationSeconds:int}
     */
    public function countAndAggregate(RiseUpActivityLogFilters $filters): array
    {
        $sqlFilters = $this->buildFilterSql($filters);
        $sessionWhere = $this->buildSessionWhereSql($filters, 'l2', 'cs');

        $metrics = $this->connection->fetchAssociative(
            <<<SQL
                SELECT
                    COUNT(*) AS logCount,
                    COUNT(DISTINCT CONCAT(COALESCE(CAST(learnerExternalId AS CHAR), ''), '|', COALESCE(learnerEmail, ''))) AS uniqueLearnersCount,
                    COUNT(DISTINCT trainingExternalId) AS uniqueTrainingsCount,
                    COALESCE(SUM(durationSeconds), 0) AS totalDurationSeconds
                FROM (
                    SELECT
                        ral.learner_external_id AS learnerExternalId,
                        ral.learner_email AS learnerEmail,
                        ral.training_external_id AS trainingExternalId,
                        ral.duration_seconds AS durationSeconds
                    FROM riseup_activity_logs ral
                    {$sqlFilters['where']}

                    UNION ALL

                    SELECT
                        l2.external_id AS learnerExternalId,
                        l2.email AS learnerEmail,
                        t2.external_id AS trainingExternalId,
                        COALESCE(cs.edu_duration, 0) * 60 AS durationSeconds
                    FROM classroom_session_registrations csr
                    INNER JOIN classroom_session_signatures css ON css.registration_id = csr.id
                    INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
                    INNER JOIN learners l2 ON l2.id = csr.learner_id
                    LEFT JOIN trainings t2 ON t2.id = cs.training_id
                    {$sessionWhere}
                ) AS combined_logs
            SQL,
            $sqlFilters['params'],
            $sqlFilters['types'],
        ) ?: [
            'logCount' => 0,
            'uniqueLearnersCount' => 0,
            'uniqueTrainingsCount' => 0,
            'totalDurationSeconds' => 0,
        ];

        return [
            'logCount' => (int) $metrics['logCount'],
            'uniqueLearnersCount' => (int) $metrics['uniqueLearnersCount'],
            'uniqueTrainingsCount' => (int) $metrics['uniqueTrainingsCount'],
            'totalDurationSeconds' => (int) $metrics['totalDurationSeconds'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findFiltered(RiseUpActivityLogFilters $filters, ?int $limit = null, ?int $offset = null): array
    {
        $sqlFilters = $this->buildFilterSql($filters);
        $sessionWhere = $this->buildSessionWhereSql($filters, 'l2', 'cs');

        $sql = <<<SQL
            SELECT
                ral.id,
                ral.source_file_name AS sourceFileName,
                ral.source_imported_at AS sourceImportedAt,
                ral.training_external_id AS trainingExternalId,
                COALESCE(t.title, CONCAT('Formation #', ral.training_external_id)) AS trainingTitle,
                ral.learner_external_id AS learnerExternalId,
                ral.learner_email AS learnerEmail,
                COALESCE(
                    NULLIF(TRIM(CONCAT(COALESCE(le.first_name, ''), ' ', COALESCE(le.last_name, ''))), ''),
                    NULLIF(TRIM(CONCAT(COALESCE(le_email.first_name, ''), ' ', COALESCE(le_email.last_name, ''))), ''),
                    ral.learner_email,
                    CONCAT('Learner #', COALESCE(ral.learner_external_id, 0))
                ) AS learnerFullName,
                ral.login_at AS loginAt,
                ral.logout_at AS logoutAt,
                ral.duration_seconds AS durationSeconds,
                ral.device,
                ral.created_at AS createdAt,
                'elearning' AS sourceType
            FROM riseup_activity_logs ral
            LEFT JOIN trainings t ON t.external_id = ral.training_external_id
            LEFT JOIN learners le ON le.external_id = ral.learner_external_id
            LEFT JOIN learners le_email ON le.id IS NULL AND le_email.email = ral.learner_email
            {$sqlFilters['where']}

            UNION ALL

            SELECT
                CONCAT('session_', csr.id, '_', css.id) AS id,
                'Classe virtuelle signée' AS sourceFileName,
                COALESCE(css.signature_date, css.synced_at) AS sourceImportedAt,
                t2.external_id AS trainingExternalId,
                COALESCE(t2.title, cs.reference, 'Session') AS trainingTitle,
                l2.external_id AS learnerExternalId,
                l2.email AS learnerEmail,
                TRIM(CONCAT(COALESCE(l2.first_name, ''), ' ', COALESCE(l2.last_name, ''))) AS learnerFullName,
                cs.start_at AS loginAt,
                cs.end_at AS logoutAt,
                COALESCE(cs.edu_duration, 0) * 60 AS durationSeconds,
                'Classe virtuelle' AS device,
                COALESCE(css.signature_date, css.synced_at) AS createdAt,
                'session' AS sourceType
            FROM classroom_session_registrations csr
            INNER JOIN classroom_session_signatures css ON css.registration_id = csr.id
            INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
            INNER JOIN learners l2 ON l2.id = csr.learner_id
            LEFT JOIN trainings t2 ON t2.id = cs.training_id
            {$sessionWhere}

            ORDER BY loginAt DESC, id DESC
        SQL;

        $params = $sqlFilters['params'];
        $types = $sqlFilters['types'];

        if ($limit !== null) {
            $sql .= ' LIMIT :limit';
            $params['limit'] = $limit;
            $types['limit'] = ParameterType::INTEGER;
        }

        if ($offset !== null) {
            $sql .= ' OFFSET :offset';
            $params['offset'] = $offset;
            $types['offset'] = ParameterType::INTEGER;
        }

        return $this->connection->fetchAllAssociative($sql, $params, $types);
    }

    /**
     * @return array<int, array{externalId:int,title:string}>
     */
    public function findAvailableTrainings(RiseUpActivityLogFilters $filters): array
    {
        if ($filters->learningPathId === null) {
            return [];
        }

        $sqlFilters = $this->buildFilterSql(new RiseUpActivityLogFilters(
            learnerQuery: $filters->learnerQuery,
            groupExternalId: $filters->groupExternalId,
            learningPathId: $filters->learningPathId,
            dateFrom: $filters->dateFrom,
            dateTo: $filters->dateTo,
        ));

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    ral.training_external_id AS trainingExternalId,
                    COALESCE(t.title, CONCAT('Formation #', ral.training_external_id)) AS trainingTitle
                FROM riseup_activity_logs ral
                INNER JOIN trainings t ON t.external_id = ral.training_external_id
                INNER JOIN learning_path_trainings lpt ON lpt.training_id = t.id AND lpt.learning_path_id = :learningPathId
                {$sqlFilters['where']}
                GROUP BY ral.training_external_id, trainingTitle
                ORDER BY trainingTitle ASC
            SQL,
            $sqlFilters['params'],
            $sqlFilters['types'],
        );

        return array_map(static fn (array $row): array => [
            'externalId' => (int) $row['trainingExternalId'],
            'title' => (string) $row['trainingTitle'],
        ], $rows);
    }

    /**
     * @return array<int, array{id:int,title:string}>
     */
    public function findAvailableLearningPaths(RiseUpActivityLogFilters $filters): array
    {
        if ($filters->groupExternalId !== null) {
            $params = ['groupExternalId' => $filters->groupExternalId];
            $types = ['groupExternalId' => ParameterType::INTEGER];
            $extra = '';

            if ($filters->learnerQuery !== null) {
                $params['learnerQuery'] = '%' . mb_strtolower($filters->learnerQuery) . '%';
                $types['learnerQuery'] = ParameterType::STRING;
                $extra .= ' AND (' . $this->learnerNameLikeSql('l') . ' OR LOWER(COALESCE(l.email, \'\')) LIKE :learnerQuery)';
            }

            if ($filters->trainingExternalId !== null) {
                $params['trainingExternalId'] = $filters->trainingExternalId;
                $types['trainingExternalId'] = ParameterType::INTEGER;
                $extra .= ' AND EXISTS (SELECT 1 FROM trainings tt INNER JOIN learning_path_trainings lpt ON lpt.training_id = tt.id WHERE tt.external_id = :trainingExternalId AND lpt.learning_path_id = lp.id)';
            }

            $rows = $this->connection->fetchAllAssociative(
                <<<SQL
                    SELECT
                        lp.id,
                        lp.title
                    FROM learning_paths lp
                    INNER JOIN learning_path_registrations lpr ON lpr.learning_path_id = lp.id
                    INNER JOIN learners l ON l.id = lpr.learner_id
                    INNER JOIN riseup_learner_groups lg ON lg.learner_id = l.id
                    INNER JOIN riseup_groups rg ON rg.id = lg.group_id
                    WHERE rg.external_id = :groupExternalId
                    {$extra}
                    GROUP BY lp.id, lp.title
                    ORDER BY lp.title ASC
                SQL,
                $params,
                $types,
            );

            return array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
            ], $rows);
        }

        if ($filters->learnerQuery !== null) {
            $rows = $this->connection->fetchAllAssociative(
                sprintf(
                    <<<SQL
                        SELECT
                            lp.id,
                            lp.title
                        FROM learning_paths lp
                        INNER JOIN learning_path_registrations lpr ON lpr.learning_path_id = lp.id
                        INNER JOIN learners l ON l.id = lpr.learner_id
                        WHERE
                            %s
                            OR LOWER(COALESCE(l.email, '')) LIKE :learnerQuery
                        ORDER BY lp.title ASC
                    SQL,
                    $this->learnerNameLikeSql('l'),
                ),
                ['learnerQuery' => '%' . mb_strtolower($filters->learnerQuery) . '%'],
                ['learnerQuery' => ParameterType::STRING],
            );

            return array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
            ], $rows);
        }

        $sqlFilters = $this->buildFilterSql(new RiseUpActivityLogFilters(
            trainingExternalId: $filters->trainingExternalId,
            dateFrom: $filters->dateFrom,
            dateTo: $filters->dateTo,
        ));
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    lp.id,
                    lp.title
                FROM riseup_activity_logs ral
                INNER JOIN trainings t ON t.external_id = ral.training_external_id
                INNER JOIN learning_path_trainings lpt ON lpt.training_id = t.id
                INNER JOIN learning_paths lp ON lp.id = lpt.learning_path_id
                {$sqlFilters['where']}
                GROUP BY lp.id, lp.title
                ORDER BY lp.title ASC
            SQL,
            $sqlFilters['params'],
            $sqlFilters['types'],
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
        ], $rows);
    }

    /**
     * @return array<int, array{externalId:int,name:string}>
     */
    public function findAvailableGroups(?string $learnerQuery): array
    {
        $params = [];
        $types = [];
        $where = '';

        if ($learnerQuery !== null) {
            $params['learnerQuery'] = '%' . mb_strtolower($learnerQuery) . '%';
            $types['learnerQuery'] = ParameterType::STRING;
            $where = 'WHERE (' . $this->learnerNameLikeSql('l') . ' OR LOWER(COALESCE(l.email, \'\')) LIKE :learnerQuery)';
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    g.external_id AS externalId,
                    g.name
                FROM riseup_groups g
                INNER JOIN riseup_learner_groups lg ON lg.group_id = g.id
                INNER JOIN learners l ON l.id = lg.learner_id
                {$where}
                GROUP BY g.external_id, g.name
                ORDER BY g.name ASC
            SQL,
            $params,
            $types,
        );

        return array_map(static fn (array $row): array => [
            'externalId' => (int) $row['externalId'],
            'name' => (string) $row['name'],
        ], $rows);
    }

    /**
     * @return array{externalId:int,name:string,memberCount:int,learningPaths:array<int, array{id:int,title:string,learnerCount:int}>}|null
     */
    public function findGroupContext(int $groupExternalId): ?array
    {
        $group = $this->connection->fetchAssociative(
            'SELECT external_id AS externalId, name FROM riseup_groups WHERE external_id = :externalId',
            ['externalId' => $groupExternalId],
            ['externalId' => ParameterType::INTEGER],
        );

        if (!is_array($group)) {
            return null;
        }

        $memberCount = (int) ($this->connection->fetchOne(
            <<<SQL
                SELECT COUNT(DISTINCT lg.learner_id)
                FROM riseup_learner_groups lg
                INNER JOIN riseup_groups g ON g.id = lg.group_id
                WHERE g.external_id = :externalId
            SQL,
            ['externalId' => $groupExternalId],
            ['externalId' => ParameterType::INTEGER],
        ) ?: 0);

        $learningPaths = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    lp.id,
                    lp.title,
                    COUNT(DISTINCT lpr.learner_id) AS learnerCount
                FROM learning_paths lp
                INNER JOIN learning_path_registrations lpr ON lpr.learning_path_id = lp.id
                INNER JOIN learners l ON l.id = lpr.learner_id
                INNER JOIN riseup_learner_groups lg ON lg.learner_id = l.id
                INNER JOIN riseup_groups g ON g.id = lg.group_id
                WHERE g.external_id = :externalId
                GROUP BY lp.id, lp.title
                ORDER BY lp.title ASC
            SQL,
            ['externalId' => $groupExternalId],
            ['externalId' => ParameterType::INTEGER],
        );

        return [
            'externalId' => (int) $group['externalId'],
            'name' => (string) $group['name'],
            'memberCount' => $memberCount,
            'learningPaths' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'learnerCount' => (int) $row['learnerCount'],
            ], $learningPaths),
        ];
    }

    public function lastImportedAt(): ?string
    {
        $value = $this->connection->fetchOne('SELECT MAX(source_imported_at) FROM riseup_activity_logs');

        return $value !== false && $value !== null ? (string) $value : null;
    }

    /**
     * Mirrors buildFilterSql()'s learner/date conditions but against the
     * classroom-session join aliases, since sessions live in a separate
     * part of the UNION with their own table aliases.
     */
    private function buildSessionWhereSql(RiseUpActivityLogFilters $filters, string $learnerAlias, string $sessionAlias): string
    {
        $conditions = ['css.has_signed = 1'];

        if ($filters->learnerQuery !== null) {
            $isEmail = str_contains($filters->learnerQuery, '@');

            if ($isEmail) {
                $conditions[] = sprintf("LOWER(COALESCE(%s.email, '')) = :learnerQuery", $learnerAlias);
            } else {
                $conditions[] = sprintf(
                    '(%s OR LOWER(COALESCE(%s.email, \'\')) LIKE :learnerQuery)',
                    $this->learnerNameLikeSql($learnerAlias),
                    $learnerAlias,
                );
            }
        }

        if ($filters->dateFrom instanceof \DateTimeImmutable) {
            $conditions[] = sprintf('%s.start_at >= :dateFrom', $sessionAlias);
        }

        if ($filters->dateTo instanceof \DateTimeImmutable) {
            $conditions[] = sprintf('%s.start_at <= :dateTo', $sessionAlias);
        }

        return 'WHERE ' . implode(' AND ', $conditions);
    }
}
