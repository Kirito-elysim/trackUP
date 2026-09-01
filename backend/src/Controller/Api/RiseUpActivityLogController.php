<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\RiseUpActivityLogFilters;
use App\Repository\RiseUpActivityLogRepository;
use App\Service\RiseUpActivityLogImportService;
use App\Service\UserPermissionResolver;
use App\Util\DurationUnit;
use Dompdf\Dompdf;
use Dompdf\Options as DompdfOptions;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/riseup-activity-logs')]
class RiseUpActivityLogController extends AbstractController
{
    // Dompdf rend un très grand tableau de manière peu efficace en mémoire (chaque ligne
    // supplémentaire coûte de plus en plus cher) ; on limite donc le PDF aux logs les plus récents
    // et on l'indique clairement dans le document. Le CSV, lui, reste sans limite.
    private const PDF_ROW_LIMIT = 800;

    public function __construct(
        private readonly RiseUpActivityLogRepository $repository,
        private readonly UserPermissionResolver $permissionResolver,
        private readonly RiseUpActivityLogImportService $importService,
        private readonly LoggerInterface $logger,
        private readonly string $logoPath,
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

    #[Route('/export-pdf', name: 'api_riseup_activity_logs_export_pdf', methods: ['GET'])]
    public function exportPdf(Request $request): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$this->permissionResolver->userHasFeature($user, 'exports.view')) {
            return $this->json(['message' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $filters = $this->filtersFromRequest($request);
        $rows = $this->repository->findFiltered($filters, self::PDF_ROW_LIMIT);
        $metrics = $this->repository->countAndAggregate($filters);

        // Le rendu PDF (mise en page + police + logo embarqué) est plus gourmand en mémoire que
        // le reste de l'app ; on relève la limite uniquement pour cette requête.
        ini_set('memory_limit', '512M');

        $options = new DompdfOptions();
        $options->setIsRemoteEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setDefaultFont('Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('a4', 'landscape');
        $dompdf->loadHtml($this->buildPdfHtml($rows, $metrics, $filters));
        $dompdf->render();

        $response = new Response($dompdf->output());
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="%s"', sprintf('riseup-activity-logs-%s.pdf', (new \DateTimeImmutable())->format('Y-m-d')))
        );

        return $response;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array{logCount: int, uniqueLearnersCount: int, uniqueTrainingsCount: int, totalDurationSeconds: int} $metrics
     */
    private function buildPdfHtml(array $rows, array $metrics, RiseUpActivityLogFilters $filters): string
    {
        $logoTag = '';
        if (is_file($this->logoPath)) {
            $logoData = base64_encode((string) file_get_contents($this->logoPath));
            $logoTag = sprintf('<img src="data:image/png;base64,%s" alt="TrackUp" class="logo" />', $logoData);
        }

        $exportedAt = (new \DateTimeImmutable())->format('d/m/Y \à H:i');

        $filterLines = [];
        if ($filters->learnerQuery !== null) {
            $filterLines[] = 'Apprenant : ' . $this->escape($filters->learnerQuery);
        }
        if ($filters->dateFrom instanceof \DateTimeImmutable) {
            $filterLines[] = 'Du : ' . $filters->dateFrom->format('d/m/Y');
        }
        if ($filters->dateTo instanceof \DateTimeImmutable) {
            $filterLines[] = 'Au : ' . $filters->dateTo->format('d/m/Y');
        }
        $filtersSummary = $filterLines !== [] ? implode(' &nbsp;·&nbsp; ', $filterLines) : 'Aucun filtre appliqué';

        $isTruncated = $metrics['logCount'] > count($rows);
        $truncationNotice = $isTruncated
            ? sprintf(
                '<p class="notice">Aperçu limité aux %d logs les plus récents sur %d au total pour ces filtres. Affinez les filtres (dates, apprenant...) ou utilisez l\'export CSV pour obtenir l\'intégralité des lignes.</p>',
                count($rows),
                $metrics['logCount'],
            )
            : '';
        $totalRowLabel = $isTruncated ? 'TOTAL (lignes affichées)' : 'TOTAL';

        $totalDurationSeconds = 0;
        $bodyRows = '';
        foreach ($rows as $row) {
            $durationSeconds = (int) $row['durationSeconds'];
            $totalDurationSeconds += $durationSeconds;

            $bodyRows .= sprintf(
                '<tr>
                    <td><span class="badge %s">%s</span></td>
                    <td>%s</td>
                    <td>%s</td>
                    <td class="mono">%s</td>
                    <td>%s<br><span class="muted">%s</span></td>
                    <td>%s</td>
                </tr>',
                $row['sourceType'] === 'session' ? 'badge-session' : 'badge-elearning',
                $row['sourceType'] === 'session' ? 'Classe virtuelle' : 'E-learning',
                $this->escape((string) ($row['loginAt'] ?? '')),
                $this->escape((string) ($row['logoutAt'] ?? '')),
                $this->formatDurationClock($durationSeconds),
                $this->escape((string) ($row['learnerFullName'] ?? '')),
                $this->escape((string) ($row['learnerEmail'] ?? '')),
                $this->escape((string) ($row['trainingTitle'] ?? '')),
            );
        }

        if ($rows === []) {
            $bodyRows = '<tr><td colspan="6" class="empty">Aucun log pour ces filtres.</td></tr>';
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 24px 28px 40px 28px; }
    body { font-family: Helvetica, Arial, sans-serif; color: #1c1330; font-size: 10px; }
    .header { width: 100%; border-bottom: 3px solid #ff0f7b; padding-bottom: 10px; margin-bottom: 14px; }
    .header td { vertical-align: middle; }
    .logo { height: 32px; }
    .header .title { text-align: right; }
    .header h1 { margin: 0; font-size: 16px; color: #1c1330; }
    .header p { margin: 2px 0 0; font-size: 9px; color: #6b6478; }
    .filters { margin-bottom: 12px; font-size: 9px; color: #6b6478; }
    .notice { margin: 0 0 12px; padding: 6px 10px; background: #fff4e5; border: 1px solid #ffd9a8; border-radius: 5px; font-size: 8.5px; color: #8a5a00; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data thead th {
        background: #1c1330; color: #ffffff; text-align: left; padding: 6px 7px;
        font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.04em;
    }
    table.data tbody td { padding: 5px 7px; border-bottom: 1px solid #ece7f2; vertical-align: top; }
    table.data tbody tr:nth-child(even) { background: #faf8fc; }
    .mono { font-family: 'Courier New', monospace; }
    .muted { color: #8a8296; font-size: 8.5px; }
    .empty { text-align: center; padding: 20px; color: #8a8296; }
    .badge { display: inline-block; padding: 2px 7px; border-radius: 9px; font-size: 8px; font-weight: bold; }
    .badge-session { background: #ffe3ee; color: #c2005f; }
    .badge-elearning { background: #e2f0ff; color: #0b5fb3; }
    tfoot td { padding: 7px; font-weight: bold; border-top: 2px solid #1c1330; }
    tfoot .total-value { color: #ff0f7b; }
    .footer { position: fixed; bottom: -28px; left: 0; right: 0; text-align: center; font-size: 8px; color: #8a8296; }
</style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width: 60%;">{$logoTag}</td>
            <td class="title">
                <h1>Historique des activités Rise Up</h1>
                <p>Export généré le {$exportedAt}</p>
            </td>
        </tr>
    </table>

    <p class="filters">{$filtersSummary}</p>

    {$truncationNotice}

    <table class="data">
        <thead>
            <tr>
                <th>Type</th>
                <th>Connexion</th>
                <th>Déconnexion</th>
                <th>Durée</th>
                <th>Apprenant</th>
                <th>Formation</th>
            </tr>
        </thead>
        <tbody>
            {$bodyRows}
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">{$totalRowLabel}</td>
                <td class="total-value">{$this->formatDurationClock($totalDurationSeconds)}</td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    </table>

    <div class="footer">TrackUp &middot; Document confidentiel &mdash; usage interne</div>
</body>
</html>
HTML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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
            $this->logger->error('Rise Up activity log import failed.', [
                'fileName' => $file->getClientOriginalName(),
                'exception' => $e,
            ]);

            return $this->json([
                'success' => false,
                'message' => 'L\'import a échoué. Consultez les logs serveur pour plus de détails.',
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
