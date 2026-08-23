<?php

namespace App\Services;

use DateTimeImmutable;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;
use ZipArchive;

class XlsxWorkbookReader
{
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const OFFICE_REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const PACKAGE_REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const MAX_UNCOMPRESSED_BYTES = 536_870_912;

    private const MAX_ROWS = 250_000;

    /**
     * @return array{properties: array<string, string>, sheets: array<int, array{name:string, rows:array<int, array{number:int,cells:array<int,mixed>}>}>}
     */
    public function read(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('XLSX faylı açıla bilmədi.');
        }

        try {
            $this->guardArchiveSize($zip);
            $workbookXml = $this->requiredEntry($zip, 'xl/workbook.xml');
            $relationshipsXml = $this->requiredEntry($zip, 'xl/_rels/workbook.xml.rels');
            $sharedStrings = $this->sharedStrings($zip);
            $dateStyles = $this->dateStyles($zip);
            $sheets = [];

            foreach ($this->sheetDefinitions($workbookXml, $relationshipsXml) as $sheet) {
                $sheets[] = [
                    'name' => $sheet['name'],
                    'rows' => $this->worksheetRows($zip, $sheet['path'], $sharedStrings, $dateStyles),
                ];
            }

            return [
                'properties' => $this->properties($zip),
                'sheets' => $sheets,
            ];
        } finally {
            $zip->close();
        }
    }

    private function guardArchiveSize(ZipArchive $zip): void
    {
        $total = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $total += (int) ($stat['size'] ?? 0);

            if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                throw new RuntimeException('XLSX faylının açılmış ölçüsü təhlükəsizlik limitini keçir.');
            }
        }
    }

    /** @return array<int, array{name:string,path:string}> */
    private function sheetDefinitions(string $workbookXml, string $relationshipsXml): array
    {
        $relationships = $this->xml($relationshipsXml);
        $relationships->registerXPathNamespace('r', self::PACKAGE_REL_NS);
        $targets = [];

        foreach ($relationships->xpath('//r:Relationship') ?: [] as $relationship) {
            $targets[(string) $relationship['Id']] = (string) $relationship['Target'];
        }

        $workbook = $this->xml($workbookXml);
        $workbook->registerXPathNamespace('x', self::MAIN_NS);
        $definitions = [];

        foreach ($workbook->xpath('//x:sheets/x:sheet') ?: [] as $sheet) {
            $relationshipId = (string) $sheet->attributes(self::OFFICE_REL_NS)['id'];
            $target = $targets[$relationshipId] ?? null;

            if ($target === null) {
                continue;
            }

            $definitions[] = [
                'name' => trim((string) $sheet['name']) ?: 'Sheet',
                'path' => $this->worksheetPath($target),
            ];
        }

        if ($definitions === []) {
            throw new RuntimeException('XLSX faylında iş vərəqi tapılmadı.');
        }

        return $definitions;
    }

    private function worksheetPath(string $target): string
    {
        $target = str_replace('\\', '/', trim($target));

        if (str_starts_with($target, '/')) {
            return ltrim($target, '/');
        }

        $parts = [];

        foreach (explode('/', 'xl/'.$target) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    /** @return array<int, string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if (! is_string($xml)) {
            return [];
        }

        $document = $this->xml($xml);
        $document->registerXPathNamespace('x', self::MAIN_NS);
        $strings = [];

        foreach ($document->xpath('//x:si') ?: [] as $item) {
            $parts = [];

            foreach ($item->xpath('.//x:t') ?: [] as $text) {
                $parts[] = (string) $text;
            }

            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    /** @return array<int, string> */
    private function dateStyles(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/styles.xml');

        if (! is_string($xml)) {
            return [];
        }

        $document = $this->xml($xml);
        $document->registerXPathNamespace('x', self::MAIN_NS);
        $customFormats = [];

        foreach ($document->xpath('//x:numFmts/x:numFmt') ?: [] as $format) {
            $customFormats[(int) $format['numFmtId']] = (string) $format['formatCode'];
        }

        $styles = [];

        foreach ($document->xpath('//x:cellXfs/x:xf') ?: [] as $index => $style) {
            $formatId = (int) $style['numFmtId'];
            $formatCode = $customFormats[$formatId] ?? '';

            if (in_array($formatId, range(14, 22), true) || in_array($formatId, [45, 46, 47], true) || $this->looksLikeDateFormat($formatCode)) {
                $styles[(int) $index] = $formatCode;
            }
        }

        return $styles;
    }

    private function looksLikeDateFormat(string $format): bool
    {
        $format = preg_replace('/"[^"]*"|\\\\.|\[[^\]]*\]/', '', mb_strtolower($format)) ?? '';

        return preg_match('/[ydhs]/', $format) === 1;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     * @param  array<int, string>  $dateStyles
     * @return array<int, array{number:int,cells:array<int,mixed>}>
     */
    private function worksheetRows(ZipArchive $zip, string $entry, array $sharedStrings, array $dateStyles): array
    {
        $xml = $this->requiredEntry($zip, $entry);
        $reader = new XMLReader;
        $flags = LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE;

        if (! $reader->XML($xml, null, $flags)) {
            throw new RuntimeException('XLSX iş vərəqi oxuna bilmədi.');
        }

        $rows = [];

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                if (count($rows) >= self::MAX_ROWS) {
                    throw new RuntimeException('XLSX sətir sayı təhlükəsizlik limitini keçir.');
                }

                $rowNumber = (int) ($reader->getAttribute('r') ?: count($rows) + 1);
                $rowXml = $reader->readOuterXML();
                $row = $this->xml($rowXml);
                $row->registerXPathNamespace('x', self::MAIN_NS);
                $cells = [];

                foreach ($row->xpath('./x:c') ?: [] as $cell) {
                    $reference = (string) $cell['r'];
                    $column = $this->columnIndex($reference);
                    $value = $this->cellValue($cell, $sharedStrings, $dateStyles);

                    if ($value !== null && $value !== '') {
                        $cells[$column] = $value;
                    }
                }

                if ($cells !== []) {
                    ksort($cells);
                    $rows[] = ['number' => $rowNumber, 'cells' => $cells];
                }
            }
        } finally {
            $reader->close();
        }

        return $rows;
    }

    /** @param array<int, string> $sharedStrings @param array<int, string> $dateStyles */
    private function cellValue(SimpleXMLElement $cell, array $sharedStrings, array $dateStyles): mixed
    {
        $cell->registerXPathNamespace('x', self::MAIN_NS);
        $type = (string) $cell['t'];
        $styleIndex = (int) ($cell['s'] ?? -1);
        $valueNode = ($cell->xpath('./x:v') ?: [null])[0];
        $raw = $valueNode === null ? null : (string) $valueNode;

        if ($type === 'inlineStr') {
            return implode('', array_map(fn (SimpleXMLElement $text): string => (string) $text, $cell->xpath('.//x:t') ?: []));
        }

        if ($type === 's') {
            return $sharedStrings[(int) $raw] ?? '';
        }

        if ($type === 'b') {
            return $raw === '1';
        }

        if ($type === 'str' || $type === 'e') {
            return $raw;
        }

        if ($raw === null || $raw === '') {
            return null;
        }

        if (isset($dateStyles[$styleIndex]) && is_numeric($raw)) {
            return $this->excelDateValue((float) $raw, $dateStyles[$styleIndex]);
        }

        return is_numeric($raw) ? (float) $raw : $raw;
    }

    private function excelDateValue(float $serial, string $format): string
    {
        $seconds = (int) round($serial * 86400);
        $date = (new DateTimeImmutable('1899-12-30 00:00:00'))->modify("+{$seconds} seconds");
        $normalized = mb_strtolower($format);
        $hasDate = preg_match('/[yd]/', $normalized) === 1;
        $hasTime = preg_match('/[hs]/', $normalized) === 1;

        if ($hasDate && $hasTime) {
            return $date->format('Y-m-d H:i:s');
        }

        return $hasDate ? $date->format('Y-m-d') : $date->format('H:i:s');
    }

    private function columnIndex(string $reference): int
    {
        preg_match('/^[A-Z]+/i', $reference, $matches);
        $letters = strtoupper($matches[0] ?? 'A');
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    /** @return array<string, string> */
    private function properties(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('docProps/core.xml');

        if (! is_string($xml)) {
            return [];
        }

        $document = $this->xml($xml);
        $document->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
        $document->registerXPathNamespace('cp', 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties');

        return [
            'title' => trim((string) (($document->xpath('//dc:title') ?: [''])[0])),
            'subject' => trim((string) (($document->xpath('//dc:subject') ?: [''])[0])),
            'description' => trim((string) (($document->xpath('//dc:description') ?: [''])[0])),
        ];
    }

    private function requiredEntry(ZipArchive $zip, string $entry): string
    {
        $content = $zip->getFromName($entry);

        if (! is_string($content)) {
            throw new RuntimeException("XLSX strukturu natamamdır: {$entry} tapılmadı.");
        }

        return $content;
    }

    private function xml(string $content): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);

            if ($xml === false) {
                throw new RuntimeException('XLSX daxilində etibarsız XML aşkarlandı.');
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
