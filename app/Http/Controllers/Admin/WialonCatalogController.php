<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\Geofence;
use App\Models\Project;
use App\Models\ProjectWialonGroup;
use App\Models\WialonCatalogSyncRun;
use App\Models\WialonGeofence;
use App\Models\WialonGeofenceGroup;
use App\Models\WialonReportTemplate;
use App\Models\WialonResource;
use App\Models\WialonUnit;
use App\Models\WialonUnitGroup;
use App\Services\WialonCatalogSyncService;
use App\Services\XlsxExportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WialonCatalogController extends Controller
{
    public function index(WialonCatalogSyncService $sync): View
    {
        $this->authorize('view-wialon-catalog');

        return view('admin.wialon-catalog.index', [
            'overview' => $sync->overview(),
            'tabs' => $this->tabs(),
            'sections' => $this->syncSections(),
            'recentRuns' => WialonCatalogSyncRun::query()
                ->with('requestedBy:id,name')
                ->latest()
                ->limit(10)
                ->get(),
            'canSync' => request()->user()?->can('sync-wialon-catalog') ?? false,
            'canManageProjects' => request()->user()?->can('manage-projects') ?? false,
        ]);
    }

    public function overview(WialonCatalogSyncService $sync): JsonResponse
    {
        $this->authorize('view-wialon-catalog');

        return response()->json($sync->overview());
    }

    public function resources(Request $request, XlsxExportService $xlsx): JsonResponse|Response
    {
        return $this->catalogList(
            $request,
            WialonResource::query(),
            ['wialon_resource_id', 'name', 'account_id'],
            ['wialon_resource_id', 'name', 'report_templates_count', 'geofences_count', 'geofence_groups_count', 'is_active', 'last_synced_at'],
            fn (WialonResource $resource): array => [
                'id' => $resource->id,
                'wialon_resource_id' => $resource->wialon_resource_id,
                'name' => $resource->name,
                'account_id' => $resource->account_id,
                'report_templates_count' => $resource->report_templates_count,
                'geofences_count' => $resource->geofences_count,
                'geofence_groups_count' => $resource->geofence_groups_count,
                'status' => $resource->is_active ? 'active' : 'inactive',
                'last_synced_at' => optional($resource->last_synced_at)->toDateTimeString(),
            ],
            $xlsx,
            'Wialon resources'
        );
    }

    public function unitGroups(Request $request, XlsxExportService $xlsx): JsonResponse|Response
    {
        return $this->catalogList(
            $request,
            WialonUnitGroup::query()->with('project:id,name'),
            ['wialon_group_id', 'name', 'resource_id', 'account_id', 'ownership_type'],
            ['wialon_group_id', 'name', 'resource_id', 'units_count', 'ownership_type', 'is_active', 'last_synced_at'],
            fn (WialonUnitGroup $group): array => [
                'id' => $group->id,
                'wialon_group_id' => $group->wialon_group_id,
                'name' => $group->name,
                'resource_id' => $group->resource_id,
                'account_id' => $group->account_id,
                'units_count' => $group->units_count,
                'project' => $group->project?->name,
                'linked_project_id' => $group->linked_project_id,
                'ownership_type' => $group->ownership_type,
                'status' => $group->is_active ? 'active' : 'inactive',
                'last_synced_at' => optional($group->last_synced_at)->toDateTimeString(),
            ],
            $xlsx,
            'Wialon unit groups'
        );
    }

    public function units(Request $request, XlsxExportService $xlsx): JsonResponse|Response
    {
        return $this->catalogList(
            $request,
            WialonUnit::query()->with(['project:id,name', 'equipment:id,name,wialon_unit_id']),
            ['wialon_unit_id', 'name', 'equipment_type_name', 'ownership_type', 'unique_id', 'imei'],
            ['wialon_unit_id', 'name', 'equipment_type_name', 'ownership_type', 'unique_id', 'imei', 'is_active', 'last_synced_at'],
            fn (WialonUnit $unit): array => [
                'id' => $unit->id,
                'wialon_unit_id' => $unit->wialon_unit_id,
                'name' => $unit->name,
                'equipment_type_name' => $unit->equipment_type_name,
                'ownership_type' => $unit->ownership_type,
                'project' => $unit->project?->name,
                'local_equipment' => $unit->equipment?->name,
                'unique_id' => $unit->unique_id,
                'imei' => $unit->imei,
                'status' => $unit->is_active ? 'active' : 'inactive',
                'last_synced_at' => optional($unit->last_synced_at)->toDateTimeString(),
            ],
            $xlsx,
            'Wialon units'
        );
    }

    public function geofenceGroups(Request $request, XlsxExportService $xlsx): JsonResponse|Response
    {
        return $this->catalogList(
            $request,
            WialonGeofenceGroup::query()->with('project:id,name'),
            ['wialon_geofence_group_id', 'name', 'resource_id', 'resource_name'],
            ['wialon_geofence_group_id', 'name', 'resource_id', 'resource_name', 'geofences_count', 'is_active', 'last_synced_at'],
            fn (WialonGeofenceGroup $group): array => [
                'id' => $group->id,
                'wialon_geofence_group_id' => $group->wialon_geofence_group_id,
                'name' => $group->name,
                'resource_id' => $group->resource_id,
                'resource_name' => $group->resource_name,
                'geofences_count' => $group->geofences_count,
                'project' => $group->project?->name,
                'status' => $group->is_active ? 'active' : 'inactive',
                'last_synced_at' => optional($group->last_synced_at)->toDateTimeString(),
            ],
            $xlsx,
            'Wialon geofence groups'
        );
    }

    public function geofences(Request $request, XlsxExportService $xlsx): JsonResponse|Response
    {
        return $this->catalogList(
            $request,
            WialonGeofence::query()->with(['project:id,name', 'localGeofence:id,name,wialon_geofence_id']),
            ['wialon_geofence_id', 'name', 'resource_id', 'resource_name', 'geofence_group_id', 'zone_type'],
            ['wialon_geofence_id', 'name', 'resource_id', 'resource_name', 'geofence_group_id', 'zone_type', 'area', 'is_home_geofence', 'is_active', 'last_synced_at'],
            fn (WialonGeofence $geofence): array => [
                'id' => $geofence->id,
                'wialon_geofence_id' => $geofence->wialon_geofence_id,
                'name' => $geofence->name,
                'resource_id' => $geofence->resource_id,
                'resource_name' => $geofence->resource_name,
                'geofence_group_id' => $geofence->geofence_group_id,
                'zone_type' => $geofence->zone_type,
                'area' => $geofence->area,
                'project' => $geofence->project?->name,
                'local_geofence' => $geofence->localGeofence?->name,
                'is_home_geofence' => $geofence->is_home_geofence,
                'status' => $geofence->is_active ? 'active' : 'inactive',
                'last_synced_at' => optional($geofence->last_synced_at)->toDateTimeString(),
            ],
            $xlsx,
            'Wialon geofences'
        );
    }

    public function reportTemplates(Request $request, XlsxExportService $xlsx): JsonResponse|Response
    {
        return $this->catalogList(
            $request,
            WialonReportTemplate::query(),
            ['wialon_template_id', 'name', 'resource_id', 'resource_name', 'report_type', 'usage_status'],
            ['wialon_template_id', 'name', 'resource_id', 'resource_name', 'report_type', 'usage_status', 'is_active', 'last_synced_at'],
            fn (WialonReportTemplate $template): array => [
                'id' => $template->id,
                'wialon_template_id' => $template->wialon_template_id,
                'name' => $template->name,
                'resource_id' => $template->resource_id,
                'resource_name' => $template->resource_name,
                'report_type' => $template->report_type,
                'used_by_modules' => implode(', ', $template->used_by_modules_json ?? []),
                'usage_status' => $template->usage_status,
                'status' => $template->is_active ? 'active' : 'inactive',
                'last_synced_at' => optional($template->last_synced_at)->toDateTimeString(),
            ],
            $xlsx,
            'Wialon report templates'
        );
    }

    public function sync(Request $request, WialonCatalogSyncService $sync): JsonResponse
    {
        $this->authorize('sync-wialon-catalog');

        $data = $request->validate([
            'sections' => ['nullable', 'array'],
            'sections.*' => [Rule::in([...config('wialon_catalog.sections', []), 'all'])],
        ]);

        $run = $sync->queue($data['sections'] ?? ['all'], 'manual', $request->user());

        return response()->json([
            'run_id' => $run->id,
            'uuid' => $run->uuid,
            'status' => $run->status,
            'sections' => $run->sections_json,
        ], 202);
    }

    public function syncRuns(Request $request): JsonResponse
    {
        $this->authorize('view-wialon-catalog');

        $runs = WialonCatalogSyncRun::query()
            ->with('requestedBy:id,name')
            ->latest()
            ->paginate(min(100, max(10, (int) $request->integer('per_page', 20))));

        return response()->json([
            'data' => $runs->getCollection()->map(fn (WialonCatalogSyncRun $run): array => $this->runRow($run))->values(),
            'meta' => $this->paginationMeta($runs),
        ]);
    }

    public function syncRun(WialonCatalogSyncRun $run): JsonResponse
    {
        $this->authorize('view-wialon-catalog');

        return response()->json([
            'run' => $this->runRow($run->load('requestedBy:id,name')),
            'items' => $run->items()->latest()->limit(300)->get()->map(fn ($item): array => [
                'section' => $item->section,
                'item_type' => $item->item_type,
                'wialon_id' => $item->wialon_id,
                'name' => $item->name,
                'action' => $item->action,
                'status' => $item->status,
                'error' => $item->error,
                'created_at' => optional($item->created_at)->toDateTimeString(),
            ]),
        ]);
    }

    public function projectOptions(): JsonResponse
    {
        $this->authorize('manage-projects');

        return response()->json([
            'projects' => Project::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'unit_groups' => WialonUnitGroup::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'wialon_group_id', 'name', 'units_count', 'linked_project_id', 'ownership_type']),
            'geofences' => WialonGeofence::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'wialon_geofence_id', 'name', 'resource_id', 'resource_name', 'linked_project_id', 'is_home_geofence']),
            'geofence_groups' => WialonGeofenceGroup::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'wialon_geofence_group_id', 'name', 'resource_id', 'resource_name', 'linked_project_id']),
            'resources' => WialonResource::query()->where('is_active', true)->orderBy('name')->get(['id', 'wialon_resource_id', 'name']),
        ]);
    }

    public function storeProject(Request $request): JsonResponse
    {
        $this->authorize('manage-projects');

        $data = $this->validatedProjectMapping($request);

        $this->assertMappingIsValid($data);

        $project = DB::transaction(function () use ($data): Project {
            $project = Project::query()->create([
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'description' => $data['description'] ?? null,
                'active' => (bool) ($data['active'] ?? true),
            ]);

            $this->applyProjectMapping($project, $data);

            return $project;
        });

        return response()->json(['project' => $project->load('wialonGroups', 'geofences')], 201);
    }

    public function updateProjectMapping(Request $request, Project $project): JsonResponse
    {
        $this->authorize('manage-projects');

        $data = $this->validatedProjectMapping($request, false);
        $this->assertMappingIsValid($data, $project);
        $this->applyProjectMapping($project, $data);

        return response()->json(['project' => $project->refresh()->load('wialonGroups', 'geofences')]);
    }

    public function validateProjectMapping(Request $request, Project $project): JsonResponse
    {
        $this->authorize('manage-projects');

        $data = $this->validatedProjectMapping($request, false);
        $result = $this->mappingValidationResult($data, $project);

        return response()->json($result, $result['valid'] ? 200 : 422);
    }

    private function catalogList(
        Request $request,
        Builder $query,
        array $searchColumns,
        array $columns,
        callable $rowMapper,
        XlsxExportService $xlsx,
        string $title
    ): JsonResponse|Response {
        $this->authorize('view-wialon-catalog');

        $query = $this->applyCatalogFilters($request, $query, $searchColumns);
        $sort = in_array($request->query('sort'), $columns, true) ? (string) $request->query('sort') : 'name';
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sort, $direction);

        if ($request->query('export') === 'xlsx') {
            $rows = $query->limit(5000)->get()->map($rowMapper)->values()->all();

            return $this->xlsxResponse($xlsx, $title, $rows);
        }

        $paginator = $query->paginate(min(100, max(10, (int) $request->integer('per_page', 25))));

        return response()->json([
            'data' => $paginator->getCollection()->map($rowMapper)->values(),
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    private function applyCatalogFilters(Request $request, Builder $query, array $searchColumns): Builder
    {
        $table = $query->getModel()->getTable();
        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            $query->where(function (Builder $query) use ($searchColumns, $search): void {
                foreach ($searchColumns as $column) {
                    $query->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }

        if ($request->query('status') === 'active') {
            $query->where('is_active', true);
        } elseif ($request->query('status') === 'inactive') {
            $query->where('is_active', false);
        }

        foreach (['resource_id', 'group_id', 'project_id'] as $filter) {
            $value = trim((string) $request->query($filter, ''));

            if ($value === '') {
                continue;
            }

            if ($filter === 'group_id') {
                $groupColumns = collect(['wialon_group_id', 'geofence_group_id', 'wialon_geofence_group_id'])
                    ->filter(fn (string $column): bool => Schema::hasColumn($table, $column))
                    ->values()
                    ->all();

                if ($groupColumns === []) {
                    continue;
                }

                $query->where(function (Builder $query) use ($value, $groupColumns): void {
                    foreach ($groupColumns as $column) {
                        $query->orWhere($column, $value);
                    }
                });

                continue;
            }

            match ($filter) {
                'resource_id' => Schema::hasColumn($table, 'resource_id')
                    ? $query->where('resource_id', $value)
                    : null,
                'project_id' => Schema::hasColumn($table, 'linked_project_id')
                    ? $query->where('linked_project_id', $value)
                    : null,
                default => null,
            };
        }

        return $query;
    }

    private function validatedProjectMapping(Request $request, bool $includeProjectFields = true): array
    {
        $rules = [
            'ownership_type' => ['required', Rule::in([Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE])],
            'wialon_group_id' => ['required', 'string', 'max:100'],
            'home_geofence_id' => ['nullable', 'integer', 'exists:wialon_geofences,id'],
            'geofence_group_id' => ['nullable', 'integer', 'exists:wialon_geofence_groups,id'],
        ];

        if ($includeProjectFields) {
            $rules = [
                'name' => ['required', 'string', 'max:255', Rule::unique('projects', 'name')],
                'code' => ['nullable', 'string', 'max:50'],
                'description' => ['nullable', 'string'],
                'active' => ['nullable', 'boolean'],
                ...$rules,
            ];
        }

        return $request->validate($rules);
    }

    private function assertMappingIsValid(array $data, ?Project $project = null): void
    {
        $result = $this->mappingValidationResult($data, $project);

        if (! $result['valid']) {
            throw ValidationException::withMessages(['wialon_group_id' => $result['message']]);
        }
    }

    private function mappingValidationResult(array $data, ?Project $project = null): array
    {
        $unitGroup = WialonUnitGroup::query()
            ->where('wialon_group_id', $data['wialon_group_id'])
            ->where('is_active', true)
            ->first();

        if (! $unitGroup) {
            return ['valid' => false, 'message' => 'Seçilmiş Wialon obyekt qrupu tapılmadı və ya aktiv deyil.'];
        }

        $conflict = ProjectWialonGroup::query()
            ->where('wialon_group_id', $data['wialon_group_id'])
            ->when($project, fn (Builder $query): Builder => $query->where('project_id', '!=', $project->id))
            ->first();

        if ($conflict) {
            return ['valid' => false, 'message' => 'Bu Wialon qrupu artıq başqa aktiv layihəyə bağlıdır.'];
        }

        if (! empty($data['home_geofence_id'])) {
            $geofence = WialonGeofence::query()->whereKey($data['home_geofence_id'])->where('is_active', true)->first();

            if (! $geofence) {
                return ['valid' => false, 'message' => 'Seçilmiş ev geozonası tapılmadı və ya aktiv deyil.'];
            }
        }

        return [
            'valid' => true,
            'message' => 'Mapping düzgündür.',
            'unit_group' => $unitGroup->only(['wialon_group_id', 'name', 'units_count', 'resource_id']),
        ];
    }

    private function applyProjectMapping(Project $project, array $data): void
    {
        $unitGroup = WialonUnitGroup::query()->where('wialon_group_id', $data['wialon_group_id'])->firstOrFail();

        ProjectWialonGroup::query()->updateOrCreate(
            ['project_id' => $project->id, 'ownership_type' => $data['ownership_type']],
            [
                'wialon_group_id' => $unitGroup->wialon_group_id,
                'name' => $unitGroup->name,
                'is_active' => true,
            ]
        );

        $unitGroup->forceFill([
            'linked_project_id' => $project->id,
            'ownership_type' => $data['ownership_type'],
        ])->save();

        if (! empty($data['home_geofence_id'])) {
            $catalogGeofence = WialonGeofence::query()->findOrFail($data['home_geofence_id']);
            $stableGeofenceId = $catalogGeofence->resource_id.':'.$catalogGeofence->wialon_geofence_id;
            $localGeofence = Geofence::query()
                ->whereIn('wialon_geofence_id', [$stableGeofenceId, $catalogGeofence->wialon_geofence_id])
                ->first() ?? new Geofence(['wialon_geofence_id' => $stableGeofenceId]);
            $localGeofence->fill([
                'name' => $catalogGeofence->name,
                'normalized_name' => str($catalogGeofence->name)->lower()->squish()->toString(),
                'project_id' => $project->id,
                'geometry_json' => $catalogGeofence->raw_geometry_json,
                'active' => true,
            ])->save();

            $catalogGeofence->forceFill([
                'linked_project_id' => $project->id,
                'local_geofence_id' => $localGeofence->id,
                'is_home_geofence' => true,
            ])->save();
        }
    }

    private function runRow(WialonCatalogSyncRun $run): array
    {
        return [
            'id' => $run->id,
            'uuid' => $run->uuid,
            'started_at' => optional($run->started_at)->toDateTimeString(),
            'completed_at' => optional($run->completed_at)->toDateTimeString(),
            'started_by' => $run->requestedBy?->name,
            'sync_type' => $run->sync_type,
            'sections' => $run->sections_json,
            'status' => $run->status,
            'added_count' => $run->added_count,
            'updated_count' => $run->updated_count,
            'deactivated_count' => $run->deactivated_count,
            'error_count' => $run->error_count,
            'duration_ms' => $run->duration_ms,
            'last_error' => $run->last_error,
        ];
    }

    private function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    private function xlsxResponse(XlsxExportService $xlsx, string $title, array $rows): Response
    {
        $columns = collect($rows)->first()
            ? array_keys(collect($rows)->first())
            : ['Məlumat'];

        $content = $xlsx->build([
            'title' => $title,
            'filters' => [['Generated at', now(config('app.timezone'))->toDateTimeString()]],
            'sections' => [[
                'title' => $title,
                'columns' => $columns,
                'rows' => collect($rows)->map(fn (array $row): array => array_values($row))->all(),
            ]],
        ]);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.str($title)->slug()->append('.xlsx')->toString().'"',
        ]);
    }

    private function tabs(): array
    {
        return [
            'overview' => 'Ümumi baxış',
            'unit-groups' => 'Obyekt qrupları',
            'units' => 'Obyektlər',
            'geofences' => 'Geozonalar',
            'geofence-groups' => 'Geozona qrupları',
            'resources' => 'Hesabat resursları',
            'report-templates' => 'Hesabat şablonları',
            'projects' => 'Layihə uyğunlaşdırılması',
            'sync-runs' => 'Sinxronizasiya tarixçəsi',
        ];
    }

    private function syncSections(): array
    {
        return [
            'all' => 'Hamısını sinxronlaşdır',
            'unit_groups' => 'Obyekt qruplarını yenilə',
            'units' => 'Obyektləri yenilə',
            'geofences' => 'Geozonaları yenilə',
            'geofence_groups' => 'Geozona qruplarını yenilə',
            'resources' => 'Resursları yenilə',
            'report_templates' => 'Hesabat şablonlarını yenilə',
        ];
    }
}
