<?php
declare(strict_types=1);

namespace App\Service;

class XlsxRawReader
{
    /**
     * @return array<int, array<int, string>>
     */
    public function readRows(string $filePath): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive extension is required to read XLSX files.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException(sprintf('Unable to open XLSX archive: %s', $filePath));
        }

        try {
            $sheetXml = $this->readWorksheetXml($zip);
            $sharedStrings = $this->readSharedStrings($zip);

            return $this->extractWorksheetRows($sheetXml, $sharedStrings);
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
}
