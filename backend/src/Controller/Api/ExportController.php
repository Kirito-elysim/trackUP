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
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/exports')]
class ExportController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPermissionResolver $permissionResolver,
    ) {
    }

    #[Route('', name: 'api_exports_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'exports.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $connection = $this->entityManager->getConnection();
        $learnerId = $this->positiveIntOrNull($request->query->get('learnerId'));

        return $this->json([
            'filters' => [
                'learnerId' => $learnerId,
                'availableLearners' => $this->fetchAvailableLearners($connection),
            ],
            'metrics' => $this->buildMetrics($connection),
            'selectedLearner' => $learnerId !== null ? $this->buildSelectedLearner($connection, $learnerId) : null,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function buildMetrics(Connection $connection): array
    {
        return [
            'learnersReadyCount' => (int) $connection->fetchOne(
                'SELECT COUNT(DISTINCT learner_id) FROM training_registrations WHERE total_time > 0'
            ),
            'learningPathsCount' => (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM learning_paths'
            ),
            'signedRegistrationsCount' => (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM classroom_session_signatures WHERE has_signed = 1'
            ),
            'sessionsWithoutSignatureCount' => (int) $connection->fetchOne(
                <<<SQL
                    SELECT COUNT(*)
                    FROM classroom_session_registrations csr
                    LEFT JOIN classroom_session_signatures css ON css.registration_id = csr.id AND css.has_signed = 1
                    WHERE csr.attended = 1 AND css.id IS NULL
                SQL
            ),
            'trackedTimeTotal' => DurationUnit::secondsToMinutesInt($connection->fetchOne(
                'SELECT COALESCE(SUM(total_time), 0) FROM training_registrations'
            )),
        ];
    }

    /**
     * @return array<int, array{id:int,fullName:string,email:string}>
     */
    private function fetchAvailableLearners(Connection $connection): array
    {
        $rows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    l.id,
                    l.email,
                    l.first_name AS firstName,
                    l.last_name AS lastName,
                    COALESCE(tr.total_time, 0) AS totalTime
                FROM learners l
                LEFT JOIN (
                    SELECT learner_id, COALESCE(SUM(total_time), 0) AS total_time
                    FROM training_registrations
                    GROUP BY learner_id
                ) tr ON tr.learner_id = l.id
                ORDER BY totalTime DESC, l.last_name ASC, l.first_name ASC, l.email ASC
            SQL
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'fullName' => trim(sprintf('%s %s', (string) $row['firstName'], (string) $row['lastName'])) ?: (string) $row['email'],
        ], $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildSelectedLearner(Connection $connection, int $learnerId): ?array
    {
        $learner = $connection->fetchAssociative(
            <<<SQL
                SELECT
                    l.id,
                    l.email,
                    l.first_name AS firstName,
                    l.last_name AS lastName,
                    l.state,
                    l.last_login_at AS lastLoginAt,
                    lss.last_activity_at AS lastActivityAt,
                    COALESCE(tr.training_count, 0) AS trainingCount,
                    COALESCE(lp.learning_path_count, 0) AS learningPathCount,
                    COALESCE(tr.total_time, 0) AS platformTime,
                    COALESCE(module_agg.module_time, 0) AS moduleTime,
                    COALESCE(sess.masterclass_time, 0) AS masterclassTime,
                    COALESCE(sig.signed_attendance_count, 0) AS signedAttendanceCount,
                    COALESCE(unsigned_alerts.unsigned_attendance_count, 0) AS unsignedAttendanceCount
                FROM learners l
                LEFT JOIN (
                    SELECT learner_id, COUNT(*) AS training_count, COALESCE(SUM(total_time), 0) AS total_time
                    FROM training_registrations
                    GROUP BY learner_id
                ) tr ON tr.learner_id = l.id
                LEFT JOIN (
                    SELECT learner_id, COUNT(*) AS learning_path_count
                    FROM learning_path_registrations
                    GROUP BY learner_id
                ) lp ON lp.learner_id = l.id
                LEFT JOIN (
                    SELECT learner_id, COALESCE(SUM(COALESCE(NULLIF(time_spent, 0), total_time, 0)), 0) AS module_time
                    FROM learner_step_states
                    GROUP BY learner_id
                ) module_agg ON module_agg.learner_id = l.id
                LEFT JOIN (
                    SELECT csr.learner_id, COALESCE(SUM(COALESCE(NULLIF(csr.edu_duration, 0), cs.edu_duration, 0)), 0) AS masterclass_time
                    FROM classroom_session_registrations csr
                    INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
                    GROUP BY csr.learner_id
                ) sess ON sess.learner_id = l.id
                LEFT JOIN (
                    SELECT csr.learner_id, COUNT(*) AS signed_attendance_count
                    FROM classroom_session_signatures css
                    INNER JOIN classroom_session_registrations csr ON csr.id = css.registration_id
                    WHERE css.has_signed = 1
                    GROUP BY csr.learner_id
                ) sig ON sig.learner_id = l.id
                LEFT JOIN (
                    SELECT csr.learner_id, COUNT(*) AS unsigned_attendance_count
                    FROM classroom_session_registrations csr
                    LEFT JOIN classroom_session_signatures css ON css.registration_id = csr.id AND css.has_signed = 1
                    WHERE csr.attended = 1 AND css.id IS NULL
                    GROUP BY csr.learner_id
                ) unsigned_alerts ON unsigned_alerts.learner_id = l.id
                LEFT JOIN (
                    SELECT learner_id, MAX(activity_at) AS last_activity_at
                    FROM learner_step_states
                    GROUP BY learner_id
                ) lss ON lss.learner_id = l.id
                WHERE l.id = :learnerId
            SQL,
            ['learnerId' => $learnerId],
            ['learnerId' => ParameterType::INTEGER],
        );

        if (!is_array($learner)) {
            return null;
        }

        $learningPaths = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    lp.id AS learningPathId,
                    lp.title,
                    lp.reference,
                    lpr.subscribed_at AS subscribedAt,
                    ROUND(COALESCE(lpr.progress, 0), 2) AS progress,
                    lpr.score,
                    COUNT(DISTINCT lpt.training_id) AS trainingCount,
                    COALESCE(SUM(tr.total_time), 0) AS platformTime,
                    COALESCE(module_logs.module_time, 0) AS moduleTime,
                    COALESCE(session_logs.masterclass_time, 0) AS masterclassTime
                FROM learning_path_registrations lpr
                INNER JOIN learning_paths lp ON lp.id = lpr.learning_path_id
                LEFT JOIN learning_path_trainings lpt ON lpt.learning_path_id = lpr.learning_path_id
                LEFT JOIN training_registrations tr ON tr.learner_id = lpr.learner_id AND tr.training_id = lpt.training_id
                LEFT JOIN (
                    SELECT
                        lpt2.learning_path_id,
                        lss.learner_id,
                        COALESCE(SUM(COALESCE(NULLIF(lss.time_spent, 0), lss.total_time, 0)), 0) AS module_time
                    FROM learner_step_states lss
                    INNER JOIN training_steps ts ON ts.id = lss.step_id
                    INNER JOIN training_modules tm ON tm.id = ts.module_id
                    INNER JOIN learning_path_trainings lpt2 ON lpt2.training_id = tm.training_id
                    GROUP BY lpt2.learning_path_id, lss.learner_id
                ) module_logs ON module_logs.learning_path_id = lpr.learning_path_id AND module_logs.learner_id = lpr.learner_id
                LEFT JOIN (
                    SELECT
                        lpt2.learning_path_id,
                        csr.learner_id,
                        COALESCE(SUM(COALESCE(NULLIF(csr.edu_duration, 0), cs.edu_duration, 0)), 0) AS masterclass_time
                    FROM classroom_session_registrations csr
                    INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
                    INNER JOIN learning_path_trainings lpt2 ON lpt2.training_id = cs.training_id
                    GROUP BY lpt2.learning_path_id, csr.learner_id
                ) session_logs ON session_logs.learning_path_id = lpr.learning_path_id AND session_logs.learner_id = lpr.learner_id
                WHERE lpr.learner_id = :learnerId
                GROUP BY lp.id, lp.title, lp.reference, lpr.subscribed_at, lpr.progress, lpr.score, module_logs.module_time, session_logs.masterclass_time
                ORDER BY lpr.subscribed_at DESC, lp.title ASC
            SQL,
            ['learnerId' => $learnerId],
            ['learnerId' => ParameterType::INTEGER],
        );

        $trainings = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    t.id AS trainingId,
                    t.title,
                    t.state,
                    tr.subscribed_at AS subscribedAt,
                    tr.total_time AS platformTime,
                    ROUND(COALESCE(tr.progress, step_progress.progress_percent, 0), 2) AS progress,
                    tr.score,
                    COALESCE(path_titles.learningPathTitles, '') AS learningPathTitles,
                    COALESCE(module_logs.module_time, 0) AS moduleTime,
                    COALESCE(session_logs.masterclass_time, 0) AS masterclassTime
                FROM trainings t
                LEFT JOIN training_registrations tr ON tr.training_id = t.id AND tr.learner_id = :learnerId
                LEFT JOIN (
                    SELECT
                        tm.training_id,
                        lss.learner_id,
                        COALESCE(SUM(COALESCE(NULLIF(lss.time_spent, 0), lss.total_time, 0)), 0) AS module_time
                    FROM learner_step_states lss
                    INNER JOIN training_steps ts ON ts.id = lss.step_id
                    INNER JOIN training_modules tm ON tm.id = ts.module_id
                    GROUP BY tm.training_id, lss.learner_id
                ) module_logs ON module_logs.training_id = t.id AND module_logs.learner_id = :learnerId
                LEFT JOIN (
                    SELECT
                        cs.training_id,
                        csr.learner_id,
                        COALESCE(SUM(COALESCE(NULLIF(csr.edu_duration, 0), cs.edu_duration, 0)), 0) AS masterclass_time
                    FROM classroom_session_registrations csr
                    INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
                    GROUP BY cs.training_id, csr.learner_id
                ) session_logs ON session_logs.training_id = t.id AND session_logs.learner_id = :learnerId
                LEFT JOIN (
                    SELECT
                        tm.training_id,
                        lss.learner_id,
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
                    GROUP BY tm.training_id, lss.learner_id, training_steps.step_count
                ) step_progress ON step_progress.training_id = t.id AND step_progress.learner_id = :learnerId
                LEFT JOIN (
                    SELECT
                        lpt.training_id,
                        GROUP_CONCAT(DISTINCT lp.title ORDER BY lp.title SEPARATOR ' | ') AS learningPathTitles
                    FROM learning_path_trainings lpt
                    INNER JOIN learning_paths lp ON lp.id = lpt.learning_path_id
                    GROUP BY lpt.training_id
                ) path_titles ON path_titles.training_id = t.id
                WHERE tr.id IS NOT NULL OR module_logs.module_time IS NOT NULL OR session_logs.masterclass_time IS NOT NULL
                ORDER BY COALESCE(tr.subscribed_at, '1970-01-01 00:00:00') DESC, t.title ASC
            SQL,
            ['learnerId' => $learnerId],
            ['learnerId' => ParameterType::INTEGER],
        );

        $logs = $this->fetchLearnerLogs($connection, $learnerId);

        return [
            'learner' => [
                'id' => (int) $learner['id'],
                'email' => $learner['email'],
                'firstName' => $learner['firstName'],
                'lastName' => $learner['lastName'],
                'fullName' => trim(sprintf('%s %s', (string) $learner['firstName'], (string) $learner['lastName'])) ?: (string) $learner['email'],
                'state' => $learner['state'],
                'lastLoginAt' => $learner['lastLoginAt'],
                'lastActivityAt' => $learner['lastActivityAt'],
                'trainingCount' => (int) $learner['trainingCount'],
                'learningPathCount' => (int) $learner['learningPathCount'],
                'platformTime' => DurationUnit::secondsToMinutesInt($learner['platformTime']),
                'moduleTime' => DurationUnit::secondsToMinutesInt($learner['moduleTime']),
                'masterclassTime' => DurationUnit::minutesToInt($learner['masterclassTime']),
                'signedAttendanceCount' => (int) $learner['signedAttendanceCount'],
                'unsignedAttendanceCount' => (int) $learner['unsignedAttendanceCount'],
            ],
            'learningPaths' => array_map(static fn (array $row): array => [
                'learningPathId' => (int) $row['learningPathId'],
                'title' => $row['title'],
                'reference' => $row['reference'],
                'subscribedAt' => $row['subscribedAt'],
                'progress' => $row['progress'] !== null ? (float) $row['progress'] : null,
                'score' => $row['score'] !== null ? (float) $row['score'] : null,
                'trainingCount' => (int) $row['trainingCount'],
                'platformTime' => DurationUnit::secondsToMinutesInt($row['platformTime']),
                'moduleTime' => DurationUnit::secondsToMinutesInt($row['moduleTime']),
                'masterclassTime' => DurationUnit::minutesToInt($row['masterclassTime']),
            ], $learningPaths),
            'trainings' => array_map(static fn (array $row): array => [
                'trainingId' => (int) $row['trainingId'],
                'title' => $row['title'],
                'state' => $row['state'],
                'subscribedAt' => $row['subscribedAt'],
                'platformTime' => DurationUnit::secondsToMinutesInt($row['platformTime'] ?? 0),
                'progress' => $row['progress'] !== null ? (float) $row['progress'] : null,
                'score' => $row['score'] !== null ? (float) $row['score'] : null,
                'learningPathTitles' => $row['learningPathTitles'] !== '' ? explode(' | ', (string) $row['learningPathTitles']) : [],
                'moduleTime' => DurationUnit::secondsToMinutesInt($row['moduleTime']),
                'masterclassTime' => DurationUnit::minutesToInt($row['masterclassTime']),
            ], $trainings),
            'logs' => array_map(function (array $row): array {
                $sourceType = (string) ($row['sourceType'] ?? '');

                return [
                    'occurredAt' => $row['occurredAt'],
                    'sourceType' => $sourceType,
                    'sourceLabel' => $row['sourceLabel'],
                    'learningPathTitle' => $row['learningPathTitle'],
                    'trainingTitle' => $row['trainingTitle'],
                    'moduleTitle' => $row['moduleTitle'],
                    'stepTitle' => $row['stepTitle'],
                    'sessionType' => $row['sessionType'],
                    'duration' => in_array($sourceType, ['masterclass', 'pathway'], true)
                        ? DurationUnit::minutesToInt($row['duration'])
                        : DurationUnit::secondsToMinutesInt($row['duration']),
                    'status' => $row['status'],
                    'signed' => $row['signed'] !== null ? (bool) $row['signed'] : null,
                    'details' => $row['details'],
                ];
            }, $logs),
        ];
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchLearnerLogs(Connection $connection, int $learnerId): array
    {
        $params = ['learnerId' => $learnerId];
        $types = ['learnerId' => ParameterType::INTEGER];

        $pathwayLogs = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    lpr.subscribed_at AS occurredAt,
                    'pathway' AS sourceType,
                    lp.title AS sourceLabel,
                    lp.title AS learningPathTitle,
                    NULL AS trainingTitle,
                    NULL AS moduleTitle,
                    NULL AS stepTitle,
                    NULL AS sessionType,
                    0 AS duration,
                    'Inscription parcours' AS status,
                    NULL AS signed,
                    COALESCE(lpr.reference, '') AS details
                FROM learning_path_registrations lpr
                INNER JOIN learning_paths lp ON lp.id = lpr.learning_path_id
                WHERE lpr.learner_id = :learnerId
            SQL,
            $params,
            $types,
        );

        $platformLogs = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    tr.subscribed_at AS occurredAt,
                    'platform' AS sourceType,
                    t.title AS sourceLabel,
                    path_titles.learningPathTitle AS learningPathTitle,
                    t.title AS trainingTitle,
                    NULL AS moduleTitle,
                    NULL AS stepTitle,
                    NULL AS sessionType,
                    COALESCE(tr.total_time, 0) AS duration,
                    CONCAT('Inscription formation · progression ', ROUND(COALESCE(tr.progress, 0), 0), ' %') AS status,
                    NULL AS signed,
                    COALESCE(tr.state, '') AS details
                FROM training_registrations tr
                INNER JOIN trainings t ON t.id = tr.training_id
                LEFT JOIN (
                    SELECT lpt.training_id, MIN(lp.title) AS learningPathTitle
                    FROM learning_path_trainings lpt
                    INNER JOIN learning_paths lp ON lp.id = lpt.learning_path_id
                    GROUP BY lpt.training_id
                ) path_titles ON path_titles.training_id = t.id
                WHERE tr.learner_id = :learnerId
            SQL,
            $params,
            $types,
        );

        $moduleLogs = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    lss.activity_at AS occurredAt,
                    'module' AS sourceType,
                    COALESCE(ts.title, tm.title, t.title) AS sourceLabel,
                    lp.title AS learningPathTitle,
                    t.title AS trainingTitle,
                    tm.title AS moduleTitle,
                    ts.title AS stepTitle,
                    ts.type AS sessionType,
                    COALESCE(NULLIF(lss.time_spent, 0), lss.total_time, 0) AS duration,
                    COALESCE(lss.state, 'activity') AS status,
                    NULL AS signed,
                    CONCAT('Score ', COALESCE(CAST(ROUND(lss.score, 0) AS CHAR), 'n/a')) AS details
                FROM learner_step_states lss
                INNER JOIN training_steps ts ON ts.id = lss.step_id
                INNER JOIN training_modules tm ON tm.id = ts.module_id
                INNER JOIN trainings t ON t.id = tm.training_id
                LEFT JOIN learning_path_trainings lpt ON lpt.training_id = t.id
                LEFT JOIN learning_paths lp ON lp.id = lpt.learning_path_id
                WHERE lss.learner_id = :learnerId
            SQL,
            $params,
            $types,
        );

        $masterclassLogs = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    COALESCE(cs.start_at, csr.subscribed_at, csr.rise_up_created_at) AS occurredAt,
                    'masterclass' AS sourceType,
                    COALESCE(cs.session_type, 'Masterclass') AS sourceLabel,
                    lp.title AS learningPathTitle,
                    t.title AS trainingTitle,
                    NULL AS moduleTitle,
                    NULL AS stepTitle,
                    cs.session_type AS sessionType,
                    COALESCE(NULLIF(csr.edu_duration, 0), cs.edu_duration, 0) AS duration,
                    CONCAT('Présence ', CASE
                        WHEN csr.attended = 1 THEN 'confirmée'
                        WHEN csr.attended = 0 THEN 'non confirmée'
                        ELSE 'non renseignée'
                    END) AS status,
                    CASE WHEN css.has_signed = 1 THEN 1 ELSE 0 END AS signed,
                    COALESCE(cs.meeting_url, '') AS details
                FROM classroom_session_registrations csr
                INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
                LEFT JOIN trainings t ON t.id = cs.training_id
                LEFT JOIN learning_path_trainings lpt ON lpt.training_id = cs.training_id
                LEFT JOIN learning_paths lp ON lp.id = lpt.learning_path_id
                LEFT JOIN classroom_session_signatures css ON css.registration_id = csr.id AND css.has_signed = 1
                WHERE csr.learner_id = :learnerId
            SQL,
            $params,
            $types,
        );

        $logs = array_merge($pathwayLogs, $platformLogs, $moduleLogs, $masterclassLogs);
        $logs = array_values(array_filter($logs, static fn (array $row): bool => isset($row['occurredAt']) && $row['occurredAt'] !== null && $row['occurredAt'] !== ''));

        usort($logs, static function (array $left, array $right): int {
            return [$right['occurredAt'], $left['sourceType']] <=> [$left['occurredAt'], $right['sourceType']];
        });

        return $logs;
    }
}
