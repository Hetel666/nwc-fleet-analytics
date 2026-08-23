<?php

namespace App\Services;

use App\Models\DashboardDataImport;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

class WialonManualXlsxParser
{
    public function __construct(private XlsxWorkbookReader $reader) {}

    /**
     * @return array{records:array<int,array<string,mixed>>,errors:array<int,array<string,mixed>>,tables:array<int,string>,source_rows:int}
     */
    public function parse(string $path, string $fileName, string $module, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $workbook = $this->reader->read($path);
        $records = [];
        $errors = [];
        $tables = [];
        $sourceRows = 0;

        foreach ($workbook['sheets'] as $sheet) {
            $section = null;
            $contextUnit = null;
            $contextDate = null;

            foreach ($sheet['rows'] as $row) {
                $cells = $row['cells'];
                $header = $this->header($cells, $module);

                if ($header !== null) {
                    $section = $header;
                    $tables[$header['kind']] = $header['label'];
                    $contextDate = null;

                    continue;
                }

                if ($section === null) {
                    $contextUnit = $this->documentUnitHint($cells, $contextUnit);

                    continue;
                }

                $sourceRows++;
                $grouping = $this->text($cells[$section['columns']['grouping']] ?? null);
                $date = $this->dateFromCells($cells, $section['columns']['date']) ?: $this->date($grouping);
                $unit = $this->unitFromCells($cells, $section['columns'], $grouping, $date);

                if ($unit !== null) {
                    $contextUnit = $unit;
                }

                if ($date !== null) {
                    $contextDate = $date;
                }

                $hoursRaw = $cells[$section['columns']['engine_hours']] ?? null;
                $hours = $this->hours($hoursRaw);

                if ($hours === null) {
                    continue;
                }

                $recordDate = $date ?: $contextDate;

                if ($recordDate === null && ! $from->isSameDay($to) && $unit !== null) {
                    // Grouped Wialon XLSX files may include a unit-level total before daily child rows.
                    continue;
                }

                if ($recordDate === null && $from->isSameDay($to)) {
                    $recordDate = $from->toDateString();
                }

                if ($recordDate === null) {
                    $errors[] = $this->error($fileName, $sheet['name'], $row['number'], 'Sətirdə tarix müəyyən edilmədi.');

                    continue;
                }

                if ($recordDate < $from->toDateString() || $recordDate > $to->toDateString()) {
                    $errors[] = $this->error($fileName, $sheet['name'], $row['number'], "Tarix seçilmiş dövrdən kənardır: {$recordDate}.");

                    continue;
                }

                $unitName = $unit ?: $contextUnit;

                if ($unitName === null || $this->isTotalLabel($unitName)) {
                    $errors[] = $this->error($fileName, $sheet['name'], $row['number'], 'Texnika adı müəyyən edilmədi.');

                    continue;
                }

                $geofence = null;

                if ($section['kind'] === 'monthly_geofence') {
                    $geofence = $this->text($cells[$section['columns']['name']] ?? null);

                    if ($geofence === '' || $this->isTotalLabel($geofence)) {
                        continue;
                    }
                }

                $records[] = [
                    'kind' => $section['kind'],
                    'file_name' => $fileName,
                    'sheet_name' => $sheet['name'],
                    'source_row_number' => $row['number'],
                    'business_date' => $recordDate,
                    'wialon_unit_id' => $this->text($cells[$section['columns']['unit_id']] ?? null) ?: null,
                    'unit_name' => $unitName,
                    'geofence_name' => $geofence,
                    'engine_hours_decimal' => round(max(0, $hours), 2),
                    'engine_seconds' => (int) round(max(0, $hours) * 3600),
                    'engine_hours_raw' => $this->text($hoursRaw),
                    'mileage_km' => $this->number($cells[$section['columns']['mileage']] ?? null),
                    'mileage_raw' => $this->text($cells[$section['columns']['mileage']] ?? null),
                    'visits_count' => (int) round(max(0, $this->number($cells[$section['columns']['visits']] ?? null) ?? 0)),
                    'started_at' => $this->dateTime($cells[$section['columns']['begin']] ?? null, $recordDate),
                    'ended_at' => $this->dateTime($cells[$section['columns']['end']] ?? null, $recordDate),
                    'raw_row' => $cells,
                ];
            }
        }

        $requiredKinds = $module === DashboardDataImport::MODULE_MONTHLY_EFFICIENCY
            ? ['monthly_total', 'monthly_geofence']
            : ['daily'];

        foreach ($requiredKinds as $requiredKind) {
            if (! isset($tables[$requiredKind])) {
                $label = $requiredKind === 'monthly_geofence' ? 'Geofence' : 'Engine hours';
                $errors[] = $this->error($fileName, null, null, "Məcburi {$label} cədvəli tapılmadı.");
            }
        }

        if ($records === [] && $errors === []) {
            throw new RuntimeException('XLSX faylında import üçün məlumat sətri tapılmadı.');
        }

        return [
            'records' => $records,
            'errors' => $errors,
            'tables' => array_values($tables),
            'source_rows' => $sourceRows,
        ];
    }

