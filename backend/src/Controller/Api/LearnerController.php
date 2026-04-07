<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\TimeMetricsService;
use App\Service\UserPermissionResolver;
use App\Util\DurationUnit;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/learners')]
class LearnerController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPermissionResolver $permissionResolver,
        private readonly TimeMetricsService $timeMetricsService,
    ) {
    }

    #[Route('', name: 'api_learners_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'learners.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $connection = $this->entityManager->getConnection();
        $limit = min(max((int) $request->query->get('limit', 50), 1), 200);
        $search = trim((string) $request->query->get('q', ''));
        $state = trim((string) $request->query->get('state', ''));

        $conditions = [];
        $params = ['limit' => $limit];
        $types = ['limit' => ParameterType::INTEGER];

        if ($search !== '') {
            $conditions[] = '(l.email LIKE :search OR l.first_name LIKE :search OR l.last_name LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        if ($state !== '') {
            $conditions[] = 'l.state = :state';
            $params['state'] = $state;
        }

        $whereSql = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

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
                    l.synced_at AS syncedAt,
                    COALESCE(trs.training_count, 0) AS trainingCount,
                    COALESCE(trs.total_time, 0) AS totalTime,
                    ROUND(COALESCE(trs.average_progress, 0), 2) AS averageProgress,
                    COALESCE(csrs.session_registration_count, 0) AS sessionRegistrationCount,
                    lss.last_activity_at AS lastActivityAt
                FROM learners l
                LEFT JOIN (
                    SELECT
                        learner_id,
                        COUNT(*) AS training_count,
                        COALESCE(SUM(total_time), 0) AS total_time,
                        COALESCE(AVG(progress), 0) AS average_progress
                    FROM training_registrations
                    GROUP BY learner_id
                ) trs ON trs.learner_id = l.id
                LEFT JOIN (
                    SELECT learner_id, COUNT(*) AS session_registration_count
                    FROM classroom_session_registrations
                    GROUP BY learner_id
                ) csrs ON csrs.learner_id = l.id
                LEFT JOIN (
                    SELECT learner_id, MAX(activity_at) AS last_activity_at
                    FROM learner_step_states
                    GROUP BY learner_id
                ) lss ON lss.learner_id = l.id
                {$whereSql}
                ORDER BY COALESCE(lss.last_activity_at, l.last_login_at) DESC, l.id DESC
                LIMIT :limit
            SQL,
            $params,
            $types
        );

        return $this->json(array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'externalId' => (int) $row['externalId'],
            'email' => $row['email'],
            'firstName' => $row['firstName'],
            'lastName' => $row['lastName'],
            'fullName' => trim(sprintf('%s %s', (string) $row['firstName'], (string) $row['lastName'])),
            'state' => $row['state'],
            'lastLoginAt' => $row['lastLoginAt'],
            'lastActivityAt' => $row['lastActivityAt'],
            'syncedAt' => $row['syncedAt'],
            'trainingCount' => (int) $row['trainingCount'],
            'totalTime' => DurationUnit::secondsToMinutesInt($row['totalTime']),
            'averageProgress' => (float) $row['averageProgress'],
            'sessionRegistrationCount' => (int) $row['sessionRegistrationCount'],
        ], $rows));
    }

    #[Route('/{id}', name: 'api_learners_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'learners.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $connection = $this->entityManager->getConnection();

        $learner = $connection->fetchAssociative(
            <<<SQL
                SELECT
                    l.id,
                    l.external_id AS externalId,
                    l.username,
                    l.email,
                    l.first_name AS firstName,
                    l.last_name AS lastName,
                    l.language,
                    l.phone_number AS phoneNumber,
                    l.timezone,
                    l.rise_up_role AS riseUpRole,
                    l.type,
                    l.state,
                    l.activated_at AS activatedAt,
                    l.suspended_at AS suspendedAt,
                    l.last_login_at AS lastLoginAt,
                    l.rise_up_created_at AS riseUpCreatedAt,
                    l.rise_up_updated_at AS riseUpUpdatedAt,
                    l.synced_at AS syncedAt,
                    COALESCE(trs.training_count, 0) AS trainingCount,
                    COALESCE(trs.total_time, 0) AS totalTime,
                    ROUND(COALESCE(trs.average_progress, 0), 2) AS averageProgress,
                    COALESCE(csrs.session_registration_count, 0) AS sessionRegistrationCount,
                    COALESCE(sig.signed_attendance_count, 0) AS signedAttendanceCount,
                    lss.last_activity_at AS lastActivityAt,
                    rg.id AS groupId,
                    rg.name AS groupName
                FROM learners l
                LEFT JOIN (
                    SELECT
                        learner_id,
                        COUNT(*) AS training_count,
                        COALESCE(SUM(total_time), 0) AS total_time,
                        COALESCE(AVG(progress), 0) AS average_progress
                    FROM training_registrations
                    GROUP BY learner_id
                ) trs ON trs.learner_id = l.id
                LEFT JOIN (
                    SELECT learner_id, COUNT(*) AS session_registration_count
                    FROM classroom_session_registrations
                    GROUP BY learner_id
                ) csrs ON csrs.learner_id = l.id
                LEFT JOIN (
                    SELECT csr.learner_id, COUNT(*) AS signed_attendance_count
                    FROM classroom_session_signatures css
                    INNER JOIN classroom_session_registrations csr ON csr.id = css.registration_id
                    WHERE css.has_signed = 1
                    GROUP BY csr.learner_id
                ) sig ON sig.learner_id = l.id
                LEFT JOIN (
                    SELECT learner_id, MAX(activity_at) AS last_activity_at
                    FROM learner_step_states
                    GROUP BY learner_id
                ) lss ON lss.learner_id = l.id
                LEFT JOIN riseup_learner_groups rlg ON rlg.learner_id = l.id
                LEFT JOIN riseup_groups rg ON rg.id = rlg.group_id
                WHERE l.id = :id
                ORDER BY rlg.synced_at DESC
                LIMIT 1
            SQL,
            ['id' => $id],
            ['id' => ParameterType::INTEGER]
        );

        if (!is_array($learner)) {
            return $this->json(['message' => 'Learner not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        // Calculer le temps du groupe en utilisant le service
        $groupId = $learner['groupId'] !== null ? (int) $learner['groupId'] : null;
        $groupTotalTime = 0;
        
        if ($groupId !== null) {
            $memberTimeMetrics = $this->timeMetricsService->getTimeMetricsByGroupMember($groupId);
            $groupTotalTime = $memberTimeMetrics[$id]['total_time_seconds'] ?? 0;
        }

        $trainingRegistrations = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    tr.id,
                    tr.external_id AS externalId,
                    t.id AS trainingId,
                    t.external_id AS trainingExternalId,
                    t.title AS trainingTitle,
                    t.state AS trainingState,
                    tr.state,
                    tr.total_time AS totalTime,
                    tr.progress,
                    tr.score,
                    tr.subscribed_at AS subscribedAt,
                    tr.training_end_at AS trainingEndAt
                FROM training_registrations tr
                INNER JOIN trainings t ON t.id = tr.training_id
                WHERE tr.learner_id = :learnerId
                ORDER BY tr.subscribed_at DESC, tr.id DESC
            SQL,
            ['learnerId' => $id],
            ['learnerId' => ParameterType::INTEGER]
        );

        $sessionRegistrations = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    csr.id,
                    csr.external_id AS externalId,
                    csr.state,
                    csr.attended,
                    csr.edu_duration AS eduDuration,
                    csr.subscribed_at AS subscribedAt,
                    cs.id AS sessionId,
                    cs.external_id AS sessionExternalId,
                    cs.session_type AS sessionType,
                    cs.start_at AS startAt,
                    cs.end_at AS endAt,
                    cs.meeting_url AS meetingUrl,
                    t.title AS trainingTitle,
                    (
                        SELECT COUNT(*)
                        FROM classroom_session_signatures css
                        WHERE css.registration_id = csr.id AND css.has_signed = 1
                    ) AS signedCount
                FROM classroom_session_registrations csr
                INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
                LEFT JOIN trainings t ON t.id = cs.training_id
                WHERE csr.learner_id = :learnerId
                ORDER BY cs.start_at DESC, csr.id DESC
            SQL,
            ['learnerId' => $id],
            ['learnerId' => ParameterType::INTEGER]
        );

        $recentActivities = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    lss.id,
                    lss.external_id AS externalId,
                    lss.state,
                    lss.time_spent AS timeSpent,
                    lss.total_time AS totalTime,
                    lss.score,
                    lss.activity_at AS activityAt,
                    ts.id AS stepId,
                    ts.external_id AS stepExternalId,
                    ts.title AS stepTitle,
                    ts.type AS stepType,
                    tm.title AS moduleTitle,
                    t.title AS trainingTitle
                FROM learner_step_states lss
                INNER JOIN training_steps ts ON ts.id = lss.step_id
                INNER JOIN training_modules tm ON tm.id = ts.module_id
                INNER JOIN trainings t ON t.id = tm.training_id
                WHERE lss.learner_id = :learnerId
                ORDER BY lss.activity_at DESC, lss.id DESC
                LIMIT 20
            SQL,
            ['learnerId' => $id],
            ['learnerId' => ParameterType::INTEGER]
        );

        return $this->json([
            'learner' => [
                'id' => (int) $learner['id'],
                'externalId' => (int) $learner['externalId'],
                'username' => $learner['username'],
                'email' => $learner['email'],
                'firstName' => $learner['firstName'],
                'lastName' => $learner['lastName'],
                'fullName' => trim(sprintf('%s %s', (string) $learner['firstName'], (string) $learner['lastName'])),
                'language' => $learner['language'],
                'phoneNumber' => $learner['phoneNumber'],
                'timezone' => $learner['timezone'],
                'riseUpRole' => $learner['riseUpRole'],
                'type' => $learner['type'],
                'state' => $learner['state'],
                'activatedAt' => $learner['activatedAt'],
                'suspendedAt' => $learner['suspendedAt'],
                'lastLoginAt' => $learner['lastLoginAt'],
                'lastActivityAt' => $learner['lastActivityAt'],
                'riseUpCreatedAt' => $learner['riseUpCreatedAt'],
                'riseUpUpdatedAt' => $learner['riseUpUpdatedAt'],
                'syncedAt' => $learner['syncedAt'],
                'trainingCount' => (int) $learner['trainingCount'],
                'totalTime' => DurationUnit::secondsToMinutesInt($learner['totalTime']),
                'averageProgress' => (float) $learner['averageProgress'],
                'sessionRegistrationCount' => (int) $learner['sessionRegistrationCount'],
                'signedAttendanceCount' => (int) $learner['signedAttendanceCount'],
                'groupId' => $groupId,
                'groupName' => $learner['groupName'],
                'groupTotalTime' => DurationUnit::secondsToMinutesInt($groupTotalTime),
            ],
            'trainingRegistrations' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'externalId' => (int) $row['externalId'],
                'trainingId' => (int) $row['trainingId'],
                'trainingExternalId' => (int) $row['trainingExternalId'],
                'trainingTitle' => $row['trainingTitle'],
                'trainingState' => $row['trainingState'],
                'state' => $row['state'],
                'totalTime' => DurationUnit::secondsToMinutesInt($row['totalTime'] ?? 0),
                'progress' => $row['progress'] !== null ? (float) $row['progress'] : null,
                'score' => $row['score'] !== null ? (float) $row['score'] : null,
                'subscribedAt' => $row['subscribedAt'],
                'trainingEndAt' => $row['trainingEndAt'],
            ], $trainingRegistrations),
            'sessionRegistrations' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'externalId' => (int) $row['externalId'],
                'state' => $row['state'],
                'attended' => $row['attended'] !== null ? (bool) $row['attended'] : null,
                'eduDuration' => $row['eduDuration'] !== null ? (int) $row['eduDuration'] : null,
                'subscribedAt' => $row['subscribedAt'],
                'sessionId' => (int) $row['sessionId'],
                'sessionExternalId' => (int) $row['sessionExternalId'],
                'sessionType' => $row['sessionType'],
                'startAt' => $row['startAt'],
                'endAt' => $row['endAt'],
                'meetingUrl' => $row['meetingUrl'],
                'trainingTitle' => $row['trainingTitle'],
                'signedCount' => (int) $row['signedCount'],
            ], $sessionRegistrations),
            'recentActivities' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'externalId' => (int) $row['externalId'],
                'state' => $row['state'],
                'timeSpent' => DurationUnit::secondsToMinutesIntOrNull($row['timeSpent']),
                'totalTime' => DurationUnit::secondsToMinutesIntOrNull($row['totalTime']),
                'score' => $row['score'] !== null ? (float) $row['score'] : null,
                'activityAt' => $row['activityAt'],
                'stepId' => (int) $row['stepId'],
                'stepExternalId' => (int) $row['stepExternalId'],
                'stepTitle' => $row['stepTitle'],
                'stepType' => $row['stepType'],
                'moduleTitle' => $row['moduleTitle'],
                'trainingTitle' => $row['trainingTitle'],
            ], $recentActivities),
        ]);
    }
}
