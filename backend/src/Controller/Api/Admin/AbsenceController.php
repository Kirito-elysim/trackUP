<?php
declare(strict_types=1);

namespace App\Controller\Api\Admin;

use App\Entity\Absence;
use App\Entity\AbsenceEvent;
use App\Entity\Learner;
use App\Entity\User;
use App\Service\AbsenceEventLogger;
use App\Service\AbsenceNotificationService;
use App\Service\AbsenceStreakService;
use App\Service\UserPermissionResolver;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
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
        private readonly AbsenceEventLogger $absenceEventLogger,
        private readonly string $uploadDir,
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
        $hasStatusFilter = $status !== '' && in_array($status, self::SETTABLE_STATUSES, true);

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

        // Filtre "à vérifier" (badge de nav "Absences") : justificatif déposé par l'apprenant, pas
        // encore traité par un admin. Implique status=en_attente (un justificatif validé/rejeté n'a
        // plus rien "à vérifier"), donc combiné avec les onglets de statut cela ne peut donner de
        // résultats que sur l'onglet "En attente".
        $pendingReviewOnly = $request->query->get('pendingReview') === '1';
        if ($pendingReviewOnly) {
            $conditions[] = "a.justification_submitted_at IS NOT NULL AND a.status = 'en_attente'";
        }

        // Les compteurs par statut (onglets "Toutes / En attente / ...") doivent rester stables quel
        // que soit l'onglet sélectionné : ils respectent les autres filtres (apprenant, groupe, type,
        // période) mais jamais le filtre de statut lui-même, sinon les onglets non sélectionnés
        // retomberaient tous à 0.
        $statsWhereSql = $conditions === [] ? '' : ('WHERE ' . implode(' AND ', $conditions));

        if ($hasStatusFilter) {
            $conditions[] = 'a.status = :status';
            $params['status'] = $status;
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
            "SELECT a.status, COUNT(*) AS total {$fromSql} {$statsWhereSql} GROUP BY a.status",
            $params,
            $types
        );
        $statsByStatus = array_fill_keys(self::SETTABLE_STATUSES, 0);
        foreach ($statsRows as $row) {
            $statsByStatus[$row['status']] = (int) $row['total'];
        }
        $statsTotal = array_sum($statsByStatus);

        // Compteur du chip "Justificatif à vérifier" : respecte les autres filtres actifs (apprenant,
        // groupe, type, période), comme les onglets de statut, mais jamais le toggle pendingReview
        // lui-même (sinon il retomberait à 0 quand il est déjà actif).
        $pendingReviewCondition = "a.justification_submitted_at IS NOT NULL AND a.status = 'en_attente'";
        $pendingReviewSql = $statsWhereSql === '' ? "WHERE {$pendingReviewCondition}" : "{$statsWhereSql} AND {$pendingReviewCondition}";
        $pendingReviewCount = (int) $connection->fetchOne("SELECT COUNT(*) {$fromSql} {$pendingReviewSql}", $params, $types);

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
                'total' => $statsTotal,
                'byStatus' => $statsByStatus,
                'pendingReviewCount' => $pendingReviewCount,
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

    // Détail complet d'une absence (sous-section Absences : page /absences/:id).
    #[Route('/{id}', name: 'api_admin_absences_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'absences.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $connection = $this->entityManager->getConnection();

        $row = $connection->fetchAssociative(
            <<<SQL
                SELECT
                    a.id, a.type, a.status, a.detected_at AS detectedAt,
                    a.notification_sent_at AS notificationSentAt,
                    a.justification_token AS justificationToken,
                    a.justification_token_expires_at AS justificationTokenExpiresAt,
                    a.justification_submitted_at AS justificationSubmittedAt,
                    a.justification_file_path AS justificationFilePath,
                    a.justification_file_original_name AS justificationFileOriginalName,
                    a.confirmation_sent_at AS confirmationSentAt,
                    a.validated_at AS validatedAt, a.admin_note AS adminNote,
                    l.id AS learnerId, l.first_name AS learnerFirstName, l.last_name AS learnerLastName, l.email AS learnerEmail,
                    l.consecutive_unjustified_masterclass_absences AS consecutiveCount,
                    l.disciplinary_alert_sent_at AS disciplinaryAlertSentAt,
                    cs.id AS sessionId, cs.start_at AS sessionStartAt, cs.end_at AS sessionEndAt,
                    COALESCE(t.title, tm.title, 'Session') AS sessionTitle,
                    u.first_name AS validatedByFirstName, u.last_name AS validatedByLastName
                FROM absences a
                INNER JOIN classroom_session_registrations csr ON csr.id = a.registration_id
                INNER JOIN learners l ON l.id = csr.learner_id
                INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
                LEFT JOIN trainings t ON t.id = cs.training_id
                LEFT JOIN training_modules tm ON tm.id = cs.module_id
                LEFT JOIN users u ON u.id = a.validated_by_id
                WHERE a.id = :id
            SQL,
            ['id' => $id],
            ['id' => ParameterType::INTEGER]
        );

        if ($row === false) {
            return $this->json(['message' => 'Absence introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id' => (int) $row['id'],
            'type' => $row['type'],
            'status' => $row['status'],
            'detectedAt' => $row['detectedAt'],
            'notificationSentAt' => $row['notificationSentAt'],
            'hasActiveJustificationToken' => $row['justificationToken'] !== null
                && ($row['justificationTokenExpiresAt'] === null || $row['justificationTokenExpiresAt'] > date('Y-m-d H:i:s')),
            'justificationTokenExpiresAt' => $row['justificationTokenExpiresAt'],
            'justificationSubmittedAt' => $row['justificationSubmittedAt'],
            'justificationFileOriginalName' => $row['justificationFileOriginalName'],
            'confirmationSentAt' => $row['confirmationSentAt'],
            'validatedAt' => $row['validatedAt'],
            'adminNote' => $row['adminNote'],
            'learner' => [
                'id' => (int) $row['learnerId'],
                'fullName' => trim(sprintf('%s %s', (string) $row['learnerFirstName'], (string) $row['learnerLastName'])),
                'email' => $row['learnerEmail'],
                'consecutiveUnjustifiedMasterclassAbsences' => (int) $row['consecutiveCount'],
                'alertTriggered' => $row['disciplinaryAlertSentAt'] !== null,
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
            'justificationFileAvailable' => $row['justificationFilePath'] !== null,
            'events' => $this->fetchAbsenceEvents($id),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAbsenceEvents(int $absenceId): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            <<<SQL
                SELECT
                    ae.type, ae.occurred_at AS occurredAt, ae.metadata,
                    u.first_name AS actorFirstName, u.last_name AS actorLastName
                FROM absence_events ae
                LEFT JOIN users u ON u.id = ae.actor_id
                WHERE ae.absence_id = :id
                ORDER BY ae.occurred_at DESC, ae.id DESC
            SQL,
            ['id' => $absenceId],
            ['id' => ParameterType::INTEGER]
        );

        return array_map(static fn (array $row): array => [
            'type' => $row['type'],
            'occurredAt' => $row['occurredAt'],
            'actorName' => $row['actorFirstName'] !== null
                ? trim(sprintf('%s %s', (string) $row['actorFirstName'], (string) $row['actorLastName']))
                : null,
            'metadata' => json_decode((string) $row['metadata'], true) ?? [],
        ], $rows);
    }

    // Visualisation du justificatif déposé (carte "Historique" côté fiche absence) : le stockage est
    // sur disque, jamais servi statiquement, donc ce endpoint est le seul moyen pour un admin de voir
    // le PDF/image envoyé par l'apprenant. `inline` pour un aperçu direct dans un nouvel onglet.
    #[Route('/{id}/justification-file', name: 'api_admin_absences_justification_file', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function justificationFile(int $id): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'absences.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $absence = $this->entityManager->getRepository(Absence::class)->find($id);
        $filePath = $absence?->getJustificationFilePath();

        if ($absence === null || $filePath === null) {
            return $this->json(['message' => 'Aucun justificatif disponible.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $fullPath = $this->uploadDir . '/' . $filePath;
        if (!is_file($fullPath)) {
            return $this->json(['message' => 'Fichier introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $mimeTypes = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

        $response = new BinaryFileResponse($fullPath);
        $response->headers->set('Content-Type', $mimeTypes[$extension] ?? 'application/octet-stream');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $absence->getJustificationFileOriginalName() ?? $filePath
        );

        return $response;
    }

    // Sous-section Absences : agrégats pour la page /absences/dashboard.
    #[Route('/dashboard', name: 'api_admin_absences_dashboard', methods: ['GET'])]
    public function dashboard(): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'absences.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $connection = $this->entityManager->getConnection();

        $total = (int) $connection->fetchOne('SELECT COUNT(*) FROM absences');

        $statsRows = $connection->fetchAllAssociative('SELECT status, COUNT(*) AS total FROM absences GROUP BY status');
        $statsByStatus = array_fill_keys(self::SETTABLE_STATUSES, 0);
        foreach ($statsRows as $row) {
            $statsByStatus[$row['status']] = (int) $row['total'];
        }

        $byGroupRows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT rg.name, COUNT(*) AS total
                FROM absences a
                INNER JOIN classroom_session_registrations csr ON csr.id = a.registration_id
                INNER JOIN riseup_learner_groups lg ON lg.learner_id = csr.learner_id
                INNER JOIN riseup_groups rg ON rg.id = lg.group_id
                GROUP BY rg.id, rg.name
                ORDER BY total DESC
                LIMIT 8
            SQL
        );

        $recentRows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    a.id, a.type, a.status, a.detected_at AS detectedAt,
                    l.first_name AS learnerFirstName, l.last_name AS learnerLastName,
                    l.disciplinary_alert_sent_at AS disciplinaryAlertSentAt,
                    cs.start_at AS sessionStartAt,
                    COALESCE(t.title, tm.title, 'Session') AS sessionTitle
                FROM absences a
                INNER JOIN classroom_session_registrations csr ON csr.id = a.registration_id
                INNER JOIN learners l ON l.id = csr.learner_id
                INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
                LEFT JOIN trainings t ON t.id = cs.training_id
                LEFT JOIN training_modules tm ON tm.id = cs.module_id
                ORDER BY a.detected_at DESC, a.id DESC
                LIMIT 6
            SQL
        );

        $alertsPreviewRows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    l.id, l.first_name AS firstName, l.last_name AS lastName,
                    l.consecutive_unjustified_masterclass_absences AS consecutiveCount,
                    (SELECT rg.name FROM riseup_learner_groups lg
                        INNER JOIN riseup_groups rg ON rg.id = lg.group_id
                        WHERE lg.learner_id = l.id ORDER BY lg.synced_at DESC LIMIT 1) AS groupName
                FROM learners l
                WHERE l.disciplinary_alert_sent_at IS NOT NULL
                ORDER BY l.consecutive_unjustified_masterclass_absences DESC
                LIMIT 5
            SQL
        );

        $activeAlertsCount = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM learners WHERE disciplinary_alert_sent_at IS NOT NULL'
        );

        // Justificatifs déposés mais pas encore traités par un admin (statut toujours en_attente) —
        // alerte demandée pour le Dashboard : "j'ai reçu un justificatif pour vérifier".
        $pendingReviewCount = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM absences WHERE status = 'en_attente' AND justification_submitted_at IS NOT NULL"
        );

        // Suivi des séries (roadmap 3.4) : le compteur de relances d'un apprenant ne compte que ses
        // absences détectées après son propre absence_counter_reset_at (reset manuel ou global) — les
        // absences antérieures à cette date ne sont donc jamais prises en compte pour le déclenchement
        // d'une alerte. Affiché tel quel côté admin pour éviter la confusion "pourquoi pas d'alerte
        // alors qu'il y a plein d'absences en attente ?".
        $streakTrackingRow = $connection->fetchAssociative(
            'SELECT MAX(absence_counter_reset_at) AS resetAt, COUNT(*) AS affectedCount
             FROM learners WHERE absence_counter_reset_at IS NOT NULL'
        );

        return $this->json([
            'stats' => ['total' => $total, 'byStatus' => $statsByStatus],
            'byGroup' => array_map(static fn (array $row): array => [
                'name' => $row['name'],
                'count' => (int) $row['total'],
            ], $byGroupRows),
            'recent' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'type' => $row['type'],
                'status' => $row['status'],
                'detectedAt' => $row['detectedAt'],
                'learnerFullName' => trim(sprintf('%s %s', (string) $row['learnerFirstName'], (string) $row['learnerLastName'])),
                'alertTriggered' => $row['disciplinaryAlertSentAt'] !== null,
                'sessionTitle' => $row['sessionTitle'],
                'sessionStartAt' => $row['sessionStartAt'],
            ], $recentRows),
            'activeAlertsCount' => $activeAlertsCount,
            'pendingReviewCount' => $pendingReviewCount,
            'activeAlertsPreview' => array_map(static fn (array $row): array => [
                'learnerId' => (int) $row['id'],
                'fullName' => trim(sprintf('%s %s', (string) $row['firstName'], (string) $row['lastName'])),
                'group' => $row['groupName'],
                'consecutiveCount' => (int) $row['consecutiveCount'],
            ], $alertsPreviewRows),
            'streakTracking' => [
                'resetAt' => $streakTrackingRow['resetAt'] ?: null,
                'affectedLearnersCount' => (int) ($streakTrackingRow['affectedCount'] ?? 0),
            ],
        ]);
    }

    // Sous-section Absences : graphique d'évolution (page /absences/dashboard) — nombre d'absences
    // détectées par période (année/mois/jour), ventilé par statut pour un histogramme empilé.
    #[Route('/evolution', name: 'api_admin_absences_evolution', methods: ['GET'])]
    public function evolution(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'absences.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $granularity = (string) $request->query->get('granularity', 'month');
        if (!in_array($granularity, ['year', 'month', 'day'], true)) {
            $granularity = 'month';
        }

        $connection = $this->entityManager->getConnection();
        $dateFormat = match ($granularity) {
            'year' => '%Y',
            'day' => '%Y-%m-%d',
            default => '%Y-%m',
        };

        $sql = <<<SQL
            SELECT DATE_FORMAT(cs.start_at, :dateFormat) AS period, a.status, COUNT(*) AS total
            FROM absences a
            INNER JOIN classroom_session_registrations csr ON csr.id = a.registration_id
            INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
            WHERE cs.start_at IS NOT NULL
        SQL;
        $params = ['dateFormat' => $dateFormat];

        // Granularité "jour" limitée aux 60 derniers jours : sur ~1 an d'historique, un point par jour
        // sur toute la période rendrait le graphique illisible.
        if ($granularity === 'day') {
            $since = (new \DateTimeImmutable('today'))->modify('-59 days');
            $sql .= ' AND cs.start_at >= :since';
            $params['since'] = $since->format('Y-m-d 00:00:00');
        }

        $sql .= ' GROUP BY period, a.status';

        $rows = $connection->fetchAllAssociative($sql, $params);

        $countsByPeriod = [];
        $minPeriod = null;
        $maxPeriod = null;
        foreach ($rows as $row) {
            $period = (string) $row['period'];
            $countsByPeriod[$period][$row['status']] = (int) $row['total'];
            if ($minPeriod === null || $period < $minPeriod) {
                $minPeriod = $period;
            }
            if ($maxPeriod === null || $period > $maxPeriod) {
                $maxPeriod = $period;
            }
        }

        $periods = $this->buildEvolutionPeriods($granularity, $minPeriod, $maxPeriod);

        $series = array_map(function (string $period) use ($countsByPeriod): array {
            $counts = $countsByPeriod[$period] ?? [];
            $entry = ['period' => $period];
            $total = 0;
            foreach (self::SETTABLE_STATUSES as $status) {
                $count = $counts[$status] ?? 0;
                $entry[$status] = $count;
                $total += $count;
            }
            $entry['total'] = $total;

            return $entry;
        }, $periods);

        return $this->json(['granularity' => $granularity, 'series' => $series]);
    }

    /**
     * @return array<int, string>
     */
    private function buildEvolutionPeriods(string $granularity, ?string $min, ?string $max): array
    {
        if ($granularity === 'day') {
            $cursor = (new \DateTimeImmutable('today'))->modify('-59 days');
            $end = new \DateTimeImmutable('today');
            $periods = [];
            while ($cursor <= $end) {
                $periods[] = $cursor->format('Y-m-d');
                $cursor = $cursor->modify('+1 day');
            }

            return $periods;
        }

        if ($min === null || $max === null) {
            return [];
        }

        if ($granularity === 'year') {
            $periods = [];
            for ($year = (int) $min; $year <= (int) $max; ++$year) {
                $periods[] = (string) $year;
            }

            return $periods;
        }

        $cursor = \DateTimeImmutable::createFromFormat('Y-m-d', $min . '-01');
        $end = \DateTimeImmutable::createFromFormat('Y-m-d', $max . '-01');
        $periods = [];
        while ($cursor <= $end) {
            $periods[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }

        return $periods;
    }

    // Badge de notification sur l'item de nav "Alertes" (sidebar) : nombre d'apprenants en alerte
    // disciplinaire active, sans le reste du payload de /alerts (chargé à chaque rendu de la sidebar).
    #[Route('/alerts/count', name: 'api_admin_absences_alerts_count', methods: ['GET'])]
    public function alertsCount(): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'absences.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $count = (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM learners WHERE disciplinary_alert_sent_at IS NOT NULL'
        );

        return $this->json(['count' => $count]);
    }

    // Badge de notification sur l'item de nav "Absences" (sidebar) : nombre de justificatifs déposés
    // par des apprenants et pas encore traités par un admin — même calcul que
    // dashboard()['pendingReviewCount'], exposé séparément pour être chargé par la sidebar sans le
    // reste du payload du dashboard.
    #[Route('/pending-review/count', name: 'api_admin_absences_pending_review_count', methods: ['GET'])]
    public function pendingReviewCount(): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'absences.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $count = (int) $this->entityManager->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM absences WHERE status = 'en_attente' AND justification_submitted_at IS NOT NULL"
        );

        return $this->json(['count' => $count]);
    }

    // Sous-section Absences : page /absences/alertes — apprenants en alerte active et "à surveiller".
    #[Route('/alerts', name: 'api_admin_absences_alerts', methods: ['GET'])]
    public function alerts(): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'absences.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $connection = $this->entityManager->getConnection();

        $alertedLearners = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    l.id, l.first_name AS firstName, l.last_name AS lastName, l.email,
                    l.consecutive_unjustified_masterclass_absences AS consecutiveCount,
                    (SELECT rg.name FROM riseup_learner_groups lg
                        INNER JOIN riseup_groups rg ON rg.id = lg.group_id
                        WHERE lg.learner_id = l.id ORDER BY lg.synced_at DESC LIMIT 1) AS groupName
                FROM learners l
                WHERE l.disciplinary_alert_sent_at IS NOT NULL
                ORDER BY l.consecutive_unjustified_masterclass_absences DESC
            SQL
        );

        $alerted = [];
        foreach ($alertedLearners as $learnerRow) {
            $recentAbsences = $connection->fetchAllAssociative(
                <<<SQL
                    SELECT a.id, a.status, cs.start_at AS sessionStartAt, COALESCE(t.title, tm.title, 'Session') AS sessionTitle
                    FROM absences a
                    INNER JOIN classroom_session_registrations csr ON csr.id = a.registration_id
                    INNER JOIN classroom_sessions cs ON cs.id = csr.session_id
                    LEFT JOIN trainings t ON t.id = cs.training_id
                    LEFT JOIN training_modules tm ON tm.id = cs.module_id
                    WHERE csr.learner_id = :learnerId AND a.type = 'masterclass'
                        AND a.status IN ('en_attente', 'non_justifiee')
                    ORDER BY cs.start_at DESC
                    LIMIT 4
                SQL,
                ['learnerId' => (int) $learnerRow['id']],
                ['learnerId' => ParameterType::INTEGER]
            );

            $alerted[] = [
                'learnerId' => (int) $learnerRow['id'],
                'fullName' => trim(sprintf('%s %s', (string) $learnerRow['firstName'], (string) $learnerRow['lastName'])),
                'email' => $learnerRow['email'],
                'group' => $learnerRow['groupName'],
                'consecutiveCount' => (int) $learnerRow['consecutiveCount'],
                'recentAbsences' => array_map(static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'status' => $row['status'],
                    'sessionTitle' => $row['sessionTitle'],
                    'sessionStartAt' => $row['sessionStartAt'],
                ], $recentAbsences),
            ];
        }

        $atRiskRows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    l.id, l.first_name AS firstName, l.last_name AS lastName,
                    l.consecutive_unjustified_masterclass_absences AS consecutiveCount,
                    (SELECT rg.name FROM riseup_learner_groups lg
                        INNER JOIN riseup_groups rg ON rg.id = lg.group_id
                        WHERE lg.learner_id = l.id ORDER BY lg.synced_at DESC LIMIT 1) AS groupName
                FROM learners l
                WHERE l.disciplinary_alert_sent_at IS NULL AND l.consecutive_unjustified_masterclass_absences > 0
                ORDER BY l.consecutive_unjustified_masterclass_absences DESC
            SQL
        );

        return $this->json([
            'alerted' => $alerted,
            'atRisk' => array_map(static fn (array $row): array => [
                'learnerId' => (int) $row['id'],
                'fullName' => trim(sprintf('%s %s', (string) $row['firstName'], (string) $row['lastName'])),
                'group' => $row['groupName'],
                'consecutiveCount' => (int) $row['consecutiveCount'],
            ], $atRiskRows),
        ]);
    }

    // Sous-section Absences : page /absences/apprenants — annuaire agrégé par apprenant.
    #[Route('/learners', name: 'api_admin_absences_learners', methods: ['GET'])]
    public function learners(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'absences.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $connection = $this->entityManager->getConnection();

        $conditions = [
            'EXISTS (SELECT 1 FROM absences a INNER JOIN classroom_session_registrations csr '
            . 'ON csr.id = a.registration_id WHERE csr.learner_id = l.id)',
        ];
        $params = [];
        $types = [];

        $search = trim((string) $request->query->get('search', ''));
        if ($search !== '') {
            $conditions[] = '(l.first_name LIKE :search OR l.last_name LIKE :search OR l.email LIKE :search)';
            $params['search'] = '%' . $search . '%';
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

        $whereSql = 'WHERE ' . implode(' AND ', $conditions);

        $rows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    l.id, l.first_name AS firstName, l.last_name AS lastName, l.email,
                    l.consecutive_unjustified_masterclass_absences AS consecutiveCount,
                    l.disciplinary_alert_sent_at AS disciplinaryAlertSentAt,
                    (SELECT rg.name FROM riseup_learner_groups lg
                        INNER JOIN riseup_groups rg ON rg.id = lg.group_id
                        WHERE lg.learner_id = l.id ORDER BY lg.synced_at DESC LIMIT 1) AS groupName,
                    (SELECT COUNT(*) FROM absences a INNER JOIN classroom_session_registrations csr
                        ON csr.id = a.registration_id WHERE csr.learner_id = l.id) AS totalAbsences,
                    (SELECT COUNT(*) FROM absences a INNER JOIN classroom_session_registrations csr
                        ON csr.id = a.registration_id WHERE csr.learner_id = l.id AND a.status = 'justifiee') AS justifiedCount,
                    (SELECT COUNT(*) FROM absences a INNER JOIN classroom_session_registrations csr
                        ON csr.id = a.registration_id WHERE csr.learner_id = l.id AND a.status = 'non_justifiee') AS unjustifiedCount,
                    (SELECT COUNT(*) FROM absences a INNER JOIN classroom_session_registrations csr
                        ON csr.id = a.registration_id WHERE csr.learner_id = l.id AND a.status = 'en_attente') AS pendingCount
                FROM learners l
                {$whereSql}
                ORDER BY l.last_name ASC, l.first_name ASC
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
            'learners' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'fullName' => trim(sprintf('%s %s', (string) $row['firstName'], (string) $row['lastName'])),
                'email' => $row['email'],
                'group' => $row['groupName'],
                'totalAbsences' => (int) $row['totalAbsences'],
                'justified' => (int) $row['justifiedCount'],
                'unjustified' => (int) $row['unjustifiedCount'],
                'pending' => (int) $row['pendingCount'],
                'consecutiveUnjustifiedMasterclassAbsences' => (int) $row['consecutiveCount'],
                'alertActive' => $row['disciplinaryAlertSentAt'] !== null,
            ], $rows),
            'filters' => [
                'availableGroups' => array_map(static fn (array $row): array => [
                    'externalId' => (int) $row['externalId'],
                    'name' => $row['name'],
                ], $availableGroups),
            ],
        ]);
    }

    // Sous-section Absences : renvoie manuellement l'email d'alerte disciplinaire (page /absences/alertes).
    #[Route('/alerts/{learnerId}/resend', name: 'api_admin_absences_alerts_resend', methods: ['POST'], requirements: ['learnerId' => '\d+'])]
    public function resendAlert(int $learnerId): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'absences.manage')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $learner = $this->entityManager->getRepository(Learner::class)->find($learnerId);

        if (!$learner instanceof Learner) {
            return $this->json(['message' => 'Apprenant introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $this->absenceNotificationService->sendDisciplinaryAlert(
            $learner,
            $learner->getConsecutiveUnjustifiedMasterclassAbsences()
        );

        return $this->json(['message' => "Email d'alerte renvoyé."]);
    }

    // Email disciplinaire envoyé DIRECTEMENT à l'apprenant (page /absences/alertes) — distinct de
    // l'alerte interne automatique à pedagogie@edup-bs.com ci-dessus. Décision explicite de
    // l'utilisateur : jamais automatique, uniquement sur clic d'un admin.
    #[Route('/alerts/{learnerId}/send-disciplinary-email', name: 'api_admin_absences_alerts_send_disciplinary_email', methods: ['POST'], requirements: ['learnerId' => '\d+'])]
    public function sendDisciplinaryEmailToLearner(int $learnerId): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'absences.manage')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $learner = $this->entityManager->getRepository(Learner::class)->find($learnerId);

        if (!$learner instanceof Learner) {
            return $this->json(['message' => 'Apprenant introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        if ($learner->getEmail() === null || $learner->getEmail() === '') {
            return $this->json(
                ['message' => "Cet apprenant n'a pas d'adresse email connue."],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $this->absenceNotificationService->sendDisciplinaryEmailToLearner(
            $learner,
            $learner->getConsecutiveUnjustifiedMasterclassAbsences(),
            $user
        );
        $this->entityManager->flush();

        return $this->json(['message' => 'Email envoyé à ' . $learner->getEmail() . '.']);
    }

    // Relance manuelle (page /absences/{id}) : renvoie l'email à l'apprenant. Par défaut réutilise le
    // même lien (même token, même expiration) tant qu'il est encore valide — décision explicite de
    // l'utilisateur : une relance ne remet pas le délai à zéro. `extend: true` dans le corps de la
    // requête prolonge explicitement l'expiration à 14 jours à partir de maintenant (bouton
    // "Prolonger" séparé côté fiche absence). Sert aussi de rattrapage pour les absences détectées
    // avant l'ajout du token dans AbsenceNotificationService, qui n'en ont jamais reçu.
    #[Route('/{id}/resend-notification', name: 'api_admin_absences_resend_notification', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resendNotification(int $id, Request $request): JsonResponse
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

        if ($absence->getStatus() !== Absence::STATUS_EN_ATTENTE) {
            return $this->json(
                ['message' => "Cette absence n'est plus en attente de justificatif, la relance est désactivée."],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $learnerEmail = $absence->getRegistration()->getLearner()->getEmail();
        if ($learnerEmail === null || $learnerEmail === '') {
            return $this->json(
                ['message' => "Cet apprenant n'a pas d'adresse email connue."],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $extend = $request->getContent() !== '' ? (bool) ($request->toArray()['extend'] ?? false) : false;
        $renewed = $this->absenceNotificationService->resend($absence, $user, $extend);
        $this->entityManager->flush();

        return $this->json([
            'notificationSentAt' => $absence->getNotificationSentAt()?->format(DATE_ATOM),
            'hasActiveJustificationToken' => $absence->getJustificationToken() !== null,
            'justificationTokenExpiresAt' => $absence->getJustificationTokenExpiresAt()?->format(DATE_ATOM),
            'renewed' => $renewed,
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
        $previousNote = $absence->getAdminNote();

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
        $noteChanged = $absence->getAdminNote() !== $previousNote;

        if ($statusChangedToFinal) {
            $absence->setValidation(new \DateTimeImmutable(), $user);
            $this->absenceNotificationService->sendConfirmation($absence, $user);
        }

        if ($statusChanged) {
            $this->absenceEventLogger->log($absence, AbsenceEvent::TYPE_STATUS_CHANGED, $user, [
                'from' => $previousStatus,
                'to' => $absence->getStatus(),
                'emailSent' => $statusChangedToFinal,
            ]);
        } elseif ($noteChanged) {
            $this->absenceEventLogger->log($absence, AbsenceEvent::TYPE_NOTE_ADDED, $user, [
                'note' => $absence->getAdminNote(),
            ]);
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
            // Renvoyé pour que la carte "Historique" se mette à jour sans recharger la page après un
            // PATCH (changement de statut ou note) — évite un second aller-retour GET côté frontend.
            'events' => $this->fetchAbsenceEvents((int) $absence->getId()),
        ];
    }
}
