<?php

namespace App\Service;

use App\Entity\RiseUpActivityLog;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class RiseUpActivityLogImportService
{
    private const REQUIRED_HEADERS = [
        'ID de la formation',
        'Date de connexion',
        'Date de déconnexion',
        'Temps passé (heures)',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(param: 'app.timezone')]
        private readonly string $appTimezone,
    ) {
    }

    /**
     * @return array{parsed:int,imported:int,skipped:int}
     */
    public function import(string $filePath, ?string $fileExtension = null): array
    {
        $rows = $this->readRows($filePath, $fileExtension);
        if ($rows === []) {
            return ['parsed' => 0, 'imported' => 0, 'skipped' => 0];
        }

        $sourceFileName = basename($filePath);
        $sourceImportedAt = new \DateTimeImmutable('now', new \DateTimeZone($this->appTimezone));
        $parsedRows = [];
        $fingerprints = [];

        foreach ($rows as $row) {
            $normalized = $this->normalizeRow($row);
            if ($normalized === null) {
                continue;
            }

            $fingerprint = $this->computeFingerprint($normalized);
            $normalized['rowFingerprint'] = $fingerprint;
            $parsedRows[] = $normalized;
            $fingerprints[] = $fingerprint;
        }

        if ($parsedRows === []) {
            return ['parsed' => 0, 'imported' => 0, 'skipped' => 0];
        }

        $existingFingerprints = $this->findExistingFingerprints($fingerprints);
        $imported = 0;
        $skipped = 0;

        foreach ($parsedRows as $index => $row) {
            if (isset($existingFingerprints[$row['rowFingerprint']])) {
                ++$skipped;
                continue;
            }

            $entity = (new RiseUpActivityLog())
                ->setSourceFileName($sourceFileName)
                ->setSourceImportedAt($sourceImportedAt)
                ->setTrainingExternalId($row['trainingExternalId'])
                ->setLearnerExternalId($row['learnerExternalId'])
                ->setLearnerEmail($row['learnerEmail'])
                ->setLoginAt($row['loginAt'])
                ->setLogoutAt($row['logoutAt'])
                ->setDurationSeconds($row['durationSeconds'])
                ->setDevice($row['device'])
                ->setRowFingerprint($row['rowFingerprint'])
                ->setCreatedAt($sourceImportedAt);

            $this->entityManager->persist($entity);
            $existingFingerprints[$row['rowFingerprint']] = true;
            ++$imported;

            if (($index + 1) % 200 === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        return [
            'parsed' => count($parsedRows),
            'imported' => $imported,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readRows(string $filePath, ?string $fileExtension = null): array
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException(sprintf('File not found: %s', $filePath));
        }

        $extension = $fileExtension ?? strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv' => $this->readCsvRows($filePath),
            'xlsx' => $this->readXlsxRows($filePath),
            default => throw new \RuntimeException(sprintf('Unsupported file extension: .%s', $extension)),
        };
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readCsvRows(string $filePath): array
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Unable to open file: %s', $filePath));
        }

        try {
            $header = null;
            $rows = [];

            while (($line = fgetcsv($handle, null, ';')) !== false) {
                if ($header === null) {
                    $header = array_map([$this, 'sanitizeHeader'], $line);
                    $this->assertRequiredHeaders($header);
                    continue;
                }

                $values = array_pad($line, count($header), '');
                $rows[] = array_combine($header, $values) ?: [];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readXlsxRows(string $filePath): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive extension is required to import XLSX activity logs.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException(sprintf('Unable to open XLSX archive: %s', $filePath));
        }

        try {
            $sheetXml = $this->readWorksheetXml($zip);
            $sharedStrings = $this->readSharedStrings($zip);
            $rows = $this->extractWorksheetRows($sheetXml, $sharedStrings);

            if ($rows === []) {
                return [];
            }

            $header = array_map([$this, 'sanitizeHeader'], $rows[0]);
            $this->assertRequiredHeaders($header);

            $items = [];
            foreach (array_slice($rows, 1) as $row) {
                $values = array_pad($row, count($header), '');
                $items[] = array_combine($header, $values) ?: [];
            }

            return $items;
        } finally {
            $zip->close();
        }
    }

    private function readWorksheetXml(\ZipArchive $zip): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $workbookRelsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if (!is_string($workbookXml) || !is_string($workbookRelsXml)) {
            throw new \RuntimeException('Invalid XLSX file: workbook metadata is missing.');
        }

        $workbook = new \DOMDocument();
        $workbook->loadXML($workbookXml);
        $workbookXPath = new \DOMXPath($workbook);
        $workbookXPath->registerNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbookXPath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $sheets = $workbookXPath->query('/a:workbook/a:sheets/a:sheet');

        if (!$sheets instanceof \DOMNodeList || $sheets->length === 0) {
            throw new \RuntimeException('Invalid XLSX file: no worksheet found.');
        }

        $firstSheet = $sheets->item(0);
        $sheetRelationshipId = $firstSheet instanceof \DOMElement
            ? (string) $firstSheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')
            : '';

        if ($sheetRelationshipId === '') {
            throw new \RuntimeException('Invalid XLSX file: worksheet relationship is missing.');
        }

        $relationships = new \DOMDocument();
        $relationships->loadXML($workbookRelsXml);
        $relationshipsXPath = new \DOMXPath($relationships);
        $relationshipsXPath->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');

        foreach ($relationshipsXPath->query('/r:Relationships/r:Relationship') ?: [] as $relationship) {
            if (!$relationship instanceof \DOMElement || $relationship->getAttribute('Id') !== $sheetRelationshipId) {
                continue;
            }

            $target = ltrim($relationship->getAttribute('Target'), '/');
            $sheetXml = $zip->getFromName('xl/' . $target);

            if (!is_string($sheetXml)) {
                throw new \RuntimeException(sprintf('Invalid XLSX file: worksheet "%s" could not be read.', $target));
            }

            return $sheetXml;
        }

        throw new \RuntimeException('Invalid XLSX file: worksheet target not found.');
    }

    /**
     * @return string[]
     */
    private function readSharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (!is_string($xml)) {
            return [];
        }

        $document = new \DOMDocument();
        $document->loadXML($xml);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $sharedStrings = [];
        foreach ($xpath->query('/a:sst/a:si') ?: [] as $item) {
            $texts = [];
            foreach ($xpath->query('.//a:t', $item) ?: [] as $textNode) {
                $texts[] = $textNode->textContent;
            }
            $sharedStrings[] = implode('', $texts);
        }

        return $sharedStrings;
    }

    /**
     * @param string[] $sharedStrings
     *
     * @return array<int, array<int, string>>
     */
    private function extractWorksheetRows(string $sheetXml, array $sharedStrings): array
    {
        $sheet = new \DOMDocument();
        $sheet->loadXML($sheetXml);
        $xpath = new \DOMXPath($sheet);
        $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $rows = [];
        foreach ($xpath->query('/a:worksheet/a:sheetData/a:row') ?: [] as $rowNode) {
            $cells = [];

            foreach ($xpath->query('./a:c', $rowNode) ?: [] as $cellNode) {
                if (!$cellNode instanceof \DOMElement) {
                    continue;
                }

                $reference = $cellNode->getAttribute('r');
                $columnIndex = $this->columnIndexFromReference($reference);
                $type = $cellNode->getAttribute('t');
                $value = '';

                if ($type === 's') {
                    $valueNode = $xpath->query('./a:v', $cellNode)?->item(0);
                    $sharedIndex = $valueNode instanceof \DOMNode ? (int) $valueNode->textContent : -1;
                    $value = $sharedStrings[$sharedIndex] ?? '';
                } elseif ($type === 'inlineStr') {
                    $texts = [];
                    foreach ($xpath->query('./a:is//a:t', $cellNode) ?: [] as $textNode) {
                        $texts[] = $textNode->textContent;
                    }
                    $value = implode('', $texts);
                } else {
                    $valueNode = $xpath->query('./a:v', $cellNode)?->item(0);
                    $value = $valueNode instanceof \DOMNode ? $valueNode->textContent : '';
                }

                $cells[$columnIndex] = $value;
            }

            if ($cells === []) {
                continue;
            }

            ksort($cells);
            $maxIndex = max(array_keys($cells));
            $row = [];
            for ($index = 0; $index <= $maxIndex; ++$index) {
                $row[] = $cells[$index] ?? '';
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function columnIndexFromReference(string $reference): int
    {
        if ($reference === '') {
            return 0;
        }

        preg_match('/^[A-Z]+/', strtoupper($reference), $matches);
        $letters = $matches[0] ?? 'A';
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    private function sanitizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;

        return trim($header);
    }

    /**
     * @param string[] $header
     */
    private function assertRequiredHeaders(array $header): void
    {
        $missingHeaders = array_values(array_diff(self::REQUIRED_HEADERS, $header));

        if ($missingHeaders !== []) {
            throw new \RuntimeException(sprintf(
                'The activity log file is missing required columns: %s',
                implode(', ', $missingHeaders),
            ));
        }
    }

    /**
     * @param array<string, string> $row
     *
     * @return array{
     *   trainingExternalId:int,
     *   learnerExternalId:?int,
     *   learnerEmail:?string,
     *   loginAt:\DateTimeImmutable,
     *   logoutAt:? \DateTimeImmutable,
     *   durationSeconds:int,
     *   device:?string
     * }|null
     */
    private function normalizeRow(array $row): ?array
    {
        $trainingExternalId = $this->positiveIntOrNull($row['ID de la formation'] ?? null);
        $loginAt = $this->parseDateTime($row['Date de connexion'] ?? null);

        if ($trainingExternalId === null || !$loginAt instanceof \DateTimeImmutable) {
            return null;
        }

        return [
            'trainingExternalId' => $trainingExternalId,
            'learnerExternalId' => $this->positiveIntOrNull($row["Identifiant d'utilisateur"] ?? null),
            'learnerEmail' => $this->normalizeNullableString($row['Email'] ?? null),
            'loginAt' => $loginAt,
            'logoutAt' => $this->parseDateTime($row['Date de déconnexion'] ?? null),
            'durationSeconds' => $this->parseDurationSeconds($row['Temps passé (heures)'] ?? null),
            'device' => $this->normalizeNullableString($row['Appareil'] ?? null),
        ];
    }

    /**
     * @param array{
     *   trainingExternalId:int,
     *   learnerExternalId:?int,
     *   learnerEmail:?string,
     *   loginAt:\DateTimeImmutable,
     *   logoutAt:? \DateTimeImmutable,
     *   durationSeconds:int
     * } $row
     */
    private function computeFingerprint(array $row): string
    {
        return hash('sha256', implode('|', [
            (string) $row['trainingExternalId'],
            (string) ($row['learnerExternalId'] ?? ''),
            (string) ($row['learnerEmail'] ?? ''),
            $row['loginAt']->format('Y-m-d H:i:s'),
            $row['logoutAt']?->format('Y-m-d H:i:s') ?? '',
            (string) $row['durationSeconds'],
        ]));
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        $int = (int) $normalized;

        return $int > 0 ? $int : null;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function parseDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        $timezone = new \DateTimeZone($this->appTimezone);
        $date = \DateTimeImmutable::createFromFormat('d/m/Y H:i:s', $normalized, $timezone);

        return $date instanceof \DateTimeImmutable ? $date : null;
    }

    private function parseDurationSeconds(mixed $value): int
    {
        if (!is_scalar($value)) {
            return 0;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return 0;
        }

        $parts = explode(':', $normalized);
        if (count($parts) !== 3) {
            return 0;
        }

        return max(0, (((int) $parts[0]) * 3600) + (((int) $parts[1]) * 60) + ((int) $parts[2]));
    }

    /**
     * @param string[] $fingerprints
     *
     * @return array<string, true>
     */
    private function findExistingFingerprints(array $fingerprints): array
    {
        $fingerprints = array_values(array_unique(array_filter($fingerprints, static fn (string $value): bool => $value !== '')));
        if ($fingerprints === []) {
            return [];
        }

        $connection = $this->entityManager->getConnection();
        $existing = [];

        foreach (array_chunk($fingerprints, 500) as $chunk) {
            $rows = $connection->fetchFirstColumn(
                'SELECT row_fingerprint FROM riseup_activity_logs WHERE row_fingerprint IN (?)',
                [$chunk],
                [ArrayParameterType::STRING],
            );

            foreach ($rows as $fingerprint) {
                if (is_string($fingerprint) && $fingerprint !== '') {
                    $existing[$fingerprint] = true;
                }
            }
        }

        return $existing;
    }
}
