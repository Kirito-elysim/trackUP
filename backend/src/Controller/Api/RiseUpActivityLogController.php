<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\RiseUpActivityLogFilters;
use App\Repository\RiseUpActivityLogRepository;
use App\Service\RiseUpActivityLogImportService;
use App\Service\UserPermissionResolver;
use App\Util\DurationUnit;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/riseup-activity-logs')]
class RiseUpActivityLogController extends AbstractController
{
    public function __construct(
        private readonly RiseUpActivityLogRepository $repository,
        private readonly UserPermissionResolver $permissionResolver,
        private readonly RiseUpActivityLogImportService $importService,
    ) {
    }

    #[Route('', name: 'api_riseup_activity_logs_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'exports.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $page = max((int) $request->query->get('page', 1), 1);
        $pageSize = min(max((int) $request->query->get('pageSize', 100), 1), 500);
        $offset = ($page - 1) * $pageSize;
        $filters = $this->filtersFromRequest($request);

        $metrics = $this->repository->countAndAggregate($filters);
        $totalRows = $metrics['logCount'];
        $rows = $this->repository->findFiltered($filters, $pageSize, $offset);

        return $this->json([
            'filters' => [
                'learnerQuery' => $filters->learnerQuery,
                'groupExternalId' => $filters->groupExternalId,
                'learningPathId' => $filters->learningPathId,
                'trainingExternalId' => $filters->trainingExternalId,
                'dateFrom' => $filters->dateFrom?->format('Y-m-d'),
                'dateTo' => $filters->dateTo?->format('Y-m-d'),
                'availableGroups' => $this->repository->findAvailableGroups($filters->learnerQuery),
                'availableLearningPaths' => $this->repository->findAvailableLearningPaths($filters),
                'availableTrainings' => $this->repository->findAvailableTrainings($filters),
            ],
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'totalRows' => $totalRows,
                'totalPages' => max(1, (int) ceil($totalRows / $pageSize)),
            ],
            'metrics' => [
                'logCount' => $metrics['logCount'],
                'uniqueLearnersCount' => $metrics['uniqueLearnersCount'],
                'uniqueTrainingsCount' => $metrics['uniqueTrainingsCount'],
                'totalDurationSeconds' => $metrics['totalDurationSeconds'],
                'totalDurationMinutes' => DurationUnit::secondsToMinutesInt($metrics['totalDurationSeconds']),
            ],
            'rows' => array_map(static fn (array $row): array => [
                'id' => $row['id'],
                'sourceFileName' => $row['sourceFileName'],
                'sourceImportedAt' => $row['sourceImportedAt'],
                'trainingExternalId' => (int) $row['trainingExternalId'],
                'trainingTitle' => $row['trainingTitle'],
                'learnerExternalId' => $row['learnerExternalId'] !== null ? (int) $row['learnerExternalId'] : null,
                'learnerEmail' => $row['learnerEmail'],
                'learnerFullName' => $row['learnerFullName'],
                'loginAt' => $row['loginAt'],
                'logoutAt' => $row['logoutAt'],
                'durationSeconds' => (int) $row['durationSeconds'],
                'durationMinutes' => DurationUnit::secondsToMinutesInt($row['durationSeconds']),
                'device' => $row['device'],
                'createdAt' => $row['createdAt'],
                'sourceType' => $row['sourceType'],
            ], $rows),
            'groupContext' => $filters->groupExternalId !== null ? $this->repository->findGroupContext($filters->groupExternalId) : null,
            'lastImportAt' => $this->repository->lastImportedAt(),
        ]);
    }

    #[Route('/export', name: 'api_riseup_activity_logs_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'exports.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $rows = $this->repository->findFiltered($this->filtersFromRequest($request));

        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to create CSV export buffer.');
        }

        $exportDate = (new \DateTimeImmutable())->format('d/m/Y H:i:s');
        $totalDurationSeconds = 0;

        // En-têtes
        fputcsv($handle, [
            "Date d'export",
            'Email',
            'Nom',
            'Prénom',
            'Type',
            'Connexion',
            'Déconnexion',
            'Durée',
        ], ';');

        // Données
        foreach ($rows as $row) {
            $fullName = $row['learnerFullName'] ?? '';
            $nameParts = explode(' ', trim($fullName), 2);
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';

            $durationSeconds = (int) $row['durationSeconds'];
            $totalDurationSeconds += $durationSeconds;

            fputcsv($handle, [
                $exportDate,
                $row['learnerEmail'] ?? '',
                $lastName,
                $firstName,
                $row['sourceType'] === 'session' ? 'Classe virtuelle' : 'E-learning',
                $row['loginAt'] ?? '',
                $row['logoutAt'] ?? '',
                $this->formatDurationClock($durationSeconds),
            ], ';');
        }

        // Ligne de total
        fputcsv($handle, [
            '',
            '',
            '',
            '',
            '',
            '',
            'TOTAL',
            $this->formatDurationClock($totalDurationSeconds),
        ], ';');

        rewind($handle);
        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        $response = new Response($content);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="%s"', sprintf('riseup-activity-logs-%s.csv', (new \DateTimeImmutable())->format('Y-m-d')))
        );

        return $response;
    }

    #[Route('/import', name: 'api_riseup_activity_logs_import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'activity_logs.import')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');

        if ($file === null) {
            return $this->json([
                'success' => false,
                'message' => 'Aucun fichier fourni.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['xlsx', 'csv'], true)) {
            return $this->json([
                'success' => false,
                'message' => 'Le fichier doit être au format XLSX ou CSV.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->importService->import($file->getPathname(), $extension);

            return $this->json([
                'success' => true,
                'message' => sprintf(
                    'Import terminé avec succès : %d ligne(s) importée(s) sur %d ligne(s) analysée(s), %d ligne(s) ignorée(s)',
                    $result['imported'],
                    $result['parsed'],
                    $result['skipped']
                ),
                'parsed' => $result['parsed'],
                'imported' => $result['imported'],
                'skipped' => $result['skipped'],
                'fileName' => $file->getClientOriginalName(),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors de l\'import : ' . $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function filtersFromRequest(Request $request): RiseUpActivityLogFilters
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

        return new RiseUpActivityLogFilters(
            learnerQuery: $this->normalizeSearchString($request->query->get('learnerQuery')),
            groupExternalId: $this->positiveIntOrNull($request->query->get('groupExternalId')),
            learningPathId: $this->positiveIntOrNull($request->query->get('learningPathId')),
            trainingExternalId: $this->positiveIntOrNull($request->query->get('trainingExternalId')),
            dateFrom: $dateFrom,
            dateTo: $dateTo,
        );
    }

    /**
     * @return array{0:? \DateTimeImmutable,1:? \DateTimeImmutable}
     */
    private function resolveDateRange(Request $request): array
    {
        $dateFrom = $this->parseDate($request->query->get('dateFrom'), false);
        $dateTo = $this->parseDate($request->query->get('dateTo'), true);

        if ($dateFrom instanceof \DateTimeImmutable && $dateTo instanceof \DateTimeImmutable && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo->setTime(0, 0), $dateFrom->setTime(23, 59, 59)];
        }

        return [$dateFrom, $dateTo];
    }

    private function parseDate(mixed $value, bool $endOfDay): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', trim($value));
        if (!$date instanceof \DateTimeImmutable) {
            return null;
        }

        return $endOfDay ? $date->setTime(23, 59, 59) : $date->setTime(0, 0);
    }

    private function formatDurationClock(int $durationSeconds): string
    {
        $hours = intdiv(max(0, $durationSeconds), 3600);
        $minutes = intdiv(max(0, $durationSeconds) % 3600, 60);
        $seconds = max(0, $durationSeconds) % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function normalizeSearchString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
