<?php

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

#[Route('/api/learningpaths')]
class LearningPathController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPermissionResolver $permissionResolver,
    ) {
    }

    #[Route('', name: 'api_learningpaths_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'learningpaths.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $connection = $this->entityManager->getConnection();
        $limit = min(max((int) $request->query->get('limit', 50), 1), 200);
        $search = trim((string) $request->query->get('q', ''));

        $conditions = [];
        $params = ['limit' => $limit];
        $types = ['limit' => ParameterType::INTEGER];

        if ($search !== '') {
            $conditions[] = '(lp.title LIKE :search OR lp.reference LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $whereSql = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $rows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    lp.id,
                    lp.external_id AS externalId,
                    lp.title,
                    lp.reference,
                    lp.language,
                    lp.description,
                    lp.sequential,
                    lp.image_url AS imageUrl,
                    COALESCE(lp.rise_up_updated_at, lp.synced_at) AS updatedAt,
                    lp.synced_at AS syncedAt,
                    COALESCE(lpt.training_count, 0) AS trainingCount,
                    COALESCE(lpr.learner_count, 0) AS learnerCount,
                    COALESCE(stats.module_time, 0) AS moduleTime,
                    COALESCE(stats.masterclass_time, 0) AS masterclassTime,
                    ROUND(COALESCE(stats.average_progress, 0), 2) AS averageProgress
                FROM learning_paths lp
                LEFT JOIN (
                    SELECT learning_path_id, COUNT(*) AS training_count
                    FROM learning_path_trainings
                    GROUP BY learning_path_id
                ) lpt ON lpt.learning_path_id = lp.id
                LEFT JOIN (
                    SELECT learning_path_id, COUNT(*) AS learner_count
                    FROM learning_path_registrations
                    GROUP BY learning_path_id
                ) lpr ON lpr.learning_path_id = lp.id
                LEFT JOIN (
                    SELECT
                        lpr.learning_path_id,
                        COALESCE(SUM(COALESCE(module_logs.module_time, 0)), 0) AS module_time,
                        COALESCE(SUM(COALESCE(session_logs.masterclass_time, 0)), 0) AS masterclass_time,
                        COALESCE(AVG(lpr.progress), 0) AS average_progress
                    FROM learning_path_registrations lpr
                    LEFT JOIN (
                        SELECT
                            lpt.learning_path_id,
                            lss.learner_id,
                            COALESCE(SUM(COALESCE(NULLIF(lss.time_spent, 0), lss.total_time, 0)), 0) AS module_time
                        FROM learner_step_states lss
                        INNER JOIN training_steps ts ON ts.id = lss.step_id
                        INNER JOIN training_modules tm ON tm.id = ts.module_id
                        INNER JOIN learning_path_trainings lpt ON lpt.training_id = tm.training_id
                        GROUP BY lpt.learning_path_id, lss.learner_id
                    ) module_logs ON module_logs.learning_path_id = lpr.learning_path_id AND module_logs.learner_id = lpr.learner_id
                    LEFT JOIN (
                        SELECT
                            lpt.learning_path_id,
                            csr.learner_id,
                            COALESCE(SUM(CASE WHEN css.has_signed = 1 THEN cs.edu_duration ELSE 0 END), 0) AS masterclass_time
                        FROM classroom_session_registrations csr
                        INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
                        INNER JOIN learning_path_trainings lpt ON lpt.training_id = cs.training_id
                        LEFT JOIN classroom_session_signatures css ON css.registration_id = csr.id
                        GROUP BY lpt.learning_path_id, csr.learner_id
                    ) session_logs ON session_logs.learning_path_id = lpr.learning_path_id AND session_logs.learner_id = lpr.learner_id
                    GROUP BY lpr.learning_path_id
                ) stats ON stats.learning_path_id = lp.id
                {$whereSql}
                ORDER BY learnerCount DESC, trainingCount DESC, lp.title ASC
                LIMIT :limit
            SQL,
            $params,
            $types,
        );

        return $this->json(array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'externalId' => (int) $row['externalId'],
            'title' => $row['title'],
            'reference' => $row['reference'],
            'language' => $row['language'],
            'description' => $row['description'],
            'sequential' => $row['sequential'] !== null ? (bool) $row['sequential'] : null,
            'imageUrl' => $row['imageUrl'],
            'updatedAt' => $row['updatedAt'],
            'syncedAt' => $row['syncedAt'],
            'trainingCount' => (int) $row['trainingCount'],
            'learnerCount' => (int) $row['learnerCount'],
            'totalTime' => $this->totalMinutes($row['moduleTime'] ?? 0, $row['masterclassTime'] ?? 0),
            'averageProgress' => (float) $row['averageProgress'],
        ], $rows));
    }

    #[Route('/{id}', name: 'api_learningpaths_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'learningpaths.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $connection = $this->entityManager->getConnection();

        $learningPath = $connection->fetchAssociative(
            <<<SQL
                SELECT
                    lp.id,
                    lp.external_id AS externalId,
                    lp.title,
                    lp.reference,
                    lp.language,
                    lp.description,
                    lp.sequential,
                    lp.image_url AS imageUrl,
                    lp.rise_up_created_at AS riseUpCreatedAt,
                    lp.rise_up_updated_at AS riseUpUpdatedAt,
                    lp.synced_at AS syncedAt,
                    COALESCE(lpt.training_count, 0) AS trainingCount,
                    COALESCE(lpr.learner_count, 0) AS learnerCount,
                    COALESCE(stats.module_time, 0) AS moduleTime,
                    COALESCE(stats.masterclass_time, 0) AS masterclassTime,
                    ROUND(COALESCE(stats.average_progress, 0), 2) AS averageProgress
                FROM learning_paths lp
                LEFT JOIN (
                    SELECT learning_path_id, COUNT(*) AS training_count
                    FROM learning_path_trainings
                    GROUP BY learning_path_id
                ) lpt ON lpt.learning_path_id = lp.id
                LEFT JOIN (
                    SELECT learning_path_id, COUNT(*) AS learner_count
                    FROM learning_path_registrations
                    GROUP BY learning_path_id
                ) lpr ON lpr.learning_path_id = lp.id
                LEFT JOIN (
                    SELECT
                        lpr.learning_path_id,
                        COALESCE(SUM(COALESCE(module_logs.module_time, 0)), 0) AS module_time,
                        COALESCE(SUM(COALESCE(session_logs.masterclass_time, 0)), 0) AS masterclass_time,
                        COALESCE(AVG(lpr.progress), 0) AS average_progress
                    FROM learning_path_registrations lpr
                    LEFT JOIN (
                        SELECT
                            lpt.learning_path_id,
                            lss.learner_id,
                            COALESCE(SUM(COALESCE(NULLIF(lss.time_spent, 0), lss.total_time, 0)), 0) AS module_time
                        FROM learner_step_states lss
                        INNER JOIN training_steps ts ON ts.id = lss.step_id
                        INNER JOIN training_modules tm ON tm.id = ts.module_id
                        INNER JOIN learning_path_trainings lpt ON lpt.training_id = tm.training_id
                        GROUP BY lpt.learning_path_id, lss.learner_id
                    ) module_logs ON module_logs.learning_path_id = lpr.learning_path_id AND module_logs.learner_id = lpr.learner_id
                    LEFT JOIN (
                        SELECT
                            lpt.learning_path_id,
                            csr.learner_id,
                            COALESCE(SUM(CASE WHEN css.has_signed = 1 THEN cs.edu_duration ELSE 0 END), 0) AS masterclass_time
                        FROM classroom_session_registrations csr
                        INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
                        INNER JOIN learning_path_trainings lpt ON lpt.training_id = cs.training_id
                        LEFT JOIN classroom_session_signatures css ON css.registration_id = csr.id
                        GROUP BY lpt.learning_path_id, csr.learner_id
                    ) session_logs ON session_logs.learning_path_id = lpr.learning_path_id AND session_logs.learner_id = lpr.learner_id
                    GROUP BY lpr.learning_path_id
                ) stats ON stats.learning_path_id = lp.id
                WHERE lp.id = :id
            SQL,
            ['id' => $id],
            ['id' => ParameterType::INTEGER],
        );

        if (!is_array($learningPath)) {
            return $this->json(['message' => 'Learning path not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $trainings = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    lpt.id,
                    lpt.position,
                    lpt.is_required AS isRequired,
                    t.id AS trainingId,
                    t.external_id AS trainingExternalId,
                    t.title,
                    t.state,
                    t.type,
                    t.edu_duration AS eduDuration,
                    COUNT(DISTINCT CASE
                        WHEN tr.id IS NOT NULL OR COALESCE(step_logs.module_time, 0) > 0 OR COALESCE(session_logs.masterclass_time, 0) > 0
                        THEN lpr.learner_id
                    END) AS learnerCount,
                    COALESCE(SUM(COALESCE(step_logs.module_time, 0)), 0) AS moduleTime,
                    COALESCE(SUM(COALESCE(session_logs.masterclass_time, 0)), 0) AS masterclassTime,
                    ROUND(COALESCE(AVG(COALESCE(tr.progress, step_progress.progress_percent, 0)), 0), 2) AS averageProgress
                FROM learning_path_trainings lpt
                INNER JOIN trainings t ON t.id = lpt.training_id
                LEFT JOIN learning_path_registrations lpr ON lpr.learning_path_id = lpt.learning_path_id
                LEFT JOIN training_registrations tr ON tr.training_id = lpt.training_id AND tr.learner_id = lpr.learner_id
                LEFT JOIN (
                    SELECT
                        tm.training_id,
                        lss.learner_id,
                        COALESCE(SUM(COALESCE(NULLIF(lss.time_spent, 0), lss.total_time, 0)), 0) AS module_time
                    FROM learner_step_states lss
                    INNER JOIN training_steps ts ON ts.id = lss.step_id
                    INNER JOIN training_modules tm ON tm.id = ts.module_id
                    GROUP BY tm.training_id, lss.learner_id
                ) step_logs ON step_logs.training_id = lpt.training_id AND step_logs.learner_id = lpr.learner_id
                LEFT JOIN (
                    SELECT
                        cs.training_id,
                        csr.learner_id,
                        COALESCE(SUM(CASE WHEN css.has_signed = 1 THEN cs.edu_duration ELSE 0 END), 0) AS masterclass_time
                    FROM classroom_session_registrations csr
                    INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
                    LEFT JOIN classroom_session_signatures css ON css.registration_id = csr.id
                    GROUP BY cs.training_id, csr.learner_id
                ) session_logs ON session_logs.training_id = lpt.training_id AND session_logs.learner_id = lpr.learner_id
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
                ) step_progress ON step_progress.training_id = lpt.training_id AND step_progress.learner_id = lpr.learner_id
                WHERE lpt.learning_path_id = :learningPathId
                GROUP BY lpt.id, lpt.position, lpt.is_required, t.id, t.external_id, t.title, t.state, t.type, t.edu_duration
                ORDER BY lpt.position ASC, lpt.id ASC
            SQL,
            ['learningPathId' => $id],
            ['learningPathId' => ParameterType::INTEGER],
        );

        $learners = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    lpr.id,
                    lpr.reference,
                    lpr.score,
                    lpr.progress,
                    lpr.subscribed_at AS subscribedAt,
                    l.id AS learnerId,
                    l.email,
                    l.first_name AS firstName,
                    l.last_name AS lastName,
                    COUNT(DISTINCT CASE WHEN tr.state = 'validated' THEN tr.training_id END) AS completedTrainingCount,
                    COALESCE(module_logs.module_time, 0) AS moduleTime,
                    COALESCE(session_logs.masterclass_time, 0) AS masterclassTime,
                    COALESCE(session_logs.expected_time, 0) AS expectedTime,
                    COALESCE(elearning_expected.expected_elearning_time, 0) AS expectedElearningTime,
                    ROUND(COALESCE(lpr.progress, 0), 2) AS averageProgress
                FROM learning_path_registrations lpr
                INNER JOIN learners l ON l.id = lpr.learner_id
                LEFT JOIN learning_path_trainings lpt ON lpt.learning_path_id = lpr.learning_path_id
                LEFT JOIN training_registrations tr ON tr.training_id = lpt.training_id AND tr.learner_id = lpr.learner_id
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
                ) module_logs ON module_logs.learning_path_id = lpr.learning_path_id AND module_logs.learner_id = lpr.learner_id
                LEFT JOIN (
                    SELECT
                        lpt2.learning_path_id,
                        csr.learner_id,
                        COALESCE(SUM(CASE WHEN css.has_signed = 1 THEN cs.edu_duration ELSE 0 END), 0) AS masterclass_time,
                        COALESCE(SUM(cs.edu_duration), 0) AS expected_time
                    FROM classroom_session_registrations csr
                    INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
                    INNER JOIN learning_path_trainings lpt2 ON lpt2.training_id = cs.training_id
                    LEFT JOIN classroom_session_signatures css ON css.registration_id = csr.id
                    GROUP BY lpt2.learning_path_id, csr.learner_id
                ) session_logs ON session_logs.learning_path_id = lpr.learning_path_id AND session_logs.learner_id = lpr.learner_id
                LEFT JOIN (
                    SELECT
                        lpt3.learning_path_id,
                        COALESCE(SUM(t3.edu_duration), 0) AS expected_elearning_time
                    FROM learning_path_trainings lpt3
                    INNER JOIN trainings t3 ON t3.id = lpt3.training_id
                    WHERE EXISTS (
                        SELECT 1 FROM training_modules tm WHERE tm.training_id = t3.id
                    )
                    GROUP BY lpt3.learning_path_id
                ) elearning_expected ON elearning_expected.learning_path_id = lpr.learning_path_id
                WHERE lpr.learning_path_id = :learningPathId
                GROUP BY lpr.id, lpr.reference, lpr.score, lpr.progress, lpr.subscribed_at, l.id, l.email, l.first_name, l.last_name, module_logs.module_time, session_logs.masterclass_time, session_logs.expected_time, elearning_expected.expected_elearning_time
                ORDER BY COALESCE(module_logs.module_time, 0) DESC, COALESCE(session_logs.masterclass_time, 0) DESC, l.last_name ASC
            SQL,
            ['learningPathId' => $id],
            ['learningPathId' => ParameterType::INTEGER],
        );

        return $this->json([
            'learningPath' => [
                'id' => (int) $learningPath['id'],
                'externalId' => (int) $learningPath['externalId'],
                'title' => $learningPath['title'],
                'reference' => $learningPath['reference'],
                'language' => $learningPath['language'],
                'description' => $learningPath['description'],
                'sequential' => $learningPath['sequential'] !== null ? (bool) $learningPath['sequential'] : null,
                'imageUrl' => $learningPath['imageUrl'],
                'riseUpCreatedAt' => $learningPath['riseUpCreatedAt'],
                'riseUpUpdatedAt' => $learningPath['riseUpUpdatedAt'],
                'syncedAt' => $learningPath['syncedAt'],
                'trainingCount' => (int) $learningPath['trainingCount'],
                'learnerCount' => (int) $learningPath['learnerCount'],
                'totalTime' => $this->totalMinutes($learningPath['moduleTime'] ?? 0, $learningPath['masterclassTime'] ?? 0),
                'averageProgress' => (float) $learningPath['averageProgress'],
            ],
            'trainings' => array_map(fn (array $row): array => [
                'id' => (int) $row['id'],
                'position' => $row['position'] !== null ? (int) $row['position'] : null,
                'isRequired' => (bool) $row['isRequired'],
                'trainingId' => (int) $row['trainingId'],
                'trainingExternalId' => (int) $row['trainingExternalId'],
                'title' => $row['title'],
                'state' => $row['state'],
                'type' => $row['type'],
                'eduDuration' => $row['eduDuration'] !== null ? (int) $row['eduDuration'] : null,
                'learnerCount' => (int) $row['learnerCount'],
                'totalTime' => $this->totalMinutes($row['moduleTime'] ?? 0, $row['masterclassTime'] ?? 0),
                'averageProgress' => (float) $row['averageProgress'],
            ], $trainings),
            'learners' => array_map(fn (array $row): array => [
                'id' => (int) $row['id'],
                'reference' => $row['reference'],
                'score' => $row['score'] !== null ? (float) $row['score'] : null,
                'progress' => $row['progress'] !== null ? (float) $row['progress'] : null,
                'subscribedAt' => $row['subscribedAt'],
                'learnerId' => (int) $row['learnerId'],
                'email' => $row['email'],
                'firstName' => $row['firstName'],
                'lastName' => $row['lastName'],
                'fullName' => trim(sprintf('%s %s', $row['firstName'] ?? '', $row['lastName'] ?? '')) ?: ($row['email'] ?? 'Apprenant'),
                'completedTrainingCount' => (int) $row['completedTrainingCount'],
                'totalTime' => $this->totalMinutes($row['moduleTime'] ?? 0, $row['masterclassTime'] ?? 0),
                'sessionTime' => DurationUnit::minutesToInt($row['masterclassTime'] ?? 0),
                'expectedTime' => DurationUnit::minutesToInt($row['expectedTime'] ?? 0),
                'elearningTime' => DurationUnit::secondsToMinutesInt($row['moduleTime'] ?? 0),
                'expectedElearningTime' => DurationUnit::minutesToInt($row['expectedElearningTime'] ?? 0),
                'averageProgress' => (float) $row['averageProgress'],
            ], $learners),
        ]);
    }

    #[Route('/{learningPathId}/learners/{learnerId}/sessions', name: 'api_learningpaths_learner_sessions', methods: ['GET'], requirements: ['learningPathId' => '\d+', 'learnerId' => '\d+'])]
    public function learnerSessions(int $learningPathId, int $learnerId): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'learningpaths.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $connection = $this->entityManager->getConnection();

        $sessions = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    cs.id,
                    cs.external_id AS externalId,
                    cs.reference AS title,
                    cs.session_type AS sessionType,
                    cs.start_at AS startAt,
                    cs.end_at AS endAt,
                    cs.edu_duration AS eduDuration,
                    csr.id AS registrationId,
                    csr.attended,
                    csr.edu_duration AS learnerEduDuration,
                    COALESCE(css.has_signed, 0) AS hasSigned,
                    css.attendance_date AS attendanceDate,
                    css.signature_date AS signatureDate,
                    t.id AS trainingId,
                    t.title AS trainingTitle,
                    CASE WHEN css.has_signed = 1 THEN cs.edu_duration ELSE 0 END AS countedTime,
                    CASE WHEN cs.start_at > NOW() THEN 1 ELSE 0 END AS isFuture
                FROM classroom_sessions cs
                INNER JOIN trainings t ON t.id = cs.training_id
                INNER JOIN learning_path_trainings lpt ON lpt.training_id = t.id
                LEFT JOIN classroom_session_registrations csr ON csr.session_id = cs.id AND csr.learner_id = :learnerId
                LEFT JOIN classroom_session_signatures css ON css.registration_id = csr.id
                WHERE lpt.learning_path_id = :learningPathId
                  AND (csr.learner_id = :learnerId OR cs.start_at > NOW())
                ORDER BY 
                    CASE WHEN cs.start_at > NOW() THEN 0 ELSE 1 END,
                    cs.start_at DESC,
                    cs.reference ASC
            SQL,
            ['learningPathId' => $learningPathId, 'learnerId' => $learnerId],
            ['learningPathId' => ParameterType::INTEGER, 'learnerId' => ParameterType::INTEGER],
        );

        return $this->json([
            'sessions' => array_map(fn (array $row): array => [
                'id' => (int) $row['id'],
                'externalId' => (int) $row['externalId'],
                'title' => $row['title'],
                'sessionType' => $row['sessionType'],
                'startAt' => $row['startAt'],
                'endAt' => $row['endAt'],
                'eduDuration' => $row['eduDuration'] !== null ? (int) $row['eduDuration'] : null,
                'registrationId' => (int) $row['registrationId'],
                'attended' => $row['attended'] !== null ? (bool) $row['attended'] : null,
                'learnerEduDuration' => $row['learnerEduDuration'] !== null ? (int) $row['learnerEduDuration'] : null,
                'hasSigned' => (bool) $row['hasSigned'],
                'attendanceDate' => $row['attendanceDate'],
                'signatureDate' => $row['signatureDate'],
                'trainingId' => (int) $row['trainingId'],
                'trainingTitle' => $row['trainingTitle'],
                'countedTime' => DurationUnit::minutesToInt($row['countedTime']),
                'isFuture' => (bool) $row['isFuture'],
            ], $sessions),
        ]);
    }

    private function totalMinutes(mixed $moduleSeconds, mixed $masterclassMinutes): int
    {
        return DurationUnit::secondsToMinutesInt($moduleSeconds) + DurationUnit::minutesToInt($masterclassMinutes);
    }
}
