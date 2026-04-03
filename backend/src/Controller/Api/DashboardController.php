<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\UserPermissionResolver;
use App\Util\DurationUnit;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/dashboard')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPermissionResolver $permissionResolver,
    ) {
    }

    #[Route('', name: 'api_dashboard_show', methods: ['GET'])]
    public function show(): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'dashboard.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $connection = $this->entityManager->getConnection();

        return $this->json([
            'metrics' => $this->buildMetrics($connection),
            'learningPaths' => $this->fetchLearningPaths($connection),
            'topTrainings' => $this->fetchTopTrainings($connection),
            'recentLearners' => $this->fetchRecentLearners($connection),
            'lastSyncAt' => $this->resolveLastSyncAt($connection),
        ]);
    }

    /**
     * @return array<string, int|float>
     */
    private function buildMetrics(Connection $connection): array
    {
        $currentYear = (int) date('Y');
        $yearStart = sprintf('%d-01-01 00:00:00', $currentYear);
        $yearEnd = sprintf('%d-12-31 23:59:59', $currentYear);

        $totalYearTime = $connection->fetchOne(
            'SELECT COALESCE(SUM(total_time), 0) FROM training_registrations WHERE subscribed_at >= ? AND subscribed_at <= ?',
            [$yearStart, $yearEnd]
        );

        return [
            'learnersCount' => (int) $connection->fetchOne('SELECT COUNT(*) FROM learners'),
            'activeLearnersCount' => (int) $connection->fetchOne("SELECT COUNT(*) FROM learners WHERE state = 'active'"),
            'learningPathsCount' => (int) $connection->fetchOne('SELECT COUNT(*) FROM learning_paths'),
            'trainingsCount' => (int) $connection->fetchOne('SELECT COUNT(*) FROM trainings'),
            'trainingRegistrationsCount' => (int) $connection->fetchOne('SELECT COUNT(*) FROM training_registrations'),
            'sessionsCount' => (int) $connection->fetchOne('SELECT COUNT(*) FROM classroom_sessions'),
            'masterclassCount' => (int) $connection->fetchOne("SELECT COUNT(*) FROM classroom_sessions WHERE session_type = 'masterclass'"),
            'sessionRegistrationsCount' => (int) $connection->fetchOne('SELECT COUNT(*) FROM classroom_session_registrations'),
            'stepStatesCount' => (int) $connection->fetchOne('SELECT COUNT(*) FROM learner_step_states'),
            'signedAttendancesCount' => (int) $connection->fetchOne('SELECT COUNT(*) FROM classroom_session_signatures WHERE has_signed = 1'),
            'totalTrackedTime' => DurationUnit::secondsToMinutesInt($connection->fetchOne('SELECT COALESCE(SUM(total_time), 0) FROM training_registrations')),
            'totalYearTime' => DurationUnit::secondsToMinutesInt($totalYearTime),
            'averageProgress' => round((float) ($connection->fetchOne('SELECT COALESCE(AVG(progress), 0) FROM training_registrations') ?: 0), 2),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchTopTrainings(Connection $connection): array
    {
        $rows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    t.id,
                    t.external_id AS externalId,
                    t.title,
                    t.state,
                    COUNT(tr.id) AS learnersCount,
                    COALESCE(SUM(tr.total_time), 0) AS totalTime,
                    ROUND(COALESCE(AVG(tr.progress), 0), 2) AS averageProgress
                FROM trainings t
                LEFT JOIN training_registrations tr ON tr.training_id = t.id
                GROUP BY t.id, t.external_id, t.title, t.state
                ORDER BY learnersCount DESC, totalTime DESC, t.title ASC
                LIMIT 5
            SQL
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'externalId' => (int) $row['externalId'],
            'title' => $row['title'],
            'state' => $row['state'],
            'learnersCount' => (int) $row['learnersCount'],
            'totalTime' => DurationUnit::secondsToMinutesInt($row['totalTime']),
            'averageProgress' => (float) $row['averageProgress'],
        ], $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchRecentLearners(Connection $connection): array
    {
        $rows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    l.id,
                    l.external_id AS externalId,
                    l.email,
                    l.first_name AS firstName,
                    l.last_name AS lastName,
                    l.state,
                    l.last_login_at AS lastLoginAt,
                    COALESCE(trs.training_count, 0) AS trainingCount,
                    COALESCE(trs.total_time, 0) AS totalTime
                FROM learners l
                LEFT JOIN (
                    SELECT learner_id, COUNT(*) AS training_count, COALESCE(SUM(total_time), 0) AS total_time
                    FROM training_registrations
                    GROUP BY learner_id
                ) trs ON trs.learner_id = l.id
                ORDER BY l.last_login_at DESC, l.id DESC
                LIMIT 5
            SQL
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'externalId' => (int) $row['externalId'],
            'email' => $row['email'],
            'firstName' => $row['firstName'],
            'lastName' => $row['lastName'],
            'fullName' => trim(sprintf('%s %s', (string) $row['firstName'], (string) $row['lastName'])),
            'state' => $row['state'],
            'lastLoginAt' => $row['lastLoginAt'],
            'trainingCount' => (int) $row['trainingCount'],
            'totalTime' => DurationUnit::secondsToMinutesInt($row['totalTime']),
        ], $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchLearningPaths(Connection $connection): array
    {
        $rows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    lp.id,
                    lp.external_id AS externalId,
                    lp.title,
                    lp.image_url AS imageUrl,
                    lp.description,
                    COUNT(DISTINCT lpr.learner_id) AS learnerCount,
                    ROUND(COALESCE(AVG(lpr.progress), 0), 2) AS averageProgress,
                    COALESCE(time_data.totalTime, 0) AS totalTime,
                    COUNT(DISTINCT lpt.training_id) AS trainingCount,
                    COALESCE(session_count.masterclassCount, 0) AS masterclassCount
                FROM learning_paths lp
                LEFT JOIN learning_path_registrations lpr ON lpr.learning_path_id = lp.id
                LEFT JOIN learning_path_trainings lpt ON lpt.learning_path_id = lp.id
                LEFT JOIN (
                    SELECT
                        lpr2.learning_path_id,
                        SUM(COALESCE(module_logs.module_time, 0)) + SUM(COALESCE(session_logs.masterclass_time, 0) * 60) AS totalTime
                    FROM learning_path_registrations lpr2
                    LEFT JOIN (
                        SELECT
                            lpt2.learning_path_id,
                            l2.id AS learner_id,
                            COALESCE(SUM(ral.duration_seconds), 0) AS module_time
                        FROM riseup_activity_logs ral
                        INNER JOIN trainings t2 ON t2.external_id = ral.training_external_id
                        INNER JOIN learning_path_trainings lpt2 ON lpt2.training_id = t2.id
                        INNER JOIN learners l2 ON l2.external_id = ral.learner_external_id
                        GROUP BY lpt2.learning_path_id, l2.id
                    ) module_logs ON module_logs.learning_path_id = lpr2.learning_path_id AND module_logs.learner_id = lpr2.learner_id
                    LEFT JOIN (
                        SELECT
                            lpt2.learning_path_id,
                            csr.learner_id,
                            COALESCE(SUM(CASE WHEN css.has_signed = 1 THEN cs2.edu_duration ELSE 0 END), 0) AS masterclass_time
                        FROM classroom_session_registrations csr
                        INNER JOIN classroom_sessions cs2 ON cs2.id = csr.session_id
                        INNER JOIN learning_path_trainings lpt2 ON lpt2.training_id = cs2.training_id
                        LEFT JOIN classroom_session_signatures css ON css.registration_id = csr.id
                        GROUP BY lpt2.learning_path_id, csr.learner_id
                    ) session_logs ON session_logs.learning_path_id = lpr2.learning_path_id AND session_logs.learner_id = lpr2.learner_id
                    GROUP BY lpr2.learning_path_id
                ) time_data ON time_data.learning_path_id = lp.id
                LEFT JOIN (
                    SELECT
                        lpt3.learning_path_id,
                        COUNT(DISTINCT cs3.id) AS masterclassCount
                    FROM learning_path_trainings lpt3
                    INNER JOIN classroom_sessions cs3 ON cs3.training_id = lpt3.training_id
                    GROUP BY lpt3.learning_path_id
                ) session_count ON session_count.learning_path_id = lp.id
                GROUP BY lp.id, lp.external_id, lp.title, lp.image_url, lp.description, time_data.totalTime, session_count.masterclassCount
                ORDER BY learnerCount DESC, lp.title ASC
            SQL
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'externalId' => (int) $row['externalId'],
            'title' => $row['title'],
            'imageUrl' => $row['imageUrl'],
            'description' => $row['description'],
            'learnerCount' => (int) $row['learnerCount'],
            'averageProgress' => (float) $row['averageProgress'],
            'totalTime' => DurationUnit::secondsToMinutesInt($row['totalTime']),
            'trainingCount' => (int) $row['trainingCount'],
            'masterclassCount' => (int) $row['masterclassCount'],
        ], $rows);
    }

    private function resolveLastSyncAt(Connection $connection): ?string
    {
        $timestamps = [
            $connection->fetchOne('SELECT MAX(synced_at) FROM learners'),
            $connection->fetchOne('SELECT MAX(synced_at) FROM trainings'),
            $connection->fetchOne('SELECT MAX(synced_at) FROM training_registrations'),
            $connection->fetchOne('SELECT MAX(synced_at) FROM training_modules'),
            $connection->fetchOne('SELECT MAX(synced_at) FROM training_steps'),
            $connection->fetchOne('SELECT MAX(synced_at) FROM learner_step_states'),
            $connection->fetchOne('SELECT MAX(synced_at) FROM classroom_sessions'),
            $connection->fetchOne('SELECT MAX(synced_at) FROM classroom_session_registrations'),
            $connection->fetchOne('SELECT MAX(synced_at) FROM classroom_session_signatures'),
        ];

        $timestamps = array_values(array_filter($timestamps, static fn ($value): bool => is_string($value) && $value !== ''));

        if ($timestamps === []) {
            return null;
        }

        rsort($timestamps);

        return $timestamps[0];
    }
}
