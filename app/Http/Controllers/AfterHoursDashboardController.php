<?php

namespace App\Http\Controllers;

use App\Services\NightDayEfficiencyDashboardService;
use App\Services\XlsxExportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class AfterHoursDashboardController extends Controller
{
    public function summary(Request $request, NightDayEfficiencyDashboardService $dashboard): array
    {
        return ['data' => $dashboard->summary($this->filters($request))];
    }

    public function projects(Request $request, NightDayEfficiencyDashboardService $dashboard): array
    {
        $rows = $dashboard->paginateProjects($this->filters($request));

        return [
            'title' => 'Qeyri iş saatlarında işləyən: '.$this->ownershipLabel($request),
            'columns' => [
                'project' => 'Layihə',
                'ownership' => 'Ownership',
                'unique_units_count' => 'Texnika sayı',
            ],
            ...$this->paginated($rows),
            'summary' => ['total' => $rows->total()],
        ];
    }

    public function units(Request $request, NightDayEfficiencyDashboardService $dashboard): array
    {
        $rows = $dashboard->paginateUnits($this->filters($request));

        return [
            'columns' => [
                'number' => '№',
                'date' => 'Tarix',
                'name' => 'Maşın nömrəsi',
                'project' => 'Layihə',
                'vehicle_type' => 'Texnika növü',
                'ownership' => 'Ownership',
                'engine_hours' => 'Motosaat',
                'started_at' => 'Başlama',
                'ended_at' => 'Bitmə',
                'mileage' => 'Yürüş',
            ],
            ...$this->paginated($rows),
            'summary' => ['total' => $rows->total()],
        ];
    }

    public function export(
        Request $request,
        NightDayEfficiencyDashboardService $dashboard,
        XlsxExportService $xlsx
    ): Response {
        $filters = $this->filters($request);
        $export = in_array(($filters['view'] ?? null), ['projects', 'units'], true)
            ? $dashboard->exportList($filters)
            : $dashboard->export($filters);
        $content = $xlsx->build($export);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Content-Length' => (string) strlen($content),
        ]);
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
            'view' => ['nullable', Rule::in(['projects', 'units'])],
            'vehicle_type' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['0_1', '1_7', '7_10', 'over_10', 'no_data'])],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', Rule::in(['date', 'name', 'project', 'vehicle_type', 'ownership', 'engine_hours', 'mileage', 'status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
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

    private function ownershipLabel(Request $request): string
    {
        return match (mb_strtolower((string) ($request->input('ownership_type') ?? $request->input('ownership')))) {
            'icare', 'icarə' => 'İcarə',
            'nwc' => 'NWC',
            default => 'NWC + İcarə',
        };
    }
}
