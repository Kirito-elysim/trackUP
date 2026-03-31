<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\UserPermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
    public function index(): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'exports.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $connection = $this->entityManager->getConnection();

        $metrics = [
            'learnersReadyCount' => (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM training_registrations WHERE total_time > 0'
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
            'trackedTimeTotal' => (int) ($connection->fetchOne(
                'SELECT COALESCE(SUM(total_time), 0) FROM training_registrations'
            ) ?: 0),
        ];

        $learnerExports = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    l.id,
                    l.email,
                    l.first_name AS firstName,
                    l.last_name AS lastName,
                    COUNT(tr.id) AS trainingCount,
                    COALESCE(SUM(tr.total_time), 0) AS totalTime,
                    ROUND(COALESCE(AVG(tr.progress), 0), 2) AS averageProgress,
                    COALESCE(sig.signed_count, 0) AS signedCount
                FROM learners l
                INNER JOIN training_registrations tr ON tr.learner_id = l.id
                LEFT JOIN (
                    SELECT csr.learner_id, COUNT(*) AS signed_count
                    FROM classroom_session_signatures css
                    INNER JOIN classroom_session_registrations csr ON csr.id = css.registration_id
                    WHERE css.has_signed = 1
                    GROUP BY csr.learner_id
                ) sig ON sig.learner_id = l.id
                GROUP BY l.id, l.email, l.first_name, l.last_name, sig.signed_count
                ORDER BY totalTime DESC, signedCount DESC, l.last_name ASC
                LIMIT 10
            SQL
        );

        $trainingExports = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    t.id,
                    t.title,
                    t.state,
                    COUNT(tr.id) AS learnersCount,
                    COALESCE(SUM(tr.total_time), 0) AS totalTime,
                    ROUND(COALESCE(AVG(tr.progress), 0), 2) AS averageProgress,
                    COALESCE(sess.session_count, 0) AS sessionCount
                FROM trainings t
                LEFT JOIN training_registrations tr ON tr.training_id = t.id
                LEFT JOIN (
                    SELECT training_id, COUNT(*) AS session_count
                    FROM classroom_sessions
                    GROUP BY training_id
                ) sess ON sess.training_id = t.id
                GROUP BY t.id, t.title, t.state, sess.session_count
                ORDER BY learnersCount DESC, totalTime DESC, t.title ASC
                LIMIT 8
            SQL
        );

        $complianceAlerts = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    l.id AS learnerId,
                    l.email,
                    l.first_name AS firstName,
                    l.last_name AS lastName,
                    t.title AS trainingTitle,
                    cs.start_at AS sessionStartAt,
                    COUNT(csr.id) AS unsignedAttendances
                FROM classroom_session_registrations csr
                INNER JOIN learners l ON l.id = csr.learner_id
                INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
                LEFT JOIN trainings t ON t.id = cs.training_id
                LEFT JOIN classroom_session_signatures css ON css.registration_id = csr.id AND css.has_signed = 1
                WHERE csr.attended = 1 AND css.id IS NULL
                GROUP BY l.id, l.email, l.first_name, l.last_name, t.title, cs.start_at
                ORDER BY cs.start_at DESC, l.last_name ASC
                LIMIT 10
            SQL
        );

        return $this->json([
            'metrics' => $metrics,
            'learnerExports' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'email' => $row['email'],
                'firstName' => $row['firstName'],
                'lastName' => $row['lastName'],
                'fullName' => trim(sprintf('%s %s', (string) $row['firstName'], (string) $row['lastName'])),
                'trainingCount' => (int) $row['trainingCount'],
                'totalTime' => (int) $row['totalTime'],
                'averageProgress' => (float) $row['averageProgress'],
                'signedCount' => (int) $row['signedCount'],
            ], $learnerExports),
            'trainingExports' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'title' => $row['title'],
                'state' => $row['state'],
                'learnersCount' => (int) $row['learnersCount'],
                'totalTime' => (int) $row['totalTime'],
                'averageProgress' => (float) $row['averageProgress'],
                'sessionCount' => (int) $row['sessionCount'],
            ], $trainingExports),
            'complianceAlerts' => array_map(static fn (array $row): array => [
                'learnerId' => (int) $row['learnerId'],
                'email' => $row['email'],
                'firstName' => $row['firstName'],
                'lastName' => $row['lastName'],
                'fullName' => trim(sprintf('%s %s', (string) $row['firstName'], (string) $row['lastName'])),
                'trainingTitle' => $row['trainingTitle'],
                'sessionStartAt' => $row['sessionStartAt'],
                'unsignedAttendances' => (int) $row['unsignedAttendances'],
            ], $complianceAlerts),
        ]);
    }
}