    /** @return array{kind:string,label:string,columns:array<string,int|null>}|null */
    private function header(array $cells, string $module): ?array
    {
        $normalized = array_map(fn (mixed $value): string => $this->normalize($this->text($value)), $cells);
        $engineHours = $this->column($normalized, ['engine hours', 'motosaat', 'moto saat', 'mühərrik saatı', 'моточасы']);

        if ($engineHours === null) {
            return null;
        }

        $grouping = $this->column($normalized, ['grouping', 'group', 'группировка', 'qruplaşdırma', 'объект', 'object', 'unit', 'texnika']);

        if ($grouping === null) {
            return null;
        }

        $entry = $this->column($normalized, ['entry time', 'giriş vaxtı', 'время входа']);
        $exit = $this->column($normalized, ['exit time', 'çıxış vaxtı', 'время выхода']);
        $name = $this->column($normalized, ['name', 'ad', 'имя', 'geofence', 'геозона']);
        $isGeofence = $module === DashboardDataImport::MODULE_MONTHLY_EFFICIENCY && $name !== null && ($entry !== null || $exit !== null);

        return [
            'kind' => $module === DashboardDataImport::MODULE_DAILY_EFFICIENCY
                ? 'daily'
                : ($isGeofence ? 'monthly_geofence' : 'monthly_total'),
            'label' => $isGeofence ? 'Geofence' : 'Engine hours',
            'columns' => [
                'grouping' => $grouping,
                'unit_id' => $this->column($normalized, ['unit id', 'wialon unit id', 'object id', 'id объекта', 'obyekt id']),
                'unit' => $this->column($normalized, ['unit', 'object', 'объект', 'texnika', 'техника', 'maşın nömrəsi', 'd.q.n.']),
                'date' => $this->column($normalized, ['date', 'tarix', 'дата', 'grouping', 'группировка']),
                'name' => $name,
                'engine_hours' => $engineHours,
                'mileage' => $this->column($normalized, ['mileage', 'mileage adjusted', 'mileage (adjusted)', 'yürüş', 'пробег']),
                'visits' => $this->column($normalized, ['visits', 'giriş sayı', 'посещения']),
                'begin' => $entry ?? $this->column($normalized, ['beginning', 'begin', 'start', 'başlama', 'начало']),
                'end' => $exit ?? $this->column($normalized, ['end', 'bitmə', 'конец']),
            ],
        ];
    }

    private function documentUnitHint(array $cells, ?string $current): ?string
    {
        $texts = array_values(array_filter(array_map(fn (mixed $value): string => $this->text($value), $cells)));

        if (count($texts) !== 1) {
            return $current;
        }

        $value = $texts[0];

        if ($this->date($value) !== null || $this->isMetaLabel($value)) {
            return $current;
        }

        return $value;
    }

