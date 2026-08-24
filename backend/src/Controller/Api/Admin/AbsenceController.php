<?php
declare(strict_types=1);

namespace App\Controller\Api\Admin;

use App\Entity\Absence;
use App\Entity\User;
use App\Service\AbsenceNotificationService;
use App\Service\AbsenceStreakService;
use App\Service\UserPermissionResolver;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/absences')]
class AbsenceController extends AbstractController
{
    private const SETTABLE_STATUSES = [
        Absence::STATUS_EN_ATTENTE,
        Absence::STATUS_JUSTIFIEE,
        Absence::STATUS_NON_JUSTIFIEE,
        Absence::STATUS_AUTRE,
    ];

    private const FINAL_STATUSES = [
        Absence::STATUS_JUSTIFIEE,
        Absence::STATUS_NON_JUSTIFIEE,
        Absence::STATUS_AUTRE,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPermissionResolver $permissionResolver,
        private readonly AbsenceNotificationService $absenceNotificationService,
        private readonly AbsenceStreakService $absenceStreakService,
    ) {
    }

    // Tableau de bord + liste filtrable (roadmap 3.3) : apprenant, groupe, période, statut.
    #[Route('', name: 'api_admin_absences_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'absences.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $connection = $this->entityManager->getConnection();

        $page = max((int) $request->query->get('page', 1), 1);
        $pageSize = min(max((int) $request->query->get('pageSize', 50), 1), 200);

        $conditions = [];
        $params = [];
        $types = [];

        $learnerQuery = trim((string) $request->query->get('learnerQuery', ''));
        if ($learnerQuery !== '') {
            $conditions[] = '(l.first_name LIKE :learnerQuery OR l.last_name LIKE :learnerQuery OR l.email LIKE :learnerQuery)';
            $params['learnerQuery'] = '%' . $learnerQuery . '%';
        }

