<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

class XlsxExportService
{
    /**
     * @param  array{
     *     title: string,
     *     filters: array<int, array<int, mixed>>,
     *     sections: array<int, array{title: string, columns: array<int, mixed>, rows: array<int, array<int, mixed>>}>
     * }  $export
     */
    public function build(array $export): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZipArchive extension is required to generate XLSX exports.');
        }

        $path = tempnam(sys_get_temp_dir(), 'fleet-xlsx-');

        if ($path === false) {
            throw new RuntimeException('Could not create temporary XLSX file.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new RuntimeException('Could not open temporary XLSX archive.');
        }

        $sheets = $this->workbookSheets($export);

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml(count($sheets)));
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('docProps/app.xml', $this->appPropsXml());
        $zip->addFromString('docProps/core.xml', $this->corePropsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml($sheets));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml($sheets));
        $zip->addFromString('xl/styles.xml', $this->stylesXml());

        foreach ($sheets as $index => $sheet) {
            $zip->addFromString('xl/worksheets/sheet'.($index + 1).'.xml', $this->worksheetXml($sheet));
        }

        $zip->close();

        $content = file_get_contents($path);
        @unlink($path);

        if ($content === false) {
            throw new RuntimeException('Could not read generated XLSX file.');
        }

        return $content;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function workbookSheets(array $export): array
    {
        if (! empty($export['sheets']) && is_array($export['sheets'])) {
            return collect($export['sheets'])
                ->values()
                ->map(fn (array $sheet, int $index): array => [
                    'name' => $sheet['name'] ?? ('Sheet '.($index + 1)),
                    'title' => $sheet['title'] ?? ($export['title'] ?? 'Dashboard'),
                    'filters' => $sheet['filters'] ?? ($export['filters'] ?? []),
                    'sections' => $sheet['sections'] ?? [],
                ])
                ->all();
        }

        return [[
            'name' => 'Dashboard',
            'title' => $export['title'] ?? 'Dashboard',
            'filters' => $export['filters'] ?? [],
            'sections' => $export['sections'] ?? [],
        ]];
    }

    private function worksheetXml(array $export): string
    {
        $sheetDataXml = '';
        $merges = [];
        $widths = [];
        $rowNumber = 1;
        $maxColumnCount = 2;

        $this->appendRow($sheetDataXml, $widths, $rowNumber, [$export['title'] ?? 'Dashboard'], 1);
        $merges[] = 'A1:B1';
        $rowNumber++;

        foreach (($export['filters'] ?? []) as $filter) {
            $this->appendRow($sheetDataXml, $widths, $rowNumber, [
                $filter[0] ?? '',
                $filter[1] ?? '',
            ], 2);
            $rowNumber++;
        }

        $rowNumber++;

        foreach (($export['sections'] ?? []) as $section) {
            $columns = array_values($section['columns'] ?? []);
            $columnCount = max(1, count($columns));
            $maxColumnCount = max($maxColumnCount, $columnCount);
            $startColumn = $this->cellReference($columnCount, $rowNumber);

            $this->appendRow($sheetDataXml, $widths, $rowNumber, [$section['title'] ?? ''], 3);
            $merges[] = "A{$rowNumber}:{$startColumn}";
            $rowNumber++;

            $this->appendRow($sheetDataXml, $widths, $rowNumber, $columns, 2);
            $rowNumber++;

            $sectionRows = $section['rows'] ?? [];

            if ($sectionRows === []) {
                $this->appendRow($sheetDataXml, $widths, $rowNumber, [__('app.no_data')], 0);
                $rowNumber++;
            } else {
                foreach ($sectionRows as $sectionRow) {
                    $this->appendRow($sheetDataXml, $widths, $rowNumber, array_values($sectionRow), 0);
                    $rowNumber++;
                }
            }

            $rowNumber++;
        }

        $columnsXml = '';

        for ($column = 1; $column <= $maxColumnCount; $column++) {
            $width = min(42, max(12, ($widths[$column] ?? 12) + 2));
            $columnsXml .= '<col min="'.$column.'" max="'.$column.'" width="'.$width.'" customWidth="1"/>';
        }

        $mergeXml = '';

        if ($merges !== []) {
            $mergeXml = '<mergeCells count="'.count($merges).'">'.
                implode('', array_map(fn (string $ref): string => '<mergeCell ref="'.$ref.'"/>', $merges)).
                '</mergeCells>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '.
            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.
            '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'.
            '<sheetFormatPr defaultRowHeight="15"/>'.
            '<cols>'.$columnsXml.'</cols>'.
            '<sheetData>'.$sheetDataXml.'</sheetData>'.
            $mergeXml.
            '</worksheet>';
    }

    private function appendRow(string &$sheetDataXml, array &$widths, int $rowNumber, array $cells, int $style): void
    {
        $cellXml = '';

        foreach ($cells as $index => $value) {
            $column = $index + 1;
            $text = $this->cellText($value);
            $widths[$column] = max($widths[$column] ?? 0, mb_strlen($text));
            $cellXml .= '<c r="'.$this->cellReference($column, $rowNumber).'" t="inlineStr" s="'.$style.'">'.
                '<is><t xml:space="preserve">'.$this->escape($text).'</t></is>'.
                '</c>';
        }

        $sheetDataXml .= '<row r="'.$rowNumber.'">'.$cellXml.'</row>';
    }

    private function cellText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $text = (string) $value;

        return $this->safeCellText($text);
    }

    private function safeCellText(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return in_array($value[0], ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }

    private function cellReference(int $column, int $row): string
    {
        $name = '';

        while ($column > 0) {
            $column--;
            $name = chr(65 + ($column % 26)).$name;
            $column = intdiv($column, 26);
        }

        return $name.$row;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function contentTypesXml(int $sheetCount = 1): string
    {
        $worksheetOverrides = '';

        for ($index = 1; $index <= max(1, $sheetCount); $index++) {
            $worksheetOverrides .= '<Override PartName="/xl/worksheets/sheet'.$index.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'.
            '<Default Extension="xml" ContentType="application/xml"/>'.
            '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'.
            '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'.
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.
            $worksheetOverrides.
            '</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'.
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'.
            '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'.
            '</Relationships>';
    }

    /**
     * @param  array<int, array<string, mixed>>  $sheets
     */
    private function workbookXml(array $sheets): string
    {
        $sheetXml = collect($sheets)
            ->values()
            ->map(fn (array $sheet, int $index): string => '<sheet name="'.$this->escapeAttribute($this->sheetName((string) ($sheet['name'] ?? 'Dashboard'))).'" sheetId="'.($index + 1).'" r:id="rId'.($index + 1).'"/>')
            ->implode('');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '.
            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.
            '<sheets>'.$sheetXml.'</sheets>'.
            '</workbook>';
    }

    /**
     * @param  array<int, array<string, mixed>>  $sheets
     */
    private function workbookRelsXml(array $sheets): string
    {
        $sheetRels = collect($sheets)
            ->values()
            ->map(fn (array $sheet, int $index): string => '<Relationship Id="rId'.($index + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($index + 1).'.xml"/>')
            ->implode('');
        $stylesId = count($sheets) + 1;

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
            $sheetRels.
            '<Relationship Id="rId'.$stylesId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'.
            '</Relationships>';
    }

    private function sheetName(string $name): string
    {
        $name = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', $name) ?: 'Sheet';
        $name = trim($name);

        return mb_substr($name !== '' ? $name : 'Sheet', 0, 31);
    }

    private function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.
            '<fonts count="2">'.
            '<font><sz val="11"/><color theme="1"/><name val="Arial"/><family val="2"/></font>'.
            '<font><b/><sz val="11"/><color theme="1"/><name val="Arial"/><family val="2"/></font>'.
            '</fonts>'.
            '<fills count="3">'.
            '<fill><patternFill patternType="none"/></fill>'.
            '<fill><patternFill patternType="gray125"/></fill>'.
            '<fill><patternFill patternType="solid"><fgColor rgb="FFE8F1FF"/><bgColor indexed="64"/></patternFill></fill>'.
            '</fills>'.
            '<borders count="2">'.
            '<border><left/><right/><top/><bottom/><diagonal/></border>'.
            '<border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/><diagonal/></border>'.
            '</borders>'.
            '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'.
            '<cellXfs count="4">'.
            '<xf numFmtId="49" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'.
            '<xf numFmtId="49" fontId="1" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1"/>'.
            '<xf numFmtId="49" fontId="1" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>'.
            '<xf numFmtId="49" fontId="1" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>'.
            '</cellXfs>'.
            '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'.
            '</styleSheet>';
    }

    private function appPropsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '.
            'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'.
            '<Application>Fleet Analytics</Application>'.
            '</Properties>';
    }

    private function corePropsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '.
            'xmlns:dc="http://purl.org/dc/elements/1.1/" '.
            'xmlns:dcterms="http://purl.org/dc/terms/" '.
            'xmlns:dcmitype="http://purl.org/dc/dcmitype/" '.
            'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'.
            '<dc:creator>Fleet Analytics</dc:creator>'.
            '<cp:lastModifiedBy>Fleet Analytics</cp:lastModifiedBy>'.
            '<dcterms:created xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:created>'.
            '<dcterms:modified xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:modified>'.
            '</cp:coreProperties>';
    }
}
