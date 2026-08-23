<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use ZipArchive;

final class XlsxFixture
{
    /**
     * @param  array<string, array<int, array<int, string|int|float|null>>>  $sheets
     */
    public static function upload(array $sheets, string $name = 'wialon-report.xlsx', bool $useSharedStrings = false): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wialon-xlsx-');

        if ($path === false) {
            throw new RuntimeException('Temporary XLSX path could not be created.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Temporary XLSX archive could not be created.');
        }

        $overrides = [];
        $workbookSheets = [];
        $relationships = [];
        $sharedStrings = $useSharedStrings ? self::sharedStringIndex($sheets) : [];

        foreach (array_values($sheets) as $index => $rows) {
            $sheetNumber = $index + 1;
            $sheetName = array_keys($sheets)[$index];
            $overrides[] = '<Override PartName="/xl/worksheets/sheet'.$sheetNumber.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbookSheets[] = '<sheet name="'.self::escape($sheetName).'" sheetId="'.$sheetNumber.'" r:id="rId'.$sheetNumber.'"/>';
            $relationships[] = '<Relationship Id="rId'.$sheetNumber.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$sheetNumber.'.xml"/>';
            $zip->addFromString('xl/worksheets/sheet'.$sheetNumber.'.xml', self::sheetXml($rows, $useSharedStrings ? $sharedStrings : null));
        }

        if ($useSharedStrings) {
            $overrides[] = '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
            $relationships[] = '<Relationship Id="rIdSharedStrings" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
            $values = array_keys($sharedStrings);
            $items = array_map(fn (string $value): string => '<si><t xml:space="preserve">'.self::escape($value).'</t></si>', $values);
            $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($values).'" uniqueCount="'.count($values).'">'
                .implode('', $items).'</sst>');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .implode('', $overrides).'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.implode('', $workbookSheets).'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .implode('', $relationships).'</Relationships>');
        $zip->close();

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** @param array<int, array<int, string|int|float|null>> $rows */
    private static function sheetXml(array $rows, ?array $sharedStrings = null): string
    {
        $xmlRows = [];

        foreach ($rows as $rowIndex => $values) {
            $number = $rowIndex + 1;
            $cells = [];

            foreach ($values as $columnIndex => $value) {
                if ($value === null) {
                    continue;
                }

                $reference = self::columnName($columnIndex).' '.$number;
                $reference = str_replace(' ', '', $reference);

                if (is_int($value) || is_float($value)) {
                    $cells[] = '<c r="'.$reference.'"><v>'.$value.'</v></c>';
                } elseif ($sharedStrings !== null) {
                    $cells[] = '<c r="'.$reference.'" t="s"><v>'.$sharedStrings[$value].'</v></c>';
                } else {
                    $cells[] = '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.self::escape($value).'</t></is></c>';
                }
            }

            $xmlRows[] = '<row r="'.$number.'">'.implode('', $cells).'</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .implode('', $xmlRows).'</sheetData></worksheet>';
    }

    private static function columnName(int $index): string
    {
        $name = '';
        $index++;

        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    /**
     * @param  array<string, array<int, array<int, string|int|float|null>>>  $sheets
     * @return array<string,int>
     */
    private static function sharedStringIndex(array $sheets): array
    {
        $index = [];

        foreach ($sheets as $rows) {
            foreach ($rows as $values) {
                foreach ($values as $value) {
                    if (is_string($value) && ! array_key_exists($value, $index)) {
                        $index[$value] = count($index);
                    }
                }
            }
        }

        return $index;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
