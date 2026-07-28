<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\UserPermissionResolver;
use App\Util\DurationUnit;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/analytics')]
class AnalyticsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPermissionResolver $permissionResolver,
        #[Autowire(param: 'app.timezone')]
        private readonly string $appTimezone,
    ) {
    }

    #[Route('', name: 'api_analytics_show', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'analytics.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $connection = $this->entityManager->getConnection();
        $window = $this->resolveWindow($request);
        $previousWindow = $this->resolvePreviousWindow($window);
        $learningPathId = $this->positiveIntOrNull($request->query->get('learningPathId'));
        $learnerId = $this->positiveIntOrNull($request->query->get('learnerId'));

        $activityRows = $this->fetchActivityRows($connection, $window['start'], $window['end'], $learningPathId, $learnerId);
        $previousActivityRows = $this->fetchActivityRows($connection, $previousWindow['start'], $previousWindow['end'], $learningPathId, $learnerId);
        $learningPathActivityRows = $this->fetchLearningPathActivityRows($connection, $window['start'], $window['end'], $learningPathId, $learnerId);
        $pathMeta = $this->fetchLearningPathMeta($connection, $learningPathId, $learnerId);
        $learnerPathMeta = $this->fetchLearnerPathMeta($connection, $learningPathId, $learnerId);
        $trainingMeta = $this->fetchTrainingMeta($connection, $learningPathId, $learnerId);
        $trainingActivityRows = $this->fetchTrainingActivityRows($connection, $window['start'], $window['end'], $learningPathId, $learnerId);
        $metrics = $this->buildMetrics($activityRows, $pathMeta, $learnerPathMeta);
        $previousMetrics = $this->buildMetrics($previousActivityRows, $pathMeta, $learnerPathMeta);

        return $this->json([
            'filters' => [
                'period' => $window['period'],
                'startAt' => $window['start']->format('Y-m-d H:i:s'),
                'endAt' => $window['end']->format('Y-m-d H:i:s'),
                'previousStartAt' => $previousWindow['start']->format('Y-m-d H:i:s'),
                'previousEndAt' => $previousWindow['end']->format('Y-m-d H:i:s'),
                'bucket' => $window['bucket'],
                'learningPathId' => $learningPathId,
                'learnerId' => $learnerId,
                'availableLearningPaths' => array_map(static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'title' => $row['title'],
                ], $connection->fetchAllAssociative('SELECT id, title FROM learning_paths ORDER BY title ASC')),
                'availableLearners' => $this->fetchAvailableLearners($connection, $learningPathId),
            ],
            'metrics' => $metrics,
            'comparison' => $this->buildComparison($metrics, $previousMetrics),
            'timeSeries' => $this->buildTimeSeries($activityRows, $window['start'], $window['end'], $window['bucket']),
            'topLearningPaths' => $this->buildTopLearningPaths($pathMeta, $learningPathActivityRows),
            'learnerPathRows' => $this->buildLearnerPathRows($learnerPathMeta, $learningPathActivityRows),
            'trainingRows' => $this->buildTrainingRows($trainingMeta, $trainingActivityRows),
            'lastSyncAt' => $this->resolveLastSyncAt($connection),
        ]);
    }

    /**
     * @return array{period:string,start:\DateTimeImmutable,end:\DateTimeImmutable,bucket:string}
     */
    private function resolveWindow(Request $request): array
    {
        $period = (string) $request->query->get('period', '30d');
        $timezone = new \DateTimeZone($this->appTimezone);
        $now = new \DateTimeImmutable('now', $timezone);

        return match ($period) {
            'day' => ['period' => 'day', 'start' => $now->setTime(0, 0), 'end' => $now->setTime(23, 59, 59), 'bucket' => 'hour'],
            '7d' => ['period' => '7d', 'start' => $now->modify('-6 days')->setTime(0, 0), 'end' => $now->setTime(23, 59, 59), 'bucket' => 'day'],
            'month' => ['period' => 'month', 'start' => $now->modify('first day of this month')->setTime(0, 0), 'end' => $now->setTime(23, 59, 59), 'bucket' => 'day'],
            'year' => ['period' => 'year', 'start' => $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0), 'end' => $now->setTime(23, 59, 59), 'bucket' => 'month'],
            'custom' => $this->resolveCustomWindow($request, $now),
            default => ['period' => '30d', 'start' => $now->modify('-29 days')->setTime(0, 0), 'end' => $now->setTime(23, 59, 59), 'bucket' => 'day'],
        };
    }

    /**
     * @return array{period:string,start:\DateTimeImmutable,end:\DateTimeImmutable,bucket:string}
     */
    private function resolveCustomWindow(Request $request, \DateTimeImmutable $now): array
    {
        $startAt = (string) $request->query->get('startAt', '');
        $endAt = (string) $request->query->get('endAt', '');
        $timezone = $now->getTimezone();

        try {
            $start = $startAt !== '' ? new \DateTimeImmutable($startAt . ' 00:00:00', $timezone) : $now->modify('-29 days')->setTime(0, 0);
            $end = $endAt !== '' ? new \DateTimeImmutable($endAt . ' 23:59:59', $timezone) : $now->setTime(23, 59, 59);
        } catch (\Throwable) {
            $start = $now->modify('-29 days')->setTime(0, 0);
            $end = $now->setTime(23, 59, 59);
        }

        if ($start > $end) {
            [$start, $end] = [$end->setTime(0, 0), $start->setTime(23, 59, 59)];
        }

        $days = (int) $start->diff($end)->format('%a');

        return [
            'period' => 'custom',
            'start' => $start,
            'end' => $end,
            'bucket' => $days > 92 ? 'month' : 'day',
        ];
    }

    /**
     * @param array{period:string,start:\DateTimeImmutable,end:\DateTimeImmutable,bucket:string} $window
     *
     * @return array{period:string,start:\DateTimeImmutable,end:\DateTimeImmutable,bucket:string}
     */
    private function resolvePreviousWindow(array $window): array
    {
        $durationInSeconds = max(1, $window['end']->getTimestamp() - $window['start']->getTimestamp());
        $previousEnd = $window['start']->modify('-1 second');
        $previousStart = $previousEnd->modify(sprintf('-%d seconds', $durationInSeconds));

        return [
            'period' => 'previous',
            'start' => $previousStart,
            'end' => $previousEnd,
            'bucket' => $window['bucket'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchActivityRows(Connection $connection, \DateTimeImmutable $start, \DateTimeImmutable $end, ?int $learningPathId, ?int $learnerId): array
    {
        $params = [
            'startAt' => $start->format('Y-m-d H:i:s'),
            'endAt' => $end->format('Y-m-d H:i:s'),
        ];
        $types = [];

        $learnerStepFilters = [
            'lss.activity_at IS NOT NULL',
            'lss.activity_at BETWEEN :startAt AND :endAt',
        ];

        $sessionFilters = [
            'COALESCE(cs.start_at, csr.subscribed_at, csr.rise_up_created_at) IS NOT NULL',
            'COALESCE(cs.start_at, csr.subscribed_at, csr.rise_up_created_at) BETWEEN :startAt AND :endAt',
            '(csr.attended = 1 OR COALESCE(csr.edu_duration, cs.edu_duration, 0) > 0)',
        ];

        if ($learnerId !== null) {
            $params['learnerId'] = $learnerId;
            $types['learnerId'] = ParameterType::INTEGER;
            $learnerStepFilters[] = 'lss.learner_id = :learnerId';
            $sessionFilters[] = 'csr.learner_id = :learnerId';
        }

        if ($learningPathId !== null) {
            $params['learningPathId'] = $learningPathId;
            $types['learningPathId'] = ParameterType::INTEGER;
            $learnerStepFilters[] = <<<SQL
                EXISTS (
                    SELECT 1
                    FROM learning_path_trainings lpt
                    INNER JOIN learning_path_registrations lpr
                        ON lpr.learning_path_id = lpt.learning_path_id
                       AND lpr.learner_id = lss.learner_id
                    WHERE lpt.training_id = tm.training_id
                      AND lpr.learning_path_id = :learningPathId
                )
            SQL;
            $sessionFilters[] = <<<SQL
                cs.training_id IS NOT NULL
                AND EXISTS (
                    SELECT 1
                    FROM learning_path_trainings lpt
                    INNER JOIN learning_path_registrations lpr
                        ON lpr.learning_path_id = lpt.learning_path_id
                       AND lpr.learner_id = csr.learner_id
                    WHERE lpt.training_id = cs.training_id
                      AND lpr.learning_path_id = :learningPathId
                )
            SQL;
        }

        return $connection->fetchAllAssociative(
            sprintf(
                <<<SQL
                    SELECT *
                    FROM (
                        SELECT
                            lss.activity_at AS activityAt,
                            lss.learner_id AS learnerId,
                            COALESCE(NULLIF(lss.time_spent, 0), lss.total_time, 0) AS duration,
                            'elearning' AS source
                        FROM learner_step_states lss
                        INNER JOIN training_steps ts ON ts.id = lss.step_id
                        INNER JOIN training_modules tm ON tm.id = ts.module_id
                        WHERE %s

                        UNION ALL

                        SELECT
                            COALESCE(cs.start_at, csr.subscribed_at, csr.rise_up_created_at) AS activityAt,
                            csr.learner_id AS learnerId,
                            COALESCE(NULLIF(csr.edu_duration, 0), cs.edu_duration, 0) AS duration,
                            'session' AS source
                        FROM classroom_session_registrations csr
                        INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
                        WHERE %s
                    ) activities
                    WHERE activities.duration > 0
                    ORDER BY activities.activityAt ASC
                SQL,
                implode(' AND ', $learnerStepFilters),
                implode(' AND ', $sessionFilters),
            ),
            $params,
            $types,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchLearningPathActivityRows(Connection $connection, \DateTimeImmutable $start, \DateTimeImmutable $end, ?int $learningPathId, ?int $learnerId): array
    {
        $params = [
            'startAt' => $start->format('Y-m-d H:i:s'),
            'endAt' => $end->format('Y-m-d H:i:s'),
        ];
        $types = [];

        $stepFilters = [
            'lss.activity_at IS NOT NULL',
            'lss.activity_at BETWEEN :startAt AND :endAt',
        ];

        $sessionFilters = [
            'COALESCE(cs.start_at, csr.subscribed_at, csr.rise_up_created_at) IS NOT NULL',
            'COALESCE(cs.start_at, csr.subscribed_at, csr.rise_up_created_at) BETWEEN :startAt AND :endAt',
            '(csr.attended = 1 OR COALESCE(csr.edu_duration, cs.edu_duration, 0) > 0)',
            'cs.training_id IS NOT NULL',
        ];

        if ($learnerId !== null) {
            $params['learnerId'] = $learnerId;
            $types['learnerId'] = ParameterType::INTEGER;
            $stepFilters[] = 'lss.learner_id = :learnerId';
            $sessionFilters[] = 'csr.learner_id = :learnerId';
        }

        if ($learningPathId !== null) {
            $params['learningPathId'] = $learningPathId;
            $types['learningPathId'] = ParameterType::INTEGER;
            $stepFilters[] = 'lpt.learning_path_id = :learningPathId';
            $sessionFilters[] = 'lpt.learning_path_id = :learningPathId';
        }

        return $connection->fetchAllAssociative(
            sprintf(
                <<<SQL
                    SELECT *
                    FROM (
                        SELECT
                            lpt.learning_path_id AS learningPathId,
                            lss.activity_at AS activityAt,
                            lss.learner_id AS learnerId,
                            COALESCE(NULLIF(lss.time_spent, 0), lss.total_time, 0) AS duration,
                            'elearning' AS source
                        FROM learner_step_states lss
                        INNER JOIN training_steps ts ON ts.id = lss.step_id
                        INNER JOIN training_modules tm ON tm.id = ts.module_id
                        INNER JOIN learning_path_trainings lpt ON lpt.training_id = tm.training_id
                        INNER JOIN learning_path_registrations lpr
                            ON lpr.learning_path_id = lpt.learning_path_id
                           AND lpr.learner_id = lss.learner_id
                        WHERE %s

                        UNION ALL

                        SELECT
                            lpt.learning_path_id AS learningPathId,
                            COALESCE(cs.start_at, csr.subscribed_at, csr.rise_up_created_at) AS activityAt,
                            csr.learner_id AS learnerId,
                            COALESCE(NULLIF(csr.edu_duration, 0), cs.edu_duration, 0) AS duration,
                            'session' AS source
                        FROM classroom_session_registrations csr
                        INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
                        INNER JOIN learning_path_trainings lpt ON lpt.training_id = cs.training_id
                        INNER JOIN learning_path_registrations lpr
                            ON lpr.learning_path_id = lpt.learning_path_id
                           AND lpr.learner_id = csr.learner_id
                        WHERE %s
                    ) path_activities
                    WHERE path_activities.duration > 0
                SQL,
                implode(' AND ', $stepFilters),
                implode(' AND ', $sessionFilters),
            ),
            $params,
            $types,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchTrainingActivityRows(Connection $connection, \DateTimeImmutable $start, \DateTimeImmutable $end, ?int $learningPathId, ?int $learnerId): array
    {
        $params = [
            'startAt' => $start->format('Y-m-d H:i:s'),
            'endAt' => $end->format('Y-m-d H:i:s'),
        ];
        $types = [];

        $stepFilters = [
            'lss.activity_at IS NOT NULL',
            'lss.activity_at BETWEEN :startAt AND :endAt',
        ];

        $sessionFilters = [
            'COALESCE(cs.start_at, csr.subscribed_at, csr.rise_up_created_at) IS NOT NULL',
            'COALESCE(cs.start_at, csr.subscribed_at, csr.rise_up_created_at) BETWEEN :startAt AND :endAt',
            '(csr.attended = 1 OR COALESCE(csr.edu_duration, cs.edu_duration, 0) > 0)',
            'cs.training_id IS NOT NULL',
        ];

        if ($learnerId !== null) {
            $params['learnerId'] = $learnerId;
            $types['learnerId'] = ParameterType::INTEGER;
            $stepFilters[] = 'lss.learner_id = :learnerId';
            $sessionFilters[] = 'csr.learner_id = :learnerId';
        }

        if ($learningPathId !== null) {
            $params['learningPathId'] = $learningPathId;
            $types['learningPathId'] = ParameterType::INTEGER;
            $stepFilters[] = 'lpt.learning_path_id = :learningPathId';
            $sessionFilters[] = 'lpt.learning_path_id = :learningPathId';
        }

        return $connection->fetchAllAssociative(
            sprintf(
                <<<SQL
                    SELECT *
                    FROM (
                        SELECT
                            tm.training_id AS trainingId,
                            lss.activity_at AS activityAt,
                            lss.learner_id AS learnerId,
                            COALESCE(NULLIF(lss.time_spent, 0), lss.total_time, 0) AS duration,
                            'elearning' AS source
                        FROM learner_step_states lss
                        INNER JOIN training_steps ts ON ts.id = lss.step_id
                        INNER JOIN training_modules tm ON tm.id = ts.module_id
                        LEFT JOIN learning_path_trainings lpt ON lpt.training_id = tm.training_id
                        WHERE %s

                        UNION ALL

                        SELECT
                            cs.training_id AS trainingId,
                            COALESCE(cs.start_at, csr.subscribed_at, csr.rise_up_created_at) AS activityAt,
                            csr.learner_id AS learnerId,
                            COALESCE(NULLIF(csr.edu_duration, 0), cs.edu_duration, 0) AS duration,
                            'session' AS source
                        FROM classroom_session_registrations csr
                        INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
                        LEFT JOIN learning_path_trainings lpt ON lpt.training_id = cs.training_id
                        WHERE %s
                    ) training_activities
                    WHERE training_activities.duration > 0
                SQL,
                implode(' AND ', $stepFilters),
                implode(' AND ', $sessionFilters),
            ),
            $params,
            $types,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchLearningPathMeta(Connection $connection, ?int $learningPathId, ?int $learnerId): array
    {
        $conditions = [];
        $params = [];
        $types = [];

        if ($learningPathId !== null) {
            $conditions[] = 'lp.id = :learningPathId';
            $params['learningPathId'] = $learningPathId;
            $types['learningPathId'] = ParameterType::INTEGER;
        }

        if ($learnerId !== null) {
            $conditions[] = 'EXISTS (SELECT 1 FROM learning_path_registrations lpr2 WHERE lpr2.learning_path_id = lp.id AND lpr2.learner_id = :learnerId)';
            $params['learnerId'] = $learnerId;
            $types['learnerId'] = ParameterType::INTEGER;
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        return $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    lp.id,
                    lp.title,
                    COALESCE(pd.target_duration, 0) AS targetDuration,
                    COUNT(DISTINCT lpr.learner_id) AS learnerCount,
                    ROUND(COALESCE(AVG(lpr.progress), 0), 2) AS averageProgress
                FROM learning_paths lp
                LEFT JOIN learning_path_registrations lpr ON lpr.learning_path_id = lp.id
                LEFT JOIN (
                    SELECT lpt.learning_path_id, COALESCE(SUM(t.edu_duration), 0) AS target_duration
                    FROM learning_path_trainings lpt
                    INNER JOIN trainings t ON t.id = lpt.training_id
                    GROUP BY lpt.learning_path_id
                ) pd ON pd.learning_path_id = lp.id
                {$where}
                GROUP BY lp.id, lp.title, pd.target_duration
                ORDER BY lp.title ASC
            SQL,
            $params,
            $types,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchLearnerPathMeta(Connection $connection, ?int $learningPathId, ?int $learnerId): array
    {
        $conditions = [];
        $params = [];
        $types = [];

        if ($learnerId !== null) {
            $conditions[] = 'l.id = :learnerId';
            $params['learnerId'] = $learnerId;
            $types['learnerId'] = ParameterType::INTEGER;
        }

        if ($learningPathId !== null) {
            $conditions[] = 'EXISTS (SELECT 1 FROM learning_path_registrations lpr2 WHERE lpr2.learner_id = l.id AND lpr2.learning_path_id = :learningPathId)';
            $params['learningPathId'] = $learningPathId;
            $types['learningPathId'] = ParameterType::INTEGER;
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        return $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    l.id AS learnerId,
                    lp.id AS learningPathId,
                    lp.title AS learningPathTitle,
                    l.email,
                    l.first_name AS firstName,
                    l.last_name AS lastName,
                    l.last_login_at AS lastLoginAt,
                    COALESCE(pd.target_duration, 0) AS pathTargetDuration,
                    ROUND(COALESCE(lpr.progress, 0), 2) AS learningPathProgressPercent,
                    ROUND(COALESCE(AVG(COALESCE(tr.progress, sp.progress_percent, 0)), 0), 2) AS trainingProgressPercent
                FROM learners l
                INNER JOIN learning_path_registrations lpr ON lpr.learner_id = l.id
                INNER JOIN learning_paths lp ON lp.id = lpr.learning_path_id
                LEFT JOIN (
                    SELECT lpt.learning_path_id, COALESCE(SUM(t.edu_duration), 0) AS target_duration
                    FROM learning_path_trainings lpt
                    INNER JOIN trainings t ON t.id = lpt.training_id
                    GROUP BY lpt.learning_path_id
                ) pd ON pd.learning_path_id = lp.id
                LEFT JOIN learning_path_trainings lpt ON lpt.learning_path_id = lp.id
                LEFT JOIN training_registrations tr ON tr.learner_id = l.id AND tr.training_id = lpt.training_id
                LEFT JOIN (
                    SELECT
                        lss.learner_id,
                        tm.training_id,
                        ROUND((COUNT(DISTINCT lss.step_id) / NULLIF(training_steps.step_count, 0)) * 100, 2) AS progress_percent
                    FROM learner_step_states lss
                    INNER JOIN training_steps ts ON ts.id = lss.step_id
                    INNER JOIN training_modules tm ON tm.id = ts.module_id
                    INNER JOIN (
                        SELECT tm2.training_id, COUNT(ts2.id) AS step_count
                        FROM training_modules tm2
                        INNER JOIN training_steps ts2 ON ts2.module_id = tm2.id
                        GROUP BY tm2.training_id
                    ) training_steps ON training_steps.training_id = tm.training_id
                    WHERE lss.activity_at IS NOT NULL OR lss.state IS NOT NULL
                    GROUP BY lss.learner_id, tm.training_id, training_steps.step_count
                ) sp ON sp.learner_id = l.id AND sp.training_id = lpt.training_id
                {$where}
                GROUP BY l.id, lp.id, lp.title, l.email, l.first_name, l.last_name, l.last_login_at, lpr.progress, pd.target_duration
                ORDER BY lp.title ASC, l.last_name ASC, l.first_name ASC
            SQL,
            $params,
            $types,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchTrainingMeta(Connection $connection, ?int $learningPathId, ?int $learnerId): array
    {
        $conditions = [];
        $params = [];
        $types = [];

        if ($learningPathId !== null) {
            $conditions[] = 'EXISTS (SELECT 1 FROM learning_path_trainings lpt2 WHERE lpt2.training_id = t.id AND lpt2.learning_path_id = :learningPathId)';
            $params['learningPathId'] = $learningPathId;
            $types['learningPathId'] = ParameterType::INTEGER;
        }

        if ($learnerId !== null) {
            $conditions[] = <<<SQL
                (
                    EXISTS (SELECT 1 FROM training_registrations tr2 WHERE tr2.training_id = t.id AND tr2.learner_id = :learnerId)
                    OR EXISTS (
                        SELECT 1
                        FROM training_modules tm2
                        INNER JOIN training_steps ts2 ON ts2.module_id = tm2.id
                        INNER JOIN learner_step_states lss2 ON lss2.step_id = ts2.id
                        WHERE tm2.training_id = t.id
                          AND lss2.learner_id = :learnerId
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM classroom_sessions cs2
                        INNER JOIN classroom_session_registrations csr2 ON csr2.session_id = cs2.id
                        WHERE cs2.training_id = t.id
                          AND csr2.learner_id = :learnerId
                    )
                )
            SQL;
            $params['learnerId'] = $learnerId;
            $types['learnerId'] = ParameterType::INTEGER;
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        return $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    t.id,
                    t.title,
                    COALESCE(t.edu_duration, 0) AS targetDuration,
                    COUNT(DISTINCT tr.learner_id) AS learnerCount,
                    ROUND(COALESCE(AVG(COALESCE(tr.progress, sp.progress_percent, 0)), 0), 2) AS averageProgress
                FROM trainings t
                LEFT JOIN training_registrations tr ON tr.training_id = t.id
                LEFT JOIN (
                    SELECT
                        lss.learner_id,
                        tm.training_id,
                        ROUND((COUNT(DISTINCT lss.step_id) / NULLIF(training_steps.step_count, 0)) * 100, 2) AS progress_percent
                    FROM learner_step_states lss
                    INNER JOIN training_steps ts ON ts.id = lss.step_id
                    INNER JOIN training_modules tm ON tm.id = ts.module_id
                    INNER JOIN (
                        SELECT tm2.training_id, COUNT(ts2.id) AS step_count
                        FROM training_modules tm2
                        INNER JOIN training_steps ts2 ON ts2.module_id = tm2.id
                        GROUP BY tm2.training_id
                    ) training_steps ON training_steps.training_id = tm.training_id
                    WHERE lss.activity_at IS NOT NULL OR lss.state IS NOT NULL
                    GROUP BY lss.learner_id, tm.training_id, training_steps.step_count
                ) sp ON sp.training_id = t.id AND sp.learner_id = tr.learner_id
                {$where}
                GROUP BY t.id, t.title, t.edu_duration
                ORDER BY t.title ASC
            SQL,
            $params,
            $types,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $activityRows
     * @param array<int, array<string, mixed>> $pathMeta
     * @param array<int, array<string, mixed>> $learnerPathMeta
     *
     * @return array<string, int|float>
     */
    private function buildMetrics(array $activityRows, array $pathMeta, array $learnerPathMeta): array
    {
        $totalTrackedTime = 0;
        $elearningTrackedTime = 0;
        $masterclassTrackedTime = 0;
        $learners = [];

        foreach ($activityRows as $row) {
            $duration = $this->durationToMinutes($row);
            $source = (string) ($row['source'] ?? 'elearning');

            $totalTrackedTime += $duration;
            if ($source === 'session') {
                $masterclassTrackedTime += $duration;
            } else {
                $elearningTrackedTime += $duration;
            }
            $learners[(int) $row['learnerId']] = true;
        }

        $averageProgress = 0.0;
        if ($learnerPathMeta !== []) {
            $averageProgress = array_sum(array_map(static fn (array $row): float => (float) $row['trainingProgressPercent'], $learnerPathMeta)) / count($learnerPathMeta);
        }

        return [
            'totalTrackedTime' => $totalTrackedTime,
            'elearningTrackedTime' => $elearningTrackedTime,
            'masterclassTrackedTime' => $masterclassTrackedTime,
            'activeLearnersCount' => count($learners),
            'learningPathsCount' => count($pathMeta),
            'activityCount' => count($activityRows),
            'averageProgress' => round($averageProgress, 2),
        ];
    }

    /**
     * @param array<string, int|float> $metrics
     * @param array<string, int|float> $previousMetrics
     *
     * @return array<string, array<string, int|float>>
     */
    private function buildComparison(array $metrics, array $previousMetrics): array
    {
        $compare = function (string $key) use ($metrics, $previousMetrics): array {
            $current = $metrics[$key] ?? 0;
            $previous = $previousMetrics[$key] ?? 0;
            $delta = $current - $previous;
            $percentDelta = $previous > 0 ? round(($delta / $previous) * 100, 2) : ($current > 0 ? 100.0 : 0.0);

            return [
                'current' => $current,
                'previous' => $previous,
                'delta' => $delta,
                'percentDelta' => $percentDelta,
            ];
        };

        return [
            'totalTrackedTime' => $compare('totalTrackedTime'),
            'elearningTrackedTime' => $compare('elearningTrackedTime'),
            'masterclassTrackedTime' => $compare('masterclassTrackedTime'),
            'activeLearnersCount' => $compare('activeLearnersCount'),
            'activityCount' => $compare('activityCount'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $activityRows
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildTimeSeries(array $activityRows, \DateTimeImmutable $start, \DateTimeImmutable $end, string $bucket): array
    {
        $buckets = [];
        foreach ($this->bucketDefinitions($start, $end, $bucket) as $bucketKey => $label) {
            $buckets[$bucketKey] = [
                'bucketKey' => $bucketKey,
                'label' => $label,
                'totalTime' => 0,
                'elearningTime' => 0,
                'masterclassTime' => 0,
                'activityCount' => 0,
                'activeLearners' => [],
            ];
        }

        foreach ($activityRows as $row) {
            $activityAt = $this->dateOrNull($row['activityAt'] ?? null);
            if (!$activityAt instanceof \DateTimeImmutable) {
                continue;
            }

            $bucketKey = $this->bucketKey($activityAt, $bucket);
            if (!isset($buckets[$bucketKey])) {
                continue;
            }

            $duration = $this->durationToMinutes($row);
            $source = (string) ($row['source'] ?? 'elearning');

            $buckets[$bucketKey]['totalTime'] += $duration;
            if ($source === 'session') {
                $buckets[$bucketKey]['masterclassTime'] += $duration;
            } else {
                $buckets[$bucketKey]['elearningTime'] += $duration;
            }
            $buckets[$bucketKey]['activityCount'] += 1;
            $buckets[$bucketKey]['activeLearners'][(int) $row['learnerId']] = true;
        }

        return array_map(static fn (array $item): array => [
            'bucketKey' => $item['bucketKey'],
            'label' => $item['label'],
            'totalTime' => $item['totalTime'],
            'elearningTime' => $item['elearningTime'],
            'masterclassTime' => $item['masterclassTime'],
            'activityCount' => $item['activityCount'],
            'activeLearnersCount' => count($item['activeLearners']),
        ], array_values($buckets));
    }

    /**
     * @param array<int, array<string, mixed>> $pathMeta
     * @param array<int, array<string, mixed>> $activityRows
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildTopLearningPaths(array $pathMeta, array $activityRows): array
    {
        $metaById = [];
        foreach ($pathMeta as $row) {
            $metaById[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'title' => $row['title'],
                'learnerCount' => (int) $row['learnerCount'],
                'averageProgress' => (float) $row['averageProgress'],
                'targetDuration' => (int) $row['targetDuration'],
                'totalTime' => 0,
                'elearningTime' => 0,
                'masterclassTime' => 0,
                'timeProgressPercent' => 0.0,
            ];
        }

        foreach ($activityRows as $row) {
            $id = (int) $row['learningPathId'];
            if (!isset($metaById[$id])) {
                continue;
            }

            $duration = $this->durationToMinutes($row);
            $source = (string) ($row['source'] ?? 'elearning');

            $metaById[$id]['totalTime'] += $duration;
            if ($source === 'session') {
                $metaById[$id]['masterclassTime'] += $duration;
            } else {
                $metaById[$id]['elearningTime'] += $duration;
            }
        }

        $items = array_values($metaById);
        foreach ($items as &$item) {
            $item['timeProgressPercent'] = $item['targetDuration'] > 0
                ? round(min(100, ($item['totalTime'] / $item['targetDuration']) * 100), 2)
                : 0.0;
        }
        unset($item);

        usort($items, static function (array $left, array $right): int {
            return [$right['totalTime'], $right['learnerCount']] <=> [$left['totalTime'], $left['learnerCount']];
        });

        return array_slice($items, 0, 6);
    }

    /**
     * @param array<int, array<string, mixed>> $learnerPathMeta
     * @param array<int, array<string, mixed>> $activityRows
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildLearnerPathRows(array $learnerPathMeta, array $activityRows): array
    {
        $items = [];
        foreach ($learnerPathMeta as $row) {
            $key = sprintf('%d-%d', (int) $row['learnerId'], (int) $row['learningPathId']);
            $items[$key] = [
                'learnerId' => (int) $row['learnerId'],
                'learningPathId' => (int) $row['learningPathId'],
                'learningPathTitle' => $row['learningPathTitle'],
                'email' => $row['email'],
                'firstName' => $row['firstName'],
                'lastName' => $row['lastName'],
                'fullName' => trim(sprintf('%s %s', (string) $row['firstName'], (string) $row['lastName'])) ?: ((string) $row['email']),
                'lastLoginAt' => $row['lastLoginAt'],
                'learningPathProgressPercent' => (float) $row['learningPathProgressPercent'],
                'trainingProgressPercent' => (float) $row['trainingProgressPercent'],
                'pathTargetDuration' => (int) $row['pathTargetDuration'],
                'totalTime' => 0,
                'elearningTime' => 0,
                'masterclassTime' => 0,
                'activityCount' => 0,
                'lastActivityAt' => null,
                'timeProgressPercent' => 0.0,
            ];
        }

        foreach ($activityRows as $row) {
            $key = sprintf('%d-%d', (int) $row['learnerId'], (int) $row['learningPathId']);
            if (!isset($items[$key])) {
                continue;
            }

            $duration = $this->durationToMinutes($row);
            $source = (string) ($row['source'] ?? 'elearning');

            $items[$key]['totalTime'] += $duration;
            if ($source === 'session') {
                $items[$key]['masterclassTime'] += $duration;
            } else {
                $items[$key]['elearningTime'] += $duration;
            }
            $items[$key]['activityCount'] += 1;

            $activityAt = (string) $row['activityAt'];
            if ($items[$key]['lastActivityAt'] === null || $activityAt > $items[$key]['lastActivityAt']) {
                $items[$key]['lastActivityAt'] = $activityAt;
            }
        }

        $items = array_values(array_filter($items, static fn (array $row): bool => $row['totalTime'] > 0 || $row['activityCount'] > 0));
        foreach ($items as &$item) {
            $item['timeProgressPercent'] = $item['pathTargetDuration'] > 0
                ? round(min(100, ($item['totalTime'] / $item['pathTargetDuration']) * 100), 2)
                : 0.0;
        }
        unset($item);

        usort($items, static function (array $left, array $right): int {
            return [$right['totalTime'], $right['activityCount']] <=> [$left['totalTime'], $left['activityCount']];
        });

        return array_slice($items, 0, 25);
    }

    /**
     * @param array<int, array<string, mixed>> $trainingMeta
     * @param array<int, array<string, mixed>> $activityRows
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildTrainingRows(array $trainingMeta, array $activityRows): array
    {
        $items = [];

        foreach ($trainingMeta as $row) {
            $id = (int) $row['id'];
            $items[$id] = [
                'trainingId' => $id,
                'title' => $row['title'],
                'learnerCount' => (int) $row['learnerCount'],
                'targetDuration' => (int) $row['targetDuration'],
                'averageProgress' => (float) $row['averageProgress'],
                'totalTime' => 0,
                'elearningTime' => 0,
                'masterclassTime' => 0,
                'timeProgressPercent' => 0.0,
            ];
        }

        foreach ($activityRows as $row) {
            $id = (int) $row['trainingId'];
            if (!isset($items[$id])) {
                continue;
            }

            $duration = $this->durationToMinutes($row);
            $source = (string) ($row['source'] ?? 'elearning');

            $items[$id]['totalTime'] += $duration;
            if ($source === 'session') {
                $items[$id]['masterclassTime'] += $duration;
            } else {
                $items[$id]['elearningTime'] += $duration;
            }
        }

        $items = array_values(array_filter($items, static fn (array $row): bool => $row['totalTime'] > 0 || $row['learnerCount'] > 0));

        foreach ($items as &$item) {
            $item['timeProgressPercent'] = $item['targetDuration'] > 0
                ? round(min(100, ($item['totalTime'] / $item['targetDuration']) * 100), 2)
                : 0.0;
        }
        unset($item);

        usort($items, static function (array $left, array $right): int {
            return [$right['totalTime'], $right['averageProgress']] <=> [$left['totalTime'], $left['averageProgress']];
        });

        return array_slice($items, 0, 50);
    }

    /**
     * @return array<string, string>
     */
    private function bucketDefinitions(\DateTimeImmutable $start, \DateTimeImmutable $end, string $bucket): array
    {
        $items = [];
        $cursor = $bucket === 'month'
            ? $start->modify('first day of this month')->setTime(0, 0)
            : $start->setTime($bucket === 'hour' ? (int) $start->format('H') : 0, 0);

        while ($cursor <= $end) {
            $key = $this->bucketKey($cursor, $bucket);
            $items[$key] = $bucket === 'month'
                ? $cursor->format('M Y')
                : ($bucket === 'hour' ? $cursor->format('H:i') : $cursor->format('d M'));

            $cursor = match ($bucket) {
                'hour' => $cursor->modify('+1 hour'),
                'month' => $cursor->modify('first day of next month'),
                default => $cursor->modify('+1 day'),
            };
        }

        return $items;
    }

    private function bucketKey(\DateTimeImmutable $date, string $bucket): string
    {
        return match ($bucket) {
            'hour' => $date->format('Y-m-d H:00'),
            'month' => $date->format('Y-m'),
            default => $date->format('Y-m-d'),
        };
    }

    private function resolveLastSyncAt(Connection $connection): ?string
    {
        $timestamps = [
            $connection->fetchOne('SELECT MAX(synced_at) FROM learners'),
            $connection->fetchOne('SELECT MAX(synced_at) FROM trainings'),
            $connection->fetchOne('SELECT MAX(synced_at) FROM training_registrations'),
            $connection->fetchOne('SELECT MAX(synced_at) FROM learner_step_states'),
            $connection->fetchOne('SELECT MAX(synced_at) FROM classroom_session_registrations'),
            $connection->fetchOne('SELECT MAX(synced_at) FROM learning_paths'),
            $connection->fetchOne('SELECT MAX(synced_at) FROM learning_path_registrations'),
        ];

        $timestamps = array_values(array_filter($timestamps, static fn ($value): bool => is_string($value) && $value !== ''));
        if ($timestamps === []) {
            return null;
        }

        rsort($timestamps);

        return $timestamps[0];
    }

    /**
     * @return array<int, array{id:int,fullName:string}>
     */
    private function fetchAvailableLearners(Connection $connection, ?int $learningPathId): array
    {
        $where = '';
        $params = [];
        $types = [];

        if ($learningPathId !== null) {
            $where = 'WHERE lpr.learning_path_id = :learningPathId';
            $params['learningPathId'] = $learningPathId;
            $types['learningPathId'] = ParameterType::INTEGER;
        }

        $rows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT DISTINCT
                    l.id,
                    l.email,
                    l.first_name AS firstName,
                    l.last_name AS lastName
                FROM learners l
                INNER JOIN learning_path_registrations lpr ON lpr.learner_id = l.id
                {$where}
                ORDER BY l.last_name ASC, l.first_name ASC, l.email ASC
            SQL,
            $params,
            $types,
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'fullName' => trim(sprintf('%s %s', (string) $row['firstName'], (string) $row['lastName'])) ?: ((string) $row['email']),
        ], $rows);
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function dateOrNull(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return new \DateTimeImmutable($value);
    }

    /**
     * Analytics aggregates mix second-based e-learning time with minute-based session durations.
     * Normalize them before building API payloads so every `*Time` field is returned in minutes.
     */
    private function durationToMinutes(array $row): int
    {
        $source = (string) ($row['source'] ?? 'elearning');
        $duration = $row['duration'] ?? 0;

        return $source === 'session'
            ? DurationUnit::minutesToInt($duration)
            : DurationUnit::secondsToMinutesInt($duration);
    }
}
