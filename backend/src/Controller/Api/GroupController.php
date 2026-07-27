<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\TimeMetricsService;
use App\Service\UserPermissionResolver;
use App\Util\DurationUnit;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/groups')]
class GroupController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPermissionResolver $permissionResolver,
        private readonly TimeMetricsService $timeMetricsService,
    ) {
    }

    #[Route('/{id}', name: 'api_groups_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'dashboard.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $connection = $this->entityManager->getConnection();

        $group = $connection->fetchAssociative(
            <<<SQL
                SELECT
                    rg.id,
                    rg.external_id AS externalId,
                    rg.name,
                    rg.reference,
                    COUNT(DISTINCT rlg.learner_id) AS memberCount,
                    COUNT(DISTINCT rglp.learning_path_external_id) AS learningPathCount,
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
                WHERE rg.id = :id
                GROUP BY rg.id, rg.external_id, rg.name, rg.reference
            SQL,
            ['id' => $id],
            ['id' => ParameterType::INTEGER],
        );

        if (!is_array($group)) {
            return $this->json(['message' => 'Group not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        // Récupérer les parcours associés au groupe
        $learningPaths = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    lp.id,
                    lp.external_id AS externalId,
                    lp.title,
                    lp.image_url AS imageUrl
                FROM riseup_group_learning_paths rglp
                INNER JOIN learning_paths lp ON lp.external_id = rglp.learning_path_external_id
                WHERE rglp.group_id = :groupId
                ORDER BY lp.title ASC
            SQL,
            ['groupId' => $id],
            ['groupId' => ParameterType::INTEGER],
        );

        // Récupérer les métriques de temps agrégées par membre du groupe
        $memberTimeMetrics = $this->timeMetricsService->getTimeMetricsByGroupMember($id);

        // Récupérer les membres du groupe avec leurs informations
        $members = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    l.id AS learnerId,
                    l.email,
                    l.first_name AS firstName,
                    l.last_name AS lastName,
                    rlg.synced_at AS joinedAt
                FROM riseup_learner_groups rlg
                INNER JOIN learners l ON l.id = rlg.learner_id
                WHERE rlg.group_id = :groupId
                ORDER BY l.last_name ASC, l.first_name ASC
            SQL,
            ['groupId' => $id],
            ['groupId' => ParameterType::INTEGER],
        );

        // Calculer la progression moyenne du groupe
        $progressRows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT AVG(lpr.progress) AS averageProgress
                FROM riseup_learner_groups rlg
                INNER JOIN learning_path_registrations lpr ON lpr.learner_id = rlg.learner_id
                INNER JOIN learning_paths lp ON lp.id = lpr.learning_path_id
                INNER JOIN riseup_group_learning_paths rglp ON rglp.learning_path_external_id = lp.external_id AND rglp.group_id = rlg.group_id
                WHERE rlg.group_id = :groupId
            SQL,
            ['groupId' => $id],
            ['groupId' => ParameterType::INTEGER],
        );

        $averageProgress = isset($progressRows[0]['averageProgress']) 
            ? (float) $progressRows[0]['averageProgress'] 
            : 0.0;

        // Calculer le temps total du groupe
        $totalTimeSeconds = $this->timeMetricsService->getTotalTimeForGroups()[$id] ?? 0;

        return $this->json([
            'group' => [
                'id' => (int) $group['id'],
                'externalId' => (int) $group['externalId'],
                'name' => $group['name'],
                'reference' => $group['reference'],
                'imageUrl' => $group['imageUrl'],
                'memberCount' => (int) $group['memberCount'],
                'learningPathCount' => (int) $group['learningPathCount'],
                'totalTime' => DurationUnit::secondsToMinutesInt($totalTimeSeconds),
                'averageProgress' => round($averageProgress, 2),
            ],
            'learningPaths' => array_map(fn (array $row): array => [
                'id' => (int) $row['id'],
                'externalId' => (int) $row['externalId'],
                'title' => $row['title'],
                'imageUrl' => $row['imageUrl'],
            ], $learningPaths),
            'members' => array_map(fn (array $row): array => [
                'learnerId' => (int) $row['learnerId'],
                'email' => $row['email'],
                'firstName' => $row['firstName'],
                'lastName' => $row['lastName'],
                'fullName' => trim(sprintf('%s %s', $row['firstName'] ?? '', $row['lastName'] ?? '')) ?: ($row['email'] ?? 'Membre'),
                'joinedAt' => $row['joinedAt'],
                'elearningTime' => DurationUnit::secondsToMinutesInt($memberTimeMetrics[(int) $row['learnerId']]['module_time_seconds'] ?? 0),
                'sessionTime' => DurationUnit::secondsToMinutesInt($memberTimeMetrics[(int) $row['learnerId']]['session_time_seconds'] ?? 0),
                'totalTime' => DurationUnit::secondsToMinutesInt($memberTimeMetrics[(int) $row['learnerId']]['total_time_seconds'] ?? 0),
                'expectedTime' => DurationUnit::secondsToMinutesInt($memberTimeMetrics[(int) $row['learnerId']]['expected_time_seconds'] ?? 0),
                'expectedElearningTime' => DurationUnit::secondsToMinutesInt($memberTimeMetrics[(int) $row['learnerId']]['expected_elearning_time_seconds'] ?? 0),
            ], $members),
        ]);
    }

    #[Route('/{groupId}/members/{learnerId}/sessions', name: 'api_groups_member_sessions', methods: ['GET'], requirements: ['groupId' => '\d+', 'learnerId' => '\d+'])]
    public function memberSessions(int $groupId, int $learnerId): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'dashboard.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $connection = $this->entityManager->getConnection();

        // Récupérer toutes les sessions de l'apprenant
        $sessions = $connection->fetchAllAssociative(
            <<<SQL
                SELECT DISTINCT
                    cs.id,
                    cs.reference AS title,
                    cs.session_type AS sessionType,
                    cs.start_at AS startAt,
                    cs.end_at AS endAt,
                    cs.edu_duration AS eduDuration,
                    CASE WHEN css.has_signed = 1 THEN cs.edu_duration ELSE 0 END AS countedTime,
                    COALESCE(css.has_signed, 0) AS hasSigned,
                    css.signature_date AS signatureDate,
                    t.title AS trainingTitle,
                    COALESCE(lp.title, 'Non rattaché à un parcours') AS learningPathTitle,
                    CASE WHEN cs.start_at > NOW() THEN 1 ELSE 0 END AS isFuture
                FROM classroom_session_registrations csr
                INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
                INNER JOIN trainings t ON t.id = cs.training_id
                LEFT JOIN learning_path_trainings lpt ON lpt.training_id = t.id
                LEFT JOIN learning_paths lp ON lp.id = lpt.learning_path_id
                LEFT JOIN classroom_session_signatures css ON css.registration_id = csr.id
                WHERE csr.learner_id = :learnerId
                ORDER BY 
                    CASE WHEN cs.start_at > NOW() THEN 0 ELSE 1 END,
                    cs.start_at DESC,
                    cs.id DESC
            SQL,
            ['learnerId' => $learnerId],
            ['learnerId' => ParameterType::INTEGER],
        );

        return $this->json([
            'sessions' => array_map(fn (array $row): array => [
                'id' => (int) $row['id'],
                'title' => $row['title'],
                'sessionType' => $row['sessionType'],
                'startAt' => $row['startAt'],
                'endAt' => $row['endAt'],
                'eduDuration' => $row['eduDuration'] !== null ? (int) $row['eduDuration'] : null,
                'countedTime' => (int) $row['countedTime'],
                'hasSigned' => (bool) $row['hasSigned'],
                'signatureDate' => $row['signatureDate'],
                'trainingTitle' => $row['trainingTitle'],
                'learningPathTitle' => $row['learningPathTitle'],
                'isFuture' => (bool) $row['isFuture'],
            ], $sessions),
        ]);
    }
}
