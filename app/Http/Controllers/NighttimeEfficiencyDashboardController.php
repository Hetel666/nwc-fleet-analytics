<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateDashboardExportJob;
use App\Models\DashboardExport;
use App\Services\DashboardDisplayConfigurationService;
use App\Services\NighttimeEfficiencyDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class NighttimeEfficiencyDashboardController extends Controller
{
    public function summary(
        Request $request,
        NighttimeEfficiencyDashboardService $dashboard,
        DashboardDisplayConfigurationService $displayConfiguration
    ): array {
        $filters = $this->visibleFilters($request, $displayConfiguration);

        return ['data' => $displayConfiguration->filterSummaryRows($dashboard->summary($filters), 'nighttime_efficiency')];
    }

    public function projects(
        Request $request,
        NighttimeEfficiencyDashboardService $dashboard,
        DashboardDisplayConfigurationService $displayConfiguration
    ): array {
        abort_unless($dashboard->isReady(), 503, 'Nighttime efficiency storage is not ready.');
        $rows = $dashboard->paginateProjects($this->visibleFilters($request, $displayConfiguration));

        return [
            'title' => 'Gecə effektivliyi - '.($request->string('status')->toString() ?: 'Layihələr'),
            'columns' => [
                'project' => 'Layihə',
                'ownership' => 'Ownership',
                'status' => 'Status',
                'equipment_shifts_count' => 'Texnika-növbə sayı',
                'unique_units_count' => 'Unikal texnika sayı',
                'average_engine_hours' => 'Orta Engine hours',
            ],
            ...$this->paginated($rows),
            'summary' => ['total' => $rows->total()],
        ];
    }

    public function units(
        Request $request,
        NighttimeEfficiencyDashboardService $dashboard,
        DashboardDisplayConfigurationService $displayConfiguration
    ): array {
        abort_unless($dashboard->isReady(), 503, 'Nighttime efficiency storage is not ready.');
        $rows = $dashboard->paginateUnits($this->visibleFilters($request, $displayConfiguration));

        return [
            'title' => 'Gecə effektivliyi - Texnika siyahısı',
            'columns' => [
                'name' => 'Texnika',
                'project' => 'Layihə',
                'vehicle_type' => 'Texnika növü',
                'ownership' => 'Ownership',
                'shift_date' => 'Növbə tarixi',
                'engine_hours' => 'Engine hours',
                'shift_started_at' => 'Növbə başlanğıcı',
                'shift_ended_at' => 'Növbə sonu',
                'started_at' => 'Faktiki başlama',
                'ended_at' => 'Faktiki bitmə',
                'mileage' => 'Yürüş',
                'status_label' => 'Status',
            ],
            ...$this->paginated($rows),
            'summary' => ['total' => $rows->total()],
        ];
    }

    public function export(
        Request $request,
        NighttimeEfficiencyDashboardService $dashboard,
        DashboardDisplayConfigurationService $displayConfiguration
    ): JsonResponse|View {
        abort_unless($dashboard->isReady(), 503, 'Nighttime efficiency storage is not ready.');
        $filters = $dashboard->normalizeFilters($this->visibleFilters($request, $displayConfiguration), 'export');
        $record = DashboardExport::query()->create([
            'user_id' => $request->user()->id,
            'block' => 'nighttime_efficiency',
            'filters' => $filters,
            'status' => DashboardExport::STATUS_PENDING,
        ]);

        GenerateDashboardExportJob::dispatch($record->id)->onConnection('database')->onQueue('default');

        if ($request->expectsJson()) {
            return response()->json([
                'id' => $record->id,
                'status' => $record->status,
                'status_url' => route('dashboard.exports.status', $record),
            ], 202);
        }

        return view('dashboard.exports.pending', ['export' => $record]);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'ownership' => ['nullable', Rule::in(['NWC', 'ICARE', 'nwc', 'icare'])],
            'ownership_type' => ['nullable', Rule::in(['NWC', 'ICARE'])],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'vehicle_type' => ['nullable', 'string', 'max:100'],
            'equipment_type_id' => ['nullable', 'integer', 'exists:equipment_types,id'],
            'status' => ['nullable', Rule::in(['0_1', '1_7', '7_10', 'over_10', 'no_data'])],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', Rule::in(['date', 'name', 'project', 'vehicle_type', 'ownership', 'engine_hours', 'mileage', 'status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'data_status' => ['nullable', 'string', 'max:30'],
            'duration_format' => ['nullable', 'string', 'max:30'],
            'has_overtime' => ['nullable'],
            'view' => ['nullable', 'string', 'max:30'],
        ]);
    }

    private function paginated($rows): array
    {
        return [
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ];
    }

    private function visibleFilters(Request $request, DashboardDisplayConfigurationService $displayConfiguration): array
    {
        $filters = $this->filters($request);
        $displayConfiguration->assertDashboardVisibleForOwnership(
            $filters,
            'efficiency_nighttime_nwc',
            'efficiency_nighttime_rental'
        );

        return $displayConfiguration->applyVisibleStatusesToFilters($filters, 'nighttime_efficiency');
    }
}
