<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreHistoricalRecalculationRequest;
use App\Models\HistoricalRecalculation;
use App\Models\HistoricalRecalculationTask;
use App\Models\Project;
use App\Services\DashboardModuleRegistry;
use App\Services\DashboardReportPipelineService;
use App\Services\HistoricalRecalculationService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class HistoricalRecalculationController extends Controller
{
    /** @var array<int, string> */
    private const ALL_DASHBOARD_SECTIONS = [
        HistoricalRecalculation::SECTION_DAILY_AVERAGES,
        HistoricalRecalculation::SECTION_EFFICIENCY,
        HistoricalRecalculation::SECTION_MONTHLY_EFFICIENCY,
        HistoricalRecalculation::SECTION_GEOFENCE_VIOLATIONS,
        HistoricalRecalculation::SECTION_GEOFENCE_OUTSIDE,
    ];

    public function index(
        Request $request,
        DashboardReportPipelineService $pipelines,
        DashboardModuleRegistry $dashboardModules
    ): View
    {
        $this->authorize('manage-historical-recalculations');
        $defaultDateFrom = $this->safeDateQuery($request->query('date_from'))
            ?: now(config('historical_recalculation.timezone', 'Asia/Baku'))->toDateString();
        $defaultDateTo = $this->safeDateQuery($request->query('date_to')) ?: $defaultDateFrom;
        $defaultProjectId = $request->integer('project_id') > 0 ? $request->integer('project_id') : null;
        $historicalModuleContracts = $dashboardModules->all()
            ->filter(fn (array $module): bool => ($module['dashboard_section'] ?? null) !== null)
            ->keyBy(fn (array $module): string => (string) $module['dashboard_section'])
            ->map(fn (array $module): array => [
                'code' => $module['code'],
                'title' => $module['title'],
                'source_report' => $module['source_report'],
                'collector_command' => $module['collector_command'],
                'manual_command' => $module['manual_command'],
                'auto_schedule' => $module['auto_schedule'],
                'result_tables' => $module['result_tables'],
                'shared_result_tables' => $module['shared_result_tables'],
                'safe_resync_scope' => $module['safe_resync_scope'],
                'writes_shared_tables' => $module['writes_shared_tables'],
                'failure_isolation' => $module['failure_isolation'],
            ]);

        return view('admin.historical-recalculations.index', [
            'projects' => Project::query()
                ->where('active', true)
                ->whereNotIn('name', Project::dashboardOperationalExcludedNames())
                ->orderBy('name')
                ->get(['id', 'name']),
            'historicalModuleContracts' => $historicalModuleContracts,
            'pipelineQueue' => $pipelines->queueSnapshot(),
            'runs' => HistoricalRecalculation::query()
                ->with('requestedBy:id,name')
                ->latest()
                ->limit(30)
                ->get(),
            'defaultTimezone' => config('historical_recalculation.timezone', 'Asia/Baku'),
            'today' => now(config('historical_recalculation.timezone', 'Asia/Baku'))->toDateString(),
            'defaultDateFrom' => $defaultDateFrom,
            'defaultDateTo' => $defaultDateTo,
            'defaultProjectId' => $defaultProjectId,
        ]);
    }

    private function safeDateQuery(mixed $value): ?string
    {
        $value = trim((string) $value);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::parse($value, config('historical_recalculation.timezone', 'Asia/Baku'))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    public function clearClosedPipelines(DashboardReportPipelineService $pipelines): RedirectResponse
    {
        $this->authorize('manage-historical-recalculations');

        $summary = $pipelines->clearClosed();

        return back()->with('status', sprintf(
            'Pipeline cleanup: %d closed entries removed, %d active entries kept.',
            (int) ($summary['removed_closed'] ?? 0),
            (int) ($summary['kept_active'] ?? 0),
        ));
    }

    public function preview(StoreHistoricalRecalculationRequest $request, HistoricalRecalculationService $service): JsonResponse
    {
        $payload = $request->validated();

        if (($payload['dashboard_section'] ?? null) !== HistoricalRecalculation::SECTION_ALL_DASHBOARDS) {
            return response()->json($service->preview($payload));
        }

        return response()->json($this->previewAllDashboards($payload, $service));
    }

    public function store(
        StoreHistoricalRecalculationRequest $request,
        HistoricalRecalculationService $service,
        DashboardReportPipelineService $pipelines
    ): RedirectResponse
    {
        $payload = $request->validated();
        $plans = $this->pipelinePlans($payload, $service);
        $preview = $plans['preview'];

        if ((int) ($preview['total_tasks'] ?? 0) === 0) {
            throw ValidationException::withMessages([
                'project_ids' => 'Seçilmiş tarix və layihə üçün icra edilə bilən tapşırıq tapılmadı.',
            ]);
        }

        $result = $pipelines->queue(
            $plans['payloads'],
            'manual',
            $pipelines->priorityForSource('manual'),
            false,
            $request->user()
        );
        $runId = $result['started_run_id']
            ?? (is_array($result['pipeline'] ?? null) ? ($result['pipeline']['current_run_id'] ?? null) : null);
        $run = $runId ? HistoricalRecalculation::query()->find((int) $runId) : null;

        if (! $run) {
            return redirect()
                ->route('admin.historical-recalculations.index')
                ->with('status', 'Tarixi məlumatların yenilənməsi pipeline növbəsinə əlavə edildi.');
        }

        return redirect()
            ->route('admin.historical-recalculations.show', $run)
            ->with('status', 'Tarixi məlumatların yenilənməsi pipeline növbəsinə əlavə edildi.');
    }

    /**
     * @return array{payloads: array<int, array<string, mixed>>, preview: array<string, mixed>}
     */
    private function pipelinePlans(array $payload, HistoricalRecalculationService $service): array
    {
        if (($payload['dashboard_section'] ?? null) !== HistoricalRecalculation::SECTION_ALL_DASHBOARDS) {
            return [
                'payloads' => [$payload],
                'preview' => $service->preview($payload),
            ];
        }

        $preview = $this->previewAllDashboards($payload, $service);

        return [
            'payloads' => collect($preview['modules'] ?? [])
                ->map(fn (array $module): array => $module['payload'])
                ->values()
                ->all(),
            'preview' => $preview,
        ];
    }

    /** @return array<string, mixed> */
    private function previewAllDashboards(array $payload, HistoricalRecalculationService $service): array
    {
        $modules = $this->dates($payload)
            ->flatMap(fn (string $date): Collection => $this->sectionPayloads($payload, $date))
            ->map(function (array $modulePayload) use ($service): ?array {
                $preview = $service->preview($modulePayload);

                if ((int) ($preview['total_tasks'] ?? 0) <= 0) {
                    return null;
                }

                return [
                    'section' => $modulePayload['dashboard_section'],
                    'date_from' => $modulePayload['date_from'],
                    'date_to' => $modulePayload['date_to'],
                    'payload' => $modulePayload,
                    'preview' => $preview,
                ];
            })
            ->filter()
            ->values();

        $tables = $modules
            ->flatMap(fn (array $module): array => $module['preview']['dry_run']['tables'] ?? [])
            ->unique(fn (array $table): string => (string) ($table['table'] ?? '').'|'.json_encode($table['filters'] ?? []))
            ->values()
            ->all();
        $warnings = $modules
            ->flatMap(fn (array $module): array => $module['preview']['dry_run']['warnings'] ?? [])
            ->unique()
            ->values()
            ->all();

        return [
            'mode' => HistoricalRecalculation::SECTION_ALL_DASHBOARDS,
            'days' => $this->dates($payload)->count(),
            'project_groups' => (int) $modules->sum(fn (array $module): int => (int) ($module['preview']['project_groups'] ?? 0)),
            'fetch_tasks' => (int) $modules->sum(fn (array $module): int => (int) ($module['preview']['fetch_tasks'] ?? 0)),
            'aggregate_tasks' => (int) $modules->sum(fn (array $module): int => (int) ($module['preview']['aggregate_tasks'] ?? 0)),
            'total_tasks' => (int) $modules->sum(fn (array $module): int => (int) ($module['preview']['total_tasks'] ?? 0)),
            'pipeline_steps' => $modules->count(),
            'modules' => $modules
                ->map(fn (array $module): array => [
                    'section' => $module['section'],
                    'date_from' => $module['date_from'],
                    'date_to' => $module['date_to'],
                    'total_tasks' => (int) ($module['preview']['total_tasks'] ?? 0),
                    'payload' => $module['payload'],
                ])
                ->all(),
            'dry_run' => [
                'dashboard_code' => HistoricalRecalculation::SECTION_ALL_DASHBOARDS,
                'source_report' => 'All registered dashboard reports',
                'manual_command' => 'dashboard-reports pipeline',
                'writes_shared_tables' => true,
                'isolation' => 'sequential pipeline',
                'force' => (bool) ($payload['force'] ?? false),
                'tables' => $tables,
                'warnings' => array_values(array_unique([
                    ...$warnings,
                    'All dashboards mode queues one module per day. The next module starts only after the current run is terminal.',
                ])),
            ],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function sectionPayloads(array $payload, string $date): Collection
    {
        return collect(self::ALL_DASHBOARD_SECTIONS)->map(function (string $section) use ($payload, $date): array {
            $modulePayload = $payload;
            $modulePayload['dashboard_section'] = $section;
            $modulePayload['date_from'] = $date;
            $modulePayload['date_to'] = $date;

            return $modulePayload;
        });
    }

    /** @return Collection<int, string> */
    private function dates(array $payload): Collection
    {
        $timezone = (string) ($payload['timezone'] ?? config('historical_recalculation.timezone', 'Asia/Baku'));

        return collect(CarbonPeriod::create(
            Carbon::parse((string) $payload['date_from'], $timezone)->startOfDay(),
            Carbon::parse((string) $payload['date_to'], $timezone)->startOfDay()
        ))->map(fn (Carbon $date): string => $date->toDateString())->values();
    }

    public function show(HistoricalRecalculation $historicalRecalculation): View
    {
        $this->authorize('manage-historical-recalculations');

        return view('admin.historical-recalculations.show', [
            'run' => $historicalRecalculation->load('requestedBy:id,name'),
            'tasks' => $historicalRecalculation->tasks()
                ->with('project:id,name')
                ->orderBy('stat_date')
                ->orderBy('project_id')
                ->orderBy('ownership_type')
                ->paginate(80),
        ]);
    }

    public function status(HistoricalRecalculation $historicalRecalculation): JsonResponse
    {
        $this->authorize('manage-historical-recalculations');

        $run = $historicalRecalculation->refresh();

        return response()->json([
            'status' => $run->status,
            'total_tasks' => $run->total_tasks,
            'completed_tasks' => $run->completed_tasks,
            'failed_tasks' => $run->failed_tasks,
            'cancelled_tasks' => $run->cancelled_tasks,
            'processed_objects' => $run->processed_objects,
            'progress_percent' => $run->total_tasks > 0
                ? round((($run->completed_tasks + $run->failed_tasks + $run->cancelled_tasks) / $run->total_tasks) * 100, 1)
                : 0,
            'last_heartbeat_at' => optional($run->last_heartbeat_at)->toDateTimeString(),
            'completed_at' => optional($run->completed_at)->toDateTimeString(),
        ]);
    }

    public function cancel(HistoricalRecalculation $historicalRecalculation, HistoricalRecalculationService $service): RedirectResponse
    {
        $this->authorize('manage-historical-recalculations');

        $service->cancel($historicalRecalculation);

        return back()->with('status', 'Gözləyən tapşırıqlar ləğv edildi.');
    }

    public function retry(HistoricalRecalculation $historicalRecalculation, HistoricalRecalculationService $service): RedirectResponse
    {
        $this->authorize('manage-historical-recalculations');

        $service->retryFailed($historicalRecalculation);

        return back()->with('status', 'Uğursuz tapşırıqlar yenidən növbəyə əlavə edildi.');
    }
}