        $groupExternalId = (int) $request->query->get('groupExternalId', 0);
        if ($groupExternalId > 0) {
            $conditions[] = 'EXISTS (
                SELECT 1 FROM riseup_learner_groups lg
                INNER JOIN riseup_groups rg ON rg.id = lg.group_id
                WHERE lg.learner_id = l.id AND rg.external_id = :groupExternalId
            )';
            $params['groupExternalId'] = $groupExternalId;
            $types['groupExternalId'] = ParameterType::INTEGER;
        }

        $status = trim((string) $request->query->get('status', ''));
        if ($status !== '' && in_array($status, self::SETTABLE_STATUSES, true)) {
            $conditions[] = 'a.status = :status';
            $params['status'] = $status;
        }

        $type = trim((string) $request->query->get('type', ''));
        if ($type !== '' && in_array($type, [Absence::TYPE_MASTERCLASS, Absence::TYPE_PRESENTIEL], true)) {
            $conditions[] = 'a.type = :type';
            $params['type'] = $type;
        }

        $dateFrom = trim((string) $request->query->get('dateFrom', ''));
        if ($dateFrom !== '') {
            $conditions[] = 'cs.start_at >= :dateFrom';
            $params['dateFrom'] = $dateFrom . ' 00:00:00';
        }

        $dateTo = trim((string) $request->query->get('dateTo', ''));
        if ($dateTo !== '') {
            $conditions[] = 'cs.start_at <= :dateTo';
            $params['dateTo'] = $dateTo . ' 23:59:59';
        }

        $whereSql = $conditions === [] ? '' : ('WHERE ' . implode(' AND ', $conditions));
        $offset = ($page - 1) * $pageSize;

        $fromSql = <<<SQL
            FROM absences a
            INNER JOIN classroom_session_registrations csr ON csr.id = a.registration_id
            INNER JOIN learners l ON l.id = csr.learner_id
            INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
            LEFT JOIN trainings t ON t.id = cs.training_id
            LEFT JOIN training_modules tm ON tm.id = cs.module_id
            LEFT JOIN users u ON u.id = a.validated_by_id
            SQL;

        $totalRows = (int) $connection->fetchOne("SELECT COUNT(*) {$fromSql} {$whereSql}", $params, $types);

        $statsRows = $connection->fetchAllAssociative(
            "SELECT a.status, COUNT(*) AS total {$fromSql} {$whereSql} GROUP BY a.status",
            $params,
            $types
        );
        $statsByStatus = array_fill_keys(self::SETTABLE_STATUSES, 0);
        foreach ($statsRows as $row) {
            $statsByStatus[$row['status']] = (int) $row['total'];
        }

        $rows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    a.id, a.type, a.status, a.detected_at AS detectedAt, a.admin_note AS adminNote,
                    a.justification_submitted_at AS justificationSubmittedAt,
                    a.justification_file_original_name AS justificationFileOriginalName,
                    a.validated_at AS validatedAt,
                    l.id AS learnerId, l.first_name AS learnerFirstName, l.last_name AS learnerLastName, l.email AS learnerEmail,
                    cs.id AS sessionId, cs.start_at AS sessionStartAt, cs.end_at AS sessionEndAt,
                    COALESCE(t.title, tm.title, 'Session') AS sessionTitle,
                    u.first_name AS validatedByFirstName, u.last_name AS validatedByLastName
                {$fromSql}
                {$whereSql}
                ORDER BY a.detected_at DESC, a.id DESC
                LIMIT {$pageSize} OFFSET {$offset}
            SQL,
            $params,
            $types
        );

        $availableGroups = $connection->fetchAllAssociative(
            <<<SQL
                SELECT DISTINCT rg.external_id AS externalId, rg.name
                FROM riseup_groups rg
                INNER JOIN riseup_learner_groups lg ON lg.group_id = rg.id
                INNER JOIN classroom_session_registrations csr ON csr.learner_id = lg.learner_id
                INNER JOIN absences a ON a.registration_id = csr.id
                ORDER BY rg.name ASC
            SQL
        );

        return $this->json([
            'absences' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'type' => $row['type'],
                'status' => $row['status'],
                'detectedAt' => $row['detectedAt'],
                'adminNote' => $row['adminNote'],
                'justificationSubmittedAt' => $row['justificationSubmittedAt'],
                'justificationFileOriginalName' => $row['justificationFileOriginalName'],
                'validatedAt' => $row['validatedAt'],
                'learner' => [
                    'id' => (int) $row['learnerId'],
                    'fullName' => trim(sprintf('%s %s', (string) $row['learnerFirstName'], (string) $row['learnerLastName'])),
                    'email' => $row['learnerEmail'],
                ],
                'session' => [
                    'id' => (int) $row['sessionId'],
                    'title' => $row['sessionTitle'],
                    'startAt' => $row['sessionStartAt'],
                    'endAt' => $row['sessionEndAt'],
                ],
                'validatedByName' => $row['validatedByFirstName'] !== null
                    ? trim(sprintf('%s %s', (string) $row['validatedByFirstName'], (string) $row['validatedByLastName']))
                    : null,
            ], $rows),
            'stats' => [
                'total' => $totalRows,
                'byStatus' => $statsByStatus,
            ],
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'totalRows' => $totalRows,
                'totalPages' => max(1, (int) ceil($totalRows / $pageSize)),
            ],
            'filters' => [
                'availableGroups' => array_map(static fn (array $row): array => [
                    'externalId' => (int) $row['externalId'],
                    'name' => $row['name'],
                ], $availableGroups),
            ],
        ]);
    }

    // Valide, rejette, remet en attente ou reclasse une absence, et/ou met à jour la note interne
    // admin (roadmap 3.2, étape 4 / 3.3). Un email de confirmation est envoyé à l'apprenant
    // uniquement lorsque le statut change effectivement vers l'un des 3 statuts définitifs.
    #[Route('/{id}', name: 'api_admin_absences_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'absences.manage')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $absence = $this->entityManager->getRepository(Absence::class)->find($id);

        if (!$absence instanceof Absence) {
            return $this->json(['message' => 'Absence introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $data = $request->toArray();
        $previousStatus = $absence->getStatus();

        if (array_key_exists('status', $data)) {
            $status = (string) $data['status'];

            if (!in_array($status, self::SETTABLE_STATUSES, true)) {
                return $this->json(['message' => 'Statut invalide.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }

            $absence->setStatus($status);
        }

        if (array_key_exists('adminNote', $data)) {
            $note = $data['adminNote'];
            $absence->setAdminNote($note !== null ? trim((string) $note) : null);
        }

        $statusChanged = $absence->getStatus() !== $previousStatus;
        $statusChangedToFinal = $statusChanged && in_array($absence->getStatus(), self::FINAL_STATUSES, true);

        if ($statusChangedToFinal) {
            $absence->setValidation(new \DateTimeImmutable(), $user);
            $this->absenceNotificationService->sendConfirmation($absence);
        }

        // Flush avant le recalcul de série : AbsenceStreakService lit les statuts d'absence via une
        // requête DQL (donc directement en base), le nouveau statut doit déjà y être persisté.
        $this->entityManager->flush();

        if ($statusChanged && $absence->getType() === Absence::TYPE_MASTERCLASS) {
            $this->absenceStreakService->recompute($absence->getRegistration()->getLearner());
            $this->entityManager->flush();
        }

        return $this->json($this->normalize($absence));
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(Absence $absence): array
    {
        $validatedBy = $absence->getValidatedBy();

        return [
            'id' => $absence->getId(),
            'type' => $absence->getType(),
            'status' => $absence->getStatus(),
            'adminNote' => $absence->getAdminNote(),
            'justificationSubmittedAt' => $absence->getJustificationSubmittedAt()?->format(DATE_ATOM),
            'validatedAt' => $absence->getValidatedAt()?->format(DATE_ATOM),
            'validatedByName' => $validatedBy instanceof User
                ? trim(sprintf('%s %s', $validatedBy->getFirstName(), $validatedBy->getLastName()))
                : null,
        ];
    }
}
