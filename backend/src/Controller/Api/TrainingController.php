<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\UserPermissionResolver;
use App\Util\DurationUnit;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/trainings')]
class TrainingController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPermissionResolver $permissionResolver,
    ) {
    }

    #[Route('', name: 'api_trainings_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'courses.view')) {
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
            $conditions[] = '(t.title LIKE :search OR t.reference LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        if ($state !== '') {
            $conditions[] = 't.state = :state';
            $params['state'] = $state;
        }

        $whereSql = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $rows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    t.id,
                    t.external_id AS externalId,
                    t.title,
                    t.reference,
                    t.state,
                    t.type,
                    t.edu_duration AS eduDuration,
                    t.language,
                    t.synced_at AS syncedAt,
                    COALESCE(stats.learners_count, 0) AS learnersCount,
                    COALESCE(stats.total_time, 0) AS totalTime,
                    ROUND(COALESCE(stats.average_progress, 0), 2) AS averageProgress,
                    COALESCE(mods.module_count, 0) AS moduleCount,
                    COALESCE(steps.step_count, 0) AS stepCount,
                    COALESCE(sess.session_count, 0) AS sessionCount
                FROM trainings t
                LEFT JOIN (
                    SELECT
                        training_id,
                        COUNT(*) AS learners_count,
                        COALESCE(SUM(total_time), 0) AS total_time,
                        COALESCE(AVG(progress), 0) AS average_progress
                    FROM training_registrations
                    GROUP BY training_id
                ) stats ON stats.training_id = t.id
                LEFT JOIN (
                    SELECT training_id, COUNT(*) AS module_count
                    FROM training_modules
                    GROUP BY training_id
                ) mods ON mods.training_id = t.id
                LEFT JOIN (
                    SELECT tm.training_id, COUNT(*) AS step_count
                    FROM training_steps ts
                    INNER JOIN training_modules tm ON tm.id = ts.module_id
                    GROUP BY tm.training_id
                ) steps ON steps.training_id = t.id
                LEFT JOIN (
                    SELECT training_id, COUNT(*) AS session_count
                    FROM classroom_sessions
                    GROUP BY training_id
                ) sess ON sess.training_id = t.id
                {$whereSql}
                ORDER BY learnersCount DESC, totalTime DESC, t.title ASC
                LIMIT :limit
            SQL,
            $params,
            $types
        );

        return $this->json(array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'externalId' => (int) $row['externalId'],
            'title' => $row['title'],
            'reference' => $row['reference'],
            'state' => $row['state'],
            'type' => $row['type'],
            'eduDuration' => $row['eduDuration'] !== null ? (int) $row['eduDuration'] : null,
            'language' => $row['language'],
            'syncedAt' => $row['syncedAt'],
            'learnersCount' => (int) $row['learnersCount'],
            'totalTime' => DurationUnit::secondsToMinutesInt($row['totalTime']),
            'averageProgress' => (float) $row['averageProgress'],
            'moduleCount' => (int) $row['moduleCount'],
            'stepCount' => (int) $row['stepCount'],
            'sessionCount' => (int) $row['sessionCount'],
        ], $rows));
    }

    #[Route('/{id}', name: 'api_trainings_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'courses.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $connection = $this->entityManager->getConnection();

        $training = $connection->fetchAssociative(
            <<<SQL
                SELECT
                    t.id,
                    t.external_id AS externalId,
                    t.title,
                    t.reference,
                    t.description,
                    t.language,
                    t.objective,
                    t.edu_duration AS eduDuration,
                    t.state,
                    t.external_link AS externalLink,
                    t.type,
                    t.sequential,
                    t.rise_up_created_at AS riseUpCreatedAt,
                    t.rise_up_updated_at AS riseUpUpdatedAt,
                    t.synced_at AS syncedAt,
                    COALESCE(stats.learners_count, 0) AS learnersCount,
                    COALESCE(stats.total_time, 0) AS totalTime,
                    ROUND(COALESCE(stats.average_progress, 0), 2) AS averageProgress,
                    COALESCE(stats.average_score, 0) AS averageScore,
                    COALESCE(sess.session_count, 0) AS sessionCount
                FROM trainings t
                LEFT JOIN (
                    SELECT
                        training_id,
                        COUNT(*) AS learners_count,
                        COALESCE(SUM(total_time), 0) AS total_time,
                        COALESCE(AVG(progress), 0) AS average_progress,
                        COALESCE(AVG(score), 0) AS average_score
                    FROM training_registrations
                    GROUP BY training_id
                ) stats ON stats.training_id = t.id
                LEFT JOIN (
                    SELECT training_id, COUNT(*) AS session_count
                    FROM classroom_sessions
                    GROUP BY training_id
                ) sess ON sess.training_id = t.id
                WHERE t.id = :id
            SQL,
            ['id' => $id],
            ['id' => ParameterType::INTEGER]
        );

        if (!is_array($training)) {
            return $this->json(['message' => 'Training not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $modules = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    tm.id,
                    tm.external_id AS externalId,
                    tm.title,
                    tm.description,
                    tm.edu_duration AS eduDuration,
                    tm.duration,
                    tm.type,
                    tm.reference,
                    tm.position,
                    tm.language,
                    COALESCE(step_count.step_count, 0) AS stepCount
                FROM training_modules tm
                LEFT JOIN (
                    SELECT module_id, COUNT(*) AS step_count
                    FROM training_steps
                    GROUP BY module_id
                ) step_count ON step_count.module_id = tm.id
                WHERE tm.training_id = :trainingId
                ORDER BY tm.position ASC, tm.id ASC
            SQL,
            ['trainingId' => $id],
            ['trainingId' => ParameterType::INTEGER]
        );

        $sessions = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    cs.id,
                    cs.external_id AS externalId,
                    cs.session_type AS sessionType,
                    cs.state,
                    cs.start_at AS startAt,
                    cs.end_at AS endAt,
                    cs.location,
                    cs.room,
                    cs.meeting_url AS meetingUrl,
                    cs.edu_duration AS eduDuration,
                    COALESCE(regs.registration_count, 0) AS registrationCount,
                    COALESCE(regs.attended_count, 0) AS attendedCount
                FROM classroom_sessions cs
                LEFT JOIN (
                    SELECT
                        session_id,
                        COUNT(*) AS registration_count,
                        SUM(CASE WHEN attended = 1 THEN 1 ELSE 0 END) AS attended_count
                    FROM classroom_session_registrations
                    GROUP BY session_id
                ) regs ON regs.session_id = cs.id
                WHERE cs.training_id = :trainingId
                ORDER BY cs.start_at DESC, cs.id DESC
            SQL,
            ['trainingId' => $id],
            ['trainingId' => ParameterType::INTEGER]
        );

        $topLearners = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    tr.id,
                    l.id AS learnerId,
                    l.email,
                    l.first_name AS firstName,
                    l.last_name AS lastName,
                    tr.state,
                    tr.total_time AS totalTime,
                    tr.progress,
                    tr.score
                FROM training_registrations tr
                INNER JOIN learners l ON l.id = tr.learner_id
                WHERE tr.training_id = :trainingId
                ORDER BY tr.total_time DESC, tr.progress DESC, tr.id DESC
                LIMIT 10
            SQL,
            ['trainingId' => $id],
            ['trainingId' => ParameterType::INTEGER]
        );

        return $this->json([
            'training' => [
                'id' => (int) $training['id'],
                'externalId' => (int) $training['externalId'],
                'title' => $training['title'],
                'reference' => $training['reference'],
                'description' => $training['description'],
                'language' => $training['language'],
                'objective' => $training['objective'],
                'eduDuration' => $training['eduDuration'] !== null ? (int) $training['eduDuration'] : null,
                'state' => $training['state'],
                'externalLink' => $training['externalLink'],
                'type' => $training['type'],
                'sequential' => $training['sequential'] !== null ? (bool) $training['sequential'] : null,
                'riseUpCreatedAt' => $training['riseUpCreatedAt'],
                'riseUpUpdatedAt' => $training['riseUpUpdatedAt'],
                'syncedAt' => $training['syncedAt'],
                'learnersCount' => (int) $training['learnersCount'],
                'totalTime' => DurationUnit::secondsToMinutesInt($training['totalTime']),
                'averageProgress' => (float) $training['averageProgress'],
                'averageScore' => (float) $training['averageScore'],
                'sessionCount' => (int) $training['sessionCount'],
            ],
            'modules' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'externalId' => (int) $row['externalId'],
                'title' => $row['title'],
                'description' => $row['description'],
                'eduDuration' => $row['eduDuration'] !== null ? (int) $row['eduDuration'] : null,
                'duration' => $row['duration'] !== null ? (int) $row['duration'] : null,
                'type' => $row['type'],
                'reference' => $row['reference'],
                'position' => $row['position'] !== null ? (int) $row['position'] : null,
                'language' => $row['language'],
                'stepCount' => (int) $row['stepCount'],
            ], $modules),
            'sessions' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'externalId' => (int) $row['externalId'],
                'sessionType' => $row['sessionType'],
                'state' => $row['state'],
                'startAt' => $row['startAt'],
                'endAt' => $row['endAt'],
                'location' => $row['location'],
                'room' => $row['room'],
                'meetingUrl' => $row['meetingUrl'],
                'eduDuration' => $row['eduDuration'] !== null ? (int) $row['eduDuration'] : null,
                'registrationCount' => (int) $row['registrationCount'],
                'attendedCount' => (int) $row['attendedCount'],
            ], $sessions),
            'topLearners' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'learnerId' => (int) $row['learnerId'],
                'email' => $row['email'],
                'firstName' => $row['firstName'],
                'lastName' => $row['lastName'],
                'fullName' => trim(sprintf('%s %s', (string) $row['firstName'], (string) $row['lastName'])),
                'state' => $row['state'],
                'totalTime' => DurationUnit::secondsToMinutesInt($row['totalTime'] ?? 0),
                'progress' => $row['progress'] !== null ? (float) $row['progress'] : null,
                'score' => $row['score'] !== null ? (float) $row['score'] : null,
            ], $topLearners),
        ]);
    }
}
