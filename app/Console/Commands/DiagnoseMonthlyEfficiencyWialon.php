<?php

namespace App\Console\Commands;

use App\Services\WialonService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DiagnoseMonthlyEfficiencyWialon extends Command
{
    protected $signature = 'fleet:diagnose-monthly-efficiency-wialon {--live : Also check report templates through the Wialon API}';

    protected $description = 'Read-only check for Aylıq effektivlik Total/Geofence/Naməlum Wialon source prerequisites.';

    public function handle(WialonService $wialon): int
    {
        $totalTemplateName = (string) config('fleet.wialon.monthly_efficiency_total_report_template_name');
        $geofenceTemplateName = (string) config('fleet.wialon.monthly_efficiency_geofence_report_template_name');
        $geofenceGroupName = (string) config('fleet.wialon.monthly_efficiency_geofence_group_name');
        $unknownLabel = (string) config('fleet.wialon.monthly_efficiency_unknown_label', 'Naməlum');

        $this->info('Aylıq effektivlik Wialon source prerequisites');
        $this->table(['Setting', 'Value'], [
            ['Total motosaat template', $totalTemplateName],
            ['Geofence motosaat template', $geofenceTemplateName],
            ['Geofence group', $geofenceGroupName],
            ['Unknown label', $unknownLabel],
        ]);

        $catalogError = null;

        try {
            $catalog = [
                'total_template' => $this->catalogTemplate($totalTemplateName),
                'geofence_template' => $this->catalogTemplate($geofenceTemplateName),
                'geofence_group' => $this->catalogGeofenceGroup($geofenceGroupName),
            ];
        } catch (QueryException $exception) {
            $catalogError = $exception->getMessage();
            $catalog = [
                'total_template' => null,
                'geofence_template' => null,
                'geofence_group' => null,
            ];
        }

        $this->table(['Dependency', 'Status', 'Catalog value'], [
            ['Total motosaat template', $this->catalogStatus($catalog['total_template'], $catalogError), $this->catalogLabel($catalog['total_template'])],
            ['Geofence motosaat template', $this->catalogStatus($catalog['geofence_template'], $catalogError), $this->catalogLabel($catalog['geofence_template'])],
            ['Geofence group', $this->catalogStatus($catalog['geofence_group'], $catalogError), $this->catalogLabel($catalog['geofence_group'])],
        ]);

        if ($catalogError !== null) {
            $this->warn('Local catalog check is unavailable: '.$catalogError);
        }

        if ($this->option('live')) {
            $this->line('Live Wialon template check');
            $this->table(['Template', 'Status', 'Wialon value'], [
                [$totalTemplateName, ...$this->liveTemplateStatus($wialon, $totalTemplateName)],
                [$geofenceTemplateName, ...$this->liveTemplateStatus($wialon, $geofenceTemplateName)],
            ]);

            $this->line('Live Wialon geofence group check');
            $this->table(['Group', 'Status', 'Wialon value'], [
                [$geofenceGroupName, ...$this->liveGeofenceGroupStatus($wialon, $geofenceGroupName)],
            ]);
        }

        $missing = collect($catalog)->filter(fn (?object $row): bool => $row === null)->keys()->all();

        if ($catalogError !== null) {
            $this->warn('Monthly geofence efficiency sync is not ready. Local catalog could not be checked.');

            return self::FAILURE;
        }

        if ($missing !== []) {
            $this->warn('Monthly geofence efficiency sync is not ready. Missing: '.implode(', ', $missing));
            $this->line('Next step: create/sync the Wialon geofence group and the geofence-filtered Engine hours report template, then rerun this check.');

            return self::FAILURE;
        }

        $this->info('Ready for backend sync implementation: Total motosaat, geofence motosaat, and Naməlum can use matching catalog dependencies.');

        return self::SUCCESS;
    }

    private function catalogTemplate(string $name): ?object
    {
        if (! Schema::hasTable('wialon_report_templates')) {
            return null;
        }

        return DB::table('wialon_report_templates')
            ->where('name', $name)
            ->where(function ($query): void {
                $query->where('is_active', true)->orWhereNull('is_active');
            })
            ->first(['resource_id', 'wialon_template_id as external_id', 'name', 'report_type']);
    }

    private function catalogGeofenceGroup(string $name): ?object
    {
        if (! Schema::hasTable('wialon_geofence_groups')) {
            return null;
        }

        return DB::table('wialon_geofence_groups')
            ->where('name', $name)
            ->where(function ($query): void {
                $query->where('is_active', true)->orWhereNull('is_active');
            })
            ->first(['resource_id', 'wialon_geofence_group_id as external_id', 'name', 'geofences_count']);
    }

    private function catalogLabel(?object $row): string
    {
        if ($row === null) {
            return '-';
        }

        $suffix = isset($row->report_type)
            ? (string) $row->report_type
            : ((isset($row->geofences_count) ? ((int) $row->geofences_count).' geofences' : ''));

        return trim(sprintf(
            '%s / %s / %s %s',
            (string) $row->resource_id,
            (string) $row->external_id,
            (string) $row->name,
            $suffix,
        ));
    }

    private function catalogStatus(?object $row, ?string $catalogError): string
    {
        if ($catalogError !== null) {
            return 'UNAVAILABLE';
        }

        return $row === null ? 'MISSING' : 'OK';
    }

    /** @return array{string, string} */
    private function liveTemplateStatus(WialonService $wialon, string $name): array
    {
        try {
            $template = $wialon->findReportTemplateByName(null, $name);
        } catch (Throwable $exception) {
            return ['ERROR', $exception->getMessage()];
        }

        if (! is_array($template)) {
            return ['MISSING', '-'];
        }

        return [
            'OK',
            sprintf(
                '%s / %s / %s',
                (string) ($template['resource_id'] ?? '-'),
                (string) ($template['id'] ?? '-'),
                (string) ($template['type'] ?? '-'),
            ),
        ];
    }

    /** @return array{string, string} */
    private function liveGeofenceGroupStatus(WialonService $wialon, string $name): array
    {
        $resourceId = (int) config('fleet.wialon.efficiency_report_resource_id');

        try {
            $resource = $wialon->getResource($resourceId);
        } catch (Throwable $exception) {
            return ['ERROR', $exception->getMessage()];
        }

        $target = $this->normalizeName($name);

        foreach ($this->geofenceGroups($resource) as $group) {
            if ($this->normalizeName($this->itemName($group)) !== $target) {
                continue;
            }

            $zoneIds = $this->geofenceGroupZoneIds($group);

            return [
                'OK',
                sprintf(
                    '%s / %s / %d geofences',
                    (string) ($resource['id'] ?? $resourceId),
                    $this->itemId($group),
                    count($zoneIds),
                ),
            ];
        }

        return ['MISSING', '-'];
    }

    /** @return array<int, array<string, mixed>> */
    private function geofenceGroups(array $resource): array
    {
        return $this->normalizeCollection($resource['zg'] ?? $resource['zoneGroups'] ?? $resource['geofenceGroups'] ?? $resource['gzl'] ?? []);
    }

    /** @return array<int, string> */
    private function geofenceGroupZoneIds(array $group): array
    {
        return collect($group['zns'] ?? $group['zones'] ?? $group['zl'] ?? $group['z'] ?? $group['items'] ?? [])
            ->map(fn (mixed $zone): string => is_array($zone) ? (string) ($zone['id'] ?? $zone['i'] ?? '') : (string) $zone)
            ->filter()
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function normalizeCollection(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(function (mixed $item, int|string $key): ?array {
                if (! is_array($item)) {
                    return null;
                }

                if (! isset($item['id']) && is_string($key) && $key !== '') {
                    $item['id'] = $key;
                }

                return $item;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function itemId(array $item): string
    {
        foreach (['id', 'i', 'itemId', 'uid'] as $key) {
            if (isset($item[$key]) && trim((string) $item[$key]) !== '') {
                return trim((string) $item[$key]);
            }
        }

        return '';
    }

    private function itemName(array $item): string
    {
        foreach (['nm', 'name', 'n'] as $key) {
            if (isset($item[$key]) && trim((string) $item[$key]) !== '') {
                return trim((string) $item[$key]);
            }
        }

        return '';
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?: $name));
    }
}
