<?php
declare(strict_types=1);
namespace App\Service;

use App\Repository\RiseUpActivityLogFilters;
use App\Repository\RiseUpActivityLogRepository;
use Dompdf\Dompdf;
use Dompdf\Options;

class ActivityLogPdfService
{
    public function __construct(private readonly RiseUpActivityLogRepository $repository, private readonly string $logoPath) {}

    public function render(RiseUpActivityLogFilters $filters, string $heading = '', array $signatures = []): string
    {
        ini_set('memory_limit', '512M');
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('Helvetica');
        $pdf = new Dompdf($options);
        $pdf->setPaper('a4', 'landscape');
        $html = $this->buildPdfHtml($this->repository->findFiltered($filters, 800), $this->repository->countAndAggregate($filters), $filters);
        if ($heading !== '') {
            $signed = count(array_filter($signatures, static fn (array $row): bool => (bool) $row['has_signed']));
            $section = '<h2>' . $this->escape($heading) . '</h2><h3>Signatures des classes virtuelles — ' . $signed . ' signée(s) / ' . count($signatures) . '</h3><table class="data"><thead><tr><th>Classe / formation</th><th>Début</th><th>Fin</th><th>Date de présence</th><th>Période</th><th>État</th><th>Date de signature</th></tr></thead><tbody>';
            foreach ($signatures as $row) {
                $section .= '<tr>';
                foreach ([$row['title'], $this->formatDate($row['start_at'], 'd/m/Y H:i'), $this->formatDate($row['end_at'], 'd/m/Y H:i'), $this->formatDate($row['attendance_date'], 'd/m/Y'), ['morning' => 'Matin', 'afternoon' => 'Après-midi', 'evening' => 'Après-midi'][$row['period'] ?? ''] ?? $row['period'], $row['has_signed'] ? 'Signée' : 'Non signée', $this->formatDate($row['signature_date'], 'd/m/Y H:i')] as $value) {
                    $section .= '<td>' . $this->escape((string) ($value ?? '—')) . '</td>';
                }
                $section .= '</tr>';
            }
            $section .= ($signatures === [] ? '<tr><td colspan="7">Aucune classe virtuelle inscrite pour ce parcours.</td></tr>' : '') . '</tbody></table><h3>Logs d’activité</h3>';
            $html = str_replace('<p class="filters">', $section . '<p class="filters">', $html);
        }
        $pdf->loadHtml($html);
        $pdf->render();
        return $pdf->output();
    }

    private function formatDate(mixed $value, string $format): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable((string) $value))->format($format);
        } catch (\Exception) {
            return (string) $value;
        }
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
        if ($filters->learnerId !== null) { $filterLines[] = 'Apprenant et parcours indiqués ci-dessus'; }
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

    <div class="footer">TrackUp &middot; Document confidentiel &mdash; destinataires autorisés</div>
</body>
</html>
HTML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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