    /** @param array<string,int|null> $columns */
    private function unitFromCells(array $cells, array $columns, string $grouping, ?string $date): ?string
    {
        $explicit = $this->text($cells[$columns['unit']] ?? null);

        if ($explicit !== '' && $this->date($explicit) === null && ! $this->isTotalLabel($explicit)) {
            return $explicit;
        }

        if ($grouping !== '' && $date === null && ! $this->isTotalLabel($grouping)) {
            return $grouping;
        }

        return null;
    }

    private function dateFromCells(array $cells, ?int $dateColumn): ?string
    {
        if ($dateColumn !== null && array_key_exists($dateColumn, $cells)) {
            $date = $this->date($cells[$dateColumn]);

            if ($date !== null) {
                return $date;
            }
        }

        foreach ($cells as $cell) {
            $date = $this->date($cell);

            if ($date !== null) {
                return $date;
            }
        }

        return null;
    }

    private function date(mixed $value): ?string
    {
        $text = $this->text($value);

        if ($text === '' || preg_match('/\d{1,4}[.\/-]\d{1,2}[.\/-]\d{1,4}/', $text) !== 1) {
            return null;
        }

        foreach (['Y-m-d', 'd.m.Y', 'd/m/Y', 'm/d/Y'] as $format) {
            $date = CarbonImmutable::createFromFormat('!'.$format, $text, $this->timezone());

            if ($date !== false && $date->format($format) === $text) {
                return $date->toDateString();
            }
        }

        try {
            return CarbonImmutable::parse($text, $this->timezone())->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function dateTime(mixed $value, string $date): ?string
    {
        $text = $this->text($value);

        if ($text === '' || in_array($text, ['-', '-----'], true)) {
            return null;
        }

        try {
            if (preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', $text) === 1) {
                return CarbonImmutable::parse($date.' '.$text, $this->timezone())->toDateTimeString();
            }

            return CarbonImmutable::parse($text, $this->timezone())->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    private function hours(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $text = $this->text($value);

        if ($text === '' || in_array($text, ['-', '-----'], true)) {
            return null;
        }

        if (preg_match('/^(\d+):(\d{2})(?::(\d{2}))?$/', $text, $matches) === 1) {
            return (int) $matches[1] + ((int) $matches[2] / 60) + ((int) ($matches[3] ?? 0) / 3600);
        }

        return $this->number($text);
    }

    private function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $text = str_replace(["\u{00A0}", ' '], '', $this->text($value));
        $text = preg_replace('/[^0-9,.-]+/u', '', $text) ?? '';

        if ($text === '' || $text === '-') {
            return null;
        }

        if (str_contains($text, ',') && str_contains($text, '.')) {
            $text = strrpos($text, ',') > strrpos($text, '.')
                ? str_replace(['.', ','], ['', '.'], $text)
                : str_replace(',', '', $text);
        } else {
            $text = str_replace(',', '.', $text);
        }

        return is_numeric($text) ? (float) $text : null;
    }

    /** @param array<int,string> $values */
    private function column(array $values, array $aliases): ?int
    {
        $aliases = array_map(fn (string $alias): string => $this->normalize($alias), $aliases);

        foreach ($values as $index => $value) {
            if (in_array($value, $aliases, true)) {
                return (int) $index;
            }
        }

        return null;
    }

    private function isTotalLabel(string $value): bool
    {
        return in_array($this->normalize($value), ['total', 'итого', 'cəmi', 'yoxdur', '----', '-----'], true);
    }

    private function isMetaLabel(string $value): bool
    {
        $value = $this->normalize($value);

        return str_contains($value, 'report') || str_contains($value, 'hesabat') || str_contains($value, 'отчет');
    }

    /** @return array<string,mixed> */
    private function error(string $fileName, ?string $sheet, ?int $row, string $message): array
    {
        return [
            'file_name' => $fileName,
            'sheet_name' => $sheet,
            'source_row_number' => $row,
            'message' => $message,
        ];
    }

    private function text(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return $value === true ? '1' : '';
        }

        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? (string) $value);
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($this->text($value));
        $value = str_replace(['(', ')', '[', ']', '_'], [' ', ' ', ' ', ' ', ' '], $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function timezone(): string
    {
        return (string) config('historical_recalculation.timezone', 'Asia/Baku');
    }
}
