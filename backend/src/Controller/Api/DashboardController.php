<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\UserPermissionResolver;
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
        return [
            'learnersCount' => (int) $connection->fetchOne('SELECT COUNT(*) FROM learners'),
            'activeLearnersCount' => (int) $connection->fetchOne("SELECT COUNT(*) FROM learners WHERE state = 'active'"),
            'trainingsCount' => (int) $connection->fetchOne('SELECT COUNT(*) FROM trainings'),
            'trainingRegistrationsCount' => (int) $connection->fetchOne('SELECT COUNT(*) FROM training_registrations'),
            'sessionsCount' => (int) $connection->fetchOne('SELECT COUNT(*) FROM classroom_sessions'),
            'sessionRegistrationsCount' => (int) $connection->fetchOne('SELECT COUNT(*) FROM classroom_session_registrations'),
            'stepStatesCount' => (int) $connection->fetchOne('SELECT COUNT(*) FROM learner_step_states'),
            'signedAttendancesCount' => (int) $connection->fetchOne('SELECT COUNT(*) FROM classroom_session_signatures WHERE has_signed = 1'),
            'totalTrackedTime' => (int) ($connection->fetchOne('SELECT COALESCE(SUM(total_time), 0) FROM training_registrations') ?: 0),
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
            'totalTime' => (int) $row['totalTime'],
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
            'totalTime' => (int) $row['totalTime'],
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
