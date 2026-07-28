<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\TimeMetricsService;
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
        private readonly TimeMetricsService $timeMetricsService,
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
            'groups' => $this->fetchGroups($connection),
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
    private function fetchGroups(Connection $connection): array
    {
        // Récupérer les temps totaux via le service centralisé
        $totalTimesByGroup = $this->timeMetricsService->getTotalTimeForGroups();

        $rows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    rg.id,
                    rg.external_id AS externalId,
                    rg.name,
                    rg.reference,
                    COUNT(DISTINCT rlg.learner_id) AS memberCount,
                    COUNT(DISTINCT rglp.learning_path_external_id) AS learningPathCount,
                    ROUND(COALESCE(AVG(lpr.progress), 0), 2) AS averageProgress,
                    (
                        SELECT lp_img.image_url
                        FROM riseup_group_learning_paths rglp_img
                        INNER JOIN learning_paths lp_img ON lp_img.external_id = rglp_img.learning_path_external_id
                        WHERE rglp_img.group_id = rg.id AND lp_img.image_url IS NOT NULL
                        LIMIT 1
                    ) AS imageUrl
                FROM riseup_groups rg
                LEFT JOIN riseup_learner_groups rlg ON rlg.group_id = rg.id
                LEFT JOIN riseup_group_learning_paths rglp ON rglp.group_id = rg.id
                LEFT JOIN learning_paths lp ON lp.external_id = rglp.learning_path_external_id
                LEFT JOIN learning_path_registrations lpr ON lpr.learning_path_id = lp.id AND lpr.learner_id = rlg.learner_id
                WHERE rg.hidden = 0
                GROUP BY rg.id, rg.external_id, rg.name, rg.reference
                ORDER BY memberCount DESC, rg.name ASC
            SQL
        );

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'externalId' => (int) $row['externalId'],
            'name' => $row['name'],
            'reference' => $row['reference'],
            'imageUrl' => $row['imageUrl'],
            'memberCount' => (int) $row['memberCount'],
            'learningPathCount' => (int) $row['learningPathCount'],
            'averageProgress' => (float) $row['averageProgress'],
            'totalTime' => DurationUnit::secondsToMinutesInt($totalTimesByGroup[(int) $row['id']] ?? 0),
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
