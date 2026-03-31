<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\UserPermissionResolver;
use App\Util\DurationUnit;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        $learningPathId = $this->positiveIntOrNull($request->query->get('learningPathId'));
        $trainingExternalId = $this->positiveIntOrNull($request->query->get('trainingExternalId'));
        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

        $filters = $this->buildFilterSql($learnerQuery, $learningPathId, $trainingExternalId, $dateFrom, $dateTo);
        $metrics = $connection->fetchAssociative(
            <<<SQL
                SELECT
                    COUNT(*) AS logCount,
                    COUNT(DISTINCT CONCAT(COALESCE(CAST(ral.learner_external_id AS CHAR), ''), '|', COALESCE(ral.learner_email, ''))) AS uniqueLearnersCount,
                    COUNT(DISTINCT ral.training_external_id) AS uniqueTrainingsCount,
                    COALESCE(SUM(ral.duration_seconds), 0) AS totalDurationSeconds
                FROM riseup_activity_logs ral
                {$filters['where']}
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
                'learningPathId' => $learningPathId,
                'trainingExternalId' => $trainingExternalId,
                'dateFrom' => $dateFrom?->format('Y-m-d'),
                'dateTo' => $dateTo?->format('Y-m-d'),
                'availableLearningPaths' => $this->fetchAvailableLearningPaths(
                    $connection,
                    $learnerQuery,
                    $trainingExternalId,
                    $dateFrom,
                    $dateTo,
                ),
                'availableTrainings' => $this->fetchAvailableTrainings(
                    $connection,
                    $learnerQuery,
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
                'id' => (int) $row['id'],
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
            ], $rows),
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
        $learningPathId = $this->positiveIntOrNull($request->query->get('learningPathId'));
        $trainingExternalId = $this->positiveIntOrNull($request->query->get('trainingExternalId'));
        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

        $filters = $this->buildFilterSql($learnerQuery, $learningPathId, $trainingExternalId, $dateFrom, $dateTo);
        $rows = $this->fetchRows($connection, $filters);

        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to create CSV export buffer.');
        }

        fputcsv($handle, [
            'ID de la formation',
            'Date de connexion',
            'Date de déconnexion',
            'Appareil',
            'Temps passé (heures)',
            "Identifiant d'utilisateur",
            'Email',
            'Apprenant',
            'Formation',
            'Fichier source',
            "Date d'import",
        ], ';');

        foreach ($rows as $row) {
            fputcsv($handle, [
                (int) $row['trainingExternalId'],
                $row['loginAt'] ?? '',
                $row['logoutAt'] ?? '',
                $row['device'] ?? '',
                $this->formatDurationClock((int) $row['durationSeconds']),
                $row['learnerExternalId'] !== null ? (int) $row['learnerExternalId'] : '',
                $row['learnerEmail'] ?? '',
                $row['learnerFullName'] ?? '',
                $row['trainingTitle'] ?? '',
                $row['sourceFileName'] ?? '',
                $row['sourceImportedAt'] ?? '',
            ], ';');
        }

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
            $conditions[] = 'ral.training_external_id = :trainingExternalId';
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
        ?int $learningPathId,
        ?\DateTimeImmutable $dateFrom,
        ?\DateTimeImmutable $dateTo,
    ): array
    {
        if ($learningPathId === null) {
            return [];
        }

        $filters = $this->buildFilterSql($learnerQuery, $learningPathId, null, $dateFrom, $dateTo);
        $rows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    ral.training_external_id AS trainingExternalId,
                    COALESCE(t.title, CONCAT('Formation #', ral.training_external_id)) AS trainingTitle
                FROM riseup_activity_logs ral
                INNER JOIN trainings t ON t.external_id = ral.training_external_id
                INNER JOIN learning_path_trainings lpt ON lpt.training_id = t.id
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
        ?int $trainingExternalId,
        ?\DateTimeImmutable $dateFrom,
        ?\DateTimeImmutable $dateTo,
    ): array
    {
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

        $filters = $this->buildFilterSql($learnerQuery, null, $trainingExternalId, $dateFrom, $dateTo);
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
                ral.created_at AS createdAt
            FROM riseup_activity_logs ral
            LEFT JOIN trainings t ON t.external_id = ral.training_external_id
            LEFT JOIN learners le ON le.external_id = ral.learner_external_id
            LEFT JOIN learners le_email ON le.id IS NULL AND le_email.email = ral.learner_email
            {$filters['where']}
            ORDER BY ral.login_at DESC, ral.id DESC
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
}
