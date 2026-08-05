<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateDashboardExportJob;
use App\Models\DashboardExport;
use App\Services\DashboardDisplayConfigurationService;
use App\Services\MonthlyEfficiencyDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class MonthlyEfficiencyDashboardController extends Controller
{
    public function summary(
        Request $request,
        MonthlyEfficiencyDashboardService $dashboard,
        DashboardDisplayConfigurationService $displayConfiguration
    ): array|JsonResponse {
        try {
            $filters = $this->visibleFilters($request, $displayConfiguration);
            $rows = $dashboard->summary($filters);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return ['data' => $displayConfiguration->filterSummaryRows($rows, 'monthly_efficiency')];
    }

    public function projects(
        Request $request,
        MonthlyEfficiencyDashboardService $dashboard,
        DashboardDisplayConfigurationService $displayConfiguration
    ): array|JsonResponse {
        abort_unless($dashboard->isReady(), 503, 'Monthly efficiency storage is not ready.');

        try {
            $rows = $dashboard->paginateProjects($this->visibleFilters($request, $displayConfiguration));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return [
            'title' => ($request->string('ownership')->lower()->toString() === 'icare' ? 'İcarə üzrə' : 'NWC üzrə')
                .' — '.$this->statusLabel($request->string('status')->toString()),
            'columns' => [
                'project' => 'Layihə',
                'count' => 'Texnika sayı',
            ],
            ...$this->paginated($rows),
            'summary' => ['total' => $rows->total()],
        ];
    }

    public function units(
        Request $request,
        MonthlyEfficiencyDashboardService $dashboard,
        DashboardDisplayConfigurationService $displayConfiguration
    ): array|JsonResponse {
        abort_unless($dashboard->isReady(), 503, 'Monthly efficiency storage is not ready.');

        try {
            $rows = $dashboard->paginateUnits($this->visibleFilters($request, $displayConfiguration));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return [
            'title' => 'Aylıq effektivlik - Texnika siyahısı',
            'columns' => [
                'number' => '№',
                'registration_number' => 'D.Q.N.',
                'vehicle_type' => 'Texnika tipi',
                'project' => 'Layihə',
                'period' => 'Dövr',
                'synced_days_count' => 'Gün sayı',
                'current_hours' => 'Cari MS',
                'normative_hours' => 'Normativ MS',
                'efficiency_percent' => 'Effektivlik %',
                'ownership' => 'Mənsubiyyət',
                'status_label' => 'Status',
            ],
            ...$this->paginated($rows),
            'summary' => ['total' => $rows->total()],
        ];
    }

    public function export(
        Request $request,
        MonthlyEfficiencyDashboardService $dashboard,
        DashboardDisplayConfigurationService $displayConfiguration
    ): JsonResponse|View {
        abort_unless($dashboard->isReady(), 503, 'Monthly efficiency storage is not ready.');

        try {
            $filters = $dashboard->normalizeFilters($this->visibleFilters($request, $displayConfiguration), 'export');
        } catch (InvalidArgumentException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            abort(422, $exception->getMessage());
        }

        $record = DashboardExport::query()->create([
            'user_id' => $request->user()->id,
            'block' => 'monthly_efficiency',
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
            'month' => ['nullable', 'date_format:Y-m'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'ownership' => ['nullable', Rule::in(['NWC', 'ICARE', 'nwc', 'icare'])],
            'ownership_type' => ['nullable', Rule::in(['NWC', 'ICARE'])],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'vehicle_type' => ['nullable', 'string', 'max:100'],
            'equipment_type_id' => ['nullable', 'integer', 'exists:equipment_types,id'],
            'status' => ['nullable', Rule::in(['critical_low', 'low', 'normal'])],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', Rule::in(['name', 'registration_number', 'project', 'vehicle_type', 'ownership', 'current_hours', 'normative_hours', 'efficiency_percent', 'status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
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
            'monthly_efficiency_nwc',
            'monthly_efficiency_rental'
        );

        return $displayConfiguration->applyVisibleStatusesToFilters($filters, 'monthly_efficiency');
    }

    private function statusLabel(?string $status): string
    {
        return match ($status) {
            'critical_low' => 'Kritik aşağı',
            'low' => 'Aşağı',
            'normal' => 'Normal',
            default => 'Layihələr',
        };
    }
}
