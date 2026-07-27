<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\RiseUpActivityLogImportService;
use App\Service\UserPermissionResolver;
use App\Util\DurationUnit;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/riseup-activity-logs')]
class RiseUpActivityLogController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPermissionResolver $permissionResolver,
        private readonly RiseUpActivityLogImportService $importService,
    ) {
    }

    #[Route('', name: 'api_riseup_activity_logs_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'exports.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $connection = $this->entityManager->getConnection();
        $page = max((int) $request->query->get('page', 1), 1);
        $pageSize = min(max((int) $request->query->get('pageSize', 100), 1), 500);
        $offset = ($page - 1) * $pageSize;
        $learnerQuery = $this->normalizeSearchString($request->query->get('learnerQuery'));
        $groupExternalId = $this->positiveIntOrNull($request->query->get('groupExternalId'));
        $learningPathId = $this->positiveIntOrNull($request->query->get('learningPathId'));
        $trainingExternalId = $this->positiveIntOrNull($request->query->get('trainingExternalId'));
        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

        $filters = $this->buildFilterSql($learnerQuery, $groupExternalId, $learningPathId, $trainingExternalId, $dateFrom, $dateTo);
        
        // Construire les conditions WHERE pour les sessions (métriques)
        $sessionConditionsMetrics = ['css.has_signed = 1'];
        if ($learnerQuery !== null) {
            $isEmail = str_contains($learnerQuery, '@');
            if ($isEmail) {
                $sessionConditionsMetrics[] = 'LOWER(COALESCE(l2.email, \'\')) = :learnerQuery';
            } else {
                $sessionConditionsMetrics[] = '(LOWER(TRIM(CONCAT(COALESCE(l2.first_name, \'\'), \' \', COALESCE(l2.last_name, \'\')))) LIKE :learnerQuery OR LOWER(COALESCE(l2.email, \'\')) LIKE :learnerQuery)';
            }
        }
        if ($dateFrom instanceof \DateTimeImmutable) {
            $sessionConditionsMetrics[] = 'cs.start_at >= :dateFrom';
        }
        if ($dateTo instanceof \DateTimeImmutable) {
            $sessionConditionsMetrics[] = 'cs.start_at <= :dateTo';
        }
        $sessionWhereMetrics = 'WHERE ' . implode(' AND ', $sessionConditionsMetrics);
        
        $metrics = $connection->fetchAssociative(
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
                    {$filters['where']}
                    
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
                    {$sessionWhereMetrics}
                ) AS combined_logs
            SQL,
            $filters['params'],
            $filters['types'],
        ) ?: [
            'logCount' => 0,
            'uniqueLearnersCount' => 0,
            'uniqueTrainingsCount' => 0,
            'totalDurationSeconds' => 0,
        ];
        $totalRows = (int) $metrics['logCount'];
        $rows = $this->fetchRows($connection, $filters, $pageSize, $offset);

        return $this->json([
            'filters' => [
                'learnerQuery' => $learnerQuery,
                'groupExternalId' => $groupExternalId,
                'learningPathId' => $learningPathId,
                'trainingExternalId' => $trainingExternalId,
                'dateFrom' => $dateFrom?->format('Y-m-d'),
                'dateTo' => $dateTo?->format('Y-m-d'),
                'availableGroups' => $this->fetchAvailableGroups($connection, $learnerQuery),
                'availableLearningPaths' => $this->fetchAvailableLearningPaths(
                    $connection,
                    $learnerQuery,
                    $groupExternalId,
                    $trainingExternalId,
                    $dateFrom,
                    $dateTo,
                ),
                'availableTrainings' => $this->fetchAvailableTrainings(
                    $connection,
                    $learnerQuery,
                    $groupExternalId,
                    $learningPathId,
                    $dateFrom,
                    $dateTo,
                ),
            ],
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'totalRows' => $totalRows,
                'totalPages' => max(1, (int) ceil($totalRows / $pageSize)),
            ],
            'metrics' => [
                'logCount' => (int) $metrics['logCount'],
                'uniqueLearnersCount' => (int) $metrics['uniqueLearnersCount'],
                'uniqueTrainingsCount' => (int) $metrics['uniqueTrainingsCount'],
                'totalDurationSeconds' => (int) $metrics['totalDurationSeconds'],
                'totalDurationMinutes' => DurationUnit::secondsToMinutesInt($metrics['totalDurationSeconds']),
            ],
            'rows' => array_map(static fn (array $row): array => [
                'id' => $row['id'],
                'sourceFileName' => $row['sourceFileName'],
                'sourceImportedAt' => $row['sourceImportedAt'],
                'trainingExternalId' => (int) $row['trainingExternalId'],
                'trainingTitle' => $row['trainingTitle'],
                'learnerExternalId' => $row['learnerExternalId'] !== null ? (int) $row['learnerExternalId'] : null,
                'learnerEmail' => $row['learnerEmail'],
                'learnerFullName' => $row['learnerFullName'],
                'loginAt' => $row['loginAt'],
                'logoutAt' => $row['logoutAt'],
                'durationSeconds' => (int) $row['durationSeconds'],
                'durationMinutes' => DurationUnit::secondsToMinutesInt($row['durationSeconds']),
                'device' => $row['device'],
                'createdAt' => $row['createdAt'],
                'sourceType' => $row['sourceType'],
            ], $rows),
            'groupContext' => $groupExternalId !== null ? $this->fetchGroupContext($connection, $groupExternalId) : null,
            'lastImportAt' => $connection->fetchOne('SELECT MAX(source_imported_at) FROM riseup_activity_logs') ?: null,
        ]);
    }

    #[Route('/export', name: 'api_riseup_activity_logs_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'exports.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $connection = $this->entityManager->getConnection();
        $learnerQuery = $this->normalizeSearchString($request->query->get('learnerQuery'));
        $groupExternalId = $this->positiveIntOrNull($request->query->get('groupExternalId'));
        $learningPathId = $this->positiveIntOrNull($request->query->get('learningPathId'));
        $trainingExternalId = $this->positiveIntOrNull($request->query->get('trainingExternalId'));
        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

        $filters = $this->buildFilterSql($learnerQuery, $groupExternalId, $learningPathId, $trainingExternalId, $dateFrom, $dateTo);
        $rows = $this->fetchRows($connection, $filters);

        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to create CSV export buffer.');
        }

        $exportDate = (new \DateTimeImmutable())->format('d/m/Y H:i:s');
        $totalDurationSeconds = 0;

        // En-têtes
        fputcsv($handle, [
            "Date d'export",
            'Email',
            'Nom',
            'Prénom',
            'Type',
            'Connexion',
            'Déconnexion',
            'Durée',
        ], ';');

        // Données
        foreach ($rows as $row) {
            $fullName = $row['learnerFullName'] ?? '';
            $nameParts = explode(' ', trim($fullName), 2);
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';
            
            $durationSeconds = (int) $row['durationSeconds'];
            $totalDurationSeconds += $durationSeconds;

            fputcsv($handle, [
                $exportDate,
                $row['learnerEmail'] ?? '',
                $lastName,
                $firstName,
                $row['sourceType'] === 'session' ? 'Classe virtuelle' : 'E-learning',
                $row['loginAt'] ?? '',
                $row['logoutAt'] ?? '',
                $this->formatDurationClock($durationSeconds),
            ], ';');
        }

        // Ligne de total
        fputcsv($handle, [
            '',
            '',
            '',
            '',
            '',
            '',
            'TOTAL',
            $this->formatDurationClock($totalDurationSeconds),
        ], ';');

        rewind($handle);
        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        $response = new Response($content);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="%s"', sprintf('riseup-activity-logs-%s.csv', (new \DateTimeImmutable())->format('Y-m-d')))
        );

        return $response;
    }

    /**
     * @return array{
     *   where:string,
     *   params:array<string, mixed>,
     *   types:array<string, int>
     * }
     */
    private function buildFilterSql(
        ?string $learnerQuery,
        ?int $groupExternalId,
        ?int $learningPathId,
        ?int $trainingExternalId,
        ?\DateTimeImmutable $dateFrom,
        ?\DateTimeImmutable $dateTo,
    ): array
    {
        $conditions = [];
        $params = [];
        $types = [];

        if ($learnerQuery !== null) {
            // Si la requête contient un @, c'est probablement un email exact
            $isEmail = str_contains($learnerQuery, '@');
            
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
                $params['learnerQuery'] = mb_strtolower($learnerQuery);
                $types['learnerQuery'] = ParameterType::STRING;
            } else {
                $conditions[] = <<<SQL
                    (
                        LOWER(COALESCE(ral.learner_email, '')) LIKE :learnerQuery
                        OR EXISTS (
                            SELECT 1
                            FROM learners lq
                            WHERE lq.external_id = ral.learner_external_id
                              AND (
                                LOWER(TRIM(CONCAT(COALESCE(lq.first_name, ''), ' ', COALESCE(lq.last_name, '')))) LIKE :learnerQuery
                                OR LOWER(COALESCE(lq.email, '')) LIKE :learnerQuery
                              )
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM learners lqe
                            WHERE lqe.email = ral.learner_email
                              AND (
                                LOWER(TRIM(CONCAT(COALESCE(lqe.first_name, ''), ' ', COALESCE(lqe.last_name, '')))) LIKE :learnerQuery
                                OR LOWER(COALESCE(lqe.email, '')) LIKE :learnerQuery
                              )
                        )
                    )
                SQL;
                $params['learnerQuery'] = '%' . mb_strtolower($learnerQuery) . '%';
                $types['learnerQuery'] = ParameterType::STRING;
            }
        }

        if ($groupExternalId !== null) {
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
            $params['groupExternalId'] = $groupExternalId;
            $types['groupExternalId'] = ParameterType::INTEGER;
        }

        if ($learningPathId !== null) {
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
            $params['learningPathId'] = $learningPathId;
            $types['learningPathId'] = ParameterType::INTEGER;
        }

        if ($trainingExternalId !== null) {
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
            $params['trainingExternalId'] = $trainingExternalId;
            $types['trainingExternalId'] = ParameterType::INTEGER;
        }

        if ($dateFrom instanceof \DateTimeImmutable) {
            $conditions[] = 'ral.login_at >= :dateFrom';
            $params['dateFrom'] = $dateFrom->format('Y-m-d H:i:s');
        }

        if ($dateTo instanceof \DateTimeImmutable) {
            $conditions[] = 'ral.login_at <= :dateTo';
            $params['dateTo'] = $dateTo->format('Y-m-d H:i:s');
        }

        return [
            'where' => $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions),
            'params' => $params,
            'types' => $types,
        ];
    }

    /**
     * @return array{0:? \DateTimeImmutable,1:? \DateTimeImmutable}
     */
    private function resolveDateRange(Request $request): array
    {
        $dateFrom = $this->parseDate($request->query->get('dateFrom'), false);
        $dateTo = $this->parseDate($request->query->get('dateTo'), true);

        if ($dateFrom instanceof \DateTimeImmutable && $dateTo instanceof \DateTimeImmutable && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo->setTime(0, 0), $dateFrom->setTime(23, 59, 59)];
        }

        return [$dateFrom, $dateTo];
    }

    private function parseDate(mixed $value, bool $endOfDay): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', trim($value));
        if (!$date instanceof \DateTimeImmutable) {
            return null;
        }

        return $endOfDay ? $date->setTime(23, 59, 59) : $date->setTime(0, 0);
    }

    /**
     * @return array<int, array{externalId:int,title:string}>
     */
    private function fetchAvailableTrainings(
        Connection $connection,
        ?string $learnerQuery,
        ?int $groupExternalId,
        ?int $learningPathId,
        ?\DateTimeImmutable $dateFrom,
        ?\DateTimeImmutable $dateTo,
    ): array
    {
        if ($learningPathId === null) {
            return [];
        }

        $filters = $this->buildFilterSql($learnerQuery, $groupExternalId, $learningPathId, null, $dateFrom, $dateTo);
        $rows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    ral.training_external_id AS trainingExternalId,
                    COALESCE(t.title, CONCAT('Formation #', ral.training_external_id)) AS trainingTitle
                FROM riseup_activity_logs ral
                INNER JOIN trainings t ON t.external_id = ral.training_external_id
                INNER JOIN learning_path_trainings lpt ON lpt.training_id = t.id AND lpt.learning_path_id = :learningPathId
                {$filters['where']}
                GROUP BY ral.training_external_id, trainingTitle
                ORDER BY trainingTitle ASC
            SQL,
            $filters['params'],
            $filters['types'],
        );

        return array_map(static fn (array $row): array => [
            'externalId' => (int) $row['trainingExternalId'],
            'title' => (string) $row['trainingTitle'],
        ], $rows);
    }

    /**
     * @return array<int, array{id:int,title:string}>
     */
    private function fetchAvailableLearningPaths(
        Connection $connection,
        ?string $learnerQuery,
        ?int $groupExternalId,
        ?int $trainingExternalId,
        ?\DateTimeImmutable $dateFrom,
        ?\DateTimeImmutable $dateTo,
    ): array
    {
        if ($groupExternalId !== null) {
            $params = ['groupExternalId' => $groupExternalId];
            $types = ['groupExternalId' => ParameterType::INTEGER];
            $extra = '';

            if ($learnerQuery !== null) {
                $params['learnerQuery'] = '%' . mb_strtolower($learnerQuery) . '%';
                $types['learnerQuery'] = ParameterType::STRING;
                $extra .= ' AND (LOWER(TRIM(CONCAT(COALESCE(l.first_name, \'\'), \' \', COALESCE(l.last_name, \'\')))) LIKE :learnerQuery OR LOWER(COALESCE(l.email, \'\')) LIKE :learnerQuery)';
            }

            if ($trainingExternalId !== null) {
                $params['trainingExternalId'] = $trainingExternalId;
                $types['trainingExternalId'] = ParameterType::INTEGER;
                $extra .= ' AND EXISTS (SELECT 1 FROM trainings tt INNER JOIN learning_path_trainings lpt ON lpt.training_id = tt.id WHERE tt.external_id = :trainingExternalId AND lpt.learning_path_id = lp.id)';
            }

            $rows = $connection->fetchAllAssociative(
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

        if ($learnerQuery !== null) {
            $rows = $connection->fetchAllAssociative(
                <<<SQL
                    SELECT
                        lp.id,
                        lp.title
                    FROM learning_paths lp
                    INNER JOIN learning_path_registrations lpr ON lpr.learning_path_id = lp.id
                    INNER JOIN learners l ON l.id = lpr.learner_id
                    WHERE
                        LOWER(TRIM(CONCAT(COALESCE(l.first_name, ''), ' ', COALESCE(l.last_name, '')))) LIKE :learnerQuery
                        OR LOWER(COALESCE(l.email, '')) LIKE :learnerQuery
                    ORDER BY lp.title ASC
                SQL,
                ['learnerQuery' => '%' . mb_strtolower($learnerQuery) . '%'],
                ['learnerQuery' => ParameterType::STRING],
            );

            return array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
            ], $rows);
        }

        $filters = $this->buildFilterSql($learnerQuery, null, null, $trainingExternalId, $dateFrom, $dateTo);
        $rows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    lp.id,
                    lp.title
                FROM riseup_activity_logs ral
                INNER JOIN trainings t ON t.external_id = ral.training_external_id
                INNER JOIN learning_path_trainings lpt ON lpt.training_id = t.id
                INNER JOIN learning_paths lp ON lp.id = lpt.learning_path_id
                {$filters['where']}
                GROUP BY lp.id, lp.title
                ORDER BY lp.title ASC
            SQL,
            $filters['params'],
            $filters['types'],
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
        ], $rows);
    }

    /**
     * @return array<int, array{externalId:int,name:string}>
     */
    private function fetchAvailableGroups(Connection $connection, ?string $learnerQuery): array
    {
        $params = [];
        $types = [];
        $where = '';

        if ($learnerQuery !== null) {
            $params['learnerQuery'] = '%' . mb_strtolower($learnerQuery) . '%';
            $types['learnerQuery'] = ParameterType::STRING;
            $where = 'WHERE (LOWER(TRIM(CONCAT(COALESCE(l.first_name, \'\'), \' \', COALESCE(l.last_name, \'\')))) LIKE :learnerQuery OR LOWER(COALESCE(l.email, \'\')) LIKE :learnerQuery)';
        }

        $rows = $connection->fetchAllAssociative(
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
    private function fetchGroupContext(Connection $connection, int $groupExternalId): ?array
    {
        $group = $connection->fetchAssociative(
            'SELECT external_id AS externalId, name FROM riseup_groups WHERE external_id = :externalId',
            ['externalId' => $groupExternalId],
            ['externalId' => ParameterType::INTEGER],
        );

        if (!is_array($group)) {
            return null;
        }

        $memberCount = (int) ($connection->fetchOne(
            <<<SQL
                SELECT COUNT(DISTINCT lg.learner_id)
                FROM riseup_learner_groups lg
                INNER JOIN riseup_groups g ON g.id = lg.group_id
                WHERE g.external_id = :externalId
            SQL,
            ['externalId' => $groupExternalId],
            ['externalId' => ParameterType::INTEGER],
        ) ?: 0);

        $learningPaths = $connection->fetchAllAssociative(
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

    /**
     * @param array{
     *   where:string,
     *   params:array<string, mixed>,
     *   types:array<string, int>
     * } $filters
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchRows(Connection $connection, array $filters, ?int $limit = null, ?int $offset = null): array
    {
        // Construire les conditions WHERE pour les sessions
        $sessionConditions = ['css.has_signed = 1'];
        
        // Ajouter le filtre learner pour les sessions si présent
        if (isset($filters['params']['learnerQuery'])) {
            $learnerQuery = $filters['params']['learnerQuery'];
            $isEmail = str_contains($learnerQuery, '@');
            
            if ($isEmail) {
                $sessionConditions[] = 'LOWER(COALESCE(l2.email, \'\')) = :learnerQuery';
            } else {
                $sessionConditions[] = '(LOWER(TRIM(CONCAT(COALESCE(l2.first_name, \'\'), \' \', COALESCE(l2.last_name, \'\')))) LIKE :learnerQuery OR LOWER(COALESCE(l2.email, \'\')) LIKE :learnerQuery)';
            }
        }

        // Propager le filtre de date sur cs.start_at pour les sessions
        if (isset($filters['params']['dateFrom'])) {
            $sessionConditions[] = 'cs.start_at >= :dateFrom';
        }
        if (isset($filters['params']['dateTo'])) {
            $sessionConditions[] = 'cs.start_at <= :dateTo';
        }
        
        $sessionWhere = 'WHERE ' . implode(' AND ', $sessionConditions);
        
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
            {$filters['where']}
            
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

        $params = $filters['params'];
        $types = $filters['types'];

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

        return $connection->fetchAllAssociative($sql, $params, $types);
    }

    private function formatDurationClock(int $durationSeconds): string
    {
        $hours = intdiv(max(0, $durationSeconds), 3600);
        $minutes = intdiv(max(0, $durationSeconds) % 3600, 60);
        $seconds = max(0, $durationSeconds) % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function normalizeSearchString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    #[Route('/import', name: 'api_riseup_activity_logs_import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'activity_logs.import')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');

        if ($file === null) {
            return $this->json([
                'success' => false,
                'message' => 'Aucun fichier fourni.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['xlsx', 'csv'], true)) {
            return $this->json([
                'success' => false,
                'message' => 'Le fichier doit être au format XLSX ou CSV.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->importService->import($file->getPathname(), $extension);

            return $this->json([
                'success' => true,
                'message' => sprintf(
                    'Import terminé avec succès : %d ligne(s) importée(s) sur %d ligne(s) analysée(s), %d ligne(s) ignorée(s)',
                    $result['imported'],
                    $result['parsed'],
                    $result['skipped']
                ),
                'parsed' => $result['parsed'],
                'imported' => $result['imported'],
                'skipped' => $result['skipped'],
                'fileName' => $file->getClientOriginalName(),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors de l\'import : ' . $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
